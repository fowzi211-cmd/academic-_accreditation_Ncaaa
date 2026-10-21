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
// Validate committee ID
// ===============================
if (!isset($_GET["committee_id"]) || !is_numeric($_GET["committee_id"])) {
    die("Invalid committee ID.");
}

$committee_id = (int)$_GET["committee_id"];

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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

// ===============================
// Required tables
// ===============================
if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

if (!tableExists($conn, "committee_meetings")) {
    die("committee_meetings table does not exist.");
}

// ===============================
// Load committee
// ===============================
$stmt = $conn->prepare("
    SELECT
        c.*,
        chair.full_name AS chair_name,
        secretary.full_name AS secretary_name
    FROM committees c
    LEFT JOIN program_staff chair ON c.chair_staff_id = chair.id
    LEFT JOIN program_staff secretary ON c.secretary_staff_id = secretary.id
    WHERE c.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $committee_id]);
$committee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$committee) {
    die("Committee not found.");
}

// ===============================
// Form defaults
// ===============================
$form = [
    "meeting_title" => "",
    "meeting_date" => date("Y-m-d"),
    "meeting_time" => "",
    "location" => "",
    "agenda" => "",
    "minutes_summary" => "",
    "status" => "Draft",
    "notes" => ""
];

// ===============================
// Handle submit
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    if ($form["meeting_title"] === "") {
        $error = "Meeting title is required.";
    } elseif ($form["meeting_date"] === "") {
        $error = "Meeting date is required.";
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO committee_meetings
            (
                committee_id,
                meeting_title,
                meeting_date,
                meeting_time,
                location,
                agenda,
                minutes_summary,
                status,
                notes
            )
            VALUES
            (
                :committee_id,
                :meeting_title,
                :meeting_date,
                :meeting_time,
                :location,
                :agenda,
                :minutes_summary,
                :status,
                :notes
            )
        ");

        $insertStmt->execute([
            ":committee_id" => $committee_id,
            ":meeting_title" => $form["meeting_title"],
            ":meeting_date" => $form["meeting_date"] !== "" ? $form["meeting_date"] : null,
            ":meeting_time" => $form["meeting_time"] !== "" ? $form["meeting_time"] : null,
            ":location" => $form["location"] !== "" ? $form["location"] : null,
            ":agenda" => $form["agenda"] !== "" ? $form["agenda"] : null,
            ":minutes_summary" => $form["minutes_summary"] !== "" ? $form["minutes_summary"] : null,
            ":status" => $form["status"] !== "" ? $form["status"] : "Draft",
            ":notes" => $form["notes"] !== "" ? $form["notes"] : null
        ]);

        $newMeetingId = $conn->lastInsertId();

        logActivitySafe(
            $conn,
            "Create",
            "Committee Meetings",
            $newMeetingId,
            "Committee meeting created: " . $form["meeting_title"] . " | Committee: " . $committee["committee_name"]
        );

        header("Location: committee_profile.php?id=" . $committee_id);
        exit;
    }
}

$statusOptions = ["Draft", "Scheduled", "Completed", "Cancelled"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add Committee Meeting</title>
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
        <h1>Add Committee Meeting</h1>
        <p>Create a meeting record for the selected committee.</p>
    </div>

    <div class="actions">
        <a href="committee_profile.php?id=<?php echo safeText($committee_id); ?>" class="btn btn-secondary">
            Back to Committee Profile
        </a>

        <a href="committees.php" class="btn">
            Committees
        </a>

        <a href="manage_committee_members.php?id=<?php echo safeText($committee_id); ?>" class="btn btn-purple">
            Manage Members
        </a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Committee Details</h2>

        <table>
            <tr>
                <th>Committee</th>
                <td><?php echo safeText($committee["committee_name"]); ?></td>
                <th>Type</th>
                <td><?php echo safeText($committee["committee_type"]); ?></td>
            </tr>

            <tr>
                <th>Academic Year</th>
                <td><?php echo safeText($committee["academic_year"]); ?></td>
                <th>Status</th>
                <td><?php echo safeText($committee["status"]); ?></td>
            </tr>

            <tr>
                <th>Chair</th>
                <td><?php echo safeText($committee["chair_name"] ?? ""); ?></td>
                <th>Secretary</th>
                <td><?php echo safeText($committee["secretary_name"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <form method="POST" action="create_committee_meeting.php?committee_id=<?php echo safeText($committee_id); ?>">

            <div class="form-section">
                <h2>Meeting Information</h2>

                <div class="form-grid">
                    <div>
                        <label>Meeting Title <span style="color:red;">*</span></label>
                        <input type="text"
                               name="meeting_title"
                               required
                               value="<?php echo safeText($form["meeting_title"]); ?>"
                               placeholder="Example: Quality Committee Meeting 1">
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

                    <div>
                        <label>Meeting Date <span style="color:red;">*</span></label>
                        <input type="date"
                               name="meeting_date"
                               required
                               value="<?php echo safeText($form["meeting_date"]); ?>">
                    </div>

                    <div>
                        <label>Meeting Time</label>
                        <input type="time"
                               name="meeting_time"
                               value="<?php echo safeText($form["meeting_time"]); ?>">
                    </div>

                    <div>
                        <label>Location</label>
                        <input type="text"
                               name="location"
                               value="<?php echo safeText($form["location"]); ?>"
                               placeholder="Meeting room / online / Teams">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Agenda and Minutes</h2>

                <label>Agenda</label>
                <textarea name="agenda" placeholder="Write meeting agenda points."><?php echo safeText($form["agenda"]); ?></textarea>

                <label>Minutes Summary</label>
                <textarea name="minutes_summary" placeholder="Write summary of discussion and decisions."><?php echo safeText($form["minutes_summary"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>
            </div>

            <br>

            <button type="submit" class="btn">Save Meeting</button>
            <a href="committee_profile.php?id=<?php echo safeText($committee_id); ?>" class="btn btn-secondary">Cancel</a>

        </form>
    </div>

</div>

</body>
</html>