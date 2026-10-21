<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];
requireProgramSpecificationAccess($conn, $spec_id);

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $stmt->execute([":table_name" => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND column_name = :column_name
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":column_name" => $columnName
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function valueOrMissing($value) {
    if ($value === null || trim((string)$value) === "") {
        return "<span class='missing'>Not entered</span>";
    }

    return safeText($value);
}

function longTextBlock($title, $content) {
    echo "<div class='text-block'>";
    echo "<h3>" . safeText($title) . "</h3>";

    if ($content === null || trim((string)$content) === "") {
        echo "<p class='missing'>Not entered.</p>";
    } else {
        echo "<p>" . nl2br(safeText($content)) . "</p>";
    }

    echo "</div>";
}

function statusBadgeClass($status) {
    if ($status === "Approved") {
        return "badge-approved";
    }

    if ($status === "Draft") {
        return "badge-draft";
    }

    if ($status === "Under Review") {
        return "badge-review";
    }

    if ($status === "Archived") {
        return "badge-archived";
    }

    return "badge-muted";
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

$hasStudyPlans = tableExists($conn, "study_plans");
$hasPlos = tableExists($conn, "program_specification_plos");
$hasCourses = tableExists($conn, "program_specification_courses");
$hasMappings = tableExists($conn, "program_specification_plo_course_mapping");
$hasKpis = tableExists($conn, "program_specification_kpis");
$hasCurriculum = tableExists($conn, "program_specification_curriculum_structure");
$hasTeachingStrategies = tableExists($conn, "program_specification_teaching_strategies");
$hasAssessmentMethods = tableExists($conn, "program_specification_assessment_methods");
$hasMarkDistributions = tableExists($conn, "program_specification_mark_distributions");
$hasStaffing = tableExists($conn, "program_specification_staffing_needs");
$hasEvaluationMatrix = tableExists($conn, "program_specification_evaluation_matrix");
$hasExitPoints = tableExists($conn, "program_specification_exit_points");
$hasTracks = tableExists($conn, "program_specification_tracks");

$hasApprovalStatus = columnExists($conn, "program_specifications", "approval_status");
$hasStatus = columnExists($conn, "program_specifications", "status");

// ===============================
// Load specification
// ===============================
$approvalStatusSelect = "NULL AS approval_status_value";

if ($hasApprovalStatus) {
    $approvalStatusSelect = "ps.approval_status AS approval_status_value";
} elseif ($hasStatus) {
    $approvalStatusSelect = "ps.status AS approval_status_value";
}

$studyPlanSelect = "
    NULL AS study_plan_title,
    NULL AS study_plan_version,
    NULL AS study_plan_academic_year
";

$studyPlanJoin = "";

if ($hasStudyPlans) {
    $studyPlanSelect = "
        sp.plan_title AS study_plan_title,
        sp.plan_version AS study_plan_version,
        sp.academic_year AS study_plan_academic_year
    ";

    $studyPlanJoin = "
        LEFT JOIN study_plans sp ON ps.study_plan_id = sp.id
    ";
}

$stmt = $conn->prepare("
    SELECT
        ps.*,
        $approvalStatusSelect,
        $studyPlanSelect
    FROM program_specifications ps
    $studyPlanJoin
    WHERE ps.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $spec_id]);
$spec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

$approvalStatus = $spec["approval_status_value"] ?? "Draft";

// ===============================
// Counts
// ===============================
function countRows($conn, $tableName, $spec_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM $tableName
        WHERE program_specification_id = :id
    ");
    $stmt->execute([":id" => $spec_id]);
    return (int)$stmt->fetchColumn();
}

$ploCount = countRows($conn, "program_specification_plos", $spec_id);
$courseCount = countRows($conn, "program_specification_courses", $spec_id);
$mappingCount = countRows($conn, "program_specification_plo_course_mapping", $spec_id);
$kpiCount = countRows($conn, "program_specification_kpis", $spec_id);
$curriculumCount = countRows($conn, "program_specification_curriculum_structure", $spec_id);
$teachingStrategyCount = countRows($conn, "program_specification_teaching_strategies", $spec_id);
$assessmentMethodCount = countRows($conn, "program_specification_assessment_methods", $spec_id);
$evaluationMatrixCount = countRows($conn, "program_specification_evaluation_matrix", $spec_id);

// ===============================
// Load related rows
// ===============================
$plos = [];
if ($hasPlos) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_plos
        WHERE program_specification_id = :id
        ORDER BY display_order ASC, id ASC
    ");
    $stmt->execute([":id" => $spec_id]);
    $plos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$curriculumRows = [];
if ($hasCurriculum) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_curriculum_structure
        WHERE program_specification_id = :id
        ORDER BY id ASC
    ");
    $stmt->execute([":id" => $spec_id]);
    $curriculumRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$courses = [];
if ($hasCourses) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_courses
        WHERE program_specification_id = :id
        ORDER BY level_no ASC, course_code ASC, id ASC
        LIMIT 300
    ");
    $stmt->execute([":id" => $spec_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$kpis = [];
if ($hasKpis) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_kpis
        WHERE program_specification_id = :id
        ORDER BY kpi_type ASC, kpi_no ASC, id ASC
        LIMIT 300
    ");
    $stmt->execute([":id" => $spec_id]);
    $kpis = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$evaluationRows = [];
if ($hasEvaluationMatrix) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_evaluation_matrix
        WHERE program_specification_id = :id
        ORDER BY id ASC
    ");
    $stmt->execute([":id" => $spec_id]);
    $evaluationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$exitPoints = [];
if ($hasExitPoints) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_exit_points
        WHERE program_specification_id = :id
        ORDER BY id ASC
    ");
    $stmt->execute([":id" => $spec_id]);
    $exitPoints = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$tracks = [];
if ($hasTracks) {
    $stmt = $conn->prepare("
        SELECT *
        FROM program_specification_tracks
        WHERE program_specification_id = :id
        ORDER BY id ASC
    ");
    $stmt->execute([":id" => $spec_id]);
    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$statusClass = statusBadgeClass($approvalStatus);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Specification Profile</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
        }

        .summary-number {
            font-size: 26px;
            font-weight: bold;
            color: #007bff;
        }

        .summary-label {
            color: #555;
            font-size: 13px;
            margin-top: 6px;
        }

        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .profile-title {
            font-size: 24px;
            font-weight: bold;
            color: #222;
            margin-bottom: 6px;
        }

        .profile-subtitle {
            color: #555;
            margin-bottom: 12px;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-approved {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-draft {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-review {
            background: #cff4fc;
            color: #055160;
        }

        .badge-archived {
            background: #f8d7da;
            color: #842029;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .text-block {
            border: 1px solid #eee;
            background: #fafafa;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .text-block h3 {
            margin-top: 0;
            color: #333;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 1200px;
            width: 100%;
            border-collapse: collapse;
        }

        .actions {
            margin-bottom: 18px;
        }

        .actions a {
            margin-bottom: 5px;
        }

        @media (max-width: 1000px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Program Specification Profile</h1>
        <p>View the main NCAAA program specification sections and related structured data.</p>
    </div>

    <div class="actions">
	<a href="program_specification_exports.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Exports
</a>
 
    	<?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
	
	<a href="program_specification_dashboard.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Dashboard
</a>
	<a href="manage_program_specification_teaching_assessment.php?id=<?php echo safeText($spec_id); ?>" class="btn">
    Teaching / Assessment
</a>
	
	<a href="program_specifications.php" class="btn btn-secondary">
        Back to Specifications
    </a>

   	 <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
        Validate Specification
    </a>

  	  <a href="program_specification_plo_mapping.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
        PLO Mapping
    </a>

        <a href="edit_program_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-edit">
            Edit Specification
        </a>

        <a href="import_program_specification_from_study_plan.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Import Courses
        </a>

        <a href="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Manage PLOs
        </a>

        <a href="manage_program_specification_kpis.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Manage KPIs
        </a>
		<a href="export_program_specification_word.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
    Export Word
</a>
	<a href="manage_program_specification_curriculum.php?id=<?php echo safeText($spec_id); ?>" class="btn">
    Curriculum Structure
</a>
	<a href="manage_program_specification_evaluation.php?id=<?php echo safeText($spec_id); ?>" class="btn">
    Evaluation Matrix
</a>

	<a href="manage_program_specification_staffing.php?id=<?php echo safeText($spec_id); ?>" class="btn">
    Staffing Needs
</a>
	
    <?php endif; ?>

</div>

    <div class="panel">
        <div class="profile-title">
            <?php echo valueOrMissing($spec["specification_title"] ?? ""); ?>
        </div>

        <div class="profile-subtitle">
            <?php echo valueOrMissing($spec["program_name"] ?? ""); ?>

            <?php if (!empty($spec["program_code"])): ?>
                | Code: <?php echo safeText($spec["program_code"]); ?>
            <?php endif; ?>

            <?php if (!empty($spec["specification_type"])): ?>
                | Type: <?php echo safeText($spec["specification_type"]); ?>
            <?php endif; ?>
        </div>

        <span class="badge <?php echo safeText($statusClass); ?>">
            <?php echo safeText($approvalStatus); ?>
        </span>

        <br><br>

        <table>
            <tr>
                <th>Program Name</th>
                <td><?php echo valueOrMissing($spec["program_name"] ?? ""); ?></td>
                <th>Program Code</th>
                <td><?php echo valueOrMissing($spec["program_code"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Qualification Level</th>
                <td><?php echo valueOrMissing($spec["qualification_level"] ?? ""); ?></td>
                <th>Total Credit Hours</th>
                <td><?php echo valueOrMissing($spec["total_credit_hours"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Department</th>
                <td><?php echo valueOrMissing($spec["department_name"] ?? ""); ?></td>
                <th>College</th>
                <td><?php echo valueOrMissing($spec["college_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Institution</th>
                <td><?php echo valueOrMissing($spec["institution_name"] ?? ""); ?></td>
                <th>Last Review Date</th>
                <td><?php echo valueOrMissing($spec["last_review_date"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Linked Study Plan</th>
                <td colspan="3">
                    <?php if (!empty($spec["study_plan_id"])): ?>
                        <a href="study_plan_profile.php?id=<?php echo safeText($spec["study_plan_id"]); ?>">
                            <?php echo safeText($spec["study_plan_title"] ?? "Study Plan"); ?>
                        </a>
                        <br>
                        <span class="small-text">
                            Version: <?php echo safeText($spec["study_plan_version"] ?? ""); ?>
                            |
                            Academic Year: <?php echo safeText($spec["study_plan_academic_year"] ?? ""); ?>
                        </span>
                    <?php else: ?>
                        <span class="missing">Not linked</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($ploCount); ?></div>
            <div class="summary-label">PLOs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($courseCount); ?></div>
            <div class="summary-label">Program Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($mappingCount); ?></div>
            <div class="summary-label">PLO-Course Mappings</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($kpiCount); ?></div>
            <div class="summary-label">KPIs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($curriculumCount); ?></div>
            <div class="summary-label">Curriculum Structure Rows</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($teachingStrategyCount); ?></div>
            <div class="summary-label">Teaching Strategies</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($assessmentMethodCount); ?></div>
            <div class="summary-label">Assessment Methods</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($evaluationMatrixCount); ?></div>
            <div class="summary-label">Evaluation Matrix Rows</div>
        </div>
    </div>

    <div class="panel">
        <h2>A. Program Identification and General Information</h2>

        <?php longTextBlock("Main Location", $spec["main_location"] ?? ""); ?>
        <?php longTextBlock("Branches Offering the Program", $spec["branches"] ?? ""); ?>
        <?php longTextBlock("Partnerships", $spec["partnerships"] ?? ""); ?>
        <?php longTextBlock("Professions / Jobs for Which Students Are Qualified", $spec["qualified_jobs"] ?? ""); ?>
        <?php longTextBlock("Relevant Occupational / Professional Sectors", $spec["occupational_sectors"] ?? ""); ?>

        <h3>Tracks / Pathways</h3>
        <table>
            <tr>
                <th>Track Name</th>
                <th>Credit Hours</th>
                <th>Professions / Jobs</th>
            </tr>
            <?php if (count($tracks) > 0): ?>
                <?php foreach ($tracks as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["track_name"]); ?></td>
                        <td><?php echo safeText($row["credit_hours"]); ?></td>
                        <td><?php echo nl2br(safeText($row["professions_jobs"])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3">No tracks recorded.</td></tr>
            <?php endif; ?>
        </table>

        <h3>Exit Points / Awarded Degrees</h3>
        <table>
            <tr>
                <th>Exit Point</th>
                <th>Credit Hours</th>
                <th>Notes</th>
            </tr>
            <?php if (count($exitPoints) > 0): ?>
                <?php foreach ($exitPoints as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["exit_point_name"]); ?></td>
                        <td><?php echo safeText($row["credit_hours"]); ?></td>
                        <td><?php echo nl2br(safeText($row["notes"])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3">No exit points recorded.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>B. Mission, Objectives, and Program Learning Outcomes</h2>

        <?php longTextBlock("Program Mission", $spec["program_mission"] ?? ""); ?>
        <?php longTextBlock("Program Goals", $spec["program_goals"] ?? ""); ?>
        <?php longTextBlock("Graduate Attributes", $spec["graduate_attributes"] ?? ""); ?>

        <h3>Program Learning Outcomes</h3>

        <table>
            <tr>
                <th>Code</th>
                <th>Domain</th>
                <th>PLO Text</th>
                <th>NQF Level</th>
            </tr>

            <?php if (count($plos) > 0): ?>
                <?php foreach ($plos as $plo): ?>
                    <tr>
                        <td><?php echo safeText($plo["plo_code"]); ?></td>
                        <td><?php echo safeText($plo["plo_domain"]); ?></td>
                        <td><?php echo nl2br(safeText($plo["plo_text"])); ?></td>
                        <td><?php echo safeText($plo["nqf_level"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No PLOs entered yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>C. Curriculum</h2>

        <h3>Curriculum Structure</h3>

        <table>
            <tr>
                <th>Category</th>
                <th>Required / Elective</th>
                <th>No. of Courses</th>
                <th>Credit Hours</th>
                <th>Percentage</th>
            </tr>

            <?php if (count($curriculumRows) > 0): ?>
                <?php foreach ($curriculumRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["structure_category"]); ?></td>
                        <td><?php echo safeText($row["requirement_type"]); ?></td>
                        <td><?php echo safeText($row["number_of_courses"]); ?></td>
                        <td><?php echo safeText($row["credit_hours"]); ?></td>
                        <td><?php echo safeText($row["percentage"]); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No curriculum structure rows recorded.</td></tr>
            <?php endif; ?>
        </table>

        <h3>Program Courses</h3>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Level</th>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Required / Elective</th>
                    <th>Prerequisites</th>
                    <th>Credit Hours</th>
                    <th>Requirement Type</th>
                </tr>

                <?php if (count($courses) > 0): ?>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><?php echo safeText($course["level_no"]); ?></td>
                            <td><?php echo safeText($course["course_code"]); ?></td>
                            <td><?php echo safeText($course["course_title"]); ?></td>
                            <td><?php echo safeText($course["required_or_elective"]); ?></td>
                            <td><?php echo nl2br(safeText($course["prerequisite_courses"])); ?></td>
                            <td><?php echo safeText($course["credit_hours"]); ?></td>
                            <td><?php echo safeText($course["requirement_type"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">No program courses imported yet.</td></tr>
                <?php endif; ?>
            </table>
        </div>

        <?php longTextBlock("Teaching and Learning Strategies", $spec["teaching_learning_strategy"] ?? ""); ?>
        <?php longTextBlock("Assessment Strategy", $spec["assessment_strategy"] ?? ""); ?>
    </div>

    <div class="panel">
        <h2>D. Student Admission and Support</h2>

        <?php longTextBlock("Admission Requirements", $spec["admission_requirements"] ?? ""); ?>
        <?php longTextBlock("Graduation Requirements", $spec["graduation_requirements"] ?? ""); ?>
        <?php longTextBlock("Guidance and Orientation Programs", $spec["guidance_orientation"] ?? ""); ?>
        <?php longTextBlock("Student Counseling Services", $spec["counseling_services"] ?? ""); ?>
        <?php longTextBlock("Special Support", $spec["special_support"] ?? ""); ?>
    </div>

    <div class="panel">
        <h2>F. Learning Resources, Facilities, and Equipment</h2>

        <?php longTextBlock("Learning Resources", $spec["learning_resources"] ?? ""); ?>
        <?php longTextBlock("Facilities and Equipment", $spec["facilities_equipment"] ?? ""); ?>
        <?php longTextBlock("Healthy and Safe Learning Environment", $spec["healthy_safe_environment"] ?? ""); ?>
    </div>

    <div class="panel">
        <h2>G. Program Quality Assurance</h2>

        <?php longTextBlock("Quality Assurance Arrangements", $spec["quality_assurance_arrangements"] ?? ""); ?>
        <?php longTextBlock("Quality Assurance System", $spec["quality_assurance_system"] ?? ""); ?>
        <?php longTextBlock("Procedures to Monitor Courses Taught by Other Departments", $spec["quality_courses_other_departments"] ?? ""); ?>
        <?php longTextBlock("Consistency Between Main Campus and Sections", $spec["consistency_between_sections"] ?? ""); ?>
        <?php longTextBlock("PLO Assessment Plan", $spec["plo_assessment_plan"] ?? ""); ?>

        <table>
            <tr>
                <th>Quality Manual Reference</th>
                <td><?php echo valueOrMissing($spec["quality_manual_reference"] ?? ""); ?></td>
                <th>PLO Assessment Plan Reference</th>
                <td><?php echo valueOrMissing($spec["plo_assessment_plan_reference"] ?? ""); ?></td>
            </tr>
        </table>

        <h3>Program Evaluation Matrix</h3>

        <table>
            <tr>
                <th>Evaluation Area</th>
                <th>Sources</th>
                <th>Methods</th>
                <th>Time</th>
            </tr>

            <?php if (count($evaluationRows) > 0): ?>
                <?php foreach ($evaluationRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["evaluation_area"]); ?></td>
                        <td><?php echo nl2br(safeText($row["evaluation_sources"])); ?></td>
                        <td><?php echo nl2br(safeText($row["evaluation_methods"])); ?></td>
                        <td><?php echo safeText($row["evaluation_time"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4">No evaluation matrix rows recorded.</td></tr>
            <?php endif; ?>
        </table>

        <h3>Program KPIs</h3>

        <table>
            <tr>
                <th>No.</th>
                <th>Code</th>
                <th>KPI</th>
                <th>Target</th>
                <th>Measurement Methods</th>
                <th>Measurement Time</th>
                <th>Type</th>
            </tr>

            <?php if (count($kpis) > 0): ?>
                <?php foreach ($kpis as $kpi): ?>
                    <tr>
                        <td><?php echo safeText($kpi["kpi_no"]); ?></td>
                        <td><?php echo safeText($kpi["kpi_code"]); ?></td>
                        <td><?php echo safeText($kpi["kpi_name"]); ?></td>
                        <td><?php echo safeText($kpi["target_value"]); ?></td>
                        <td><?php echo nl2br(safeText($kpi["measurement_methods"])); ?></td>
                        <td><?php echo safeText($kpi["measurement_time"]); ?></td>
                        <td><?php echo safeText($kpi["kpi_type"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7">No KPIs entered yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>H. Specification Approval Data</h2>

        <table>
            <tr>
                <th>Council / Committee</th>
                <td><?php echo valueOrMissing($spec["approval_council_committee"] ?? ""); ?></td>
                <th>Reference No.</th>
                <td><?php echo valueOrMissing($spec["approval_reference_no"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Approval Date Text</th>
                <td><?php echo valueOrMissing($spec["approval_date_text"] ?? ""); ?></td>
                <th>Approval Date</th>
                <td><?php echo valueOrMissing($spec["approval_date"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Previous Version File</th>
                <td><?php echo valueOrMissing($spec["previous_version_file"] ?? ""); ?></td>
                <th>Status</th>
                <td>
                    <span class="badge <?php echo safeText($statusClass); ?>">
                        <?php echo safeText($approvalStatus); ?>
                    </span>
                </td>
            </tr>

            <tr>
                <th>Notes</th>
                <td colspan="3"><?php echo nl2br(safeText($spec["notes"] ?? "")); ?></td>
            </tr>
        </table>
    </div>

</div>

</body>
</html>
