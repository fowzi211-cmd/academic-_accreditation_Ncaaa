<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Validate meeting ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid meeting ID.");
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

function getStatusClass($status) {
    if ($status === "Completed" || $status === "Closed" || $status === "Present") {
        return "badge-active";
    }

    if ($status === "Draft" || $status === "Scheduled" || $status === "Open" || $status === "In Progress") {
        return "badge-warning";
    }

    if ($status === "Cancelled" || $status === "Absent") {
        return "badge-danger";
    }

    return "badge-muted";
}

function getPriorityClass($priority) {
    if ($priority === "High") {
        return "badge-danger";
    }

    if ($priority === "Medium") {
        return "badge-warning";
    }

    if ($priority === "Low") {
        return "badge-active";
    }

    return "badge-muted";
}

// ===============================
// Required table check
// ===============================
if (!tableExists($conn, "committee_meetings")) {
    die("committee_meetings table does not exist.");
}

// ===============================
// Load meeting
// ===============================
$stmt = $conn->prepare("
    SELECT
        m.*,
        c.committee_name,
        c.committee_type,
        c.academic_year,
        c.status AS committee_status
    FROM committee_meetings m
    INNER JOIN committees c ON m.committee_id = c.id
    WHERE m.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    die("Committee meeting not found.");
}

$committee_id = (int)$meeting["committee_id"];

// ===============================
// Default arrays
// ===============================
$attendanceRows = [];
$recommendations = [];

