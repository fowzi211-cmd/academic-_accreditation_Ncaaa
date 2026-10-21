<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole("Admin");

$checks = [];

function addCheck(&$checks, $category, $item, $status, $message = "") {
    $checks[] = [
        "category" => $category,
        "item" => $item,
        "status" => $status,
        "message" => $message
    ];
}

function tableExists($conn, $tableName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");

    $stmt->execute([
        ":table_name" => $tableName
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function columnExists($conn, $tableName, $columnName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
          AND column_name = :column_name
    ");

    $stmt->execute([
        ":table_name" => $tableName,
        ":column_name" => $columnName
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function formatBytesHealth($bytes) {
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

function getLatestBackupFile($backupDir, $extension) {
    if (!is_dir($backupDir)) {
        return null;
    }

    $files = glob($backupDir . DIRECTORY_SEPARATOR . "*." . $extension);

    if ($files === false || count($files) === 0) {
        return null;
    }

    rsort($files);

    return $files[0];
}

function backupAgeDays($filePath) {
    if (!$filePath || !file_exists($filePath)) {
        return null;
    }

    return floor((time() - filemtime($filePath)) / 86400);
}

// ===============================
// Backup health summary
// ===============================
$backupDir = ensureStorageDirectory('backups');
$maxBackupAgeDays = 7;

$latestDatabaseBackup = getLatestBackupFile($backupDir, "sql");
$latestFilesBackup = getLatestBackupFile($backupDir, "zip");

$latestDatabaseBackupAge = backupAgeDays($latestDatabaseBackup);
$latestFilesBackupAge = backupAgeDays($latestFilesBackup);

$databaseBackupStatus = "Problem";
$databaseBackupMessage = "No database backup found.";

if ($latestDatabaseBackup) {
    if ($latestDatabaseBackupAge <= $maxBackupAgeDays && filesize($latestDatabaseBackup) > 0) {
        $databaseBackupStatus = "OK";
        $databaseBackupMessage = "Latest database backup is recent and not empty.";
    } else {
        $databaseBackupStatus = "Warning";
        $databaseBackupMessage = "Database backup exists but may be old or empty.";
    }
}

$filesBackupStatus = "Problem";
$filesBackupMessage = "No files backup found.";

if ($latestFilesBackup) {
    if ($latestFilesBackupAge <= $maxBackupAgeDays && filesize($latestFilesBackup) > 0) {
        $filesBackupStatus = "OK";
        $filesBackupMessage = "Latest files backup is recent and not empty.";
    } else {
        $filesBackupStatus = "Warning";
        $filesBackupMessage = "Files backup exists but may be old or empty.";
    }
}

// Add backup checks to main health-check result table
if ($databaseBackupStatus === "OK") {
    addCheck($checks, "Backups", "Database Backup", "PASS", $databaseBackupMessage);
} else {
    addCheck($checks, "Backups", "Database Backup", "FAIL", $databaseBackupMessage);
}

if ($filesBackupStatus === "OK") {
    addCheck($checks, "Backups", "Files Backup", "PASS", $filesBackupMessage);
} else {
    addCheck($checks, "Backups", "Files Backup", "FAIL", $filesBackupMessage);
}

// ===============================
// Database connection check
// ===============================
try {
    $conn->query("SELECT 1");
    addCheck($checks, "Database", "Connection", "PASS", "Database connection is working.");
} catch (PDOException $e) {
    addCheck($checks, "Database", "Connection", "FAIL", $e->getMessage());
}

// ===============================
// Required tables
// ===============================
$requiredTables = [
    "users",
    "system_settings",
    "standards",
    "substandards",
    "evidence_items",
    "evidence_files",
    "tasks",
    "course_reports",
    "semester_reports",
    "kpis",
    "kpi_results",
    "semester_report_evidence",
    "semester_report_tasks",
    "semester_report_course_reports",
    "semester_report_kpi_results",
    "ssr_sections",
    "activity_logs",
    "system_versions"
];

foreach ($requiredTables as $table) {
    try {
        if (tableExists($conn, $table)) {
            addCheck($checks, "Database Tables", $table, "PASS", "Table exists.");
        } else {
            addCheck($checks, "Database Tables", $table, "FAIL", "Table is missing.");
        }
    } catch (PDOException $e) {
        addCheck($checks, "Database Tables", $table, "FAIL", $e->getMessage());
    }
}

// ===============================
// Important column checks
// ===============================
$requiredColumns = [
    "users" => ["id", "full_name", "email", "password_hash", "role", "status"],
    "system_settings" => ["id", "institution_name", "college_name", "department_name", "program_name", "default_academic_year", "default_semester"],
    "standards" => ["id", "standard_code", "standard_title", "description"],
    "substandards" => ["id", "standard_id", "substandard_code", "substandard_text"],
    "evidence_items" => ["id", "substandard_id", "title", "evidence_type", "status", "review_status"],
    "evidence_files" => ["id", "evidence_id", "file_name", "file_path"],
    "tasks" => ["id", "task_title", "responsible_person", "due_date", "priority", "status"],
    "course_reports" => ["id", "course_code", "course_title", "academic_year", "semester", "status"],
    "semester_reports" => ["id", "academic_year", "semester", "report_title", "status"],
    "kpis" => ["id", "kpi_code", "kpi_name", "target_value", "status"],
    "kpi_results" => ["id", "kpi_id", "academic_year", "semester", "target_value", "actual_value", "achievement_percentage"],
    "ssr_sections" => ["id", "standard_id", "academic_year", "report_cycle", "section_title", "status"],
    "activity_logs" => ["id", "user_id", "user_name", "user_role", "action_type", "module_name", "record_id", "description"],
    "system_versions" => ["id", "version_name", "version_date", "description", "backup_file_name", "folder_copy_name"]
];

foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        try {
            if (tableExists($conn, $table) && columnExists($conn, $table, $column)) {
                addCheck($checks, "Database Columns", $table . "." . $column, "PASS", "Column exists.");
            } else {
                addCheck($checks, "Database Columns", $table . "." . $column, "FAIL", "Column is missing.");
            }
        } catch (PDOException $e) {
            addCheck($checks, "Database Columns", $table . "." . $column, "FAIL", $e->getMessage());
        }
    }
}

// ===============================
// Folder checks
// ===============================
$folders = [
    "uploads" => storagePath('uploads'),
    "assets" => __DIR__ . "/assets"
];

foreach ($folders as $name => $path) {
    if (is_dir($path)) {
        addCheck($checks, "Folders", $name, "PASS", "Folder exists.");
    } else {
        addCheck($checks, "Folders", $name, "FAIL", "Folder is missing.");
    }
}

// ===============================
// File checks
// ===============================
$files = [
    "db.php",
    "auth_check.php",
    "role_check.php",
    "log_activity.php",
    "nav.php",
    "login.php",
    "logout.php",
    "home.php",
    "dashboard.php",
    "index.php",
    "assets/style.css"
];

foreach ($files as $file) {
    $path = __DIR__ . "/" . $file;

    if (file_exists($path)) {
        addCheck($checks, "Core Files", $file, "PASS", "File exists.");
    } else {
        addCheck($checks, "Core Files", $file, "FAIL", "File is missing.");
    }
}

// ===============================
// Count results
// ===============================
$totalChecks = count($checks);

$passedChecks = count(array_filter($checks, function ($check) {
    return $check["status"] === "PASS";
}));

$failedChecks = count(array_filter($checks, function ($check) {
    return $check["status"] === "FAIL";
}));

$passRate = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 1) : 0;
$failRate = $totalChecks > 0 ? round(($failedChecks / $totalChecks) * 100, 1) : 0;

$overallStatus = $failedChecks === 0 ? "PASS" : "FAIL";
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Health Check</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .overall-status {
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-weight: bold;
            border: 1px solid #ddd;
        }

        .overall-pass {
            background: #d1e7dd;
            color: #0f5132;
            border-color: #badbcc;
        }

        .overall-fail {
            background: #f8d7da;
            color: #842029;
            border-color: #f5c2c7;
        }

        .status-pass {
            color: #28a745;
            font-weight: bold;
        }

        .status-fail {
            color: #dc3545;
            font-weight: bold;
        }
.backup-ok {
    background: #d1e7dd;
    color: #0f5132;
    padding: 5px 9px;
    border-radius: 999px;
    font-weight: bold;
    display: inline-block;
}

.backup-warning {
    background: #fff3cd;
    color: #664d03;
    padding: 5px 9px;
    border-radius: 999px;
    font-weight: bold;
    display: inline-block;
}

.backup-problem {
    background: #f8d7da;
    color: #842029;
    padding: 5px 9px;
    border-radius: 999px;
    font-weight: bold;
    display: inline-block;
}


        .summary-number.pass {
            color: #28a745;
        }

        .summary-number.fail {
            color: #dc3545;
        }

        .summary-number.rate {
            color: #6f42c1;
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Health Check</h1>
        <p>System file, folder, database table, and database column verification.</p>
    </div>

    <?php if ($overallStatus === "PASS"): ?>
        <div class="overall-status overall-pass">
            Overall Status: PASS — all required checks passed.
        </div>
    <?php else: ?>
        <div class="overall-status overall-fail">
            Overall Status: FAIL — <?php echo htmlspecialchars($failedChecks); ?> check(s) failed. Review the failed rows below.
        </div>
    <?php endif; ?>

    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-number"><?php echo htmlspecialchars($totalChecks); ?></div>
            <div class="summary-label">Total Checks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number pass"><?php echo htmlspecialchars($passedChecks); ?></div>
            <div class="summary-label">Passed Checks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number fail"><?php echo htmlspecialchars($failedChecks); ?></div>
            <div class="summary-label">Failed Checks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number rate"><?php echo htmlspecialchars($passRate); ?>%</div>
            <div class="summary-label">Pass Rate</div>
        </div>
    </div>

        </div>

    <div class="panel">
        <h2>Backup Health Summary</h2>

        <div class="actions">
            <a href="backup_dashboard.php" class="btn btn-purple">Backup Dashboard</a>
            <a href="backup_all_now.php" class="btn">Backup All</a>
            <a href="backup_integrity_check.php" class="btn">Backup Integrity Check</a>
            <a href="backup_cleanup.php" class="btn btn-delete">Backup Cleanup</a>
            <a href="restore_guide.php" class="btn">Restore Guide</a>
        </div>

        <table>
            <tr>
                <th>Backup Type</th>
                <th>Status</th>
                <th>Latest File</th>
                <th>Age</th>
                <th>Size</th>
                <th>Message</th>
            </tr>

            <tr>
                <td>Database Backup (.sql)</td>
                <td>
                    <?php if ($databaseBackupStatus === "OK"): ?>
                        <span class="backup-ok">OK</span>
                    <?php elseif ($databaseBackupStatus === "Warning"): ?>
                        <span class="backup-warning">Warning</span>
                    <?php else: ?>
                        <span class="backup-problem">Problem</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo $latestDatabaseBackup ? htmlspecialchars(basename($latestDatabaseBackup)) : "Not found"; ?>
                </td>
                <td>
                    <?php echo $latestDatabaseBackupAge !== null ? htmlspecialchars($latestDatabaseBackupAge . " day(s)") : "-"; ?>
                </td>
                <td>
                    <?php echo $latestDatabaseBackup ? htmlspecialchars(formatBytesHealth(filesize($latestDatabaseBackup))) : "-"; ?>
                </td>
                <td><?php echo htmlspecialchars($databaseBackupMessage); ?></td>
            </tr>

            <tr>
                <td>Files Backup (.zip)</td>
                <td>
                    <?php if ($filesBackupStatus === "OK"): ?>
                        <span class="backup-ok">OK</span>
                    <?php elseif ($filesBackupStatus === "Warning"): ?>
                        <span class="backup-warning">Warning</span>
                    <?php else: ?>
                        <span class="backup-problem">Problem</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo $latestFilesBackup ? htmlspecialchars(basename($latestFilesBackup)) : "Not found"; ?>
                </td>
                <td>
                    <?php echo $latestFilesBackupAge !== null ? htmlspecialchars($latestFilesBackupAge . " day(s)") : "-"; ?>
                </td>
                <td>
                    <?php echo $latestFilesBackup ? htmlspecialchars(formatBytesHealth(filesize($latestFilesBackup))) : "-"; ?>
                </td>
                <td><?php echo htmlspecialchars($filesBackupMessage); ?></td>
            </tr>
        </table>

        <p>
            Backup age warning limit:
            <strong><?php echo htmlspecialchars($maxBackupAgeDays); ?> day(s)</strong>
        </p>
    </div>

    <div class="panel">
        <h2>Health Check Results</h2>

        <table>
            <tr>
                <th>#</th>
                <th>Category</th>
                <th>Item</th>
                <th>Status</th>
                <th>Message</th>
            </tr>

            <?php foreach ($checks as $index => $check): ?>
                <tr>
                    <td><?php echo htmlspecialchars($index + 1); ?></td>

                    <td><?php echo htmlspecialchars($check["category"]); ?></td>

                    <td><?php echo htmlspecialchars($check["item"]); ?></td>

                    <td>
                        <?php if ($check["status"] === "PASS"): ?>
                            <span class="status-pass">PASS</span>
                        <?php else: ?>
                            <span class="status-fail">FAIL</span>
                        <?php endif; ?>
                    </td>

                    <td><?php echo htmlspecialchars($check["message"]); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($failedChecks > 0): ?>
        <div class="panel">
            <h2>Failed Checks Only</h2>

            <table>
                <tr>
                    <th>#</th>
                    <th>Category</th>
                    <th>Item</th>
                    <th>Message</th>
                </tr>

                <?php
                $failIndex = 1;
                foreach ($checks as $check):
                    if ($check["status"] !== "FAIL") {
                        continue;
                    }
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($failIndex); ?></td>
                        <td><?php echo htmlspecialchars($check["category"]); ?></td>
                        <td><?php echo htmlspecialchars($check["item"]); ?></td>
                        <td><?php echo htmlspecialchars($check["message"]); ?></td>
                    </tr>
                <?php
                    $failIndex++;
                endforeach;
                ?>
            </table>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
