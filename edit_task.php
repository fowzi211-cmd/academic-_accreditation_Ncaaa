<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
    header('Location: /tasks/' . (int)$_GET['id'] . '/edit', true, 302);
    exit;
}

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid task ID.");
}

$id = (int)$_GET["id"];
$error = "";

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

function logTaskStatusChange($conn, $taskId, $oldStatus, $newStatus, $changedBy, $source, $comment = "") {
    if (!tableExists($conn, "task_status_logs")) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO task_status_logs
        (
            task_id,
            old_status,
            new_status,
            changed_by,
            change_source,
            change_comment
        )
        VALUES
        (
            :task_id,
            :old_status,
            :new_status,
            :changed_by,
            :change_source,
            :change_comment
        )
    ");

    $stmt->execute([
        ":task_id" => $taskId,
        ":old_status" => $oldStatus,
        ":new_status" => $newStatus,
        ":changed_by" => $changedBy,
        ":change_source" => $source,
        ":change_comment" => $comment
    ]);
}

// ===============================
// Check optional related columns
// ===============================
$hasRelatedModule = columnExists($conn, "tasks", "related_module");
$hasRelatedRecordId = columnExists($conn, "tasks", "related_record_id");

// ===============================
// Load current task
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM tasks
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    die("Task not found.");
}

// ===============================
// Restrict Faculty to tasks assigned to them
// ===============================
$loggedUserName = $_SESSION["full_name"] ?? "";

if (!hasRole(["Admin", "Quality Coordinator", "Program Leader"])) {
    if (stripos($task["responsible_person"] ?? "", $loggedUserName) === false) {
        die("Access denied. You can only edit tasks assigned to you.");
    }
}

// ===============================
// Load substandards for dropdown
// ===============================
$substandards = [];

