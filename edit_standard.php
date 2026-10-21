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
// Check ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid standard ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load current standard
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM standards
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$standard = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$standard) {
    die("Standard not found.");
}

// ===============================
// Handle update
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
            UPDATE standards
            SET 
                standard_code = :standard_code,
                standard_title = :standard_title,
                description = :description
            WHERE id = :id
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":standard_code" => $standard_code,
            ":standard_title" => $standard_title,
            ":description" => $description,
            ":id" => $id
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
    <title>Edit Standard</title>
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
        <h1>Edit Standard</h1>
        <p>Update accreditation standard code, title, and description.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_standard.php?id=<?php echo htmlspecialchars($id); ?>" class="standard-form">

            <div class="form-section">
                <h2>Standard Information</h2>

                <label>Standard Code</label>
                <input type="text" name="standard_code" required
                       value="<?php echo htmlspecialchars($standard["standard_code"] ?? ""); ?>">

                <label>Standard Title</label>
                <input type="text" name="standard_title" required
                       value="<?php echo htmlspecialchars($standard["standard_title"] ?? ""); ?>">

                <label>Description</label>
                <textarea name="description"><?php echo htmlspecialchars($standard["description"] ?? ""); ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn btn-edit">Update Standard</button>
                <a href="standards.php" class="btn btn-secondary">Back to Standards List</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>