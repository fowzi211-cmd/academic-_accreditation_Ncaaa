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
    die("Invalid substandard ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load current substandard
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM substandards
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$substandard = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$substandard) {
    die("Substandard not found.");
}

// ===============================
// Load standards for dropdown
// ===============================
$standardStmt = $conn->query("
    SELECT 
        id,
        standard_code,
        standard_title
    FROM standards
    ORDER BY id
");
$standards = $standardStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Handle update
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $standard_id = !empty($_POST["standard_id"]) ? (int)$_POST["standard_id"] : 0;
    $substandard_code = trim($_POST["substandard_code"] ?? "");
    $substandard_text = trim($_POST["substandard_text"] ?? "");

    if ($standard_id === 0) {
        $error = "Please select a parent standard.";
    } elseif ($substandard_code === "") {
        $error = "Substandard code is required.";
    } elseif ($substandard_text === "") {
        $error = "Substandard requirement text is required.";
    } else {
        $sql = "
            UPDATE substandards
            SET 
                standard_id = :standard_id,
                substandard_code = :substandard_code,
                substandard_text = :substandard_text
            WHERE id = :id
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":standard_id" => $standard_id,
            ":substandard_code" => $substandard_code,
            ":substandard_text" => $substandard_text,
            ":id" => $id
        ]);

        header("Location: substandards.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Substandard</title>
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

        .substandard-form {
            max-width: 850px;
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
            min-height: 150px;
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
            .substandard-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Edit Substandard</h1>
        <p>Update the parent standard, substandard code, and requirement text.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_substandard.php?id=<?php echo htmlspecialchars($id); ?>" class="substandard-form">

            <div class="form-section">
                <h2>Substandard Information</h2>

                <label>Parent Standard</label>
                <select name="standard_id" required>
                    <option value="">-- Select Standard --</option>

                    <?php foreach ($standards as $standard): ?>
                        <option value="<?php echo htmlspecialchars($standard["id"]); ?>"
                            <?php if ((int)($substandard["standard_id"] ?? 0) === (int)$standard["id"]) echo "selected"; ?>>
                            <?php
                                echo htmlspecialchars(
                                    $standard["standard_code"] . " - " . $standard["standard_title"]
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Substandard Code</label>
                <input type="text" name="substandard_code" required
                       value="<?php echo htmlspecialchars($substandard["substandard_code"] ?? ""); ?>">
                <div class="hint">
                    Use the exact substandard code from the accreditation framework.
                </div>

                <label>Requirement Text</label>
                <textarea name="substandard_text" required><?php echo htmlspecialchars($substandard["substandard_text"] ?? ""); ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn btn-edit">Update Substandard</button>
                <a href="substandards.php" class="btn btn-secondary">Back to Substandards List</a>
                <a href="standards.php" class="btn btn-purple">Standards List</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>