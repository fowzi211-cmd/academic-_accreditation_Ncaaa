<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

// ===============================
// Validate meeting ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid meeting ID.");
}

$meeting_id = (int)$_GET["id"];
$error = "";
$message = "";

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
if (!tableExists($conn, "committee_meetings")) {
    die("committee_meetings table does not exist.");
}

if (!tableExists($conn, "committee_meeting_attendance")) {
    die("committee_meeting_attendance table does not exist.");
}

if (!tableExists($conn, "committee_members")) {
    die("committee_members table does not exist.");
}

if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist.");
}

// ===============================
// Load meeting
// ===============================
$stmt = $conn->prepare("
    SELECT
        m.*,
        c.committee_name,
        c.committee_type,
        c.academic_year
    FROM committee_meetings m
    INNER JOIN committees c ON m.committee_id = c.id
    WHERE m.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $meeting_id]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    die("Committee meeting not found.");
}

$committee_id = (int)$meeting["committee_id"];

// ===============================
// Bulk create attendance from committee members
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["generate_attendance"])) {
    $membersStmt = $conn->prepare("
        SELECT cm.staff_id
        FROM committee_members cm
        WHERE cm.committee_id = :committee_id
          AND cm.status = 'Active'
          AND cm.staff_id NOT IN (
              SELECT staff_id
              FROM committee_meeting_attendance
              WHERE meeting_id = :meeting_id
          )
    ");
    $membersStmt->execute([
        ":committee_id" => $committee_id,
        ":meeting_id" => $meeting_id
    ]);

    $membersToAdd = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

    $insertStmt = $conn->prepare("
        INSERT INTO committee_meeting_attendance
        (
            meeting_id,
            staff_id,
            attendance_status,
            notes
        )
        VALUES
        (
            :meeting_id,
            :staff_id,
            :attendance_status,
            :notes
        )
    ");

    $addedCount = 0;

    foreach ($membersToAdd as $member) {
        $insertStmt->execute([
            ":meeting_id" => $meeting_id,
            ":staff_id" => $member["staff_id"],
            ":attendance_status" => "Present",
            ":notes" => "Generated from active committee members."
        ]);

        $addedCount++;
    }

    logActivitySafe(
        $conn,
        "Create",
        "Committee Meeting Attendance",
        $meeting_id,
        "Generated attendance records for meeting: " . $meeting["meeting_title"] . " | Added: " . $addedCount
    );

    header("Location: manage_committee_meeting_attendance.php?id=" . $meeting_id);
    exit;
}

// ===============================
// Add single attendance record
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_attendance"])) {
    $staff_id = !empty($_POST["staff_id"]) ? (int)$_POST["staff_id"] : 0;
    $attendance_status = trim($_POST["attendance_status"] ?? "Present");
    $notes = trim($_POST["notes"] ?? "");

    if ($staff_id <= 0) {
        $error = "Please select a staff member.";
    } else {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM committee_meeting_attendance
            WHERE meeting_id = :meeting_id
              AND staff_id = :staff_id
        ");
        $checkStmt->execute([
            ":meeting_id" => $meeting_id,
            ":staff_id" => $staff_id
        ]);

        if ((int)$checkStmt->fetchColumn() > 0) {
            $error = "This staff member already has an attendance record for this meeting.";
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO committee_meeting_attendance
                (
                    meeting_id,
                    staff_id,
                    attendance_status,
                    notes
                )
                VALUES
                (
                    :meeting_id,
                    :staff_id,
                    :attendance_status,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":meeting_id" => $meeting_id,
                ":staff_id" => $staff_id,
                ":attendance_status" => $attendance_status !== "" ? $attendance_status : "Present",
                ":notes" => $notes !== "" ? $notes : null
            ]);

            logActivitySafe(
                $conn,
                "Create",
                "Committee Meeting Attendance",
                $meeting_id,
                "Attendance record added for meeting: " . $meeting["meeting_title"]
            );

            header("Location: manage_committee_meeting_attendance.php?id=" . $meeting_id);
            exit;
        }
    }
}

// ===============================
// Update attendance
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_attendance"])) {
    $attendance_id = !empty($_POST["attendance_id"]) ? (int)$_POST["attendance_id"] : 0;
    $attendance_status = trim($_POST["attendance_status"] ?? "Present");
    $notes = trim($_POST["notes"] ?? "");

    if ($attendance_id <= 0) {
        $error = "Invalid attendance record.";
    } else {
        $updateStmt = $conn->prepare("
            UPDATE committee_meeting_attendance
            SET
                attendance_status = :attendance_status,
                notes = :notes
            WHERE id = :id
              AND meeting_id = :meeting_id
        ");

        $updateStmt->execute([
            ":attendance_status" => $attendance_status !== "" ? $attendance_status : "Present",
            ":notes" => $notes !== "" ? $notes : null,
            ":id" => $attendance_id,
            ":meeting_id" => $meeting_id
        ]);

        logActivitySafe(
            $conn,
            "Update",
            "Committee Meeting Attendance",
            $attendance_id,
            "Attendance record updated for meeting: " . $meeting["meeting_title"]
        );

        header("Location: manage_committee_meeting_attendance.php?id=" . $meeting_id);
        exit;
    }
}

