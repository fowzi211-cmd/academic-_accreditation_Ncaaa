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
    die("Invalid recommendation ID.");
}

$recommendation_id = (int)$_GET["id"];
$error = "";

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

if (!tableExists($conn, "committee_recommendations")) {
    die("committee_recommendations table does not exist.");
}

if (!tableExists($conn, "tasks")) {
    die("tasks table does not exist.");
}

if (!columnExists($conn, "committee_recommendations", "linked_task_id")) {
    die("linked_task_id column is missing from committee_recommendations table.");
}

$stmt = $conn->prepare("
    SELECT
        r.*,
        c.committee_name,
        c.committee_type,
        c.academic_year,
        m.meeting_title,
        m.meeting_date,
        ps.full_name AS responsible_name,
        ps.email AS responsible_email
    FROM committee_recommendations r
    LEFT JOIN committees c ON r.committee_id = c.id
    LEFT JOIN committee_meetings m ON r.meeting_id = m.id
    LEFT JOIN program_staff ps ON r.responsible_staff_id = ps.id
    WHERE r.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $recommendation_id]);
$recommendation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recommendation) {
    die("Committee recommendation not found.");
}

$meeting_id = (int)($recommendation["meeting_id"] ?? 0);
$committee_id = (int)($recommendation["committee_id"] ?? 0);

if (!empty($recommendation["linked_task_id"])) {
    header("Location: edit_task.php?id=" . (int)$recommendation["linked_task_id"]);
    exit;
}

$hasRelatedModule = columnExists($conn, "tasks", "related_module");
$hasRelatedRecordId = columnExists($conn, "tasks", "related_record_id");
$hasEvidenceId = columnExists($conn, "tasks", "evidence_id");

$defaultTitle = "Follow up committee recommendation";

if (!empty($recommendation["committee_name"])) {
    $defaultTitle .= ": " . $recommendation["committee_name"];
}

$defaultDescription =
    "Committee Recommendation Follow-up\n\n" .
    "Committee: " . ($recommendation["committee_name"] ?? "") . "\n" .
    "Meeting: " . ($recommendation["meeting_title"] ?? "") . "\n" .
    "Meeting Date: " . ($recommendation["meeting_date"] ?? "") . "\n\n" .
    "Recommendation:\n" . ($recommendation["recommendation_text"] ?? "") . "\n\n" .
    "Follow-up Notes:\n" . ($recommendation["followup_notes"] ?? "");

$form = [
    "task_title" => $defaultTitle,
    "task_description" => $defaultDescription,
    "responsible_person" => $recommendation["responsible_name"] ?? "",
    "due_date" => $recommendation["target_date"] ?? "",
    "priority" => $recommendation["priority"] ?? "Medium",
    "status" => "Pending",
    "notes" => "Created from committee recommendation ID: " . $recommendation_id
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["task_title"] === "") {
        $error = "Task title is required.";
    } else {
        $columns = [
            "task_title",
            "task_description",
            "responsible_person",
            "due_date",
            "priority",
            "status",
            "notes"
        ];

        $placeholders = [
            ":task_title",
            ":task_description",
            ":responsible_person",
            ":due_date",
            ":priority",
            ":status",
            ":notes"
        ];

        $params = [
            ":task_title" => $form["task_title"],
            ":task_description" => $form["task_description"] !== "" ? $form["task_description"] : null,
            ":responsible_person" => $form["responsible_person"] !== "" ? $form["responsible_person"] : null,
            ":due_date" => $form["due_date"] !== "" ? $form["due_date"] : null,
            ":priority" => $form["priority"] !== "" ? $form["priority"] : "Medium",
            ":status" => $form["status"] !== "" ? $form["status"] : "Pending",
            ":notes" => $form["notes"] !== "" ? $form["notes"] : null
        ];

        if ($hasEvidenceId && !empty($recommendation["linked_evidence_id"])) {
            $columns[] = "evidence_id";
            $placeholders[] = ":evidence_id";
            $params[":evidence_id"] = (int)$recommendation["linked_evidence_id"];
        }

        if ($hasRelatedModule) {
            $columns[] = "related_module";
            $placeholders[] = ":related_module";
            $params[":related_module"] = "Committee Recommendation";
        }

        if ($hasRelatedRecordId) {
            $columns[] = "related_record_id";
            $placeholders[] = ":related_record_id";
            $params[":related_record_id"] = $recommendation_id;
        }

        $insertSql = "
            INSERT INTO tasks
            (" . implode(", ", $columns) . ")
            VALUES
            (" . implode(", ", $placeholders) . ")
        ";

        $insertStmt = $conn->prepare($insertSql);
        $insertStmt->execute($params);

        $newTaskId = (int)$conn->lastInsertId();

        $updateStmt = $conn->prepare("
            UPDATE committee_recommendations
            SET
                linked_task_id = :linked_task_id,
                status = CASE
                    WHEN status = 'Open' THEN 'In Progress'
                    ELSE status
                END
            WHERE id = :id
        ");
        $updateStmt->execute([
            ":linked_task_id" => $newTaskId,
            ":id" => $recommendation_id
        ]);

        logActivitySafe(
            $conn,
            "Create",
            "Committee Recommendation Follow-up Task",
            $newTaskId,
            "Task created from committee recommendation ID: " . $recommendation_id
        );

        header("Location: edit_task.php?id=" . $newTaskId);
        exit;
    }
}

$priorityOptions = ["High", "Medium", "Low"];
$statusOptions = ["Pending", "In Progress", "Completed", "Cancelled"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create Follow-up Task</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 150px;
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
        <h1>Create Recommendation Follow-up Task</h1>
        <p>Convert a committee recommendation into a formal system task.</p>
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
        <h2>Source Recommendation</h2>

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
                <th>Responsible</th>
                <td><?php echo safeText($recommendation["responsible_name"]); ?></td>
            </tr>

            <tr>
                <th>Recommendation</th>
                <td colspan="3"><?php echo nl2br(safeText($recommendation["recommendation_text"])); ?></td>
            </tr>

            <tr>
                <th>Priority</th>
                <td><?php echo safeText($recommendation["priority"]); ?></td>
                <th>Target Date</th>
                <td><?php echo safeText($recommendation["target_date"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Task Details</h2>

        <form method="POST" action="committee_recommendation_followup_task.php?id=<?php echo safeText($recommendation_id); ?>">

            <label>Task Title <span style="color:red;">*</span></label>
            <input type="text" name="task_title" required value="<?php echo safeText($form["task_title"]); ?>">

            <label>Task Description</label>
            <textarea name="task_description"><?php echo safeText($form["task_description"]); ?></textarea>

            <div class="form-grid">
                <div>
                    <label>Responsible Person</label>
                    <input type="text" name="responsible_person" value="<?php echo safeText($form["responsible_person"]); ?>">
                </div>

                <div>
                    <label>Due Date</label>
                    <input type="date" name="due_date" value="<?php echo safeText($form["due_date"]); ?>">
                </div>

                <div>
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach ($priorityOptions as $priority): ?>
                            <option value="<?php echo safeText($priority); ?>" <?php if ($form["priority"] === $priority) echo "selected"; ?>>
                                <?php echo safeText($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>" <?php if ($form["status"] === $status) echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label>Notes</label>
            <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>

            <br>

            <button type="submit" class="btn">
                Create Follow-up Task
            </button>

            <a href="committee_meeting_profile.php?id=<?php echo safeText($meeting_id); ?>" class="btn btn-secondary">
                Cancel
            </a>

        </form>
    </div>

</div>

</body>
</html>