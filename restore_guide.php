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
$projectDir = __DIR__;
$backupDir = ensureStorageDirectory('backups');
$dbName = "quality_system";

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

// ===============================
// Get latest backups
// ===============================
$latestDatabaseBackup = null;
$latestFilesBackup = null;

if (is_dir($backupDir)) {
    $sqlFiles = glob($backupDir . DIRECTORY_SEPARATOR . "*.sql");
    $zipFiles = glob($backupDir . DIRECTORY_SEPARATOR . "*.zip");

    if ($sqlFiles !== false && count($sqlFiles) > 0) {
        rsort($sqlFiles);
        $latestDatabaseBackup = $sqlFiles[0];
    }

    if ($zipFiles !== false && count($zipFiles) > 0) {
        rsort($zipFiles);
        $latestFilesBackup = $zipFiles[0];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Restore Guide</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .danger-box {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .success-box {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .code-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 12px;
            font-family: Consolas, monospace;
            white-space: pre-wrap;
            direction: ltr;
            text-align: left;
            margin-top: 8px;
            margin-bottom: 12px;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .actions {
            margin-bottom: 18px;
        }

        ol li,
        ul li {
            margin-bottom: 8px;
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Restore Guide</h1>
        <p>Manual steps to restore the Quality System database and project files from backup.</p>
    </div>

    <div class="actions">
        <a href="backup_dashboard.php" class="btn btn-purple">Backup Dashboard</a>
        <a href="backup_all_now.php" class="btn">Backup All</a>
        <a href="backup_now.php" class="btn">Database Backup</a>
        <a href="backup_files_now.php" class="btn">Files Backup</a>
        <a href="health_check.php" class="btn">Health Check</a>
        <a href="home.php" class="btn btn-secondary">Back Home</a>
    </div>

    <div class="danger-box">
        <strong>Do not restore blindly.</strong>
        Restoring can overwrite current database records or project files.
        Always create a fresh backup first before restoring anything.
    </div>

    <div class="warning-box">
        <strong>Recommended rule:</strong>
        restore manually from PowerShell or MySQL console, not from the browser.
        Browser-based restore is possible, but it is risky for a local quality system because one wrong click can replace live data.
    </div>

    <div class="panel">
        <h2>Current Backup Location</h2>

        <table>
            <tr>
                <th>Project Folder</th>
                <td><?php echo safeText($projectDir); ?></td>
            </tr>

            <tr>
                <th>Backup Folder</th>
                <td><?php echo safeText($backupDir); ?></td>
            </tr>

            <tr>
                <th>Database Name</th>
                <td><?php echo safeText($dbName); ?></td>
            </tr>

            <tr>
                <th>Latest Database Backup</th>
                <td>
                    <?php if ($latestDatabaseBackup): ?>
                        <?php echo safeText(basename($latestDatabaseBackup)); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText(date("Y-m-d H:i:s", filemtime($latestDatabaseBackup))); ?>
                            |
                            <?php echo safeText(formatBytes(filesize($latestDatabaseBackup))); ?>
                        </span>
                    <?php else: ?>
                        No database backup found.
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Latest Files Backup</th>
                <td>
                    <?php if ($latestFilesBackup): ?>
                        <?php echo safeText(basename($latestFilesBackup)); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText(date("Y-m-d H:i:s", filemtime($latestFilesBackup))); ?>
                            |
                            <?php echo safeText(formatBytes(filesize($latestFilesBackup))); ?>
                        </span>
                    <?php else: ?>
                        No files backup found.
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Before Restoring</h2>

        <ol>
            <li>Create a fresh combined backup from <strong>Backup All</strong>.</li>
            <li>Close unnecessary browser tabs using the system.</li>
            <li>Know exactly which backup file you want to restore.</li>
            <li>Confirm whether you are restoring the database only, files only, or both.</li>
            <li>Keep an external copy of important backups outside <strong>C:\quality_system</strong>.</li>
        </ol>
    </div>

    <div class="panel">
        <h2>Restore Database Backup (.sql)</h2>

        <p>Use this when you want to restore database records, tables, tasks, reports, evidence metadata, users, and logs.</p>

        <h3>Step 1: Open PowerShell</h3>

        <div class="code-box">cd C:\quality_system</div>

        <h3>Step 2: Restore using mysql</h3>

        <p>Replace the file name with the actual `.sql` backup file you want to restore.</p>

        <div class="code-box">mysql -u root -p quality_system &lt; backups\YOUR_DATABASE_BACKUP_FILE.sql</div>

        <p>Example:</p>

        <div class="code-box">mysql -u root -p quality_system &lt; backups\quality_system_database_backup_2026-05-24_14-30-00.sql</div>

        <h3>Step 3: Enter MySQL password</h3>

        <p>When asked, enter your MySQL root password.</p>

        <h3>Step 4: Test the system</h3>

        <div class="code-box">php -S localhost:8000</div>

        <p>Then open:</p>

        <div class="code-box">http://localhost:8000/health_check.php</div>
    </div>

    <div class="panel">
        <h2>Alternative Database Restore Using MySQL Console</h2>

        <p>This method is useful if the direct PowerShell command does not work.</p>

        <div class="code-box">mysql -u root -p</div>

        <p>Then inside MySQL:</p>

        <div class="code-box">USE quality_system;
SOURCE C:/quality_system/backups/YOUR_DATABASE_BACKUP_FILE.sql;</div>

        <p>Use forward slashes `/` inside the MySQL `SOURCE` command.</p>
    </div>

    <div class="panel">
        <h2>Restore Project Files Backup (.zip)</h2>

        <p>Use this when you want to restore PHP files, uploaded files, CSS, and local project assets.</p>

        <ol>
            <li>Stop the PHP local server using <strong>Ctrl + C</strong>.</li>
            <li>Go to <strong>C:\quality_system</strong>.</li>
            <li>Copy the current folder somewhere safe before overwriting files.</li>
            <li>Open the selected ZIP backup from <strong>C:\quality_system\backups</strong>.</li>
            <li>Extract the ZIP contents into <strong>C:\quality_system</strong>.</li>
            <li>Allow overwrite only if you are sure this is the correct backup.</li>
            <li>Restart the server:</li>
        </ol>

        <div class="code-box">cd C:\quality_system
php -S localhost:8000</div>

        <p>Then test:</p>

        <div class="code-box">http://localhost:8000/health_check.php</div>
    </div>

    <div class="panel">
        <h2>Full Restore: Database + Files</h2>

        <ol>
            <li>Stop the PHP server.</li>
            <li>Restore files ZIP first.</li>
            <li>Restore database SQL second.</li>
            <li>Restart PHP server.</li>
            <li>Run Health Check.</li>
            <li>Open main pages and verify login, tasks, evidence, course reports, and backups.</li>
        </ol>

        <div class="code-box">cd C:\quality_system
php -S localhost:8000</div>

        <div class="code-box">http://localhost:8000/health_check.php</div>
    </div>

    <div class="success-box">
        <strong>Safe restore principle:</strong>
        never restore over the live system unless you have a newer backup of the current state.
    </div>

</div>

</body>
</html>
