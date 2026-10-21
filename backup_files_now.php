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
// Settings
// ===============================
$projectDir = __DIR__;
$backupDir = ensureStorageDirectory('backups');

$excludeFolders = [
    "backups",
    ".git",
    "vendor",
    "node_modules"
];

$excludeExtensions = [
    "zip"
];

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

function logActivitySafe($action, $module, $recordId, $description) {
    global $conn;

    if (isset($conn) && function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function shouldExcludePath($filePath, $projectDir, $excludeFolders, $excludeExtensions) {
    $relativePath = str_replace($projectDir . DIRECTORY_SEPARATOR, "", $filePath);
    $relativePath = str_replace("\\", "/", $relativePath);

    foreach ($excludeFolders as $folder) {
        if ($relativePath === $folder || strpos($relativePath, $folder . "/") === 0) {
            return true;
        }
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if (in_array($extension, $excludeExtensions, true)) {
        return true;
    }

    return false;
}

// ===============================
// Handle backup request
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["run_file_backup"])) {

    if (!class_exists("ZipArchive")) {
        $error = "PHP ZipArchive is not enabled. Enable the PHP zip extension first.";
    }

    if ($error === "" && !is_dir($backupDir)) {
        if (!mkdir($backupDir, 0777, true)) {
            $error = "Failed to create backup folder: " . $backupDir;
        }
    }

    if ($error === "") {
        $timestamp = date("Y-m-d_H-i-s");
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . "quality_system_files_backup_" . $timestamp . ".zip";

        $zip = new ZipArchive();

        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $error = "Could not create ZIP backup file.";
        } else {
            $filesAdded = 0;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($projectDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                $filePath = $file->getRealPath();

                if ($filePath === false) {
                    continue;
                }

                if (shouldExcludePath($filePath, $projectDir, $excludeFolders, $excludeExtensions)) {
                    continue;
                }

                $relativePath = str_replace($projectDir . DIRECTORY_SEPARATOR, "", $filePath);
                $relativePath = str_replace("\\", "/", $relativePath);

                if ($file->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } elseif ($file->isFile()) {
                    $zip->addFile($filePath, $relativePath);
                    $filesAdded++;
                }
            }

            $zip->close();

            if (file_exists($backupFile) && filesize($backupFile) > 0) {
                $message = "File backup created successfully. Files added: " . $filesAdded;

                logActivitySafe(
                    "Create",
                    "File Backup",
                    0,
                    "Manual file backup created: " . basename($backupFile)
                );
            } else {
                $error = "File backup failed or ZIP file is empty.";

                if (file_exists($backupFile)) {
                    @unlink($backupFile);
                }
            }
        }
    }
}

// ===============================
// Existing file backups
// ===============================
$fileBackups = [];

if (is_dir($backupDir)) {
    $files = glob($backupDir . DIRECTORY_SEPARATOR . "quality_system_files_backup_*.zip");

    if ($files !== false) {
        rsort($files);

        foreach ($files as $file) {
            $fileBackups[] = [
                "name" => basename($file),
                "size" => filesize($file),
                "modified" => date("Y-m-d H:i:s", filemtime($file))
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manual Files Backup</title>
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
        <h1>Manual Files Backup</h1>
        <p>Create a ZIP backup of the Quality System project files.</p>
    </div>

    <div class="actions">
        <a href="backup_now.php" class="btn">Database Backup</a>
        <a href="health_check.php" class="btn">Health Check</a>
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
        <strong>Important:</strong> This backs up project files into a ZIP file.
        It excludes the <strong>backups</strong> folder to avoid copying old backups inside new backups.
        <br>
        You should still keep the stable external backup:
        <strong>C:\quality_system_v11_working</strong>
    </div>

    <div class="panel">
        <h2>Create Files Backup Now</h2>

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
                <th>Backup Type</th>
                <td>ZIP archive of project files</td>
            </tr>

            <tr>
                <th>Excluded Folders</th>
                <td><?php echo safeText(implode(", ", $excludeFolders)); ?></td>
            </tr>
        </table>

        <br>

        <form method="POST">
            <button type="submit"
                    name="run_file_backup"
                    class="btn"
                    onclick="return confirm('Create a manual project files backup now?');">
                Run Files Backup Now
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Existing File Backups</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>File Name</th>
                <th>Size</th>
                <th>Created / Modified</th>
                <th>Folder</th>
            </tr>

            <?php if (count($fileBackups) > 0): ?>
                <?php foreach ($fileBackups as $index => $file): ?>
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
                    <td colspan="5">No file backup ZIP files found yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
