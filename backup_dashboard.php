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

    if (strpos($lower, "database") !== false || substr($lower, -4) === ".sql") {
        return "Database";
    }

    if (strpos($lower, "files") !== false || substr($lower, -4) === ".zip") {
        return "Files";
    }

    return "Other";
}

// ===============================
// Read backup files
// ===============================
$backupFiles = [];
$totalSize = 0;

$databaseBackups = [];
$fileBackups = [];
$otherBackups = [];

if (is_dir($backupDir)) {
    $files = glob($backupDir . DIRECTORY_SEPARATOR . "*");

    if ($files !== false) {
        rsort($files);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $name = basename($file);
            $size = filesize($file);
            $modifiedTimestamp = filemtime($file);
            $modified = date("Y-m-d H:i:s", $modifiedTimestamp);
            $type = detectBackupType($name);

            $item = [
                "name" => $name,
                "path" => $file,
                "type" => $type,
                "size" => $size,
                "modified_timestamp" => $modifiedTimestamp,
                "modified" => $modified
            ];

            $backupFiles[] = $item;
            $totalSize += $size;

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

$totalBackups = count($backupFiles);
$totalDatabaseBackups = count($databaseBackups);
$totalFileBackups = count($fileBackups);
$totalOtherBackups = count($otherBackups);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Backup Dashboard</title>
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

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
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
        <h1>Backup Dashboard</h1>
        <p>Monitor database backups, project file backups, total backup size, and latest backup status.</p>
    </div>

    <div class="actions">
    <a href="backup_all_now.php" class="btn btn-purple">Backup All</a>
    <a href="backup_now.php" class="btn">Database Backup</a>
    <a href="backup_files_now.php" class="btn">Files Backup</a>
    <a href="backup_integrity_check.php" class="btn">Integrity Check</a>
    <a href="backup_cleanup.php" class="btn btn-delete">Backup Cleanup</a>
    <a href="restore_guide.php" class="btn">Restore Guide</a>
    <a href="export_backup_report_csv.php" class="btn btn-purple">Export Backup Report CSV</a>
    <a href="health_check.php" class="btn">Health Check</a>
    <a href="home.php" class="btn btn-secondary">Back Home</a>
</div>

    <div class="warning-box">
        <strong>Reminder:</strong> Database backups are SQL files. File backups are ZIP files.
        Keep an external copy outside <strong>C:\quality_system</strong> for real protection.
    </div>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalBackups); ?></div>
            <div class="dashboard-label">Total Backup Files</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalDatabaseBackups); ?></div>
            <div class="dashboard-label">Database Backups</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText($totalFileBackups); ?></div>
            <div class="dashboard-label">File ZIP Backups</div>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-number"><?php echo safeText(formatBytes($totalSize)); ?></div>
            <div class="dashboard-label">Total Backup Size</div>
        </div>
    </div>

    <div class="panel">
        <h2>Latest Backup Summary</h2>

        <table>
            <tr>
                <th>Backup Folder</th>
                <td><?php echo safeText($backupDir); ?></td>
            </tr>

            <tr>
                <th>Latest Any Backup</th>
                <td>
                    <?php if ($latestAnyBackup): ?>
                        <?php echo safeText($latestAnyBackup["name"]); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText($latestAnyBackup["modified"]); ?>
                            |
                            <?php echo safeText(formatBytes($latestAnyBackup["size"])); ?>
                        </span>
                    <?php else: ?>
                        <span class="missing">No backup found</span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Latest Database Backup</th>
                <td>
                    <?php if ($latestDatabaseBackup): ?>
                        <?php echo safeText($latestDatabaseBackup["name"]); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText($latestDatabaseBackup["modified"]); ?>
                            |
                            <?php echo safeText(formatBytes($latestDatabaseBackup["size"])); ?>
                        </span>
                    <?php else: ?>
                        <span class="missing">No database backup found</span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Latest Files Backup</th>
                <td>
                    <?php if ($latestFileBackup): ?>
                        <?php echo safeText($latestFileBackup["name"]); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText($latestFileBackup["modified"]); ?>
                            |
                            <?php echo safeText(formatBytes($latestFileBackup["size"])); ?>
                        </span>
                    <?php else: ?>
                        <span class="missing">No files backup found</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>All Backup Files</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>Type</th>
                <th>File Name</th>
                <th>Size</th>
                <th>Created / Modified</th>
                <th>Folder</th>
            </tr>

            <?php if (count($backupFiles) > 0): ?>
                <?php foreach ($backupFiles as $index => $file): ?>
                    <?php
                        $badgeClass = "badge-other";

                        if ($file["type"] === "Database") {
                            $badgeClass = "badge-db";
                        } elseif ($file["type"] === "Files") {
                            $badgeClass = "badge-files";
                        }
                    ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>

                        <td>
                            <span class="badge <?php echo safeText($badgeClass); ?>">
                                <?php echo safeText($file["type"]); ?>
                            </span>
                        </td>

                        <td><?php echo safeText($file["name"]); ?></td>

                        <td><?php echo safeText(formatBytes($file["size"])); ?></td>

                        <td><?php echo safeText($file["modified"]); ?></td>

                        <td>
                            <span class="small-text">
                                <?php echo safeText($backupDir); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6">No backup files found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
