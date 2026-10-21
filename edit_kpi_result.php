<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid KPI result ID.");
}

$id = (int)$_GET["id"];
$error = "";

// ===============================
// Load KPI result
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM kpi_results
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    die("KPI result not found.");
}

// ===============================
// Load KPIs
// ===============================
$kpiStmt = $conn->query("
    SELECT 
        id,
        kpi_code,
        kpi_name,
        target_value,
        unit
    FROM kpis
    ORDER BY kpi_code
");
$kpis = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load semester reports
// ===============================
$reportStmt = $conn->query("
    SELECT 
        id,
        report_title,
        academic_year,
        semester,
        status
    FROM semester_reports
    ORDER BY id DESC
");
$semesterReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Handle update
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $kpi_id = !empty($_POST["kpi_id"]) ? (int)$_POST["kpi_id"] : 0;
    $academic_year = trim($_POST["academic_year"] ?? "");
    $semester = trim($_POST["semester"] ?? "");

    $target_value = ($_POST["target_value"] ?? "") !== "" ? (float)$_POST["target_value"] : 0;
    $actual_value = ($_POST["actual_value"] ?? "") !== "" ? (float)$_POST["actual_value"] : 0;

    $achievement_percentage = 0;
    if ($target_value > 0) {
        $achievement_percentage = round(($actual_value / $target_value) * 100, 2);
    }

    $analysis = trim($_POST["analysis"] ?? "");
    $improvement_action = trim($_POST["improvement_action"] ?? "");
    $linked_semester_report_id = !empty($_POST["linked_semester_report_id"]) ? (int)$_POST["linked_semester_report_id"] : null;
    $status = trim($_POST["status"] ?? "Draft");

    if ($kpi_id === 0) {
        $error = "Please select a KPI.";
    } elseif ($academic_year === "") {
        $error = "Academic year is required.";
    } elseif ($semester === "") {
        $error = "Semester is required.";
    } else {
        $sql = "
            UPDATE kpi_results
            SET
                kpi_id = :kpi_id,
                academic_year = :academic_year,
                semester = :semester,
                target_value = :target_value,
                actual_value = :actual_value,
                achievement_percentage = :achievement_percentage,
                analysis = :analysis,
                improvement_action = :improvement_action,
                linked_semester_report_id = :linked_semester_report_id,
                status = :status
            WHERE id = :id
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":kpi_id" => $kpi_id,
            ":academic_year" => $academic_year,
            ":semester" => $semester,
            ":target_value" => $target_value,
            ":actual_value" => $actual_value,
            ":achievement_percentage" => $achievement_percentage,
            ":analysis" => $analysis,
            ":improvement_action" => $improvement_action,
            ":linked_semester_report_id" => $linked_semester_report_id,
            ":status" => $status,
            ":id" => $id
        ]);

        // Get KPI label for activity log
        $kpiNameStmt = $conn->prepare("
            SELECT kpi_code, kpi_name
            FROM kpis
            WHERE id = :id
        ");
        $kpiNameStmt->execute([":id" => $kpi_id]);
        $kpiInfo = $kpiNameStmt->fetch(PDO::FETCH_ASSOC);

        $kpiLabel = $kpiInfo
            ? $kpiInfo["kpi_code"] . " - " . $kpiInfo["kpi_name"]
            : "KPI ID " . $kpi_id;

        logActivity(
            $conn,
            "Update",
            "KPI Results",
            $id,
            "KPI result updated for " . $kpiLabel . " | " . $academic_year . " | " . $semester . " | Achievement: " . $achievement_percentage . "%"
        );

        header("Location: kpi_results.php?kpi_id=" . $kpi_id);
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit KPI Result</title>
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

        .kpi-result-form {
            max-width: 1000px;
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

        .large-textarea {
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
            .form-grid {
                grid-template-columns: 1fr;
            }

            .kpi-result-form {
                max-width: 100%;
            }
        }
    </style>

    <script>
        function updateTargetFromKpi() {
            const select = document.getElementById("kpi_id");

            if (!select || select.selectedIndex < 0) {
                return;
            }

            const selectedOption = select.options[select.selectedIndex];
            const target = selectedOption.getAttribute("data-target");

            if (target !== null && target !== "") {
                document.getElementById("target_value").value = target;
            }

            calculateAchievement();
        }

        function calculateAchievement() {
            const target = parseFloat(document.getElementById("target_value").value) || 0;
            const actual = parseFloat(document.getElementById("actual_value").value) || 0;

            let percentage = 0;

            if (target > 0) {
                percentage = (actual / target) * 100;
            }

            document.getElementById("achievement_preview").value = percentage.toFixed(2) + "%";
        }
    </script>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Edit KPI Result</h1>
        <p>Update KPI achievement values, academic period, analysis, improvement action, and linked semester report.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_kpi_result.php?id=<?php echo htmlspecialchars($id); ?>" class="kpi-result-form">

            <div class="form-section">
                <h2>KPI and Academic Period</h2>

                <div class="form-grid">
                    <div>
                        <label>KPI</label>
                        <select name="kpi_id" id="kpi_id" required onchange="updateTargetFromKpi();">
                            <option value="">-- Select KPI --</option>

                            <?php foreach ($kpis as $kpi): ?>
                                <option value="<?php echo htmlspecialchars($kpi["id"]); ?>"
                                        data-target="<?php echo htmlspecialchars($kpi["target_value"]); ?>"
                                    <?php if ((int)($result["kpi_id"] ?? 0) === (int)$kpi["id"]) echo "selected"; ?>>
                                    <?php
                                        echo htmlspecialchars(
                                            $kpi["kpi_code"] . " - " .
                                            $kpi["kpi_name"] . " | Target: " .
                                            $kpi["target_value"] . " " .
                                            ($kpi["unit"] ?? "")
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <?php
                            $statuses = ["Draft", "Submitted", "Under Review", "Approved", "Archived"];
                            foreach ($statuses as $status):
                            ?>
                                <option value="<?php echo htmlspecialchars($status); ?>"
                                    <?php if (($result["status"] ?? "") === $status) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" required
                               value="<?php echo htmlspecialchars($result["academic_year"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Semester</label>
                        <select name="semester" required>
                            <?php
                            $semesters = ["First Semester", "Second Semester", "Third Semester", "Annual"];
                            foreach ($semesters as $sem):
                            ?>
                                <option value="<?php echo htmlspecialchars($sem); ?>"
                                    <?php if (($result["semester"] ?? "") === $sem) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($sem); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>KPI Result Values</h2>

                <div class="form-grid">
                    <div>
                        <label>Target Value</label>
                        <input type="number" step="0.01" name="target_value" id="target_value"
                               value="<?php echo htmlspecialchars($result["target_value"] ?? 0); ?>"
                               oninput="calculateAchievement();">
                    </div>

                    <div>
                        <label>Actual Value</label>
                        <input type="number" step="0.01" name="actual_value" id="actual_value"
                               value="<?php echo htmlspecialchars($result["actual_value"] ?? 0); ?>"
                               oninput="calculateAchievement();">
                    </div>

                    <div>
                        <label>Achievement Percentage Preview</label>
                        <input type="text" id="achievement_preview" readonly
                               value="<?php echo htmlspecialchars($result["achievement_percentage"] ?? 0); ?>%">
                        <div class="hint">
                            The final value is recalculated when saved.
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Analysis and Improvement</h2>

                <label>Analysis</label>
                <textarea name="analysis" class="large-textarea"><?php echo htmlspecialchars($result["analysis"] ?? ""); ?></textarea>

                <label>Improvement Action</label>
                <textarea name="improvement_action" class="large-textarea"><?php echo htmlspecialchars($result["improvement_action"] ?? ""); ?></textarea>
            </div>

            <div class="form-section">
                <h2>Link to Semester Report</h2>

                <label>Linked Semester Program Report</label>
                <select name="linked_semester_report_id">
                    <option value="">-- Optional: Select Semester Report --</option>

                    <?php foreach ($semesterReports as $report): ?>
                        <option value="<?php echo htmlspecialchars($report["id"]); ?>"
                            <?php if ((int)($result["linked_semester_report_id"] ?? 0) === (int)$report["id"]) echo "selected"; ?>>
                            <?php
                                echo htmlspecialchars(
                                    $report["id"] . " - " .
                                    $report["report_title"] . " | " .
                                    $report["academic_year"] . " | " .
                                    $report["semester"] . " | " .
                                    $report["status"]
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Update KPI Result</button>

                <a href="kpi_results.php?kpi_id=<?php echo htmlspecialchars($result["kpi_id"]); ?>" class="btn btn-secondary">
                    Back to KPI Results
                </a>
            </div>

        </form>

    </div>

</div>

<script>
    calculateAchievement();
</script>

</body>
</html>