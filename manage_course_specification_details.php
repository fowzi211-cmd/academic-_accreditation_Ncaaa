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
$message = "";

// ===============================
// Validate specification ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid course specification ID.");
}

$spec_id = (int)$_GET["id"];

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""));
}

function postText($key) {
    return trim($_POST[$key] ?? "");
}

function postNumber($key) {
    return $_POST[$key] !== "" ? $_POST[$key] : 0;
}

function redirectBack($spec_id, $message = "") {
    $url = "manage_course_specification_details.php?id=" . (int)$spec_id;

    if ($message !== "") {
        $url .= "&message=" . urlencode($message);
    }

    header("Location: " . $url);
    exit;
}

function loadRows($conn, $table, $spec_id) {
    $allowedTables = [
        "course_specification_clos",
        "course_specification_topics",
        "course_specification_assessments",
        "course_specification_resources",
        "course_specification_facilities",
        "course_specification_quality_assessment",
        "course_specification_teaching_modes",
        "course_specification_contact_hours"
    ];

    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM $table
        WHERE course_specification_id = :course_specification_id
        ORDER BY display_order ASC, id ASC
    ");

    $stmt->execute([
        ":course_specification_id" => $spec_id
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deleteRow($conn, $table, $row_id, $spec_id) {
    $allowedTables = [
        "course_specification_clos",
        "course_specification_topics",
        "course_specification_assessments",
        "course_specification_resources",
        "course_specification_facilities",
        "course_specification_quality_assessment",
        "course_specification_teaching_modes",
        "course_specification_contact_hours"
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $stmt = $conn->prepare("
        DELETE FROM $table
        WHERE id = :id
          AND course_specification_id = :course_specification_id
    ");

    $stmt->execute([
        ":id" => $row_id,
        ":course_specification_id" => $spec_id
    ]);

    return $stmt->rowCount() > 0;
}

function deleteButton($table, $rowId, $spec_id) {
    ?>
    <form method="POST"
          action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>"
          onsubmit="return confirm('Are you sure you want to delete this row?');">
        <input type="hidden" name="action" value="delete_row">
        <input type="hidden" name="table_name" value="<?php echo safeText($table); ?>">
        <input type="hidden" name="row_id" value="<?php echo safeText($rowId); ?>">
        <button type="submit" class="btn btn-delete">Delete</button>
    </form>
    <?php
}

// ===============================
// Load course specification
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE id = :id
");
$stmt->execute([":id" => $spec_id]);
$spec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Course specification not found.");
}

if (isset($_GET["message"])) {
    $message = trim($_GET["message"]);
}

// ===============================
// Handle POST
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    // ===============================
    // Add CLO
    // ===============================
    if ($action === "add_clo") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_clos
            (
                course_specification_id,
                clo_code,
                clo_domain,
                clo_text,
                aligned_plo_code,
                teaching_strategies,
                assessment_methods,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :clo_code,
                :clo_domain,
                :clo_text,
                :aligned_plo_code,
                :teaching_strategies,
                :assessment_methods,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":clo_code" => postText("clo_code"),
            ":clo_domain" => postText("clo_domain"),
            ":clo_text" => postText("clo_text"),
            ":aligned_plo_code" => postText("aligned_plo_code"),
            ":teaching_strategies" => postText("teaching_strategies"),
            ":assessment_methods" => postText("assessment_methods"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification CLOs", $spec_id, "CLO added to course specification.");
        redirectBack($spec_id, "CLO added.");
    }

    // ===============================
    // Add topic
    // ===============================
    if ($action === "add_topic") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_topics
            (
                course_specification_id,
                topic_no,
                topic_title,
                contact_hours,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :topic_no,
                :topic_title,
                :contact_hours,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":topic_no" => postText("topic_no"),
            ":topic_title" => postText("topic_title"),
            ":contact_hours" => postNumber("contact_hours"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification Topics", $spec_id, "Topic added to course specification.");
        redirectBack($spec_id, "Topic added.");
    }

    // ===============================
    // Add assessment activity
    // ===============================
    if ($action === "add_assessment") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_assessments
            (
                course_specification_id,
                assessment_no,
                assessment_activity,
                assessment_timing,
                percentage,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :assessment_no,
                :assessment_activity,
                :assessment_timing,
                :percentage,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":assessment_no" => postText("assessment_no"),
            ":assessment_activity" => postText("assessment_activity"),
            ":assessment_timing" => postText("assessment_timing"),
            ":percentage" => postText("percentage"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification Assessments", $spec_id, "Assessment activity added.");
        redirectBack($spec_id, "Assessment activity added.");
    }

    // ===============================
    // Add resource
    // ===============================
    if ($action === "add_resource") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_resources
            (
                course_specification_id,
                resource_type,
                resource_text,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :resource_type,
                :resource_text,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":resource_type" => postText("resource_type"),
            ":resource_text" => postText("resource_text"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification Resources", $spec_id, "Learning resource added.");
        redirectBack($spec_id, "Learning resource added.");
    }

    // ===============================
    // Add facility
    // ===============================
    if ($action === "add_facility") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_facilities
            (
                course_specification_id,
                facility_item,
                facility_resources,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :facility_item,
                :facility_resources,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":facility_item" => postText("facility_item"),
            ":facility_resources" => postText("facility_resources"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification Facilities", $spec_id, "Facility/equipment row added.");
        redirectBack($spec_id, "Facility/equipment row added.");
    }

    // ===============================
    // Add quality assessment row
    // ===============================
    if ($action === "add_quality") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_quality_assessment
            (
                course_specification_id,
                assessment_area,
                assessor,
                assessment_methods,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :assessment_area,
                :assessor,
                :assessment_methods,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":assessment_area" => postText("assessment_area"),
            ":assessor" => postText("assessor"),
            ":assessment_methods" => postText("assessment_methods"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification Quality Assessment", $spec_id, "Course quality assessment row added.");
        redirectBack($spec_id, "Course quality assessment row added.");
    }

    // ===============================
    // Add teaching mode
    // ===============================
    if ($action === "add_teaching_mode") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_teaching_modes
            (
                course_specification_id,
                mode_of_instruction,
                contact_hours,
                percentage,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :mode_of_instruction,
                :contact_hours,
                :percentage,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":mode_of_instruction" => postText("mode_of_instruction"),
            ":contact_hours" => postNumber("contact_hours"),
            ":percentage" => postText("percentage"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification Teaching Modes", $spec_id, "Teaching mode row added.");
        redirectBack($spec_id, "Teaching mode row added.");
    }

    // ===============================
    // Add contact hour
    // ===============================
    if ($action === "add_contact_hour") {
        $stmt = $conn->prepare("
            INSERT INTO course_specification_contact_hours
            (
                course_specification_id,
                activity,
                contact_hours,
                display_order
            )
            VALUES
            (
                :course_specification_id,
                :activity,
                :contact_hours,
                :display_order
            )
        ");

        $stmt->execute([
            ":course_specification_id" => $spec_id,
            ":activity" => postText("activity"),
            ":contact_hours" => postNumber("contact_hours"),
            ":display_order" => (int)postNumber("display_order")
        ]);

        logActivity($conn, "Create", "Course Specification Contact Hours", $spec_id, "Contact hour row added.");
        redirectBack($spec_id, "Contact hour row added.");
    }

    // ===============================
    // Delete row
    // ===============================
    if ($action === "delete_row") {
        $table = postText("table_name");
        $row_id = (int)postNumber("row_id");

        if ($row_id <= 0) {
            die("Invalid row ID.");
        }

        $deleted = deleteRow($conn, $table, $row_id, $spec_id);

        if ($deleted) {
            logActivity($conn, "Delete", "Course Specification Details", $spec_id, "Detail row deleted from " . $table);
            redirectBack($spec_id, "Row deleted.");
        }

        redirectBack($spec_id, "No row deleted.");
    }
}

// ===============================
// Load saved rows
// ===============================
$cloRows = loadRows($conn, "course_specification_clos", $spec_id);
$topicRows = loadRows($conn, "course_specification_topics", $spec_id);
$assessmentRows = loadRows($conn, "course_specification_assessments", $spec_id);
$resourceRows = loadRows($conn, "course_specification_resources", $spec_id);
$facilityRows = loadRows($conn, "course_specification_facilities", $spec_id);
$qualityRows = loadRows($conn, "course_specification_quality_assessment", $spec_id);
$teachingModeRows = loadRows($conn, "course_specification_teaching_modes", $spec_id);
$contactHourRows = loadRows($conn, "course_specification_contact_hours", $spec_id);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Course Specification Details</title>
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 90px;
        }

        .button-row {
            margin-top: 18px;
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

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .actions form {
            display: inline-block;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Manage Course Specification Details</h1>
        <p>Add CLOs, teaching strategies, assessment methods, topics, resources, facilities, quality assessment, teaching modes, and contact hours.</p>
    </div>

    <?php if ($message !== ""): ?>
        <div class="alert-success">
            <?php echo safeText($message); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Course Specification</h2>

        <table>
            <tr>
                <th>Course</th>
                <td>
                    <strong><?php echo safeText($spec["course_code"]); ?></strong>
                    -
                    <?php echo safeText($spec["course_title"]); ?>
                </td>
            </tr>

            <tr>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"]); ?></td>
            </tr>

            <tr>
                <th>Department</th>
                <td><?php echo safeText($spec["department_name"]); ?></td>
            </tr>

            <tr>
                <th>Version</th>
                <td><?php echo safeText($spec["specification_version"]); ?></td>
            </tr>
        </table>

        <a href="course_specifications.php" class="btn btn-secondary">Back to Course Specifications</a>
        <a href="edit_course_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-edit">Edit Main Specification</a>
        <a href="view_course_specification.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">View Specification</a>
    </div>

    <div class="panel">
        <h2>B. Course Learning Outcomes, Teaching Strategies and Assessment Methods</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_clo">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>CLO Code</label>
                    <input type="text" name="clo_code" placeholder="Example: 1.1">
                </div>

                <div>
                    <label>CLO Domain</label>
                    <input type="text" name="clo_domain" placeholder="Knowledge / Skills / Values">
                </div>

                <div>
                    <label>Aligned PLO Code</label>
                    <input type="text" name="aligned_plo_code" placeholder="Example: AKI-1">
                </div>

                <div>
                    <label>Teaching Strategies</label>
                    <textarea name="teaching_strategies" placeholder="Interactive lecturing, classroom activities, group work discussion"></textarea>
                </div>

                <div>
                    <label>Assessment Methods</label>
                    <textarea name="assessment_methods" placeholder="Assignments, examinations, presentations"></textarea>
                </div>
            </div>

            <label>CLO Text</label>
            <textarea name="clo_text" required></textarea>

            <div class="button-row">
                <button type="submit" class="btn">Add CLO</button>
            </div>
        </form>

        <h3>Saved CLOs</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>Code</th>
                <th>Domain</th>
                <th>CLO</th>
                <th>PLO</th>
                <th>Teaching</th>
                <th>Assessment</th>
                <th>Delete</th>
            </tr>

            <?php if (count($cloRows) > 0): ?>
                <?php foreach ($cloRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo safeText($row["clo_code"]); ?></td>
                        <td><?php echo safeText($row["clo_domain"]); ?></td>
                        <td><?php echo nl2br(safeText($row["clo_text"])); ?></td>
                        <td><?php echo safeText($row["aligned_plo_code"]); ?></td>
                        <td><?php echo nl2br(safeText($row["teaching_strategies"])); ?></td>
                        <td><?php echo nl2br(safeText($row["assessment_methods"])); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_clos", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8">No CLOs added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>C. Course Content</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_topic">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>Topic No.</label>
                    <input type="text" name="topic_no" placeholder="Example: 1">
                </div>

                <div>
                    <label>Contact Hours</label>
                    <input type="number" step="0.5" name="contact_hours" value="0">
                </div>
            </div>

            <label>Topic Title</label>
            <textarea name="topic_title" required></textarea>

            <div class="button-row">
                <button type="submit" class="btn">Add Topic</button>
            </div>
        </form>

        <h3>Saved Topics</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>No.</th>
                <th>Topic</th>
                <th>Hours</th>
                <th>Delete</th>
            </tr>

            <?php if (count($topicRows) > 0): ?>
                <?php foreach ($topicRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo safeText($row["topic_no"]); ?></td>
                        <td><?php echo nl2br(safeText($row["topic_title"])); ?></td>
                        <td><?php echo safeText($row["contact_hours"]); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_topics", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No topics added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>D. Students Assessment Activities</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_assessment">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>No.</label>
                    <input type="text" name="assessment_no" placeholder="Example: 1">
                </div>

                <div>
                    <label>Assessment Activity</label>
                    <input type="text" name="assessment_activity" required placeholder="Example: Quiz">
                </div>

                <div>
                    <label>Assessment Timing</label>
                    <input type="text" name="assessment_timing" placeholder="Example: 3-5">
                </div>

                <div>
                    <label>Percentage</label>
                    <input type="text" name="percentage" placeholder="Example: 10%">
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Add Assessment Activity</button>
            </div>
        </form>

        <h3>Saved Assessment Activities</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>No.</th>
                <th>Activity</th>
                <th>Timing</th>
                <th>Percentage</th>
                <th>Delete</th>
            </tr>

            <?php if (count($assessmentRows) > 0): ?>
                <?php foreach ($assessmentRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo safeText($row["assessment_no"]); ?></td>
                        <td><?php echo safeText($row["assessment_activity"]); ?></td>
                        <td><?php echo safeText($row["assessment_timing"]); ?></td>
                        <td><?php echo safeText($row["percentage"]); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_assessments", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6">No assessment activities added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>E. Learning Resources</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_resource">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>Resource Type</label>
                    <select name="resource_type" required>
                        <option value="">-- Select --</option>
                        <option value="Essential References">Essential References</option>
                        <option value="Supportive References">Supportive References</option>
                        <option value="Electronic Materials">Electronic Materials</option>
                        <option value="Other Learning Materials">Other Learning Materials</option>
                    </select>
                </div>
            </div>

            <label>Resource Text</label>
            <textarea name="resource_text"></textarea>

            <div class="button-row">
                <button type="submit" class="btn">Add Resource</button>
            </div>
        </form>

        <h3>Saved Resources</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>Type</th>
                <th>Resource</th>
                <th>Delete</th>
            </tr>

            <?php if (count($resourceRows) > 0): ?>
                <?php foreach ($resourceRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo safeText($row["resource_type"]); ?></td>
                        <td><?php echo nl2br(safeText($row["resource_text"])); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_resources", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4">No resources added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Facilities and Equipment</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_facility">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>Facility Item</label>
                    <input type="text" name="facility_item" required placeholder="Facilities / Technology equipment / Other">
                </div>
            </div>

            <label>Resources</label>
            <textarea name="facility_resources"></textarea>

            <div class="button-row">
                <button type="submit" class="btn">Add Facility</button>
            </div>
        </form>

        <h3>Saved Facilities</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>Item</th>
                <th>Resources</th>
                <th>Delete</th>
            </tr>

            <?php if (count($facilityRows) > 0): ?>
                <?php foreach ($facilityRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo safeText($row["facility_item"]); ?></td>
                        <td><?php echo nl2br(safeText($row["facility_resources"])); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_facilities", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4">No facilities added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>F. Assessment of Course Quality</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_quality">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>Assessment Area / Issue</label>
                    <input type="text" name="assessment_area" required>
                </div>

                <div>
                    <label>Assessor</label>
                    <input type="text" name="assessor" placeholder="Students / Course Review Committee">
                </div>

                <div>
                    <label>Assessment Methods</label>
                    <input type="text" name="assessment_methods" placeholder="Direct / Indirect">
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Add Quality Assessment Row</button>
            </div>
        </form>

        <h3>Saved Quality Assessment Rows</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>Assessment Area</th>
                <th>Assessor</th>
                <th>Method</th>
                <th>Delete</th>
            </tr>

            <?php if (count($qualityRows) > 0): ?>
                <?php foreach ($qualityRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo nl2br(safeText($row["assessment_area"])); ?></td>
                        <td><?php echo safeText($row["assessor"]); ?></td>
                        <td><?php echo safeText($row["assessment_methods"]); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_quality_assessment", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No quality assessment rows added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Teaching Mode</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_teaching_mode">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>Mode of Instruction</label>
                    <input type="text" name="mode_of_instruction" required placeholder="Traditional classroom / E-learning / Hybrid">
                </div>

                <div>
                    <label>Contact Hours</label>
                    <input type="number" step="0.5" name="contact_hours" value="0">
                </div>

                <div>
                    <label>Percentage</label>
                    <input type="text" name="percentage" placeholder="Example: 100%">
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Add Teaching Mode</button>
            </div>
        </form>

        <h3>Saved Teaching Modes</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>Mode</th>
                <th>Hours</th>
                <th>Percentage</th>
                <th>Delete</th>
            </tr>

            <?php if (count($teachingModeRows) > 0): ?>
                <?php foreach ($teachingModeRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo safeText($row["mode_of_instruction"]); ?></td>
                        <td><?php echo safeText($row["contact_hours"]); ?></td>
                        <td><?php echo safeText($row["percentage"]); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_teaching_modes", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No teaching modes added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Contact Hours</h2>

        <form method="POST" action="manage_course_specification_details.php?id=<?php echo safeText($spec_id); ?>">
            <input type="hidden" name="action" value="add_contact_hour">

            <div class="form-grid">
                <div>
                    <label>Display Order</label>
                    <input type="number" name="display_order" value="0">
                </div>

                <div>
                    <label>Activity</label>
                    <input type="text" name="activity" required placeholder="Lectures / Laboratory / Field / Tutorial / Others">
                </div>

                <div>
                    <label>Contact Hours</label>
                    <input type="number" step="0.5" name="contact_hours" value="0">
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Add Contact Hour</button>
            </div>
        </form>

        <h3>Saved Contact Hours</h3>

        <table>
            <tr>
                <th>Order</th>
                <th>Activity</th>
                <th>Hours</th>
                <th>Delete</th>
            </tr>

            <?php if (count($contactHourRows) > 0): ?>
                <?php foreach ($contactHourRows as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["display_order"]); ?></td>
                        <td><?php echo safeText($row["activity"]); ?></td>
                        <td><?php echo safeText($row["contact_hours"]); ?></td>
                        <td class="actions"><?php deleteButton("course_specification_contact_hours", $row["id"], $spec_id); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4">No contact hours added yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>