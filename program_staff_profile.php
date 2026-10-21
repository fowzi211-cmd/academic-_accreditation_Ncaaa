<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Validate staff ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid staff ID.");
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

// ===============================
// Check required table
// ===============================
if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist.");
}

// ===============================
// Load staff profile
// ===============================
$stmt = $conn->prepare("
    SELECT
        ps.*,
        u.full_name AS user_full_name,
        u.email AS user_email,
        u.role AS user_role,
        u.status AS user_status
    FROM program_staff ps
    LEFT JOIN users u ON ps.user_id = u.id
    WHERE ps.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    die("Program staff record not found.");
}

// ===============================
// Default arrays - prevents undefined variable errors
// ===============================
$committeeMemberships = [];
$leadershipCommittees = [];
$committeeTasks = [];
$meetingAttendance = [];
$recommendations = [];

// ===============================
// Committee memberships
// ===============================
if (tableExists($conn, "committee_members") && tableExists($conn, "committees")) {
    $memberStmt = $conn->prepare("
        SELECT
            cm.id,
            cm.member_role,
            cm.start_date,
            cm.end_date,
            cm.status,
            c.id AS committee_id,
            c.committee_name,
            c.committee_type,
            c.academic_year,
            c.status AS committee_status
        FROM committee_members cm
        INNER JOIN committees c ON cm.committee_id = c.id
        WHERE cm.staff_id = :staff_id
        ORDER BY c.academic_year DESC, c.committee_name ASC
    ");
    $memberStmt->execute([":staff_id" => $id]);
    $committeeMemberships = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Committees chaired / secretary
// Important: do not reuse same PDO placeholder name multiple times
// ===============================
if (tableExists($conn, "committees")) {
    $leadStmt = $conn->prepare("
        SELECT
            id,
            committee_name,
            committee_type,
            academic_year,
            formation_date,
            status,
            CASE
                WHEN chair_staff_id = :chair_case_id THEN 'Chair'
                WHEN secretary_staff_id = :secretary_case_id THEN 'Secretary'
                ELSE ''
            END AS leadership_role
        FROM committees
        WHERE chair_staff_id = :chair_where_id
           OR secretary_staff_id = :secretary_where_id
        ORDER BY academic_year DESC, committee_name ASC
    ");

    $leadStmt->execute([
        ":chair_case_id" => $id,
        ":secretary_case_id" => $id,
        ":chair_where_id" => $id,
        ":secretary_where_id" => $id
    ]);

    $leadershipCommittees = $leadStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Committee tasks assigned
// ===============================
if (tableExists($conn, "committee_tasks")) {
    $taskStmt = $conn->prepare("
        SELECT
            ct.id,
            ct.committee_id,
            ct.task_title,
            ct.task_description,
            ct.due_date,
            ct.priority,
            ct.status,
            c.committee_name
        FROM committee_tasks ct
        LEFT JOIN committees c ON ct.committee_id = c.id
        WHERE ct.assigned_to_staff_id = :staff_id
        ORDER BY
            CASE
                WHEN ct.status = 'Pending' THEN 1
                WHEN ct.status = 'In Progress' THEN 2
                WHEN ct.status = 'Completed' THEN 3
                ELSE 4
            END,
            ct.due_date ASC,
            ct.id DESC
    ");
    $taskStmt->execute([":staff_id" => $id]);
    $committeeTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Meeting attendance
// ===============================
if (tableExists($conn, "committee_meeting_attendance") && tableExists($conn, "committee_meetings")) {
    $attendanceStmt = $conn->prepare("
        SELECT
            a.id,
            a.attendance_status,
            a.notes,
            m.id AS meeting_id,
            m.meeting_title,
            m.meeting_date,
            m.meeting_time,
            m.status AS meeting_status,
            c.committee_name
        FROM committee_meeting_attendance a
        INNER JOIN committee_meetings m ON a.meeting_id = m.id
        LEFT JOIN committees c ON m.committee_id = c.id
        WHERE a.staff_id = :staff_id
        ORDER BY m.meeting_date DESC, m.meeting_time DESC
        LIMIT 20
    ");
    $attendanceStmt->execute([":staff_id" => $id]);
    $meetingAttendance = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Recommendations assigned
// ===============================
if (tableExists($conn, "committee_recommendations")) {
    $recStmt = $conn->prepare("
        SELECT
            r.id,
            r.committee_id,
            r.meeting_id,
            r.recommendation_text,
            r.target_date,
            r.priority,
            r.status,
            r.linked_task_id,
            r.linked_evidence_id,
            r.followup_notes,
            c.committee_name
        FROM committee_recommendations r
        LEFT JOIN committees c ON r.committee_id = c.id
        WHERE r.responsible_staff_id = :staff_id
        ORDER BY
            CASE
                WHEN r.status = 'Open' THEN 1
                WHEN r.status = 'In Progress' THEN 2
                WHEN r.status = 'Completed' THEN 3
                ELSE 4
            END,
            r.target_date ASC,
            r.id DESC
    ");
    $recStmt->execute([":staff_id" => $id]);
    $recommendations = $recStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Summary counts
// ===============================
$membershipCount = count($committeeMemberships);
$leadershipCount = count($leadershipCommittees);
$committeeTaskCount = count($committeeTasks);
$meetingAttendanceCount = count($meetingAttendance);
$recommendationCount = count($recommendations);

// ===============================
// Status badge
// ===============================
$statusClass = "badge-muted";

if (($staff["employment_status"] ?? "") === "Active") {
    $statusClass = "badge-active";
} elseif (in_array(($staff["employment_status"] ?? ""), ["Inactive", "Former"], true)) {
    $statusClass = "badge-danger";
} elseif (($staff["employment_status"] ?? "") === "On Leave") {
    $statusClass = "badge-warning";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Staff Profile</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
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

        .profile-title {
            font-size: 24px;
            font-weight: bold;
            color: #222;
            margin-bottom: 6px;
        }

        .profile-subtitle {
            color: #555;
            margin-bottom: 12px;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-active {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
        }

        .badge-muted {
            background: #e9ecef;
            color: #495057;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        .missing {
            color: #999;
            font-style: italic;
        }

        .actions {
            margin-bottom: 18px;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Program Staff Profile</h1>
        <p>Staff profile with linked committees, tasks, meetings, and recommendations.</p>
    </div>

    <div class="actions">
        <a href="program_staff.php" class="btn btn-secondary">Back to Program Staff</a>

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="edit_program_staff.php?id=<?php echo safeText($id); ?>" class="btn btn-edit">Edit Staff</a>
        <?php endif; ?>

        <a href="export_program_staff_csv.php" class="btn btn-purple">Export Staff CSV</a>
    </div>

    <div class="panel">
        <div class="profile-title">
            <?php echo safeText($staff["full_name"]); ?>
        </div>

        <div class="profile-subtitle">
            <?php echo safeText($staff["academic_rank"] ?? ""); ?>

            <?php if (!empty($staff["specialty"])): ?>
                | <?php echo safeText($staff["specialty"]); ?>
            <?php endif; ?>
        </div>

        <span class="badge <?php echo safeText($statusClass); ?>">
            <?php echo safeText($staff["employment_status"] ?? ""); ?>
        </span>

        <br><br>

        <table>
            <tr>
                <th>Staff Code</th>
                <td><?php echo safeText($staff["staff_code"] ?? ""); ?></td>
                <th>Role in Program</th>
                <td><?php echo safeText($staff["role_in_program"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Qualification</th>
                <td><?php echo safeText($staff["qualification"] ?? ""); ?></td>
                <th>Specialty</th>
                <td><?php echo safeText($staff["specialty"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Department</th>
                <td><?php echo safeText($staff["department"] ?? ""); ?></td>
                <th>College</th>
                <td><?php echo safeText($staff["college"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Institution</th>
                <td><?php echo safeText($staff["institution"] ?? ""); ?></td>
                <th>Email</th>
                <td><?php echo safeText($staff["email"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Phone</th>
                <td><?php echo safeText($staff["phone"] ?? ""); ?></td>
                <th>Linked System User</th>
                <td>
                    <?php if (!empty($staff["user_id"])): ?>
                        <?php echo safeText($staff["user_full_name"] ?? ""); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText($staff["user_email"] ?? ""); ?>
                            |
                            <?php echo safeText($staff["user_role"] ?? ""); ?>
                            |
                            <?php echo safeText($staff["user_status"] ?? ""); ?>
                        </span>
                    <?php else: ?>
                        <span class="missing">Not linked</span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Notes</th>
                <td colspan="3"><?php echo nl2br(safeText($staff["notes"] ?? "")); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($membershipCount); ?></div>
            <div class="summary-label">Committee Memberships</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($leadershipCount); ?></div>
            <div class="summary-label">Leadership Roles</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($committeeTaskCount); ?></div>
            <div class="summary-label">Committee Tasks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($meetingAttendanceCount); ?></div>
            <div class="summary-label">Meeting Attendance</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($recommendationCount); ?></div>
            <div class="summary-label">Assigned Recommendations</div>
        </div>
    </div>

    <div class="panel">
        <h2>Committee Memberships</h2>

        <table>
            <tr>
                <th>Committee</th>
                <th>Type</th>
                <th>Academic Year</th>
                <th>Member Role</th>
                <th>Status</th>
            </tr>

            <?php if (count($committeeMemberships) > 0): ?>
                <?php foreach ($committeeMemberships as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["committee_name"]); ?></td>
                        <td><?php echo safeText($row["committee_type"]); ?></td>
                        <td><?php echo safeText($row["academic_year"]); ?></td>
                        <td><?php echo safeText($row["member_role"]); ?></td>
                        <td><?php echo safeText($row["status"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No committee membership records found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Committee Leadership Roles</h2>

        <table>
            <tr>
                <th>Committee</th>
                <th>Leadership Role</th>
                <th>Academic Year</th>
                <th>Formation Date</th>
                <th>Status</th>
            </tr>

            <?php if (count($leadershipCommittees) > 0): ?>
                <?php foreach ($leadershipCommittees as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["committee_name"]); ?></td>
                        <td><?php echo safeText($row["leadership_role"]); ?></td>
                        <td><?php echo safeText($row["academic_year"]); ?></td>
                        <td><?php echo safeText($row["formation_date"]); ?></td>
                        <td><?php echo safeText($row["status"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No leadership role records found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Assigned Committee Tasks</h2>

        <table>
            <tr>
                <th>Committee</th>
                <th>Task</th>
                <th>Due Date</th>
                <th>Priority</th>
                <th>Status</th>
            </tr>

            <?php if (count($committeeTasks) > 0): ?>
                <?php foreach ($committeeTasks as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["committee_name"]); ?></td>
                        <td>
                            <strong><?php echo safeText($row["task_title"]); ?></strong>
                            <br>
                            <span class="small-text"><?php echo safeText($row["task_description"]); ?></span>
                        </td>
                        <td><?php echo safeText($row["due_date"]); ?></td>
                        <td><?php echo safeText($row["priority"]); ?></td>
                        <td><?php echo safeText($row["status"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No committee tasks assigned.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Recent Meeting Attendance</h2>

        <table>
            <tr>
                <th>Committee</th>
                <th>Meeting</th>
                <th>Date</th>
                <th>Time</th>
                <th>Attendance</th>
            </tr>

            <?php if (count($meetingAttendance) > 0): ?>
                <?php foreach ($meetingAttendance as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["committee_name"]); ?></td>
                        <td><?php echo safeText($row["meeting_title"]); ?></td>
                        <td><?php echo safeText($row["meeting_date"]); ?></td>
                        <td><?php echo safeText($row["meeting_time"]); ?></td>
                        <td><?php echo safeText($row["attendance_status"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No meeting attendance records found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Assigned Recommendations</h2>

        <table>
            <tr>
                <th>Committee</th>
                <th>Recommendation</th>
                <th>Target Date</th>
                <th>Priority</th>
                <th>Status</th>
            </tr>

            <?php if (count($recommendations) > 0): ?>
                <?php foreach ($recommendations as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["committee_name"]); ?></td>
                        <td><?php echo nl2br(safeText($row["recommendation_text"])); ?></td>
                        <td><?php echo safeText($row["target_date"]); ?></td>
                        <td><?php echo safeText($row["priority"]); ?></td>
                        <td><?php echo safeText($row["status"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No assigned recommendations found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>