<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$error = "";

// ===============================
// Handle form submission
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
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM kpis 
            WHERE kpi_code = :kpi_code
        ");
        $checkStmt->execute([":kpi_code" => $kpi_code]);
        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $error = "This KPI code already exists.";
        } else {
            $sql = "
                INSERT INTO kpis
                (
                    kpi_code,
                    kpi_name,
                    kpi_description,
                    measurement_method,
                    target_value,
                    unit,
                    responsible_person,
                    status
                )
                VALUES
                (
                    :kpi_code,
                    :kpi_name,
                    :kpi_description,
                    :measurement_method,
                    :target_value,
                    :unit,
                    :responsible_person,
                    :status
                )
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
                ":status" => $status
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
    <title>Add KPI</title>
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
        <h1>Add KPI</h1>
        <p>Create a program KPI with code, target, measurement method, responsible person, and status.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create_kpi.php" class="kpi-form">

            <div class="form-section">
                <h2>KPI Identity</h2>

                <div class="form-grid">
                    <div>
                        <label>KPI Code</label>
                        <input type="text" name="kpi_code" required placeholder="Example: KPI-04">
                        <div class="hint">
                            Use a unique KPI code. Example: KPI-01, KPI-02, KPI-03.
                        </div>
                    </div>

                    <div>
                        <label>KPI Name</label>
                        <input type="text" name="kpi_name" required placeholder="Example: Employer Satisfaction">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Target and Responsibility</h2>

                <div class="form-grid">
                    <div>
                        <label>Target Value</label>
                        <input type="number" step="0.01" name="target_value" value="0">
                    </div>

                    <div>
                        <label>Unit</label>
                        <input type="text" name="unit" placeholder="Example: %, number, score">
                    </div>

                    <div>
                        <label>Responsible Person</label>
                        <input type="text" name="responsible_person" placeholder="Example: Quality Coordinator">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>KPI Details</h2>

                <label>KPI Description</label>
                <textarea name="kpi_description" placeholder="Describe what this KPI measures."></textarea>

                <label>Measurement Method</label>
                <textarea name="measurement_method" placeholder="Explain data source, calculation method, and frequency."></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save KPI</button>
                <a href="kpis.php" class="btn btn-secondary">Back to KPIs</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>