<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

$error = "";

// ===============================
// Check evidence ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid evidence ID.");
}

$evidence_id = (int)$_GET["id"];

// ===============================
// Get evidence item
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM evidence_items
    WHERE id = :id
");
$stmt->execute([":id" => $evidence_id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    die("Evidence item not found.");
}

// ===============================
// Handle upload
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!isset($_FILES["evidence_file"]) || $_FILES["evidence_file"]["error"] !== UPLOAD_ERR_OK) {
        $error = "Please select a valid file.";
    } else {

        $uploadDir = ensureStorageDirectory('uploads');

        $originalName = $_FILES["evidence_file"]["name"];
        $tmpName = $_FILES["evidence_file"]["tmp_name"];
        $fileSize = $_FILES["evidence_file"]["size"];

        $allowedExtensions = [
            "pdf",
            "doc",
            "docx",
            "xls",
            "xlsx",
            "ppt",
            "pptx",
            "jpg",
            "jpeg",
            "png"
        ];

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            $error = "File type not allowed. Allowed files: PDF, Word, Excel, PowerPoint, JPG, PNG.";
        } elseif ($fileSize <= 0) {
            $error = "The selected file is empty.";
        } elseif ($fileSize > 10 * 1024 * 1024) {
            $error = "File is too large. Maximum allowed size is 10 MB.";
        } else {
            try {
                validateUploadedDocument($tmpName, $extension, $allowedExtensions);
            } catch (RuntimeException $validationError) {
                $error = $validationError->getMessage();
            }

            $newFileName = bin2hex(random_bytes(24)) . "." . $extension;

            $targetPath = $uploadDir . $newFileName;
            $relativePath = "uploads/" . $newFileName;

            if ($error === '' && move_uploaded_file($tmpName, $targetPath)) {

                $sql = "
                    INSERT INTO evidence_files 
                    (
                        evidence_id,
                        file_name,
                        file_path
                    )
                    VALUES 
                    (
                        :evidence_id,
                        :file_name,
                        :file_path
                    )
                ";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ":evidence_id" => $evidence_id,
                    ":file_name" => $originalName,
                    ":file_path" => $relativePath
                ]);

                $newFileId = $conn->lastInsertId();

                logActivity(
                    $conn,
                    "Upload",
                    "Evidence Files",
                    $newFileId,
                    "File uploaded for evidence ID " . $evidence_id . ": " . $originalName
                );

                header("Location: upload_file.php?id=" . $evidence_id);
                exit;

            } elseif ($error === '') {
                $error = "File upload failed.";
            }
        }
    }
}

// ===============================
// Load uploaded files
// ===============================
$fileStmt = $conn->prepare("
    SELECT * 
    FROM evidence_files 
    WHERE evidence_id = :evidence_id 
    ORDER BY uploaded_at DESC
");
$fileStmt->execute([":evidence_id" => $evidence_id]);
$files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Upload Evidence File</title>
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

        .evidence-summary {
            max-width: 900px;
        }

        .upload-form {
            max-width: 900px;
        }

        .file-hint {
            font-size: 12px;
            color: #555;
            margin-top: 10px;
        }

        input[type="file"] {
            margin-top: 8px;
            padding: 8px;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            width: 100%;
            box-sizing: border-box;
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

        .missing {
            color: #999;
            font-style: italic;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .button-row {
            margin-top: 18px;
        }

        .actions a {
            display: inline-block;
            margin-bottom: 4px;
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Upload Evidence File</h1>
        <p>Upload and manage supporting files for the selected evidence item.</p>
    </div>

    <div class="panel evidence-summary">
        <h2>Evidence Details</h2>

        <p>
            <strong>Evidence ID:</strong>
            <?php echo htmlspecialchars($item["id"]); ?>
        </p>

        <p>
            <strong>Evidence Title:</strong>
            <?php echo htmlspecialchars($item["title"]); ?>
        </p>

        <div class="file-hint">
            Allowed file types: PDF, Word, Excel, PowerPoint, JPG, JPEG, PNG. Maximum size: 10 MB.
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel upload-form">
        <h2>Upload New File</h2>

        <form method="POST" enctype="multipart/form-data">

            <label>Select Evidence File</label>
            <input type="file" name="evidence_file" required>

            <div class="button-row">
                <button type="submit" class="btn">Upload File</button>
                <a href="index.php" class="btn btn-secondary">Back to Evidence List</a>
                <a href="edit.php?id=<?php echo htmlspecialchars($evidence_id); ?>" class="btn btn-purple">Edit Evidence</a>
            </div>

        </form>
    </div>

    <div class="panel">
        <h2>Uploaded Files</h2>

        <?php if (count($files) > 0): ?>

            <table>
                <tr>
                    <th>ID</th>
                    <th>File Name</th>
                    <th>Uploaded At</th>
                    <th>Open</th>
                    <th>Delete</th>
                </tr>

                <?php foreach ($files as $file): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($file["id"]); ?></td>

                        <td><?php echo htmlspecialchars($file["file_name"]); ?></td>

                        <td>
                            <span class="small-text">
                                <?php echo htmlspecialchars($file["uploaded_at"]); ?>
                            </span>
                        </td>

                        <td>
                            <a href="download_file.php?type=evidence&amp;id=<?php echo urlencode((string)$file['id']); ?>" target="_blank">
                                Open File
                            </a>
                        </td>

                        <td class="actions">
                            <?php if (hasRole(["Admin", "Quality Coordinator"])): ?>
                                <?php echo deleteForm('delete_file.php?id=' . urlencode((string)$file['id']), 'Are you sure you want to delete this uploaded file?'); ?>
                            <?php else: ?>
                                <span class="missing">No permission</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

            </table>

        <?php else: ?>

            <p class="missing">No files uploaded yet.</p>

        <?php endif; ?>
    </div>

</div>

</body>
</html>
