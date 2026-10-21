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
// Validate recommendation ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid recommendation ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Helper functions
// ===============================
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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

// ===============================
// Required tables
// ===============================
if (!tableExists($conn, "committee_recommendations")) {
    die("committee_recommendations table does not exist.");
}

if (!tableExists($conn, "committee_meetings")) {
    die("committee_meetings table does not exist.");
}

if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist.");
}

// ===============================
// Load recommendation
// ===============================
$stmt = $conn->prepare("
    SELECT
        r.*,
        m.meeting_title,
        m.meeting_date,
        c.committee_name,
        c.committee_type,
        c.academic_year
    FROM committee_recommendations r
    INNER JOIN committee_meetings m ON r.meeting_id = m.id
    INNER JOIN committees c ON r.committee_id = c.id
    WHERE r.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$recommendation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recommendation) {
    die("Committee recommendation not found.");
}

$meeting_id = (int)$recommendation["meeting_id"];
$committee_id = (int)$recommendation["committee_id"];

// ===============================
// Load active staff
// ===============================
$staffStmt = $conn->query("
    SELECT id, full_name, academic_rank, role_in_program, email
    FROM program_staff
    WHERE employment_status = 'Active'
    ORDER BY full_name ASC
");
$staffRows = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load evidence items
// ===============================
$evidenceRows = [];

if (tableExists($conn, "evidence_items")) {
    $evidenceStmt = $conn->query("
        SELECT id, title, status, review_status
        FROM evidence_items
        ORDER BY id DESC
        LIMIT 500
    ");
    $evidenceRows = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Check linked_task_id column exists
// ===============================
$hasLinkedTaskId = columnExists($conn, "committee_recommendations", "linked_task_id");

// ===============================
// Load tasks if available
// ===============================
$taskRows = [];

if ($hasLinkedTaskId && tableExists($conn, "tasks")) {
    $taskStmt = $conn->query("
        SELECT id, task_title, status, priority, due_date
        FROM tasks
        ORDER BY id DESC
        LIMIT 500
    ");
    $taskRows = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Form values
// ===============================
$form = [
    "recommendation_text" => $recommendation["recommendation_text"] ?? "",
    "responsible_staff_id" => $recommendation["responsible_staff_id"] ?? "",
    "target_date" => $recommendation["target_date"] ?? "",
    "priority" => $recommendation["priority"] ?? "Medium",
    "status" => $recommendation["status"] ?? "Open",
    "linked_task_id" => $recommendation["linked_task_id"] ?? "",
    "linked_evidence_id" => $recommendation["linked_evidence_id"] ?? "",
    "followup_notes" => $recommendation["followup_notes"] ?? ""
];

// ===============================
// Handle submit
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    $responsible_staff_id = $form["responsible_staff_id"] !== "" ? (int)$form["responsible_staff_id"] : null;
    $linked_evidence_id = $form["linked_evidence_id"] !== "" ? (int)$form["linked_evidence_id"] : null;
    $linked_task_id = $form["linked_task_id"] !== "" ? (int)$form["linked_task_id"] : null;

    if ($form["recommendation_text"] === "") {
        $error = "Recommendation text is required.";
    } else {
        if ($hasLinkedTaskId) {
            $updateStmt = $conn->prepare("
                UPDATE committee_recommendations
                SET
                    recommendation_text = :recommendation_text,
                    responsible_staff_id = :responsible_staff_id,
                    target_date = :target_date,
                    priority = :priority,
                    status = :status,
                    linked_task_id = :linked_task_id,
                    linked_evidence_id = :linked_evidence_id,
                    followup_notes = :followup_notes
                WHERE id = :id
            ");

            $updateStmt->execute([
                ":recommendation_text" => $form["recommendation_text"],
                ":responsible_staff_id" => $responsible_staff_id,
                ":target_date" => $form["target_date"] !== "" ? $form["target_date"] : null,
                ":priority" => $form["priority"] !== "" ? $form["priority"] : "Medium",
                ":status" => $form["status"] !== "" ? $form["status"] : "Open",
                ":linked_task_id" => $linked_task_id,
                ":linked_evidence_id" => $linked_evidence_id,
                ":followup_notes" => $form["followup_notes"] !== "" ? $form["followup_notes"] : null,
                ":id" => $id
            ]);
        } else {
            $updateStmt = $conn->prepare("
                UPDATE committee_recommendations
                SET
                    recommendation_text = :recommendation_text,
                    responsible_staff_id = :responsible_staff_id,
                    target_date = :target_date,
                    priority = :priority,
                    status = :status,
                    linked_evidence_id = :linked_evidence_id,
                    followup_notes = :followup_notes
                WHERE id = :id
            ");

            $updateStmt->execute([
                ":recommendation_text" => $form["recommendation_text"],
                ":responsible_staff_id" => $responsible_staff_id,
                ":target_date" => $form["target_date"] !== "" ? $form["target_date"] : null,
                ":priority" => $form["priority"] !== "" ? $form["priority"] : "Medium",
                ":status" => $form["status"] !== "" ? $form["status"] : "Open",
                ":linked_evidence_id" => $linked_evidence_id,
                ":followup_notes" => $form["followup_notes"] !== "" ? $form["followup_notes"] : null,
                ":id" => $id
            ]);
        }

        logActivitySafe(
            $conn,
            "Update",
            "Committee Recommendations",
            $id,
            "Committee recommendation updated. Committee: " . $recommendation["committee_name"]
        );

        header("Location: committee_meeting_profile.php?id=" . $meeting_id);
        exit;
    }
}

$priorityOptions = ["High", "Medium", "Low"];
$statusOptions = ["Open", "In Progress", "Completed", "Closed", "Cancelled"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Committee Recommendation</title>
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
            min-height: 130px;
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

        .small-text {
            font-size: 12px;
            color: #555;
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
        <h1>Edit Committee Recommendation</h1>
        <p>Update recommendation responsibility, priority, status, evidence link, and follow-up notes.</p>
    </div>

    <div class="actions">
        <a href="committee_meeting_profile.php?id=<?php echo safeText($meeting_id); ?>" class="btn btn-secondary">
            Back to Meeting
        </a>

        <a href="committee_profile.php?id=<?php echo safeText($committee_id); ?>" class="btn">
            Committee Profile
        </a>

        <a href="committees.php" class="btn">
            Committees
        </a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Source Meeting</h2>

        <table>
            <tr>
                <th>Committee</th>
                <td><?php echo safeText($recommendation["committee_name"]); ?></td>
                <th>Meeting</th>
                <td><?php echo safeText($recommendation["meeting_title"]); ?></td>
            </tr>

            <tr>
                <th>Meeting Date</th>
                <td><?php echo safeText($recommendation["meeting_date"]); ?></td>
                <th>Academic Year</th>
                <td><?php echo safeText($recommendation["academic_year"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <form method="POST" action="edit_committee_recommendation.php?id=<?php echo safeText($id); ?>">

            <h2>Recommendation Details</h2>

            <label>Recommendation Text <span style="color:red;">*</span></label>
            <textarea name="recommendation_text" required><?php echo safeText($form["recommendation_text"]); ?></textarea>

            <div class="form-grid">
                <div>
                    <label>Responsible Staff</label>
                    <select name="responsible_staff_id">
                        <option value="">-- Not assigned --</option>

                        <?php foreach ($staffRows as $staff): ?>
                            <option value="<?php echo safeText($staff["id"]); ?>"
                                <?php if ((string)$form["responsible_staff_id"] === (string)$staff["id"]) echo "selected"; ?>>
                                <?php
                                echo safeText(
                                    $staff["full_name"] .
                                    (!empty($staff["academic_rank"]) ? " | " . $staff["academic_rank"] : "") .
                                    (!empty($staff["role_in_program"]) ? " | " . $staff["role_in_program"] : "")
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Target Date</label>
                    <input type="date" name="target_date" value="<?php echo safeText($form["target_date"]); ?>">
                </div>

                <div>
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach ($priorityOptions as $priority): ?>
                            <option value="<?php echo safeText($priority); ?>"
                                <?php if ($form["priority"] === $priority) echo "selected"; ?>>
                                <?php echo safeText($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>"
                                <?php if ($form["status"] === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($hasLinkedTaskId): ?>
                    <div>
                        <label>Linked System Task</label>
                        <select name="linked_task_id">
                            <option value="">-- Not linked --</option>

                            <?php foreach ($taskRows as $task): ?>
                                <option value="<?php echo safeText($task["id"]); ?>"
                                    <?php if ((string)$form["linked_task_id"] === (string)$task["id"]) echo "selected"; ?>>
                                    <?php
                                    echo safeText(
                                        $task["id"] . " - " .
                                        $task["task_title"] . " | " .
                                        $task["status"] . " | " .
                                        $task["priority"]
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div>
                    <label>Linked Evidence</label>
                    <select name="linked_evidence_id">
                        <option value="">-- Not linked --</option>

                        <?php foreach ($evidenceRows as $evidence): ?>
                            <option value="<?php echo safeText($evidence["id"]); ?>"
                                <?php if ((string)$form["linked_evidence_id"] === (string)$evidence["id"]) echo "selected"; ?>>
                                <?php
                                echo safeText(
                                    $evidence["id"] . " - " .
                                    $evidence["title"] . " | " .
                                    $evidence["status"] . " | " .
                                    $evidence["review_status"]
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="small-text">
                        Optional. Link the evidence that proves the recommendation was implemented.
                    </div>
                </div>
            </div>

            <br>

            <label>Follow-up Notes</label>
            <textarea name="followup_notes"><?php echo safeText($form["followup_notes"]); ?></textarea>

            <br>

            <button type="submit" class="btn">Update Recommendation</button>

            <a href="committee_meeting_profile.php?id=<?php echo safeText($meeting_id); ?>" class="btn btn-secondary">
                Cancel
            </a>

        </form>
    </div>

</div>

</body>
</html>