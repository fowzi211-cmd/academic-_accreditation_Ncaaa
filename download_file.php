<?php

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/role_check.php';
require_once __DIR__ . '/db.php';

requireRole(['Admin', 'Quality Coordinator', 'Program Leader', 'Faculty', 'Reviewer']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$type = $_GET['type'] ?? 'evidence';

if (!$id || !in_array($type, ['evidence', 'course_specification'], true)) {
    http_response_code(400);
    exit('Invalid file request.');
}

if ($type === 'evidence') {
    $statement = $conn->prepare('SELECT file_name AS original_name, file_path FROM evidence_files WHERE id = :id');
} else {
    $statement = $conn->prepare('SELECT original_filename AS original_name, file_path FROM course_specification_files WHERE id = :id');
}

$statement->execute([':id' => $id]);
$record = $statement->fetch(PDO::FETCH_ASSOC);

if (!$record || empty($record['file_path'])) {
    http_response_code(404);
    exit('File not found.');
}

try {
    $path = storagePath((string)$record['file_path']);
} catch (InvalidArgumentException) {
    http_response_code(400);
    exit('Invalid stored file path.');
}

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = detectUploadedMime($path);
$downloadName = safeDownloadName((string)($record['original_name'] ?: basename($path)));

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
