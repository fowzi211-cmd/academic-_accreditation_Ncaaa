<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";

requireRole(["Admin", "Quality Coordinator"]);

// ===============================
// Settings
// ===============================
$backupDir = ensureStorageDirectory('backups');

$maxBackupAgeDays = 7;
$maxBackupFolderSizeMB = 1024; // 1 GB warning limit

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function formatBytes($bytes) {
    $bytes = (int)$bytes;

    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . " GB";
    }

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . " MB";
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . " KB";
    }

    return $bytes . " bytes";
}

function detectBackupType($filename) {
    $lower = strtolower($filename);

    if (substr($lower, -4) === ".sql" || strpos($lower, "database") !== false) {
        return "Database";
    }

    if (substr($lower, -4) === ".zip" || strpos($lower, "files") !== false) {
        return "Files";
    }

    return "Other";
}

function statusBadge($status) {
    if ($status === "OK") {
        return '<span class="badge badge-ok">OK</span>';
    }

    if ($status === "Warning") {
        return '<span class="badge badge-warning">Warning</span>';
    }

    return '<span class="badge badge-danger">Problem</span>';
}

function daysSinceTimestamp($timestamp) {
    if (!$timestamp) {
        return null;
    }

    $seconds = time() - $timestamp;
    return floor($seconds / 86400);
}

// ===============================
// Scan backup folder
// ===============================
$backupFiles = [];
$databaseBackups = [];
$fileBackups = [];
$otherBackups = [];

$totalSize = 0;
$totalFiles = 0;

$folderExists = is_dir($backupDir);

if ($folderExists) {
    $files = glob($backupDir . DIRECTORY_SEPARATOR . "*");

    if ($files !== false) {
        rsort($files);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (!in_array($extension, ["sql", "zip"], true)) {
                continue;
            }

            $name = basename($file);
            $size = filesize($file);
            $modifiedTimestamp = filemtime($file);
            $modified = date("Y-m-d H:i:s", $modifiedTimestamp);
            $type = detectBackupType($name);

            $status = "OK";
            $note = "File exists and is not empty.";

            if ($size <= 0) {
                $status = "Problem";
                $note = "File is empty.";
            }

            if ($type === "Database" && $extension !== "sql") {
                $status = "Warning";
                $note = "Database backup should normally be a .sql file.";
            }

            if ($type === "Files" && $extension !== "zip") {
                $status = "Warning";
                $note = "Files backup should normally be a .zip file.";
            }

            $item = [
                "name" => $name,
                "path" => $file,
                "type" => $type,
                "extension" => $extension,
                "size" => $size,
                "modified_timestamp" => $modifiedTimestamp,
                "modified" => $modified,
                "age_days" => daysSinceTimestamp($modifiedTimestamp),
                "status" => $status,
                "note" => $note
            ];

            $backupFiles[] = $item;
            $totalSize += $size;
            $totalFiles++;

            if ($type === "Database") {
                $databaseBackups[] = $item;
            } elseif ($type === "Files") {
                $fileBackups[] = $item;
            } else {
                $otherBackups[] = $item;
            }
        }
    }
}

$latestDatabaseBackup = count($databaseBackups) > 0 ? $databaseBackups[0] : null;
$latestFileBackup = count($fileBackups) > 0 ? $fileBackups[0] : null;
$latestAnyBackup = count($backupFiles) > 0 ? $backupFiles[0] : null;

$totalDatabaseBackups = count($databaseBackups);
$totalFileBackups = count($fileBackups);
$totalOtherBackups = count($otherBackups);

$folderSizeMB = $totalSize / 1048576;

// ===============================
// Integrity checks
// ===============================
$checks = [];

$checks[] = [
    "check" => "Backup folder exists",
    "status" => $folderExists ? "OK" : "Problem",
    "details" => $folderExists ? $backupDir : "Backup folder does not exist."
];

$checks[] = [
    "check" => "At least one database backup exists",
    "status" => $totalDatabaseBackups > 0 ? "OK" : "Problem",
    "details" => $totalDatabaseBackups . " database backup file(s) found."
];

