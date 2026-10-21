<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$message = "";
$error = "";
$insertedCount = 0;
$skippedCount = 0;

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function tableExists($conn, $tableName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");
    $stmt->execute([":table_name" => $tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

function normalizeHeader($value) {
    $value = strtolower(trim((string)$value));
    $value = str_replace([" ", "-", "."], "_", $value);
    return $value;
}

if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist.");
}

$expectedHeaders = [
    "staff_code",
    "full_name",
    "academic_rank",
    "qualification",
    "specialty",
    "department",
    "college",
    "institution",
    "email",
    "phone",
    "employment_status",
    "role_in_program",
    "notes"
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["upload_staff"])) {
    if (!isset($_FILES["staff_file"]) || $_FILES["staff_file"]["error"] !== UPLOAD_ERR_OK) {
        $error = "Please upload a valid CSV file.";
    } else {
        $fileName = $_FILES["staff_file"]["name"];
        $tmpPath = $_FILES["staff_file"]["tmp_name"];
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension !== "csv") {
            $error = "Only CSV files are supported in this version. Save your Excel sheet as CSV first.";
        } else {
            $handle = fopen($tmpPath, "r");

            if (!$handle) {
                $error = "Could not read uploaded CSV file.";
            } else {
                $headers = fgetcsv($handle);

                if (!$headers) {
                    $error = "CSV file is empty or missing header row.";
                } else {
                    $normalizedHeaders = [];

                    foreach ($headers as $header) {
                        $normalizedHeaders[] = normalizeHeader($header);
                    }

                    if (!in_array("full_name", $normalizedHeaders, true)) {
                        $error = "CSV must contain a full_name column.";
                    } else {
                        $insertSql = "
                            INSERT INTO program_staff
                            (
                                staff_code,
                                full_name,
                                academic_rank,
                                qualification,
                                specialty,
                                department,
                                college,
                                institution,
                                email,
                                phone,
                                employment_status,
                                role_in_program,
                                notes
                            )
                            VALUES
                            (
                                :staff_code,
                                :full_name,
                                :academic_rank,
                                :qualification,
                                :specialty,
                                :department,
                                :college,
                                :institution,
                                :email,
                                :phone,
                                :employment_status,
                                :role_in_program,
                                :notes
                            )
                        ";

                        $insertStmt = $conn->prepare($insertSql);

                        while (($row = fgetcsv($handle)) !== false) {
                            $data = [];

                            foreach ($expectedHeaders as $field) {
                                $data[$field] = "";
                            }

                            foreach ($normalizedHeaders as $index => $fieldName) {
                                if (in_array($fieldName, $expectedHeaders, true)) {
                                    $data[$fieldName] = trim($row[$index] ?? "");
                                }
                            }

                            if ($data["full_name"] === "") {
                                $skippedCount++;
                                continue;
                            }

                            if ($data["employment_status"] === "") {
                                $data["employment_status"] = "Active";
                            }

                            $insertStmt->execute([
                                ":staff_code" => $data["staff_code"],
                                ":full_name" => $data["full_name"],
                                ":academic_rank" => $data["academic_rank"],
                                ":qualification" => $data["qualification"],
                                ":specialty" => $data["specialty"],
                                ":department" => $data["department"],
                                ":college" => $data["college"],
                                ":institution" => $data["institution"],
                                ":email" => $data["email"],
                                ":phone" => $data["phone"],
                                ":employment_status" => $data["employment_status"],
                                ":role_in_program" => $data["role_in_program"],
                                ":notes" => $data["notes"]
                            ]);

                            $insertedCount++;
                        }

                        $message = "Staff upload completed. Inserted: " . $insertedCount . ". Skipped: " . $skippedCount . ".";

                        logActivitySafe(
                            $conn,
                            "Import",
                            "Program Staff",
                            0,
                            "Program staff CSV uploaded. Inserted: " . $insertedCount . " | Skipped: " . $skippedCount
                        );
                    }
                }

                fclose($handle);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Upload Program Staff CSV</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
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

        .code-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 12px;
            font-family: Consolas, monospace;
            white-space: pre-wrap;
            direction: ltr;
            text-align: left;
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Upload Program Staff CSV</h1>
        <p>Import Health Promotion program staff from a CSV file exported from Excel.</p>
    </div>

    <div class="actions">
        <a href="program_staff.php" class="btn btn-secondary">Back to Program Staff</a>
        <a href="create_program_staff.php" class="btn">Add Staff Manually</a>
    </div>

    <?php if ($message !== ""): ?>
        <div class="alert-success"><?php echo safeText($message); ?></div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>CSV Format Required</h2>

        <p>Prepare your Excel sheet with these headers, then save it as CSV:</p>

        <div class="code-box">staff_code,full_name,academic_rank,qualification,specialty,department,college,institution,email,phone,employment_status,role_in_program,notes</div>

        <p><strong>Required column:</strong> full_name</p>
        <p><strong>Default status:</strong> if employment_status is empty, it will be saved as Active.</p>
    </div>

    <div class="panel">
        <h2>Upload CSV File</h2>

        <form method="POST" enctype="multipart/form-data">
            <label>Select CSV File</label>
            <input type="file" name="staff_file" accept=".csv" required>

            <br><br>

            <button type="submit" name="upload_staff" class="btn">
                Upload Staff CSV
            </button>
        </form>
    </div>

</div>

</body>
</html>