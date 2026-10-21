<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$error = "";

// ===============================
// Optional standard preselection
// ===============================
$selected_standard_id = $_GET["standard_id"] ?? "";

// ===============================
// Load system settings
// ===============================
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

$default_academic_year = $settings["default_academic_year"] ?? "";
$default_prepared_by = $_SESSION["full_name"] ?? "";

// ===============================
// Load standards
// ===============================
$standardsStmt = $conn->query("
    SELECT 
        id,
        standard_code,
        standard_title
    FROM standards
    ORDER BY id
");
$standards = $standardsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Handle form submission
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $standard_id = !empty($_POST["standard_id"]) ? (int)$_POST["standard_id"] : 0;
    $academic_year = trim($_POST["academic_year"] ?? "");
    $report_cycle = trim($_POST["report_cycle"] ?? "");
    $section_title = trim($_POST["section_title"] ?? "");

    $evidence_based_explanation = trim($_POST["evidence_based_explanation"] ?? "");
    $strengths = trim($_POST["strengths"] ?? "");
    $areas_for_improvement = trim($_POST["areas_for_improvement"] ?? "");
    $action_plan = trim($_POST["action_plan"] ?? "");
    $conclusion = trim($_POST["conclusion"] ?? "");

    $status = trim($_POST["status"] ?? "Draft");
    $prepared_by = trim($_POST["prepared_by"] ?? "");
    $reviewed_by = trim($_POST["reviewed_by"] ?? "");

    if ($standard_id === 0) {
        $error = "Please select a standard.";
    } elseif ($section_title === "") {
        $error = "Section title is required.";
    } else {
        // Prevent duplicate SSR section for the same standard and academic year/report cycle
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM ssr_sections
            WHERE standard_id = :standard_id
              AND academic_year = :academic_year
              AND report_cycle = :report_cycle
        ");
        $checkStmt->execute([
            ":standard_id" => $standard_id,
            ":academic_year" => $academic_year,
            ":report_cycle" => $report_cycle
        ]);

        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $error = "An SSR section already exists for this standard, academic year, and report cycle.";
        } else {
            $sql = "
                INSERT INTO ssr_sections
                (
                    standard_id,
                    academic_year,
                    report_cycle,
                    section_title,
                    evidence_based_explanation,
                    strengths,
                    areas_for_improvement,
                    action_plan,
                    conclusion,
                    status,
                    prepared_by,
                    reviewed_by
                )
                VALUES
                (
                    :standard_id,
                    :academic_year,
                    :report_cycle,
                    :section_title,
                    :evidence_based_explanation,
                    :strengths,
                    :areas_for_improvement,
                    :action_plan,
                    :conclusion,
                    :status,
                    :prepared_by,
                    :reviewed_by
                )
            ";

            $stmt = $conn->prepare($sql);

            $stmt->execute([
                ":standard_id" => $standard_id,
                ":academic_year" => $academic_year,
                ":report_cycle" => $report_cycle,
                ":section_title" => $section_title,
                ":evidence_based_explanation" => $evidence_based_explanation,
                ":strengths" => $strengths,
                ":areas_for_improvement" => $areas_for_improvement,
                ":action_plan" => $action_plan,
                ":conclusion" => $conclusion,
                ":status" => $status,
                ":prepared_by" => $prepared_by,
                ":reviewed_by" => $reviewed_by
            ]);

            $newSsrId = $conn->lastInsertId();

            logActivity(
                $conn,
                "Create",
                "SSR Sections",
                $newSsrId,
                "SSR section created: " . $section_title
            );

            header("Location: view_ssr_section.php?id=" . $newSsrId);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create SSR Section</title>
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

        .ssr-form {
            max-width: 1050px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(250px, 1fr));
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
            min-height: 130px;
        }

        .large-textarea {
            min-height: 180px;
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
            max-width: 1050px;
            box-sizing: border-box;
        }

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .ssr-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Create SSR Section</h1>
        <p>Write the self-study narrative for one standard using evidence-based explanation, strengths, gaps, and action plan.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create_ssr_section.php<?php echo $selected_standard_id !== "" ? '?standard_id=' . htmlspecialchars($selected_standard_id) : ''; ?>" class="ssr-form">

            <div class="form-section">
                <h2>Section Information</h2>

                <div class="form-grid">
                    <div>
                        <label>Standard</label>
                        <select name="standard_id" required>
                            <option value="">-- Select Standard --</option>

                            <?php foreach ($standards as $standard): ?>
                                <option value="<?php echo htmlspecialchars($standard["id"]); ?>"
                                    <?php if ((string)$selected_standard_id === (string)$standard["id"]) echo "selected"; ?>>
                                    <?php
                                        echo htmlspecialchars(
                                            $standard["standard_code"] . " - " . $standard["standard_title"]
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">Each SSR section is linked to one standard.</div>
                    </div>

                    <div>
                        <label>Section Title</label>
                        <input type="text" name="section_title" required
                               placeholder="Example: Standard 2 - Program Management and Quality Assurance">
                    </div>

                    <div>
                        <label>Academic Year</label>
                        <input type="text" name="academic_year"
                               value="<?php echo htmlspecialchars($default_academic_year); ?>"
                               placeholder="Example: 2025-2026">
                    </div>

                    <div>
                        <label>Report Cycle</label>
                        <input type="text" name="report_cycle"
                               placeholder="Example: Program Self-Study 2025-2026">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="Draft">Draft</option>
                            <option value="Under Review">Under Review</option>
                            <option value="Approved">Approved</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>

                    <div>
                        <label>Prepared By</label>
                        <input type="text" name="prepared_by"
                               value="<?php echo htmlspecialchars($default_prepared_by); ?>">
                    </div>

                    <div>
                        <label>Reviewed By</label>
                        <input type="text" name="reviewed_by" placeholder="Reviewer name">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>SSR Narrative</h2>

                <label>Evidence-Based Explanation</label>
                <textarea name="evidence_based_explanation" class="large-textarea"
                          placeholder="Write a clear narrative explaining how the program meets this standard, based on evidence and data."></textarea>

                <label>Strengths</label>
                <textarea name="strengths"
                          placeholder="List and explain the main strengths supported by evidence."></textarea>

                <label>Areas for Improvement</label>
                <textarea name="areas_for_improvement"
                          placeholder="Identify gaps, risks, missing evidence, or weak performance areas."></textarea>

                <label>Action Plan</label>
                <textarea name="action_plan"
                          placeholder="Write improvement actions, responsible persons, timelines, and follow-up mechanisms."></textarea>

                <label>Conclusion</label>
                <textarea name="conclusion"
                          placeholder="Brief conclusion about compliance level and next steps."></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save SSR Section</button>
                <a href="ssr_builder.php" class="btn btn-secondary">Back to SSR Builder</a>

                <?php if ($selected_standard_id !== ""): ?>
                    <a href="standard_evidence_report.php?standard_id=<?php echo htmlspecialchars($selected_standard_id); ?>" class="btn btn-purple">
                        Evidence Map
                    </a>
                <?php endif; ?>
            </div>

        </form>

    </div>

</div>

</body>
</html>