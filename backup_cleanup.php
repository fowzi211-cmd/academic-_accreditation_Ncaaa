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

    if (substr($lower, -4) === ".sql" || strpos($lower, "database") !== false) {
        return "Database";
    }

    if (substr($lower, -4) === ".zip" || strpos($lower, "files") !== false) {
        return "Files";
    }

    return "Other";
}

function logActivitySafe($action, $module, $recordId, $description) {
    global $conn;

    if (isset($conn) && function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function isSafeBackupFile($backupDir, $filename) {
    if ($filename === "") {
        return false;
    }

    if (strpos($filename, "..") !== false) {
        return false;
    }

    if (strpos($filename, "/") !== false || strpos($filename, "\\") !== false) {
        return false;
    }

    $allowedExtensions = ["sql", "zip"];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        return false;
    }

    $fullPath = realpath($backupDir . DIRECTORY_SEPARATOR . $filename);
    $realBackupDir = realpath($backupDir);

    if ($fullPath === false || $realBackupDir === false) {
        return false;
    }

    if (strpos($fullPath, $realBackupDir) !== 0) {
        return false;
    }

    if (!is_file($fullPath)) {
        return false;
    }

    return true;
}

// ===============================
// Ensure backup folder exists
// ===============================
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// ===============================
// Handle delete request
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_selected"])) {
    $selectedFiles = $_POST["backup_files"] ?? [];

    if (!is_array($selectedFiles) || count($selectedFiles) === 0) {
        $error = "No backup files selected.";
    } else {
        $deletedCount = 0;
        $failedFiles = [];

        foreach ($selectedFiles as $filename) {
            $filename = basename((string)$filename);

            if (!isSafeBackupFile($backupDir, $filename)) {
                $failedFiles[] = $filename;
                continue;
            }

            $fullPath = realpath($backupDir . DIRECTORY_SEPARATOR . $filename);
            $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;

            if (@unlink($fullPath)) {
                $deletedCount++;

                logActivitySafe(
                    "Delete",
                    "Backup Files",
                    0,
                    "Backup file deleted: " . $filename . " | Size: " . formatBytes($fileSize)
                );
            } else {
                $failedFiles[] = $filename;
            }
        }

        if ($deletedCount > 0) {
            $message = $deletedCount . " backup file(s) deleted successfully.";
        }

        if (count($failedFiles) > 0) {
            $error = "Some files could not be deleted: " . implode(", ", $failedFiles);
        }
    }
}

// ===============================
// Load backup files
// ===============================
$backupFiles = [];
$totalSize = 0;

if (is_dir($backupDir)) {
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

            $backupFiles[] = [
                "name" => $name,
                "type" => detectBackupType($name),
                "size" => $size,
                "modified" => date("Y-m-d H:i:s", $modifiedTimestamp),
                "modified_timestamp" => $modifiedTimestamp
            ];

            $totalSize += $size;
        }
    }
}

$totalFiles = count($backupFiles);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Backup Cleanup</title>
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

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
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

        .actions {
            margin-bottom: 18px;
        }

        .checkbox-cell input {
            width: auto;
        }

        .delete-zone {
            margin-top: 16px;
            padding: 12px;
            border: 1px solid #f5c2c7;
            background: #f8d7da;
            border-radius: 8px;
        }

        @media (max-width: 900px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        function toggleAllBackups(source) {
            const checkboxes = document.querySelectorAll('input[name="backup_files[]"]');
            checkboxes.forEach(function(box) {
                box.checked = source.checked;
            });
        }

        function confirmDeleteSelected() {
            const checked = document.querySelectorAll('input[name="backup_files[]"]:checked');

            if (checked.length === 0) {
                alert("No backup files selected.");
                return false;
            }

            return confirm("Delete " + checked.length + " selected backup file(s)? This cannot be undone.");
        }
    </script>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Backup Cleanup</h1>
        <p>Delete old database and file backups safely from the backups folder.</p>
    </div>

    <div class="actions">
        <a href="backup_dashboard.php" class="btn btn-purple">Backup Dashboard</a>
        <a href="backup_all_now.php" class="btn">Backup All</a>
        <a href="backup_now.php" class="btn">Database Backup</a>
        <a href="backup_files_now.php" class="btn">Files Backup</a>
        <a href="health_check.php" class="btn">Health Check</a>
    </div>

    <?php if ($message !== ""): ?>
        <div class="alert-success">
            <?php echo safeText($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="warning-box">
        <strong>Warning:</strong> deleting backup files cannot be undone.
        Keep at least one recent database backup and one recent files backup before cleanup.
        For real protection, keep another copy outside <strong>C:\quality_system</strong>.
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalFiles); ?></div>
            <div class="summary-label">Backup Files</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(formatBytes($totalSize)); ?></div>
            <div class="summary-label">Total Backup Size</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($backupDir); ?></div>
            <div class="summary-label">Backup Folder</div>
        </div>
    </div>

    <div class="panel">
        <h2>Backup Files</h2>

        <form method="POST" onsubmit="return confirmDeleteSelected();">

            <table>
                <tr>
                    <th class="checkbox-cell">
                        <input type="checkbox" onclick="toggleAllBackups(this)">
                    </th>
                    <th>No.</th>
                    <th>Type</th>
                    <th>File Name</th>
                    <th>Size</th>
                    <th>Created / Modified</th>
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
                            <td class="checkbox-cell">
                                <input type="checkbox"
                                       name="backup_files[]"
                                       value="<?php echo safeText($file["name"]); ?>">
                            </td>

                            <td><?php echo safeText($index + 1); ?></td>

                            <td>
                                <span class="badge <?php echo safeText($badgeClass); ?>">
                                    <?php echo safeText($file["type"]); ?>
                                </span>
                            </td>

                            <td><?php echo safeText($file["name"]); ?></td>

                            <td><?php echo safeText(formatBytes($file["size"])); ?></td>

                            <td><?php echo safeText($file["modified"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No backup files found.</td>
                    </tr>
                <?php endif; ?>
            </table>

            <?php if (count($backupFiles) > 0): ?>
                <div class="delete-zone">
                    <button type="submit" name="delete_selected" class="btn btn-delete">
                        Delete Selected Backups
                    </button>
                </div>
            <?php endif; ?>

        </form>
    </div>

</div>

</body>
</html>