// ===============================
// Load attendance
// ===============================
if (tableExists($conn, "committee_meeting_attendance") && tableExists($conn, "program_staff")) {
    $attendanceStmt = $conn->prepare("
        SELECT
            a.id,
            a.staff_id,
            a.attendance_status,
            a.notes,
            a.created_at,
            ps.full_name,
            ps.academic_rank,
            ps.email,
            ps.role_in_program
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
    $attendanceStmt->execute([":meeting_id" => $id]);
    $attendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load recommendations
// ===============================
if (tableExists($conn, "committee_recommendations")) {
    $recStmt = $conn->prepare("
        SELECT
            r.id,
            r.recommendation_text,
            r.responsible_staff_id,
            r.target_date,
            r.priority,
            r.status,
            r.linked_task_id,
            r.linked_evidence_id,
            r.followup_notes,
            r.created_at,
            r.updated_at,
            ps.full_name AS responsible_name
        FROM committee_recommendations r
        LEFT JOIN program_staff ps ON r.responsible_staff_id = ps.id
        WHERE r.meeting_id = :meeting_id
        ORDER BY
            CASE
                WHEN r.status = 'Open' THEN 1
                WHEN r.status = 'In Progress' THEN 2
                WHEN r.status = 'Completed' THEN 3
                WHEN r.status = 'Closed' THEN 4
                ELSE 5
            END,
            r.target_date ASC,
            r.id DESC
    ");
    $recStmt->execute([":meeting_id" => $id]);
    $recommendations = $recStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Summary counts
// ===============================
$attendanceCount = count($attendanceRows);
$recommendationCount = count($recommendations);

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

$openRecommendationCount = 0;

foreach ($recommendations as $row) {
    if (in_array(($row["status"] ?? ""), ["Open", "In Progress"], true)) {
        $openRecommendationCount++;
    }
}

$meetingStatusClass = getStatusClass($meeting["status"] ?? "");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Committee Meeting Profile</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
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

        .section-actions {
            margin-bottom: 12px;
        }

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(3, minmax(130px, 1fr));
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
        <h1>Committee Meeting Profile</h1>
        <p>Meeting details, attendance, recommendations, and follow-up links.</p>
    </div>

    <div class="actions">
        <a href="committee_profile.php?id=<?php echo safeText($committee_id); ?>" class="btn btn-secondary">
            Back to Committee Profile
        </a>

        <a href="committees.php" class="btn">
            Committees
        </a>

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="manage_committee_meeting_attendance.php?id=<?php echo safeText($id); ?>" class="btn btn-purple">
                Manage Attendance
            </a>

            <a href="create_committee_recommendation.php?meeting_id=<?php echo safeText($id); ?>" class="btn">
                Add Recommendation
            </a>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="profile-title">
            <?php echo safeText($meeting["meeting_title"]); ?>
        </div>

        <div class="profile-subtitle">
            <?php echo safeText($meeting["committee_name"]); ?>

            <?php if (!empty($meeting["academic_year"])): ?>
                | Academic Year: <?php echo safeText($meeting["academic_year"]); ?>
            <?php endif; ?>
        </div>

        <span class="badge <?php echo safeText($meetingStatusClass); ?>">
            <?php echo safeText($meeting["status"]); ?>
        </span>

        <br><br>

        <table>
            <tr>
                <th>Committee</th>
                <td><?php echo safeText($meeting["committee_name"]); ?></td>
                <th>Committee Type</th>
                <td><?php echo safeText($meeting["committee_type"]); ?></td>
            </tr>

            <tr>
                <th>Meeting Date</th>
                <td><?php echo safeText($meeting["meeting_date"]); ?></td>
                <th>Meeting Time</th>
                <td><?php echo safeText($meeting["meeting_time"]); ?></td>
            </tr>

            <tr>
                <th>Location</th>
                <td><?php echo safeText($meeting["location"]); ?></td>
                <th>Meeting Status</th>
                <td><?php echo safeText($meeting["status"]); ?></td>
            </tr>

            <tr>
                <th>Agenda</th>
                <td colspan="3"><?php echo nl2br(safeText($meeting["agenda"])); ?></td>
            </tr>

            <tr>
                <th>Minutes Summary</th>
                <td colspan="3"><?php echo nl2br(safeText($meeting["minutes_summary"])); ?></td>
            </tr>

            <tr>
                <th>Notes</th>
                <td colspan="3"><?php echo nl2br(safeText($meeting["notes"])); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($attendanceCount); ?></div>
            <div class="summary-label">Attendance Records</div>
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

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($recommendationCount); ?></div>
            <div class="summary-label">Recommendations</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($openRecommendationCount); ?></div>
            <div class="summary-label">Open / In Progress</div>
        </div>
    </div>

    <div class="panel">
        <h2>Meeting Attendance</h2>

        <div class="section-actions">
            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                <a href="manage_committee_meeting_attendance.php?id=<?php echo safeText($id); ?>" class="btn btn-purple">
                    Manage Attendance
                </a>
            <?php endif; ?>
        </div>

        <table>
            <tr>
                <th>Staff Member</th>
                <th>Academic Rank</th>
                <th>Role in Program</th>
                <th>Attendance Status</th>
                <th>Notes</th>
            </tr>

            <?php if (count($attendanceRows) > 0): ?>
                <?php foreach ($attendanceRows as $row): ?>
                    <tr>
                        <td>
                            <a href="program_staff_profile.php?id=<?php echo safeText($row["staff_id"]); ?>">
                                <?php echo safeText($row["full_name"]); ?>
                            </a>
                            <br>
                            <span class="small-text">
                                <?php echo safeText($row["email"]); ?>
                            </span>
                        </td>

                        <td><?php echo safeText($row["academic_rank"]); ?></td>

                        <td><?php echo safeText($row["role_in_program"]); ?></td>

                        <td>
                            <span class="badge <?php echo safeText(getStatusClass($row["attendance_status"])); ?>">
                                <?php echo safeText($row["attendance_status"]); ?>
                            </span>
                        </td>

                        <td><?php echo nl2br(safeText($row["notes"])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No attendance records found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Meeting Recommendations</h2>

        <div class="section-actions">
            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                <a href="create_committee_recommendation.php?meeting_id=<?php echo safeText($id); ?>" class="btn">
                    Add Recommendation
                </a>
            <?php endif; ?>
        </div>

        <table>
            <tr>
    <th>Recommendation</th>
    <th>Responsible</th>
    <th>Target Date</th>
    <th>Priority</th>
    <th>Status</th>
    <th>Linked Task / Evidence</th>
    <th>Follow-up Notes</th>
    <th>Actions</th>
</tr>

            <?php if (count($recommendations) > 0): ?>
                <?php foreach ($recommendations as $row): ?>
                    <tr>
                        <td><?php echo nl2br(safeText($row["recommendation_text"])); ?></td>

                        <td><?php echo safeText($row["responsible_name"]); ?></td>

                        <td><?php echo safeText($row["target_date"]); ?></td>

                        <td>
                            <span class="badge <?php echo safeText(getPriorityClass($row["priority"])); ?>">
                                <?php echo safeText($row["priority"]); ?>
                            </span>
                        </td>

                        <td>
                            <span class="badge <?php echo safeText(getStatusClass($row["status"])); ?>">
                                <?php echo safeText($row["status"]); ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($row["linked_task_id"])): ?>
                                <a href="edit_task.php?id=<?php echo safeText($row["linked_task_id"]); ?>">
                                    Task #<?php echo safeText($row["linked_task_id"]); ?>
                                </a>
                                <br>
                            <?php endif; ?>

                            <?php if (!empty($row["linked_evidence_id"])): ?>
                                Evidence ID: <?php echo safeText($row["linked_evidence_id"]); ?>
                            <?php endif; ?>

                            <?php if (empty($row["linked_task_id"]) && empty($row["linked_evidence_id"])): ?>
                                <span class="missing">Not linked</span>
                            <?php endif; ?>
                        </td>

                        <td><?php echo nl2br(safeText($row["followup_notes"])); ?></td>

<td>
    <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
        <a href="edit_committee_recommendation.php?id=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
            Edit
        </a>

        <?php echo deleteForm('delete_committee_recommendation.php?id=' . urlencode((string)$row['id']), 'Delete this recommendation? This cannot be undone.'); ?>
<?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
    <?php if (!empty($row["linked_task_id"])): ?>
        <a href="edit_task.php?id=<?php echo safeText($row["linked_task_id"]); ?>" class="btn">
            View Task
        </a>
    <?php else: ?>
        <a href="committee_recommendation_followup_task.php?id=<?php echo safeText($row["id"]); ?>" class="btn btn-purple">
            Create Task
        </a>
    <?php endif; ?>
<?php endif; ?>

    <?php endif; ?>
</td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">No recommendations recorded for this meeting.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
