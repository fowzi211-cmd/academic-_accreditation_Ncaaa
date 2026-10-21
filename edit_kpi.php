<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid KPI ID.");
}

$id = (int)$_GET["id"];
$error = "";

// ===============================
// Load KPI
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM kpis
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$kpi = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kpi) {
    die("KPI not found.");
}

// ===============================
// Handle update
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $kpi_code = trim($_POST["kpi_code"] ?? "");
    $kpi_name = trim($_POST["kpi_name"] ?? "");
    $kpi_description = trim($_POST["kpi_description"] ?? "");
    $measurement_method = trim($_POST["measurement_method"] ?? "");
    $target_value = ($_POST["target_value"] ?? "") !== "" ? (float)$_POST["target_value"] : 0;
    $unit = trim($_POST["unit"] ?? "");
    $responsible_person = trim($_POST["responsible_person"] ?? "");
    $status = trim($_POST["status"] ?? "Active");

    if ($kpi_code === "") {
        $error = "KPI code is required.";
    } elseif ($kpi_name === "") {
        $error = "KPI name is required.";
    } else {
        // Check duplicate KPI code except current KPI
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM kpis 
            WHERE kpi_code = :kpi_code
              AND id <> :id
        ");
        $checkStmt->execute([
            ":kpi_code" => $kpi_code,
            ":id" => $id
        ]);

        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $error = "This KPI code is already used by another KPI.";
        } else {
            $sql = "
                UPDATE kpis
                SET
                    kpi_code = :kpi_code,
                    kpi_name = :kpi_name,
                    kpi_description = :kpi_description,
                    measurement_method = :measurement_method,
                    target_value = :target_value,
                    unit = :unit,
                    responsible_person = :responsible_person,
                    status = :status
                WHERE id = :id
            ";

            $stmt = $conn->prepare($sql);

            $stmt->execute([
                ":kpi_code" => $kpi_code,
                ":kpi_name" => $kpi_name,
                ":kpi_description" => $kpi_description,
                ":measurement_method" => $measurement_method,
                ":target_value" => $target_value,
                ":unit" => $unit,
                ":responsible_person" => $responsible_person,
                ":status" => $status,
                ":id" => $id
            ]);

            header("Location: kpis.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit KPI</title>
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

        .kpi-form {
            max-width: 950px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
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
            min-height: 120px;
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

        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
        }

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .kpi-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Edit KPI</h1>
        <p>Update KPI code, name, target, measurement method, responsible person, and status.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_kpi.php?id=<?php echo htmlspecialchars($id); ?>" class="kpi-form">

            <div class="form-section">
                <h2>KPI Identity</h2>

                <div class="form-grid">
                    <div>
                        <label>KPI Code</label>
                        <input type="text" name="kpi_code" required
                               value="<?php echo htmlspecialchars($kpi["kpi_code"] ?? ""); ?>">
                        <div class="hint">
                            KPI code must be unique.
                        </div>
                    </div>

                    <div>
                        <label>KPI Name</label>
                        <input type="text" name="kpi_name" required
                               value="<?php echo htmlspecialchars($kpi["kpi_name"] ?? ""); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Target and Responsibility</h2>

                <div class="form-grid">
                    <div>
                        <label>Target Value</label>
                        <input type="number" step="0.01" name="target_value"
                               value="<?php echo htmlspecialchars($kpi["target_value"] ?? 0); ?>">
                    </div>

                    <div>
                        <label>Unit</label>
                        <input type="text" name="unit"
                               value="<?php echo htmlspecialchars($kpi["unit"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Responsible Person</label>
                        <input type="text" name="responsible_person"
                               value="<?php echo htmlspecialchars($kpi["responsible_person"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <?php
                            $statuses = ["Active", "Inactive", "Archived"];
                            foreach ($statuses as $status):
                            ?>
                                <option value="<?php echo htmlspecialchars($status); ?>"
                                    <?php if (($kpi["status"] ?? "") === $status) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>KPI Details</h2>

                <label>KPI Description</label>
                <textarea name="kpi_description"><?php echo htmlspecialchars($kpi["kpi_description"] ?? ""); ?></textarea>

                <label>Measurement Method</label>
                <textarea name="measurement_method"><?php echo htmlspecialchars($kpi["measurement_method"] ?? ""); ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Update KPI</button>

                <a href="kpis.php" class="btn btn-secondary">
                    Back to KPIs
                </a>

                <a href="kpi_results.php?kpi_id=<?php echo htmlspecialchars($id); ?>" class="btn btn-purple">
                    KPI Results
                </a>
            </div>

        </form>

    </div>

</div>

</body>
</html>