$checks[] = [
    "check" => "At least one files backup exists",
    "status" => $totalFileBackups > 0 ? "OK" : "Problem",
    "details" => $totalFileBackups . " files backup ZIP(s) found."
];

if ($latestDatabaseBackup) {
    $dbAge = $latestDatabaseBackup["age_days"];

    $checks[] = [
        "check" => "Latest database backup is recent",
        "status" => $dbAge <= $maxBackupAgeDays ? "OK" : "Warning",
        "details" => "Latest database backup age: " . $dbAge . " day(s). Limit: " . $maxBackupAgeDays . " day(s)."
    ];

    $checks[] = [
        "check" => "Latest database backup is not empty",
        "status" => $latestDatabaseBackup["size"] > 0 ? "OK" : "Problem",
        "details" => $latestDatabaseBackup["name"] . " | " . formatBytes($latestDatabaseBackup["size"])
    ];
} else {
    $checks[] = [
        "check" => "Latest database backup is recent",
        "status" => "Problem",
        "details" => "No database backup found."
    ];
}

if ($latestFileBackup) {
    $fileAge = $latestFileBackup["age_days"];

    $checks[] = [
        "check" => "Latest files backup is recent",
        "status" => $fileAge <= $maxBackupAgeDays ? "OK" : "Warning",
        "details" => "Latest files backup age: " . $fileAge . " day(s). Limit: " . $maxBackupAgeDays . " day(s)."
    ];

    $checks[] = [
        "check" => "Latest files backup is not empty",
        "status" => $latestFileBackup["size"] > 0 ? "OK" : "Problem",
        "details" => $latestFileBackup["name"] . " | " . formatBytes($latestFileBackup["size"])
    ];
} else {
    $checks[] = [
        "check" => "Latest files backup is recent",
        "status" => "Problem",
        "details" => "No files backup found."
    ];
}

$checks[] = [
    "check" => "Backup folder size is controlled",
    "status" => $folderSizeMB <= $maxBackupFolderSizeMB ? "OK" : "Warning",
    "details" => "Current size: " . formatBytes($totalSize) . ". Warning limit: " . $maxBackupFolderSizeMB . " MB."
];

// ===============================
// Overall status
// ===============================
$problemCount = 0;
$warningCount = 0;
$okCount = 0;

foreach ($checks as $check) {
    if ($check["status"] === "Problem") {
        $problemCount++;
    } elseif ($check["status"] === "Warning") {
        $warningCount++;
    } else {
        $okCount++;
    }
}

$overallStatus = "OK";

