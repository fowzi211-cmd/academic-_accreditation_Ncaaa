<?php

declare(strict_types=1);

function storageRoot(): string
{
    $configured = getenv('QUALITY_STORAGE_ROOT');
    return rtrim($configured !== false && $configured !== '' ? $configured : 'C:\\quality_system_storage', "\\/");
}

function storagePath(string $relativePath = ''): string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    $relativePath = ltrim($relativePath, '/');

    $segments = array_values(array_filter(explode('/', $relativePath), static fn(string $part): bool => $part !== ''));
    foreach ($segments as $segment) {
        if ($segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
            throw new InvalidArgumentException('Invalid storage path.');
        }
    }

    return storageRoot() . ($segments ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments) : '');
}

function ensureStorageDirectory(string $relativePath): string
{
    $path = storagePath($relativePath);
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create the protected storage directory.');
    }
    return $path;
}

function detectUploadedMime(string $path): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return strtolower((string)$finfo->file($path));
}

function validateUploadedDocument(string $path, string $extension, array $allowedExtensions): string
{
    $extension = strtolower($extension);
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('The selected file type is not allowed.');
    }

    $mime = detectUploadedMime($path);
    $simpleMimeMap = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/x-ole-storage', 'application/vnd.ms-office', 'application/msword'],
        'xls' => ['application/x-ole-storage', 'application/vnd.ms-office', 'application/vnd.ms-excel'],
        'ppt' => ['application/x-ole-storage', 'application/vnd.ms-office', 'application/vnd.ms-powerpoint'],
    ];

    if (isset($simpleMimeMap[$extension]) && !in_array($mime, $simpleMimeMap[$extension], true)) {
        throw new RuntimeException('The file contents do not match the selected file type.');
    }

    $officeRoots = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];
    if (isset($officeRoots[$extension])) {
        if (!in_array($mime, ['application/zip', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'], true)) {
            throw new RuntimeException('The Office document has an invalid container type.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true || $zip->locateName('[Content_Types].xml') === false) {
            throw new RuntimeException('The Office document container is invalid.');
        }

        $requiredRoot = $officeRoots[$extension];
        $hasRequiredRoot = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = (string)$zip->getNameIndex($index);
            if (str_starts_with($entry, $requiredRoot)) {
                $hasRequiredRoot = true;
                break;
            }
        }
        $zip->close();

        if (!$hasRequiredRoot) {
            throw new RuntimeException('The Office document contents do not match its extension.');
        }
    }

    return $mime;
}

function safeDownloadName(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '_', $name) ?: 'download';
    return mb_substr($name, 0, 180);
}
