<?php
require_once "auth_check.php";
require_once "role_check.php";

requireRole(["Admin", "Quality Coordinator"]);

$backupDir = ensureStorageDirectory('backups');
$maxBackupAgeDays = 7;
$maxBackupFolderSizeMB = 1024;

function formatBytesPlain($bytes) {
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

function getAgeDays($timestamp) {
    if (!$timestamp) {
        return "";
    }

    return floor((time() - $timestamp) / 86400);
}

$backupFiles = [];
$totalSize = 0;

$databaseCount = 0;
$filesCount = 0;
$otherCount = 0;

$okCount = 0;
$warningCount = 0;
$problemCount = 0;

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

            if ($extension !== "sql" && $extension !== "zip") {
                continue;
            }

            $name = basename($file);
            $type = detectBackupType($name);
            $size = filesize($file);
            $modifiedTime = filemtime($file);
            $modifiedDate = date("Y-m-d H:i:s", $modifiedTime);
            $ageDays = getAgeDays($modifiedTime);

            $status = "OK";
            $note = "File exists and is not empty.";

            if ($size <= 0) {
                $status = "Problem";
                $note = "File is empty.";
            } elseif ($ageDays !== "" && $ageDays > $maxBackupAgeDays) {
                $status = "Warning";
                $note = "Backup is older than " . $maxBackupAgeDays . " days.";
            }

            if ($type === "Database") {
                $databaseCount++;
            } elseif ($type === "Files") {
                $filesCount++;
            } else {
                $otherCount++;
            }

            if ($status === "OK") {
                $okCount++;
            } elseif ($status === "Warning") {
                $warningCount++;
            } else {
                $problemCount++;
            }

            $totalSize += $size;

            $backupFiles[] = [
                "type" => $type,
                "name" => $name,
                "extension" => $extension,
                "size_text" => formatBytesPlain($size),
                "modified" => $modifiedDate,
                "age_days" => $ageDays,
                "status" => $status,
                "note" => $note
            ];
        }
    }
}

$folderSizeMB = $totalSize / 1048576;

if ($folderSizeMB > $maxBackupFolderSizeMB) {
    $folderSizeStatus = "Warning";
    $folderSizeNote = "Backup folder exceeds " . $maxBackupFolderSizeMB . " MB.";
} else {
    $folderSizeStatus = "OK";
    $folderSizeNote = "Backup folder size is within limit.";
}

$filename = "backup_report_" . date("Y-m-d_H-i-s") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF";

$output = fopen("php://output", "w");

fputcsv($output, ["Backup Report"]);
fputcsv($output, ["Generated At", date("Y-m-d H:i:s")]);
fputcsv($output, ["Backup Folder", $backupDir]);
fputcsv($output, []);

fputcsv($output, ["Summary Metric", "Value"]);
fputcsv($output, ["Backup Folder Exists", $folderExists ? "Yes" : "No"]);
fputcsv($output, ["Total Backup Files", count($backupFiles)]);
fputcsv($output, ["Database Backups", $databaseCount]);
fputcsv($output, ["Files Backups", $filesCount]);
fputcsv($output, ["Other Backups", $otherCount]);
fputcsv($output, ["Total Backup Size", formatBytesPlain($totalSize)]);
fputcsv($output, ["OK Files", $okCount]);
fputcsv($output, ["Warning Files", $warningCount]);
fputcsv($output, ["Problem Files", $problemCount]);
fputcsv($output, ["Folder Size Status", $folderSizeStatus]);
fputcsv($output, ["Folder Size Note", $folderSizeNote]);

fputcsv($output, []);
fputcsv($output, []);

fputcsv($output, ["Backup File Details"]);
fputcsv($output, [
    "No.",
    "Type",
    "File Name",
    "Extension",
    "Size",
    "Modified Date",
    "Age Days",
    "Integrity Status",
    "Note"
]);

foreach ($backupFiles as $index => $file) {
    fputcsv($output, [
        $index + 1,
        $file["type"],
        $file["name"],
        $file["extension"],
        $file["size_text"],
        $file["modified"],
        $file["age_days"],
        $file["status"],
        $file["note"]
    ]);
}

fclose($output);
exit;
