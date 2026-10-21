<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course report ID.");
}

$report_id = (int)$_GET["id"];

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

// ===============================
// Load course report
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM course_reports
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Course report not found.");
}

$course_specification_id = (int)($report["course_specification_id"] ?? 0);

if ($course_specification_id <= 0) {
    die("
        <h3>No Course Specification Linked</h3>
        <p>This Course Report is not linked to a Course Specification.</p>
        <p>Edit the Course Report first and select the approved Course Specification.</p>
        <p><a href='edit_course_report.php?id=" . safeText($report_id) . "'>Edit Course Report</a></p>
    ");
}

// ===============================
// Load linked specification
// ===============================
$specStmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE id = :id
    LIMIT 1
");
$specStmt->execute([":id" => $course_specification_id]);
$spec = $specStmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("
        <h3>Broken Course Specification Link</h3>
        <p>The Course Report has course_specification_id = " . safeText($course_specification_id) . ", but no matching Course Specification exists.</p>
        <p><a href='edit_course_report.php?id=" . safeText($report_id) . "'>Fix Link</a></p>
    ");
}

// ===============================
// Load CLOs from specification
// ===============================
$closStmt = $conn->prepare("
    SELECT *
    FROM course_specification_clos
    WHERE course_specification_id = :course_specification_id
    ORDER BY display_order ASC, id ASC
");
$closStmt->execute([
    ":course_specification_id" => $course_specification_id
]);
$specClos = $closStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$specClos || count($specClos) === 0) {
    die("
        <h3>No CLOs Found</h3>
        <p>The linked Course Specification does not have CLO rows yet.</p>
        <p><a href='manage_course_specification_details.php?id=" . safeText($course_specification_id) . "'>Manage Specification Details</a></p>
    ");
}

// ===============================
// Confirmation screen
// ===============================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Import Final Exam Alignment from Specification</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>

    <?php include "nav.php"; ?>

    <div class="container">
        <div class="page-header">
            <h1>Import Final Exam Alignment from Specification</h1>
            <p>This creates final exam alignment rows from the approved CLOs in the linked Course Specification.</p>
        </div>

        <div class="panel">
            <h2>Course Report</h2>

            <table>
                <tr>
                    <th>Course</th>
                    <td>
                        <strong><?php echo safeText($report["course_code"] ?? ""); ?></strong>
                        -
                        <?php echo safeText($report["course_title"] ?? ""); ?>
                    </td>
                </tr>

                <tr>
                    <th>Academic Year</th>
                    <td><?php echo safeText($report["academic_year"] ?? ""); ?></td>
                </tr>

                <tr>
                    <th>Semester</th>
                    <td><?php echo safeText($report["semester"] ?? ""); ?></td>
                </tr>

                <tr>
                    <th>Linked Specification</th>
                    <td>
                        <strong><?php echo safeText($spec["course_code"] ?? ""); ?></strong>
                        -
                        <?php echo safeText($spec["course_title"] ?? ""); ?>
                        <br>
                        Version:
                        <?php echo safeText($spec["specification_version"] ?? ""); ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="panel">
            <h2>Alignment Rows to Import</h2>

            <table>
                <tr>
                    <th>No.</th>
                    <th>CLO Code</th>
                    <th>CLO Text</th>
                    <th>Aligned PLO</th>
                    <th>Question Numbers</th>
                    <th>Note</th>
                </tr>

                <?php foreach ($specClos as $index => $clo): ?>
                    <tr>
                        <td><?php echo safeText($index + 1); ?></td>
                        <td><?php echo safeText($clo["clo_code"] ?? ""); ?></td>
                        <td><?php echo nl2br(safeText($clo["clo_text"] ?? "")); ?></td>
                        <td><?php echo safeText($clo["aligned_plo_code"] ?? ""); ?></td>
                        <td class="small-text">To be filled later</td>
                        <td class="small-text">Imported from Course Specification</td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <form method="post">
                <input type="hidden" name="confirm_import" value="1">

                <button type="submit" class="btn">
                    Import Exam Alignment Rows
                </button>

                <a href="manage_course_report_details.php?id=<?php echo safeText($report_id); ?>" class="btn btn-secondary">
                    Cancel
                </a>
            </form>
        </div>
    </div>

    </body>
    </html>
    <?php
    exit;
}

// ===============================
// Import exam alignment rows
// ===============================
if (!isset($_POST["confirm_import"]) || $_POST["confirm_import"] !== "1") {
    die("Import confirmation missing.");
}

try {
    $conn->beginTransaction();

    $inserted = 0;
    $skipped = 0;

    foreach ($specClos as $index => $clo) {
        $clo_code = trim((string)($clo["clo_code"] ?? ""));

        if ($clo_code === "") {
            $clo_code = "CLO" . ($index + 1);
        }

        // Skip duplicate CLO code for the same report
        $dupStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM course_report_exam_alignment
            WHERE course_report_id = :course_report_id
              AND clo_code = :clo_code
        ");
        $dupStmt->execute([
            ":course_report_id" => $report_id,
            ":clo_code" => $clo_code
        ]);

        $exists = (int)$dupStmt->fetchColumn();

        if ($exists > 0) {
            $skipped++;
            continue;
        }

        $stmt = $conn->prepare("
            INSERT INTO course_report_exam_alignment
            (
                course_report_id,
                clo_code,
                clo_text,
                aligned_plo,
                question_numbers,
                note,
                display_order
            )
            VALUES
            (
                :course_report_id,
                :clo_code,
                :clo_text,
                :aligned_plo,
                :question_numbers,
                :note,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_report_id" => $report_id,
            ":clo_code" => $clo_code,
            ":clo_text" => $clo["clo_text"] ?? "",
            ":aligned_plo" => $clo["aligned_plo_code"] ?? "",
            ":question_numbers" => "",
            ":note" => "Imported from Course Specification. Add final exam question numbers after exam blueprint is finalized.",
            ":display_order" => $clo["display_order"] ?? ($index + 1)
        ]);

        $inserted++;
    }

    logActivitySafe(
        $conn,
        "Import",
        "Course Report Exam Alignment",
        $report_id,
        "Imported final exam alignment rows from linked Course Specification. Inserted: {$inserted}, skipped duplicates: {$skipped}."
    );

    $conn->commit();

    header("Location: manage_course_report_details.php?id=" . $report_id . "&message=" . urlencode("Exam alignment rows imported. Inserted: {$inserted}. Skipped duplicates: {$skipped}."));
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}
?>