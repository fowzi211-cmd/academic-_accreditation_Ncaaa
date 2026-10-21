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
    die("Invalid SSR section ID.");
}

$id = (int)$_GET["id"];
$error = "";

// ===============================
// Load SSR section
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM ssr_sections
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$section = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$section) {
    die("SSR section not found.");
}

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
// Handle update
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
        // Prevent duplicate SSR section for same standard/year/cycle excluding current section
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM ssr_sections
            WHERE standard_id = :standard_id
              AND academic_year = :academic_year
              AND report_cycle = :report_cycle
              AND id <> :id
        ");
        $checkStmt->execute([
            ":standard_id" => $standard_id,
            ":academic_year" => $academic_year,
            ":report_cycle" => $report_cycle,
            ":id" => $id
        ]);

        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $error = "Another SSR section already exists for this standard, academic year, and report cycle.";
        } else {
            $sql = "
                UPDATE ssr_sections
                SET
                    standard_id = :standard_id,
                    academic_year = :academic_year,
                    report_cycle = :report_cycle,
                    section_title = :section_title,
                    evidence_based_explanation = :evidence_based_explanation,
                    strengths = :strengths,
                    areas_for_improvement = :areas_for_improvement,
                    action_plan = :action_plan,
                    conclusion = :conclusion,
                    status = :status,
                    prepared_by = :prepared_by,
                    reviewed_by = :reviewed_by
                WHERE id = :id
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
                ":reviewed_by" => $reviewed_by,
                ":id" => $id
            ]);

            logActivity(
                $conn,
                "Update",
                "SSR Sections",
                $id,
                "SSR section updated: " . $section_title
            );

            header("Location: view_ssr_section.php?id=" . $id);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit SSR Section</title>
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
        <h1>Edit SSR Section</h1>
        <p>Update the self-study narrative for the selected standard.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_ssr_section.php?id=<?php echo htmlspecialchars($id); ?>" class="ssr-form">

            <div class="form-section">
                <h2>Section Information</h2>

                <div class="form-grid">
                    <div>
                        <label>Standard</label>
                        <select name="standard_id" required>
                            <option value="">-- Select Standard --</option>

                            <?php foreach ($standards as $standard): ?>
                                <option value="<?php echo htmlspecialchars($standard["id"]); ?>"
                                    <?php if ((int)($section["standard_id"] ?? 0) === (int)$standard["id"]) echo "selected"; ?>>
                                    <?php
                                        echo htmlspecialchars(
                                            $standard["standard_code"] . " - " . $standard["standard_title"]
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="hint">
                            Changing the standard may affect evidence mapping and SSR organization.
                        </div>
                    </div>

                    <div>
                        <label>Section Title</label>
                        <input type="text" name="section_title" required
                               value="<?php echo htmlspecialchars($section["section_title"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Academic Year</label>
                        <input type="text" name="academic_year"
                               value="<?php echo htmlspecialchars($section["academic_year"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Report Cycle</label>
                        <input type="text" name="report_cycle"
                               value="<?php echo htmlspecialchars($section["report_cycle"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <?php
                            $statuses = ["Draft", "Under Review", "Approved", "Archived"];
                            foreach ($statuses as $status):
                            ?>
                                <option value="<?php echo htmlspecialchars($status); ?>"
                                    <?php if (($section["status"] ?? "") === $status) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Prepared By</label>
                        <input type="text" name="prepared_by"
                               value="<?php echo htmlspecialchars($section["prepared_by"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Reviewed By</label>
                        <input type="text" name="reviewed_by"
                               value="<?php echo htmlspecialchars($section["reviewed_by"] ?? ""); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>SSR Narrative</h2>

                <label>Evidence-Based Explanation</label>
                <textarea name="evidence_based_explanation" class="large-textarea"><?php echo htmlspecialchars($section["evidence_based_explanation"] ?? ""); ?></textarea>

                <label>Strengths</label>
                <textarea name="strengths"><?php echo htmlspecialchars($section["strengths"] ?? ""); ?></textarea>

                <label>Areas for Improvement</label>
                <textarea name="areas_for_improvement"><?php echo htmlspecialchars($section["areas_for_improvement"] ?? ""); ?></textarea>

                <label>Action Plan</label>
                <textarea name="action_plan"><?php echo htmlspecialchars($section["action_plan"] ?? ""); ?></textarea>

                <label>Conclusion</label>
                <textarea name="conclusion"><?php echo htmlspecialchars($section["conclusion"] ?? ""); ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Update SSR Section</button>

                <a href="view_ssr_section.php?id=<?php echo htmlspecialchars($id); ?>" class="btn btn-purple">
                    View SSR Section
                </a>

                <a href="ssr_builder.php" class="btn btn-secondary">
                    Back to SSR Builder
                </a>

                <a href="standard_evidence_report.php?standard_id=<?php echo htmlspecialchars($section["standard_id"]); ?>" class="btn">
                    Evidence Map
                </a>
            </div>

        </form>

    </div>

</div>

</body>
</html>