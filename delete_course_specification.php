<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator"]);

// ===============================
// Validate ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course specification ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load specification before deleting
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$spec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Course specification not found.");
}

// ===============================
// Check if linked to course reports
// ===============================
$checkStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM course_reports
    WHERE course_specification_id = :id
");
$checkStmt->execute([":id" => $id]);
$linkedReports = (int)$checkStmt->fetchColumn();

if ($linkedReports > 0) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Cannot Delete Course Specification</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>

    <?php include "nav.php"; ?>

    <div class="container">
        <h2>Cannot Delete Course Specification</h2>

        <div class="alert alert-danger">
            This Course Specification cannot be deleted because it is linked to
            <strong><?php echo htmlspecialchars($linkedReports); ?></strong>
            Course Report(s).
        </div>

        <p>
            For accreditation and audit traceability, do not delete a specification that has already been used in reports.
            Remove the link from the related Course Reports first, or keep this specification as historical evidence.
        </p>

        <table class="table">
            <tr>
                <th>Course Code</th>
                <td><?php echo htmlspecialchars($spec["course_code"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Course Title</th>
                <td><?php echo htmlspecialchars($spec["course_title"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Version</th>
                <td><?php echo htmlspecialchars($spec["version"] ?? $spec["specification_version"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Status</th>
                <td><?php echo htmlspecialchars($spec["status"] ?? ""); ?></td>
            </tr>
        </table>

        <p>
            <a href="course_specifications.php" class="btn btn-secondary">Back to Course Specifications</a>
        </p>
    </div>

    </body>
    </html>
    <?php
    exit;
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
        <title>Delete Course Specification</title>
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>

    <?php include "nav.php"; ?>

    <div class="container">
        <h2>Delete Course Specification</h2>

        <div class="alert alert-danger">
            <strong>Warning:</strong> This action will permanently delete this Course Specification.
            If your database foreign keys are correctly set with ON DELETE CASCADE, related details will also be deleted.
        </div>

        <table class="table">
            <tr>
                <th>Course Code</th>
                <td><?php echo htmlspecialchars($spec["course_code"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Course Title</th>
                <td><?php echo htmlspecialchars($spec["course_title"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Program</th>
                <td><?php echo htmlspecialchars($spec["program"] ?? $spec["program_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Department</th>
                <td><?php echo htmlspecialchars($spec["department"] ?? $spec["department_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Version</th>
                <td><?php echo htmlspecialchars($spec["version"] ?? $spec["specification_version"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Status</th>
                <td><?php echo htmlspecialchars($spec["status"] ?? ""); ?></td>
            </tr>
        </table>

        <form method="post">
            <input type="hidden" name="confirm_delete" value="1">

            <button type="submit" class="btn btn-danger">
                Yes, Delete Course Specification
            </button>

            <a href="course_specifications.php" class="btn btn-secondary">
                Cancel
            </a>
        </form>
    </div>

    </body>
    </html>
    <?php
    exit;
}

// ===============================
// Delete specification
// ===============================
if (!isset($_POST["confirm_delete"]) || $_POST["confirm_delete"] !== "1") {
    die("Delete confirmation missing.");
}

try {
    $conn->beginTransaction();

    // Manual child-table delete added for safety.
    // This works even if ON DELETE CASCADE was not created correctly.
    $childTables = [
        "course_specification_clos",
        "course_specification_topics",
        "course_specification_assessments",
        "course_specification_resources",
        "course_specification_facilities",
        "course_specification_quality_assessment",
        "course_specification_teaching_modes",
        "course_specification_contact_hours"
    ];

    foreach ($childTables as $table) {
        $tableCheck = $conn->prepare("SHOW TABLES LIKE :table_name");
        $tableCheck->execute([":table_name" => $table]);

        if ($tableCheck->fetch(PDO::FETCH_NUM)) {
            $deleteChildStmt = $conn->prepare("
                DELETE FROM `$table`
                WHERE course_specification_id = :id
            ");
            $deleteChildStmt->execute([":id" => $id]);
        }
    }

    $deleteStmt = $conn->prepare("
        DELETE FROM course_specifications
        WHERE id = :id
    ");
    $deleteStmt->execute([":id" => $id]);

    logActivity(
        $conn,
        "Delete",
        "Course Specifications",
        $id,
        "Course specification deleted: " . ($spec["course_code"] ?? "") . " - " . ($spec["course_title"] ?? "")
    );

    $conn->commit();

    header("Location: course_specifications.php?deleted=1");
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Delete failed: " . htmlspecialchars($e->getMessage()));
}
?>