if (tableExists($conn, "substandards") && tableExists($conn, "standards")) {
    $substandardStmt = $conn->query("
        SELECT 
            ss.id,
            ss.substandard_code,
            ss.substandard_text,
            s.standard_code,
            s.standard_title
        FROM substandards ss
        INNER JOIN standards s ON ss.standard_id = s.id
        ORDER BY s.id, ss.substandard_code
    ");
    $substandards = $substandardStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load evidence items for dropdown
// ===============================
$evidenceItems = [];

if (tableExists($conn, "evidence_items")) {
    $evidenceStmt = $conn->query("
        SELECT 
            id,
            title
        FROM evidence_items
        ORDER BY id DESC
    ");
    $evidenceItems = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load course reports for related record dropdown
// ===============================
$courseReports = [];

if (tableExists($conn, "course_reports")) {
    $courseReportStmt = $conn->query("
        SELECT 
            id,
            course_code,
            course_title,
            academic_year,
            semester,
            status
        FROM course_reports
        ORDER BY id DESC
    ");
    $courseReports = $courseReportStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Handle update
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $task_title = trim($_POST["task_title"] ?? "");
    $task_description = trim($_POST["task_description"] ?? "");
    $substandard_id = !empty($_POST["substandard_id"]) ? (int)$_POST["substandard_id"] : null;
    $evidence_id = !empty($_POST["evidence_id"]) ? (int)$_POST["evidence_id"] : null;
    $responsible_person = trim($_POST["responsible_person"] ?? "");
    $due_date = !empty($_POST["due_date"]) ? $_POST["due_date"] : null;
    $priority = trim($_POST["priority"] ?? "Medium");
    $status = trim($_POST["status"] ?? "Pending");
    $notes = trim($_POST["notes"] ?? "");

    $related_module = trim($_POST["related_module"] ?? "");
    $related_record_id = !empty($_POST["related_record_id"]) ? (int)$_POST["related_record_id"] : null;

    $allowedPriorities = ["Low", "Medium", "High"];
    $allowedStatuses = ["Pending", "In Progress", "Completed", "Cancelled"];
    $allowedRelatedModules = ["", "Course Reports"];

    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = "Medium";
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $status = "Pending";
    }

    if (!in_array($related_module, $allowedRelatedModules, true)) {
        $related_module = "";
        $related_record_id = null;
    }

    if ($related_module === "") {
        $related_record_id = null;
    }

    if ($task_title === "") {
    $error = "Task title is required.";
} elseif ($related_module === "Course Reports" && empty($related_record_id)) {
    $error = "Please select a Course Report when Related Module is Course Reports.";
} else {
    $old_status = $task["status"] ?? "";

        // Faculty should not reassign responsibility
        if (!hasRole(["Admin", "Quality Coordinator", "Program Leader"])) {
            $responsible_person = $task["responsible_person"];
        }

        $setParts = [
            "task_title = :task_title",
            "task_description = :task_description",
            "substandard_id = :substandard_id",
            "evidence_id = :evidence_id",
            "responsible_person = :responsible_person",
            "due_date = :due_date",
            "priority = :priority",
            "status = :status",
            "notes = :notes"
        ];

        $params = [
            ":task_title" => $task_title,
            ":task_description" => $task_description,
            ":substandard_id" => $substandard_id,
            ":evidence_id" => $evidence_id,
            ":responsible_person" => $responsible_person,
            ":due_date" => $due_date,
            ":priority" => $priority,
            ":status" => $status,
            ":notes" => $notes,
            ":id" => $id
        ];

        if ($hasRelatedModule) {
            $setParts[] = "related_module = :related_module";
            $params[":related_module"] = $related_module !== "" ? $related_module : null;
        }

        if ($hasRelatedRecordId) {
            $setParts[] = "related_record_id = :related_record_id";
            $params[":related_record_id"] = $related_record_id;
        }

        $sql = "
            UPDATE tasks
            SET " . implode(",\n                ", $setParts) . "
            WHERE id = :id
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ($old_status !== $status) {
    $changedBy = $_SESSION["full_name"] ?? ($_SESSION["username"] ?? "Unknown user");

    logTaskStatusChange(
        $conn,
        $id,
        $old_status,
        $status,
        $changedBy,
        "Edit Task",
        "Status changed from full task edit page."
    );
}

logActivitySafe(
    $conn,
    "Update",
    "Tasks",
    $id,
    "Task updated: " . $task_title . " | Status: " . $old_status . " to " . $status
);

header("Location: tasks.php");
exit;
    }
}

// ===============================
// Use POST values if validation failed, otherwise existing task
// ===============================
$formTask = $task;

if ($_SERVER["REQUEST_METHOD"] === "POST" && $error !== "") {
    foreach ($_POST as $key => $value) {
        $formTask[$key] = $value;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Task</title>
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

        .task-form {
            max-width: 950px;
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
            min-height: 110px;
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

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .task-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Edit Task</h1>
        <p>Update task details, linked accreditation items, related Course Report, responsibility, due date, priority, status, and notes.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasRelatedModule || !$hasRelatedRecordId): ?>
        <div class="warning-box">
            The task related-record columns are missing. To enable Course Report linking, add
            <strong>related_module</strong> and <strong>related_record_id</strong> to the tasks table.
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit_task.php?id=<?php echo safeText($id); ?>" class="task-form">

            <div class="form-section">
                <h2>Task Information</h2>

                <label>Task Title</label>
                <input type="text" name="task_title" required
                       value="<?php echo safeText($formTask["task_title"] ?? ""); ?>">

                <label>Task Description</label>
                <textarea name="task_description"><?php echo safeText($formTask["task_description"] ?? ""); ?></textarea>
            </div>

            <div class="form-section">
                <h2>Linked Accreditation Items</h2>

                <label>Linked Substandard</label>
                <select name="substandard_id">
                    <option value="">-- Optional: Select Substandard --</option>

                    <?php foreach ($substandards as $sub): ?>
                        <option value="<?php echo safeText($sub["id"]); ?>"
                            <?php if ((int)($formTask["substandard_id"] ?? 0) === (int)$sub["id"]) echo "selected"; ?>>
                            <?php
                                echo safeText(
                                    $sub["standard_code"] . " - " .
                                    $sub["substandard_code"] . " | " .
                                    $sub["substandard_text"]
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="hint">
                    The Standard is automatically linked through the selected Substandard.
                </div>

                <label>Linked Evidence Item</label>
                <select name="evidence_id">
                    <option value="">-- Optional: Select Evidence --</option>

                    <?php foreach ($evidenceItems as $evidence): ?>
                        <option value="<?php echo safeText($evidence["id"]); ?>"
                            <?php if ((int)($formTask["evidence_id"] ?? 0) === (int)$evidence["id"]) echo "selected"; ?>>
                            <?php echo safeText($evidence["id"] . " - " . $evidence["title"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-section">
                <h2>Related Quality Record</h2>

                <div class="form-grid">
                    <div>
                        <label>Related Module</label>
                        <select name="related_module">
                            <option value="">-- Optional: No related module --</option>
                            <option value="Course Reports"
                                <?php if (($formTask["related_module"] ?? "") === "Course Reports") echo "selected"; ?>>
                                Course Reports
                            </option>
                        </select>

                        <div class="hint">
                            Use this when the task is linked to a Course Report.
                        </div>
                    </div>

                    <div>
                        <label>Related Course Report</label>
                        <select name="related_record_id">
                            <option value="">-- Optional: Select Course Report --</option>

                            <?php foreach ($courseReports as $report): ?>
                                <option value="<?php echo safeText($report["id"]); ?>"
                                    <?php if ((int)($formTask["related_record_id"] ?? 0) === (int)$report["id"]) echo "selected"; ?>>
                                    <?php
                                        echo safeText(
                                            $report["id"] . " - " .
                                            ($report["course_code"] ?? "") . " - " .
                                            ($report["course_title"] ?? "") . " | " .
                                            ($report["academic_year"] ?? "") . " | " .
                                            ($report["semester"] ?? "") . " | " .
                                            ($report["status"] ?? "")
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="hint">
                            Select this only if Related Module is Course Reports.
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Responsibility and Status</h2>

                <div class="form-grid">
                    <div>
                        <label>Responsible Person</label>
                        <input type="text" name="responsible_person"
                               value="<?php echo safeText($formTask["responsible_person"] ?? ""); ?>"
                               <?php if (!hasRole(["Admin", "Quality Coordinator", "Program Leader"])) echo "readonly"; ?>>

                        <?php if (!hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <div class="hint">
                                Only leadership roles can reassign tasks.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Due Date</label>
                        <input type="date" name="due_date"
                               value="<?php echo safeText($formTask["due_date"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Priority</label>
                        <select name="priority">
                            <?php
                            $priorities = ["Low", "Medium", "High"];
                            foreach ($priorities as $priority):
                            ?>
                                <option value="<?php echo safeText($priority); ?>"
                                    <?php if (($formTask["priority"] ?? "") === $priority) echo "selected"; ?>>
                                    <?php echo safeText($priority); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <?php
                            $statuses = ["Pending", "In Progress", "Completed", "Cancelled"];
                            foreach ($statuses as $status):
                            ?>
                                <option value="<?php echo safeText($status); ?>"
                                    <?php if (($formTask["status"] ?? "") === $status) echo "selected"; ?>>
                                    <?php echo safeText($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Notes</h2>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($formTask["notes"] ?? ""); ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Update Task</button>
                <a href="tasks.php" class="btn btn-secondary">Back to Tasks</a>
                <a href="my_tasks.php" class="btn btn-purple">My Tasks</a>

                <?php if (($formTask["related_module"] ?? "") === "Course Reports" && !empty($formTask["related_record_id"])): ?>
                    <a href="view_course_report.php?id=<?php echo safeText($formTask["related_record_id"]); ?>" class="btn">
                        View Related Course Report
                    </a>
                <?php endif; ?>
            </div>

        </form>

    </div>

</div>

</body>
</html>