if ($problemCount > 0) {
    $overallStatus = "Problem";
} elseif ($warningCount > 0) {
    $overallStatus = "Warning";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Backup Integrity Check</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .dashboard-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .dashboard-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }

        .dashboard-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .actions {
            margin-bottom: 18px;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-ok {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
        }

        .badge-db {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-files {
            background: #cff4fc;
            color: #055160;
        }

        .badge-other {
            background: #e9ecef;
            color: #495057;
        }

        .summary-ok {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .summary-warning {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #664d03;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .summary-danger {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        @media (max-width: 1100px) {
            .dashboard-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Backup Integrity Check</h1>
        <p>Check whether backup files exist, are recent, non-empty, and under control.</p>
    </div>

    <div class="actions">
        <a href="backup_dashboard.php" class="btn btn-purple">Backup Dashboard</a>
        <a href="backup_all_now.php" class="btn">Backup All</a>
        <a href="backup_now.php" class="btn">Database Backup</a>
        <a href="backup_files_now.php" class="btn">Files Backup</a>
        <a href="backup_cleanup.php" class="btn btn-delete">Backup Cleanup</a>
        <a href="restore_guide.php" class="btn">Restore Guide</a>
        <a href="health_check.php" class="btn">Health Check</a>
	<a href="export_backup_report_csv.php" class="btn btn-purple">Export Backup Report CSV</a>
	<a href="export_backup_report_csv.php" class="btn btn-purple">Export Backup Report CSV</a>
    </div>

    <?php if ($overallStatus === "OK"): ?>
        <div class="summary-ok">
            Overall Status: OK. Backup condition looks acceptable.
        </div>
    <?php elseif ($overallStatus === "Warning"): ?>
        <div class="summary-warning">
            Overall Status: Warning. Backup exists, but at least one item needs attention.
        </div>
    <?php else: ?>
        <div class="summary-danger">
            Overall Status: Problem. One or more critical backup checks failed.
        </div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalFiles); ?></div>
            <div class="dashboard-label">Backup Files</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalDatabaseBackups); ?></div>
            <div class="dashboard-label">Database Backups</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalFileBackups); ?></div>
            <div class="dashboard-label">Files Backups</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText(formatBytes($totalSize)); ?></div>
            <div class="dashboard-label">Backup Folder Size</div>
        </div>
    </div>

    <div class="panel">
        <h2>Integrity Checks</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>Check</th>
                <th>Status</th>
                <th>Details</th>
            </tr>

            <?php foreach ($checks as $index => $check): ?>
                <tr>
                    <td><?php echo safeText($index + 1); ?></td>
                    <td><?php echo safeText($check["check"]); ?></td>
                    <td><?php echo statusBadge($check["status"]); ?></td>
                    <td><?php echo safeText($check["details"]); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Latest Backup Files</h2>

        <table>
            <tr>
                <th>Type</th>
                <th>File</th>
                <th>Age</th>
                <th>Size</th>
                <th>Date</th>
            </tr>

            <tr>
                <td>Database</td>
                <td>
                    <?php if ($latestDatabaseBackup): ?>
                        <?php echo safeText($latestDatabaseBackup["name"]); ?>
                    <?php else: ?>
                        <span class="missing">No database backup found</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo $latestDatabaseBackup ? safeText($latestDatabaseBackup["age_days"] . " day(s)") : "-"; ?>
                </td>
                <td>
                    <?php echo $latestDatabaseBackup ? safeText(formatBytes($latestDatabaseBackup["size"])) : "-"; ?>
                </td>
                <td>
                    <?php echo $latestDatabaseBackup ? safeText($latestDatabaseBackup["modified"]) : "-"; ?>
                </td>
            </tr>

            <tr>
                <td>Files</td>
                <td>
                    <?php if ($latestFileBackup): ?>
                        <?php echo safeText($latestFileBackup["name"]); ?>
                    <?php else: ?>
                        <span class="missing">No files backup found</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo $latestFileBackup ? safeText($latestFileBackup["age_days"] . " day(s)") : "-"; ?>
                </td>
                <td>
                    <?php echo $latestFileBackup ? safeText(formatBytes($latestFileBackup["size"])) : "-"; ?>
                </td>
                <td>
                    <?php echo $latestFileBackup ? safeText($latestFileBackup["modified"]) : "-"; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Backup File Details</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>Type</th>
                <th>File Name</th>
                <th>Status</th>
                <th>Size</th>
                <th>Age</th>
                <th>Modified</th>
                <th>Note</th>
            </tr>

            <?php if (count($backupFiles) > 0): ?>
                <?php foreach ($backupFiles as $index => $file): ?>
                    <?php
                    $typeBadge = "badge-other";

                    if ($file["type"] === "Database") {
                        $typeBadge = "badge-db";
                    } elseif ($file["type"] === "Files") {
                        $typeBadge = "badge-files";
                    }
                    ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>

                        <td>
                            <span class="badge <?php echo safeText($typeBadge); ?>">
                                <?php echo safeText($file["type"]); ?>
                            </span>
                        </td>

                        <td><?php echo safeText($file["name"]); ?></td>

                        <td><?php echo statusBadge($file["status"]); ?></td>

                        <td><?php echo safeText(formatBytes($file["size"])); ?></td>

                        <td><?php echo safeText($file["age_days"] . " day(s)"); ?></td>

                        <td><?php echo safeText($file["modified"]); ?></td>

                        <td><?php echo safeText($file["note"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">No backup files found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