// ===============================
// Delete attendance
// ===============================
if (isset($_GET["delete_attendance"]) && is_numeric($_GET["delete_attendance"])) {
    $attendance_id = (int)$_GET["delete_attendance"];

    $loadStmt = $conn->prepare("
        SELECT
            a.*,
            ps.full_name
        FROM committee_meeting_attendance a
        LEFT JOIN program_staff ps ON a.staff_id = ps.id
        WHERE a.id = :id
          AND a.meeting_id = :meeting_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ":id" => $attendance_id,
        ":meeting_id" => $meeting_id
    ]);

    $attendance = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendance) {
        die("Attendance record not found.");
    }

    $deleteStmt = $conn->prepare("
        DELETE FROM committee_meeting_attendance
        WHERE id = :id
          AND meeting_id = :meeting_id
    ");
    $deleteStmt->execute([
        ":id" => $attendance_id,
        ":meeting_id" => $meeting_id
    ]);

    logActivitySafe(
        $conn,
        "Delete",
        "Committee Meeting Attendance",
        $attendance_id,
        "Attendance record removed: " . ($attendance["full_name"] ?? "") . " | Meeting: " . $meeting["meeting_title"]
    );

    header("Location: manage_committee_meeting_attendance.php?id=" . $meeting_id);
    exit;
}

