<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator"]);

$message = "";
$error = "";
$backupFile = "";

// ===============================
// Backup settings
// ===============================
$databaseConfig = require 'C:\\quality_system_config\\database.php';
$dbName = $databaseConfig['name'];
$dbUser = $databaseConfig['user'];
$dbPass = $databaseConfig['password'];
$dbHost = $databaseConfig['host'];

$backupDir = ensureStorageDirectory('backups');

// Try common mysqldump locations first
$possibleMysqldumpPaths = [
    "C:\\xampp\\mysql\\bin\\mysqldump.exe",
    "C:\\Program Files\\MySQL\\MySQL Server 9.7\\bin\\mysqldump.exe",
    "mysqldump",
    "C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin\\mysqldump.exe",
    "C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe"
];

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function findMysqldump($possiblePaths) {
    foreach ($possiblePaths as $path) {
        if ($path === "mysqldump") {
            return $path;
        }

        if (file_exists($path)) {
            return $path;
        }
    }

    return "mysqldump";
}

function logActivitySafe($action, $module, $recordId, $description) {
    global $conn;

    if (isset($conn) && function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

// ===============================
// Handle backup request
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["run_backup"])) {

    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0777, true)) {
            $error = "Failed to create backup folder: " . $backupDir;
        }
    }

    if ($error === "") {
        $timestamp = date("Y-m-d_H-i-s");
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . "quality_system_backup_" . $timestamp . ".sql";

        $mysqldump = findMysqldump($possibleMysqldumpPaths);

        $command = '"' . $mysqldump . '"'
            . " --host=" . escapeshellarg($dbHost)
            . " --user=" . escapeshellarg($dbUser);

        $command .= " --databases " . escapeshellarg($dbName)
            . " --single-transaction --skip-lock-tables"
            . " > " . escapeshellarg($backupFile)
            . " 2>&1";

        $output = [];
        $returnCode = 0;

        putenv('MYSQL_PWD=' . $dbPass);
        exec($command, $output, $returnCode);
        putenv('MYSQL_PWD');

        if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
            $message = "Backup created successfully.";
            logActivitySafe(
                "Create",
                "Database Backup",
                0,
                "Manual database backup created: " . basename($backupFile)
            );
        } else {
            $error = "Backup failed. Check MySQL password or mysqldump path.";

            if (file_exists($backupFile) && filesize($backupFile) === 0) {
                @unlink($backupFile);
            }

            if (!empty($output)) {
                $error .= " Details: " . implode(" | ", $output);
            }
        }
    }
}

// ===============================
// Existing backups
// ===============================
$backupFiles = [];

if (is_dir($backupDir)) {
    $files = glob($backupDir . DIRECTORY_SEPARATOR . "*.sql");

    if ($files !== false) {
        rsort($files);

        foreach ($files as $file) {
            $backupFiles[] = [
                "name" => basename($file),
                "path" => $file,
                "size" => filesize($file),
                "modified" => date("Y-m-d H:i:s", filemtime($file))
            ];
        }
    }
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
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manual Database Backup</title>
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

        .alert-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .actions {
            margin-bottom: 18px;
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Manual Database Backup</h1>
        <p>Create a manual SQL backup of the local Quality System database.</p>
    </div>

    <div class="actions">
    <a href="backup_files_now.php" class="btn btn-purple">Files Backup</a>
    <a href="health_check.php" class="btn">Health Check</a>
    <a href="settings.php" class="btn">Settings</a>
    <a href="home.php" class="btn btn-secondary">Back Home</a>
    <a href="backup_dashboard.php" class="btn btn-purple">Backup Dashboard</a>
</div>

    <?php if ($message !== ""): ?>
        <div class="alert-success">
            <?php echo safeText($message); ?>

            <?php if ($backupFile !== ""): ?>
                <br>
                <span class="small-text">
                    File: <?php echo safeText($backupFile); ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="warning-box">
        <strong>Important:</strong> This creates a database-only backup as a `.sql` file.
        It does not copy uploaded evidence files or PHP source files.
        Keep your stable folder backup separately:
        <br>
        <strong>C:\quality_system_v11_working</strong>
    </div>

    <div class="panel">
        <h2>Create Backup Now</h2>

        <table>
            <tr>
                <th>Database</th>
                <td><?php echo safeText($dbName); ?></td>
            </tr>

            <tr>
                <th>Backup Folder</th>
                <td><?php echo safeText($backupDir); ?></td>
            </tr>

            <tr>
                <th>Backup Type</th>
                <td>SQL dump using mysqldump</td>
            </tr>
        </table>

        <br>

        <form method="POST">
            <button type="submit"
                    name="run_backup"
                    class="btn"
                    onclick="return confirm('Create a manual database backup now?');">
                Run Backup Now
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Existing Backups</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>File Name</th>
                <th>Size</th>
                <th>Created / Modified</th>
                <th>Folder</th>
            </tr>

            <?php if (count($backupFiles) > 0): ?>
                <?php foreach ($backupFiles as $index => $file): ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>
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
                    <td colspan="5">No backup files found yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
