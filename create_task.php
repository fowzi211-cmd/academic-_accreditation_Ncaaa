<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: /tasks/create', true, 302);
    exit;
}

$error = "";

// ===============================
// Helper functions
// ===============================
function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE :table_name");
        $stmt->execute([":table_name" => $tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) ? true : false;
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
// Check related task columns
// ===============================
$hasRelatedModule = columnExists($conn, "tasks", "related_module");
$hasRelatedRecordId = columnExists($conn, "tasks", "related_record_id");

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
// Handle form submission
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
        $columns = [
            "task_title",
            "task_description",
            "substandard_id",
            "evidence_id",
            "responsible_person",
            "due_date",
            "priority",
            "status",
            "notes"
        ];

        $placeholders = [
            ":task_title",
            ":task_description",
            ":substandard_id",
            ":evidence_id",
            ":responsible_person",
            ":due_date",
            ":priority",
            ":status",
            ":notes"
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
            ":notes" => $notes
        ];

        if ($hasRelatedModule) {
            $columns[] = "related_module";
            $placeholders[] = ":related_module";
            $params[":related_module"] = $related_module !== "" ? $related_module : null;
        }

        if ($hasRelatedRecordId) {
            $columns[] = "related_record_id";
            $placeholders[] = ":related_record_id";
            $params[":related_record_id"] = $related_record_id;
        }

        $sql = "
            INSERT INTO tasks
            (`" . implode("`, `", $columns) . "`)
            VALUES
            (" . implode(", ", $placeholders) . ")
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $newTaskId = $conn->lastInsertId();

        logActivitySafe(
            $conn,
            "Create",
            "Tasks",
            $newTaskId,
            "Task created: " . $task_title
        );

        header("Location: tasks.php");
        exit;
    }
}

// ===============================
// Preserve submitted values after validation error
// ===============================
$old = $_POST;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Task</title>
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
        <h1>Add New Task</h1>
        <p>Create a quality improvement task linked to accreditation items, evidence, or Course Reports.</p>
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

        <form method="POST" action="create_task.php" class="task-form">

            <div class="form-section">
                <h2>Task Information</h2>

                <label>Task Title</label>
                <input type="text"
                       name="task_title"
                       required
                       value="<?php echo safeText($old["task_title"] ?? ""); ?>"
                       placeholder="Example: Upload course reports for Semester 1">

                <label>Task Description</label>
                <textarea name="task_description" placeholder="Describe what needs to be done."><?php echo safeText($old["task_description"] ?? ""); ?></textarea>
            </div>

            <div class="form-section">
                <h2>Linked Accreditation Items</h2>

                <label>Linked Substandard</label>
                <select name="substandard_id">
                    <option value="">-- Optional: Select Substandard --</option>

                    <?php foreach ($substandards as $sub): ?>
                        <option value="<?php echo safeText($sub["id"]); ?>"
                            <?php if (($old["substandard_id"] ?? "") == $sub["id"]) echo "selected"; ?>>
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
                    Use this when the task is linked to an accreditation requirement.
                </div>

                <label>Linked Evidence Item</label>
                <select name="evidence_id">
                    <option value="">-- Optional: Select Evidence --</option>

                    <?php foreach ($evidenceItems as $evidence): ?>
                        <option value="<?php echo safeText($evidence["id"]); ?>"
                            <?php if (($old["evidence_id"] ?? "") == $evidence["id"]) echo "selected"; ?>>
                            <?php echo safeText($evidence["id"] . " - " . $evidence["title"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="hint">
                    Use this when the task is linked to a specific evidence item.
                </div>
            </div>

            <div class="form-section">
                <h2>Related Quality Record</h2>

                <div class="form-grid">
                    <div>
                        <label>Related Module</label>
                        <select name="related_module">
                            <option value="">-- Optional: No related module --</option>
                            <option value="Course Reports"
                                <?php if (($old["related_module"] ?? "") === "Course Reports") echo "selected"; ?>>
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
                                    <?php if (($old["related_record_id"] ?? "") == $report["id"]) echo "selected"; ?>>
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
                        <input type="text"
                               name="responsible_person"
                               value="<?php echo safeText($old["responsible_person"] ?? ""); ?>"
                               placeholder="Example: Quality Coordinator">
                    </div>

                    <div>
                        <label>Due Date</label>
                        <input type="date"
                               name="due_date"
                               value="<?php echo safeText($old["due_date"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Priority</label>
                        <select name="priority">
                            <?php
                            $priorities = ["Low", "Medium", "High"];
                            $oldPriority = $old["priority"] ?? "Medium";
                            foreach ($priorities as $priority):
                            ?>
                                <option value="<?php echo safeText($priority); ?>"
                                    <?php if ($oldPriority === $priority) echo "selected"; ?>>
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
                            $oldStatus = $old["status"] ?? "Pending";
                            foreach ($statuses as $status):
                            ?>
                                <option value="<?php echo safeText($status); ?>"
                                    <?php if ($oldStatus === $status) echo "selected"; ?>>
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
                <textarea name="notes" placeholder="Any additional notes."><?php echo safeText($old["notes"] ?? ""); ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Task</button>
                <a href="tasks.php" class="btn btn-secondary">Back to Tasks</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>
