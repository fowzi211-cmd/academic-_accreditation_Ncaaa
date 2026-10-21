<?php
require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

// ===============================
// Validate report ID
// ===============================
$report_id = 0;

if (isset($_GET["report_id"]) && is_numeric($_GET["report_id"])) {
    $report_id = (int)$_GET["report_id"];
} elseif (isset($_GET["id"]) && is_numeric($_GET["id"])) {
    $report_id = (int)$_GET["id"];
}

if ($report_id <= 0) {
    die("Invalid course report ID.");
}

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

function getUserOptions($conn) {
    if (!tableExists($conn, "users")) {
        return [];
    }

    $displayColumn = null;

    if (columnExists($conn, "users", "full_name")) {
        $displayColumn = "full_name";
    } elseif (columnExists($conn, "users", "name")) {
        $displayColumn = "name";
    } elseif (columnExists($conn, "users", "username")) {
        $displayColumn = "username";
    } elseif (columnExists($conn, "users", "email")) {
        $displayColumn = "email";
    }

    if ($displayColumn === null || !columnExists($conn, "users", "id")) {
        return [];
    }

    $stmt = $conn->query("
        SELECT id, `$displayColumn` AS display_name
        FROM users
        ORDER BY `$displayColumn` ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createTask(
    $conn,
    $title,
    $description,
    $assignedTo,
    $dueDate,
    $priority,
    $substandardId,
    $evidenceId,
    $relatedReportId
) {
    if (!tableExists($conn, "tasks")) {
        $dbStmt = $conn->query("SELECT DATABASE()");
        $dbName = $dbStmt->fetchColumn();

        return "Tasks table was not found in the current database connection. Current database: " . $dbName;
    }

    if (!columnExists($conn, "tasks", "task_title")) {
        return "tasks.task_title column does not exist.";
    }

    $columns = [];
    $placeholders = [];
    $params = [];

    $columns[] = "task_title";
    $placeholders[] = ":task_title";
    $params[":task_title"] = $title;

    if (columnExists($conn, "tasks", "task_description")) {
        $columns[] = "task_description";
        $placeholders[] = ":task_description";
        $params[":task_description"] = $description;
    }

    if (columnExists($conn, "tasks", "substandard_id")) {
        $columns[] = "substandard_id";
        $placeholders[] = ":substandard_id";
        $params[":substandard_id"] = $substandardId > 0 ? $substandardId : null;
    }

    if (columnExists($conn, "tasks", "evidence_id")) {
        $columns[] = "evidence_id";
        $placeholders[] = ":evidence_id";
        $params[":evidence_id"] = $evidenceId > 0 ? $evidenceId : null;
    }

    if (columnExists($conn, "tasks", "responsible_person")) {
        $columns[] = "responsible_person";
        $placeholders[] = ":responsible_person";
        $params[":responsible_person"] = $assignedTo !== "" ? $assignedTo : null;
    }

    if (columnExists($conn, "tasks", "due_date")) {
        $columns[] = "due_date";
        $placeholders[] = ":due_date";
        $params[":due_date"] = $dueDate !== "" ? $dueDate : null;
    }

    if (columnExists($conn, "tasks", "priority")) {
        $columns[] = "priority";
        $placeholders[] = ":priority";
        $params[":priority"] = $priority;
    }

    if (columnExists($conn, "tasks", "status")) {
        $columns[] = "status";
        $placeholders[] = ":status";
        $params[":status"] = "Pending";
    }

    if (columnExists($conn, "tasks", "notes")) {
        $columns[] = "notes";
        $placeholders[] = ":notes";
        $params[":notes"] = "Created from Course Report Follow-up Actions.";
    }

    if (columnExists($conn, "tasks", "related_module")) {
        $columns[] = "related_module";
        $placeholders[] = ":related_module";
        $params[":related_module"] = "Course Reports";
    }

    if (columnExists($conn, "tasks", "related_record_id")) {
        $columns[] = "related_record_id";
        $placeholders[] = ":related_record_id";
        $params[":related_record_id"] = $relatedReportId;
    }

    if (columnExists($conn, "tasks", "created_at")) {
        $columns[] = "created_at";
        $placeholders[] = "NOW()";
    }

    if (columnExists($conn, "tasks", "updated_at")) {
        $columns[] = "updated_at";
        $placeholders[] = "NOW()";
    }

    $sql = "
        INSERT INTO tasks
        (`" . implode("`, `", $columns) . "`)
        VALUES
        (" . implode(", ", $placeholders) . ")
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return "";
}

// ===============================
// Load course report
// ===============================
$stmt = $conn->prepare("
    SELECT 
        cr.*,
        cs.id AS spec_id,
        cs.course_code AS spec_course_code,
        cs.course_title AS spec_course_title,
        cs.specification_version AS spec_version
    FROM course_reports cr
    LEFT JOIN course_specifications cs
        ON cs.id = cr.course_specification_id
    WHERE cr.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die("Course report not found.");
}

// ===============================
// Load substandards
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
        ORDER BY s.id ASC, ss.substandard_code ASC
    ");
    $substandards = $substandardStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load evidence items
// ===============================
$evidenceItems = [];

if (tableExists($conn, "evidence_items")) {
    $evidenceStmt = $conn->query("
        SELECT 
            id,
            title,
            evidence_type,
            status
        FROM evidence_items
        ORDER BY id DESC
    ");
    $evidenceItems = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// URL suggested task data
// ===============================
$issue = trim($_GET["issue"] ?? "");
$recommended_action = trim($_GET["recommended_action"] ?? "");
$priority = trim($_GET["priority"] ?? "Medium");
$completion_rate = trim($_GET["completion_rate"] ?? "");

$allowedPriorities = ["High", "Medium", "Low"];

if (!in_array($priority, $allowedPriorities, true)) {
    $priority = "Medium";
}

$courseLabel = trim(($report["course_code"] ?? "") . " - " . ($report["course_title"] ?? ""), " -");

if ($issue === "") {
    $issue = "Course Report requires follow-up.";
}

if ($recommended_action === "") {
    $recommended_action = "Review the Course Report completion checklist and complete missing sections.";
}

$defaultTitle = "Follow up Course Report: " . $courseLabel;

$defaultDescription =
    "Course Report ID: " . $report_id . "\n" .
    "Course: " . $courseLabel . "\n" .
    "Academic Year: " . ($report["academic_year"] ?? "") . "\n" .
    "Semester: " . ($report["semester"] ?? "") . "\n" .
    "Issue: " . $issue . "\n" .
    "Recommended Action: " . $recommended_action . "\n";

if ($completion_rate !== "") {
    $defaultDescription .= "Completion Rate: " . $completion_rate . "%\n";
}

// ===============================
// Handle POST
// ===============================
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $assignedTo = trim($_POST["assigned_to"] ?? "");
    $dueDate = trim($_POST["due_date"] ?? "");
    $priority = trim($_POST["priority"] ?? "Medium");
    $substandardId = !empty($_POST["substandard_id"]) ? (int)$_POST["substandard_id"] : 0;
    $evidenceId = !empty($_POST["evidence_id"]) ? (int)$_POST["evidence_id"] : 0;

    if ($title === "") {
        $error = "Task title is required.";
    } elseif (!in_array($priority, $allowedPriorities, true)) {
        $error = "Invalid priority.";
    } else {
        $taskError = createTask(
            $conn,
            $title,
            $description,
            $assignedTo,
            $dueDate,
            $priority,
            $substandardId,
            $evidenceId,
            $report_id
        );

        if ($taskError !== "") {
            $error = $taskError;
        } else {
            logActivitySafe(
                $conn,
                "Create",
                "Course Report Follow-up Task",
                $report_id,
                "Created follow-up task: " . $title
            );

            header("Location: course_report_followup_actions.php?message=" . urlencode("Follow-up task created successfully."));
            exit;
        }
    }
}

$users = getUserOptions($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create Course Report Follow-up Task</title>
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

        .small-text,
        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
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
        <h1>Create Course Report Follow-up Task</h1>
        <p>Create a task linked to a Course Report, and optionally link it to the relevant Standard/Substandard and Evidence Item.</p>
    </div>

    <div class="actions">
        <a href="course_report_followup_actions.php" class="btn btn-secondary">Back to Follow-up Actions</a>
        <a href="course_report_completion_check.php?id=<?php echo safeText($report_id); ?>" class="btn">Completion Check</a>
        <a href="manage_course_report_details.php?id=<?php echo safeText($report_id); ?>" class="btn btn-edit">Manage Details</a>
        <a href="view_course_report.php?id=<?php echo safeText($report_id); ?>" class="btn btn-purple">View Report</a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

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
                <th>Coordinator</th>
                <td><?php echo safeText($report["course_coordinator"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Linked Specification</th>
                <td>
                    <?php if (!empty($report["spec_id"])): ?>
                        <?php echo safeText($report["spec_course_code"] ?? ""); ?>
                        -
                        <?php echo safeText($report["spec_course_title"] ?? ""); ?>
                        |
                        Version:
                        <?php echo safeText($report["spec_version"] ?? ""); ?>
                    <?php else: ?>
                        Not linked
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Task Details</h2>

        <form method="POST">

            <div class="form-section">
                <h3>Task Information</h3>

                <div class="form-grid">
                    <div>
                        <label>Task Title</label>
                        <input type="text"
                               name="title"
                               value="<?php echo safeText($_POST["title"] ?? $defaultTitle); ?>"
                               required>
                    </div>

                    <div>
                        <label>Responsible Person</label>

                        <?php if (count($users) > 0): ?>
                            <select name="assigned_to">
                                <option value="">Unassigned</option>

                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo safeText($user["display_name"]); ?>"
                                        <?php if (($_POST["assigned_to"] ?? "") == $user["display_name"]) echo "selected"; ?>>
                                        <?php echo safeText($user["display_name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text"
                                   name="assigned_to"
                                   value="<?php echo safeText($_POST["assigned_to"] ?? ""); ?>"
                                   placeholder="Example: Quality Coordinator">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Due Date</label>
                        <input type="date"
                               name="due_date"
                               value="<?php echo safeText($_POST["due_date"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Priority</label>
                        <select name="priority">
                            <?php foreach ($allowedPriorities as $p): ?>
                                <option value="<?php echo safeText($p); ?>"
                                    <?php if (($_POST["priority"] ?? $priority) === $p) echo "selected"; ?>>
                                    <?php echo safeText($p); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label>Description</label>
                <textarea name="description"><?php echo safeText($_POST["description"] ?? $defaultDescription); ?></textarea>
            </div>

            <div class="form-section">
                <h3>Linked Accreditation Requirement</h3>

                <label>Linked Substandard</label>
                <select name="substandard_id">
                    <option value="">-- Optional: Select Substandard --</option>

                    <?php foreach ($substandards as $sub): ?>
                        <option value="<?php echo safeText($sub["id"]); ?>"
                            <?php if (($_POST["substandard_id"] ?? "") == $sub["id"]) echo "selected"; ?>>
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
                    Select the NCAAA/SNQF substandard this follow-up task supports. The Standard is automatically linked through the selected Substandard.
                </div>

                <label>Linked Evidence Item</label>
                <select name="evidence_id">
                    <option value="">-- Optional: Select Evidence Item --</option>

                    <?php foreach ($evidenceItems as $evidence): ?>
                        <option value="<?php echo safeText($evidence["id"]); ?>"
                            <?php if (($_POST["evidence_id"] ?? "") == $evidence["id"]) echo "selected"; ?>>
                            <?php
                                echo safeText(
                                    $evidence["id"] . " - " .
                                    ($evidence["title"] ?? "") . " | " .
                                    ($evidence["evidence_type"] ?? "") . " | " .
                                    ($evidence["status"] ?? "")
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="hint">
                    Optional. Use this if the task is directly related to a specific evidence item.
                </div>
            </div>

            <div style="margin-top: 18px;">
                <button type="submit" class="btn">Create Task</button>
                <a href="course_report_followup_actions.php" class="btn btn-secondary">Cancel</a>
            </div>

        </form>
    </div>

</div>

</body>
</html>