<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole("Admin");

$error = "";

// ===============================
// Handle form submission
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $standard_code = trim($_POST["standard_code"] ?? "");
    $standard_title = trim($_POST["standard_title"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($standard_code === "") {
        $error = "Standard code is required.";
    } elseif ($standard_title === "") {
        $error = "Standard title is required.";
    } else {
        $sql = "
            INSERT INTO standards 
            (
                standard_code,
                standard_title,
                description
            )
            VALUES 
            (
                :standard_code,
                :standard_title,
                :description
            )
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":standard_code" => $standard_code,
            ":standard_title" => $standard_title,
            ":description" => $description
        ]);

        header("Location: standards.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Standard</title>
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

        .standard-form {
            max-width: 800px;
        }

        .form-section {
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid #ddd;
        }

        .form-section:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }

        textarea {
            min-height: 130px;
        }

        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
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

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .standard-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Add New Standard</h1>
        <p>Create a new accreditation standard that can later be linked to substandards and evidence items.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create_standard.php" class="standard-form">

            <div class="form-section">
                <h2>Standard Information</h2>

                <label>Standard Code</label>
                <input type="text" name="standard_code" required placeholder="Example: Standard 7">
                <div class="hint">
                    Use the same coding format used in your accreditation framework.
                </div>

                <label>Standard Title</label>
                <input type="text" name="standard_title" required placeholder="Example: Quality Assurance and Continuous Improvement">

                <label>Description</label>
                <textarea name="description" placeholder="Write a short description of this standard."></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Standard</button>
                <a href="standards.php" class="btn btn-secondary">Back to Standards List</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>