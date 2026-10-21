<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$program_specification_id = isset($_GET["program_specification_id"]) && is_numeric($_GET["program_specification_id"])
    ? (int)$_GET["program_specification_id"]
    : 3;

$file_id = isset($_GET["file_id"]) && is_numeric($_GET["file_id"])
    ? (int)$_GET["file_id"]
    : null;

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function cleanText($text) {
    $text = (string)$text;
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function normalizeLineText($text) {
    $text = cleanText($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function getDocxXml($filePath, $xmlPath) {
    if (!class_exists("ZipArchive")) {
        throw new Exception("PHP ZipArchive extension is not enabled.");
    }

    $zip = new ZipArchive();

    if ($zip->open($filePath) !== true) {
        throw new Exception("Could not open DOCX file.");
    }

    $xml = $zip->getFromName($xmlPath);
    $zip->close();

    if ($xml === false) {
        throw new Exception("Could not read " . $xmlPath . " from DOCX.");
    }

    return $xml;
}

function extractDocxTables($filePath) {
    $xml = getDocxXml($filePath, "word/document.xml");

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace("w", "http://schemas.openxmlformats.org/wordprocessingml/2006/main");

    $tables = [];
    $tblNodes = $xpath->query("//w:tbl");

    foreach ($tblNodes as $tblIndex => $tbl) {
        $table = [];
        $rowNodes = $xpath->query("./w:tr", $tbl);

        foreach ($rowNodes as $tr) {
            $row = [];
            $cellNodes = $xpath->query("./w:tc", $tr);

            foreach ($cellNodes as $tc) {
                $paragraphs = [];
                $pNodes = $xpath->query(".//w:p", $tc);

                foreach ($pNodes as $p) {
                    $parts = [];
                    $texts = $xpath->query(".//w:t", $p);

                    foreach ($texts as $t) {
                        $parts[] = $t->nodeValue;
                    }

                    $line = normalizeLineText(implode("", $parts));

                    if ($line !== "") {
                        $paragraphs[] = $line;
                    }
                }

                $row[] = cleanText(implode("\n", $paragraphs));
            }

            $hasValue = false;

            foreach ($row as $cell) {
                if (trim($cell) !== "") {
                    $hasValue = true;
                    break;
                }
            }

            if ($hasValue) {
                $table[] = $row;
            }
        }

        if (count($table) > 0) {
            $tables[] = $table;
        }
    }

    return $tables;
}

if ($file_id === null) {
    $stmt = $conn->prepare("
        SELECT *
        FROM course_specification_files
        WHERE program_specification_id = :program_specification_id
        ORDER BY id DESC
    ");
    $stmt->execute([":program_specification_id" => $program_specification_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $conn->prepare("
        SELECT *
        FROM course_specification_files
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $stmt->execute([
        ":id" => $file_id,
        ":program_specification_id" => $program_specification_id
    ]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        die("File not found.");
    }

    $filePath = storagePath($file["file_path"]);

    if (!file_exists($filePath)) {
        die("Physical file not found: " . safeText($filePath));
    }

    $tables = extractDocxTables($filePath);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug DOCX Tables</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .table-box {
            margin-bottom: 30px;
            border: 2px solid #0d6efd;
            padding: 10px;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .cell-content {
            white-space: pre-wrap;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        th, td {
            border: 1px solid #777;
            padding: 6px;
            vertical-align: top;
        }

        th {
            background: #eee;
        }
    </style>
</head>
<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Debug DOCX Tables</h1>
        <p>This page shows how PHP reads the uploaded course specification DOCX tables.</p>
    </div>

    <div class="panel">
        <a href="import_course_specification_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-secondary">
            Back to Import
        </a>
    </div>

    <?php if ($file_id === null): ?>

        <div class="panel">
            <h2>Select Uploaded DOCX File</h2>

            <table>
                <tr>
                    <th>ID</th>
                    <th>Course Code</th>
                    <th>Filename</th>
                    <th>Type</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($files as $f): ?>
                    <tr>
                        <td><?php echo safeText($f["id"]); ?></td>
                        <td><?php echo safeText($f["course_code"] ?? ""); ?></td>
                        <td><?php echo safeText($f["original_filename"] ?? ""); ?></td>
                        <td><?php echo safeText($f["file_type"] ?? ""); ?></td>
                        <td>
                            <?php if (strtolower($f["file_type"] ?? "") === "docx"): ?>
                                <a href="debug_course_specification_docx_tables.php?program_specification_id=<?php echo safeText($program_specification_id); ?>&file_id=<?php echo safeText($f["id"]); ?>" class="btn">
                                    Debug
                                </a>
                            <?php else: ?>
                                Not DOCX
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

    <?php else: ?>

        <div class="panel">
            <h2>File</h2>
            <table>
                <tr>
                    <th>File ID</th>
                    <td><?php echo safeText($file["id"]); ?></td>
                    <th>Course Code</th>
                    <td><?php echo safeText($file["course_code"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Filename</th>
                    <td colspan="3"><?php echo safeText($file["original_filename"] ?? ""); ?></td>
                </tr>
                <tr>
                    <th>Total Tables Detected</th>
                    <td colspan="3"><?php echo safeText(count($tables)); ?></td>
                </tr>
            </table>
        </div>

        <?php foreach ($tables as $tableIndex => $table): ?>
            <div class="table-box">
                <h2>Table <?php echo safeText($tableIndex + 1); ?> | Rows: <?php echo safeText(count($table)); ?></h2>

                <table>
                    <?php foreach ($table as $rowIndex => $row): ?>
                        <tr>
                            <th style="width:70px;">Row <?php echo safeText($rowIndex + 1); ?></th>
                            <?php foreach ($row as $cellIndex => $cell): ?>
                                <td>
                                    <strong>Cell <?php echo safeText($cellIndex + 1); ?></strong>
                                    <div class="cell-content"><?php echo safeText($cell); ?></div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>
