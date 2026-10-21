<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];
requireProgramSpecificationAccess($conn, $spec_id);
$error = "";
$message = "";

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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_plos")) {
    die("program_specification_plos table does not exist.");
}

if (!tableExists($conn, "program_specification_courses")) {
    die("program_specification_courses table does not exist.");
}

if (!tableExists($conn, "program_specification_plo_course_mapping")) {
    die("program_specification_plo_course_mapping table does not exist.");
}

$specStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$specStmt->execute([":id" => $spec_id]);
$spec = $specStmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

$canEdit = hasRole(["Admin", "Quality Coordinator", "Program Leader"]);

$plosStmt = $conn->prepare("
    SELECT *
    FROM program_specification_plos
    WHERE program_specification_id = :id
    ORDER BY
        CASE
            WHEN plo_domain = 'Knowledge and Understanding' THEN 1
            WHEN plo_domain = 'Skills' THEN 2
            WHEN plo_domain = 'Values, Autonomy, and Responsibility' THEN 3
            ELSE 4
        END,
        display_order ASC,
        plo_code ASC,
        id ASC
");
$plosStmt->execute([":id" => $spec_id]);
$plos = $plosStmt->fetchAll(PDO::FETCH_ASSOC);

$coursesStmt = $conn->prepare("
    SELECT *
    FROM program_specification_courses
    WHERE program_specification_id = :id
    ORDER BY level_no ASC, course_code ASC, id ASC
");
$coursesStmt->execute([":id" => $spec_id]);
$courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

$mappingStmt = $conn->prepare("
    SELECT *
    FROM program_specification_plo_course_mapping
    WHERE program_specification_id = :id
");
$mappingStmt->execute([":id" => $spec_id]);
$mappingRows = $mappingStmt->fetchAll(PDO::FETCH_ASSOC);

$mapping = [];
foreach ($mappingRows as $row) {
    $courseCode = strtoupper(trim((string)($row["course_code"] ?? "")));
    $ploCode = strtoupper(trim((string)($row["plo_code"] ?? "")));

    if ($courseCode !== "" && $ploCode !== "") {
        $mapping[$courseCode][$ploCode] = $row["performance_level"] ?? "";
    }
}

if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $conn->beginTransaction();

        $deleteStmt = $conn->prepare("
            DELETE FROM program_specification_plo_course_mapping
            WHERE program_specification_id = :id
        ");
        $deleteStmt->execute([":id" => $spec_id]);

        $insertStmt = $conn->prepare("
            INSERT INTO program_specification_plo_course_mapping
            (
                program_specification_id,
                course_id,
                course_code,
                plo_code,
                performance_level,
                notes
            )
            VALUES
            (
                :program_specification_id,
                :course_id,
                :course_code,
                :plo_code,
                :performance_level,
                :notes
            )
        ");

        $inserted = 0;

        foreach ($courses as $course) {
            $courseId = (int)($course["id"] ?? 0);
            $courseCode = strtoupper(trim((string)($course["course_code"] ?? "")));

            if ($courseCode === "") {
                continue;
            }

            foreach ($plos as $plo) {
                $ploCode = strtoupper(trim((string)($plo["plo_code"] ?? "")));

                if ($ploCode === "") {
                    continue;
                }

                $fieldName = "map_" . $courseId . "_" . $ploCode;
                $level = trim((string)($_POST[$fieldName] ?? ""));

                if ($level === "") {
                    continue;
                }

                if (!in_array($level, ["I", "P", "M"], true)) {
                    continue;
                }

                $insertStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":course_id" => $courseId,
                    ":course_code" => $courseCode,
                    ":plo_code" => $ploCode,
                    ":performance_level" => $level,
                    ":notes" => null
                ]);

                $inserted++;
            }
        }

        $conn->commit();

        logActivitySafe(
            $conn,
            "Update",
            "Program Specification PLO-Course Mapping",
            $spec_id,
            "Updated PLO-course mapping matrix. Records saved: " . $inserted
        );

        header("Location: program_specification_plo_mapping.php?id=" . $spec_id . "&saved=1");
        exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $error = "Mapping save failed: " . $e->getMessage();
    }
}

if (isset($_GET["saved"]) && $_GET["saved"] === "1") {
    $message = "PLO-course mapping matrix saved successfully.";
}

$totalPossible = count($courses) * count($plos);
$totalMapped = count($mappingRows);
$coveragePercent = 0;

if ($totalPossible > 0) {
    $coveragePercent = round(($totalMapped / $totalPossible) * 100, 1);
}

$domainCounts = [
    "Knowledge and Understanding" => 0,
    "Skills" => 0,
    "Values, Autonomy, and Responsibility" => 0
];

foreach ($plos as $plo) {
    $domain = $plo["plo_domain"] ?? "";
    if (isset($domainCounts[$domain])) {
        $domainCounts[$domain]++;
    }
}

$levelCounts = [
    "I" => 0,
    "P" => 0,
    "M" => 0
];

foreach ($mappingRows as $row) {
    $level = $row["performance_level"] ?? "";
    if (isset($levelCounts[$level])) {
        $levelCounts[$level]++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PLO-Course Mapping Matrix</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
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

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
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

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 720px;
        }

        .matrix-table {
            min-width: 1300px;
            width: 100%;
            border-collapse: collapse;
        }

        .matrix-table th,
        .matrix-table td {
            font-size: 12px;
            vertical-align: middle;
            text-align: center;
            white-space: nowrap;
        }

        .matrix-table th {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            z-index: 3;
        }

        .matrix-table .course-col {
            text-align: left;
            min-width: 260px;
            white-space: normal;
        }

        .matrix-table .code-col {
            text-align: left;
            min-width: 90px;
        }

        .matrix-table select {
            width: 58px;
            padding: 4px;
            font-size: 12px;
        }

        .domain-header {
            background: #e9ecef !important;
            font-weight: bold;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
            font-size: 14px;
        }

        .legend span {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 8px 10px;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(130px, 1fr));
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
        <h1>PLO-Course Mapping Matrix</h1>
        <p>Map each program course to PLOs using I = Introduced, P = Practiced, and M = Mastered.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Validate Specification
        </a>

        <a href="manage_program_specification_plos.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Manage PLOs
        </a>

        <a href="import_program_specification_from_study_plan.php?id=<?php echo safeText($spec_id); ?>" class="btn">
            Import Courses
        </a>

        <?php if ($canEdit): ?>
            <a href="import_default_hpe_plo_course_mapping.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
                Import Default Mapping
            </a>
        <?php endif; ?>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
        <div class="alert-success"><?php echo safeText($message); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification</th>
                <td><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
                <th>Qualification</th>
                <td><?php echo safeText($spec["qualification_level"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($courses)); ?></div>
            <div class="summary-label">Courses</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($plos)); ?></div>
            <div class="summary-label">PLOs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalMapped); ?></div>
            <div class="summary-label">Mapped Cells</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($coveragePercent); ?>%</div>
            <div class="summary-label">Matrix Coverage</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($levelCounts["I"]); ?></div>
            <div class="summary-label">Introduced</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($levelCounts["M"]); ?></div>
            <div class="summary-label">Mastered</div>
        </div>
    </div>

    <div class="panel">
        <h2>Mapping Matrix</h2>

        <div class="legend">
            <span><strong>I</strong> = Introduced</span>
            <span><strong>P</strong> = Practiced</span>
            <span><strong>M</strong> = Mastered</span>
            <span>Blank = Not mapped</span>
        </div>

        <?php if (count($plos) === 0): ?>
            <div class="alert-error">
                No PLOs found. Import or add PLOs first.
            </div>
        <?php elseif (count($courses) === 0): ?>
            <div class="alert-error">
                No courses found. Import courses from the linked study plan first.
            </div>
        <?php else: ?>

            <form method="POST" action="program_specification_plo_mapping.php?id=<?php echo safeText($spec_id); ?>">

                <div class="wide-table-wrapper">
                    <table class="matrix-table">
                        <tr>
                            <th rowspan="2">Level</th>
                            <th rowspan="2">Course Code</th>
                            <th rowspan="2" class="course-col">Course Title</th>

                            <?php
                            $domains = [];
                            foreach ($plos as $plo) {
                                $domain = $plo["plo_domain"] ?? "Other";
                                if (!isset($domains[$domain])) {
                                    $domains[$domain] = 0;
                                }
                                $domains[$domain]++;
                            }
                            ?>

                            <?php foreach ($domains as $domain => $count): ?>
                                <th colspan="<?php echo safeText($count); ?>" class="domain-header">
                                    <?php echo safeText($domain); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <?php foreach ($plos as $plo): ?>
                                <th title="<?php echo safeText($plo["plo_text"] ?? ""); ?>">
                                    <?php echo safeText($plo["plo_code"]); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>

                        <?php foreach ($courses as $course): ?>
                            <?php
                            $courseId = (int)($course["id"] ?? 0);
                            $courseCode = strtoupper(trim((string)($course["course_code"] ?? "")));
                            ?>
                            <tr>
                                <td><?php echo safeText($course["level_no"] ?? ""); ?></td>
                                <td class="code-col"><strong><?php echo safeText($courseCode); ?></strong></td>
                                <td class="course-col"><?php echo safeText($course["course_title"] ?? ""); ?></td>

                                <?php foreach ($plos as $plo): ?>
                                    <?php
                                    $ploCode = strtoupper(trim((string)($plo["plo_code"] ?? "")));
                                    $currentValue = $mapping[$courseCode][$ploCode] ?? "";
                                    $fieldName = "map_" . $courseId . "_" . $ploCode;
                                    ?>
                                    <td>
                                        <?php if ($canEdit): ?>
                                            <select name="<?php echo safeText($fieldName); ?>">
                                                <option value="" <?php if ($currentValue === "") echo "selected"; ?>></option>
                                                <option value="I" <?php if ($currentValue === "I") echo "selected"; ?>>I</option>
                                                <option value="P" <?php if ($currentValue === "P") echo "selected"; ?>>P</option>
                                                <option value="M" <?php if ($currentValue === "M") echo "selected"; ?>>M</option>
                                            </select>
                                        <?php else: ?>
                                            <?php echo safeText($currentValue); ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <?php if ($canEdit): ?>
                    <br>
                    <button type="submit" class="btn btn-purple">
                        Save Mapping Matrix
                    </button>
                <?php endif; ?>

            </form>

        <?php endif; ?>
    </div>

</div>

</body>
</html>