// ===============================
// Available staff for manual add
// ===============================
$availableStmt = $conn->prepare("
    SELECT
        ps.id,
        ps.full_name,
        ps.academic_rank,
        ps.specialty,
        ps.role_in_program,
        ps.email
    FROM program_staff ps
    WHERE ps.employment_status = 'Active'
      AND ps.id NOT IN (
          SELECT staff_id
          FROM committee_meeting_attendance
          WHERE meeting_id = :meeting_id
      )
    ORDER BY ps.full_name ASC
");
$availableStmt->execute([":meeting_id" => $meeting_id]);
$availableStaff = $availableStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Current attendance
// ===============================
$attendanceStmt = $conn->prepare("
    SELECT
        a.id,
        a.staff_id,
        a.attendance_status,
        a.notes,
        a.created_at,
        ps.full_name,
        ps.academic_rank,
        ps.specialty,
        ps.role_in_program,
        ps.email
    FROM committee_meeting_attendance a
    INNER JOIN program_staff ps ON a.staff_id = ps.id
    WHERE a.meeting_id = :meeting_id
    ORDER BY
        CASE
            WHEN a.attendance_status = 'Present' THEN 1
            WHEN a.attendance_status = 'Excused' THEN 2
            WHEN a.attendance_status = 'Absent' THEN 3
            ELSE 4
        END,
        ps.full_name ASC
");
$attendanceStmt->execute([":meeting_id" => $meeting_id]);
$attendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Counts
// ===============================
$totalAttendance = count($attendanceRows);
$presentCount = 0;
$absentCount = 0;
$excusedCount = 0;

foreach ($attendanceRows as $row) {
    if (($row["attendance_status"] ?? "") === "Present") {
        $presentCount++;
    } elseif (($row["attendance_status"] ?? "") === "Absent") {
        $absentCount++;
    } elseif (($row["attendance_status"] ?? "") === "Excused") {
        $excusedCount++;
    }
}

$attendanceStatuses = ["Present", "Absent", "Excused", "Late", "Online"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Meeting Attendance</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
        }

        .inline-form {
            display: grid;
            grid-template-columns: 140px 1fr 90px;
            gap: 6px;
            align-items: start;
        }

        .inline-form select,
        .inline-form textarea {
            font-size: 12px;
            padding: 6px;
        }

        .inline-form textarea {
            min-height: 38px;
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

        .actions {
            margin-bottom: 18px;
        }

        @media (max-width: 1000px) {
            .summary-grid,
            .form-grid,
            .inline-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Manage Meeting Attendance</h1>
        <p>Record and update attendance for a committee meeting.</p>
    </div>

    <div class="actions">
        <a href="committee_meeting_profile.php?id=<?php echo safeText($meeting_id); ?>" class="btn btn-secondary">
            Back to Meeting Profile
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
        <h2>Meeting Details</h2>

        <table>
            <tr>
                <th>Committee</th>
                <td><?php echo safeText($meeting["committee_name"]); ?></td>
                <th>Meeting</th>
                <td><?php echo safeText($meeting["meeting_title"]); ?></td>
            </tr>

            <tr>
                <th>Date</th>
                <td><?php echo safeText($meeting["meeting_date"]); ?></td>
                <th>Time</th>
                <td><?php echo safeText($meeting["meeting_time"]); ?></td>
            </tr>

            <tr>
                <th>Location</th>
                <td><?php echo safeText($meeting["location"]); ?></td>
                <th>Status</th>
                <td><?php echo safeText($meeting["status"]); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalAttendance); ?></div>
            <div class="summary-label">Total Records</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($presentCount); ?></div>
            <div class="summary-label">Present</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($absentCount); ?></div>
            <div class="summary-label">Absent</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($excusedCount); ?></div>
            <div class="summary-label">Excused</div>
        </div>
    </div>

    <div class="panel">
        <h2>Generate Attendance from Committee Members</h2>

        <p>
            This will add attendance records for active committee members who do not already have attendance records for this meeting.
            Default status will be <strong>Present</strong>.
        </p>

        <form method="POST" action="manage_committee_meeting_attendance.php?id=<?php echo safeText($meeting_id); ?>">
            <button type="submit"
                    name="generate_attendance"
                    class="btn btn-purple"
                    onclick="return confirm('Generate attendance records from active committee members?');">
                Generate Attendance
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Add Attendance Manually</h2>

        <form method="POST" action="manage_committee_meeting_attendance.php?id=<?php echo safeText($meeting_id); ?>">
            <div class="form-grid">
                <div>
                    <label>Staff Member</label>
                    <select name="staff_id" required>
                        <option value="">-- Select Staff --</option>

                        <?php foreach ($availableStaff as $staff): ?>
                            <option value="<?php echo safeText($staff["id"]); ?>">
                                <?php
                                echo safeText(
                                    $staff["full_name"] .
                                    (!empty($staff["academic_rank"]) ? " | " . $staff["academic_rank"] : "") .
                                    (!empty($staff["specialty"]) ? " | " . $staff["specialty"] : "")
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Attendance Status</label>
                    <select name="attendance_status">
                        <?php foreach ($attendanceStatuses as $status): ?>
                            <option value="<?php echo safeText($status); ?>" <?php if ($status === "Present") echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Notes</label>
                    <input type="text" name="notes">
                </div>
            </div>

            <br>

            <button type="submit" name="add_attendance" class="btn">
                Add Attendance
            </button>
        </form>
    </div>

    <div class="panel">
        <h2>Attendance Records</h2>

        <table>
            <tr>
                <th>Staff Member</th>
                <th>Details</th>
                <th>Update Attendance</th>
                <th>Actions</th>
            </tr>

            <?php if (count($attendanceRows) > 0): ?>
                <?php foreach ($attendanceRows as $row): ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="program_staff_profile.php?id=<?php echo safeText($row["staff_id"]); ?>">
                                    <?php echo safeText($row["full_name"]); ?>
                                </a>
                            </strong>
                            <br>
                            <span class="small-text"><?php echo safeText($row["email"]); ?></span>
                        </td>

                        <td>
                            <?php echo safeText($row["academic_rank"]); ?>
                            <br>
                            <span class="small-text">
                                <?php echo safeText($row["specialty"]); ?>
                                <?php if (!empty($row["role_in_program"])): ?>
                                    | <?php echo safeText($row["role_in_program"]); ?>
                                <?php endif; ?>
                            </span>
                        </td>

                        <td>
                            <form method="POST"
                                  action="manage_committee_meeting_attendance.php?id=<?php echo safeText($meeting_id); ?>"
                                  class="inline-form">

                                <input type="hidden" name="attendance_id" value="<?php echo safeText($row["id"]); ?>">

                                <select name="attendance_status">
                                    <?php foreach ($attendanceStatuses as $status): ?>
                                        <option value="<?php echo safeText($status); ?>" <?php if (($row["attendance_status"] ?? "") === $status) echo "selected"; ?>>
                                            <?php echo safeText($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <textarea name="notes"><?php echo safeText($row["notes"]); ?></textarea>

                                <button type="submit" name="update_attendance" class="btn btn-small">
                                    Update
                                </button>
                            </form>
                        </td>

                        <td>
                            <a href="manage_committee_meeting_attendance.php?id=<?php echo safeText($meeting_id); ?>&delete_attendance=<?php echo safeText($row["id"]); ?>"
                               class="btn btn-delete"
                               onclick="return confirm('Remove this attendance record?');">
                                Remove
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No attendance records found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>