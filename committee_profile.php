<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

// ===============================
// Validate committee ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid committee ID.");
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
    if ($status === "Active" || $status === "Completed" || $status === "Closed") {
        return "badge-active";
    }

    if ($status === "Draft" || $status === "Pending" || $status === "Open" || $status === "In Progress") {
        return "badge-warning";
    }

    if ($status === "Inactive" || $status === "Archived" || $status === "Cancelled") {
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
if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

// ===============================
// Load committee
// ===============================
$stmt = $conn->prepare("
    SELECT
        c.*,
        chair.full_name AS chair_name,
        chair.email AS chair_email,
        chair.academic_rank AS chair_rank,
        secretary.full_name AS secretary_name,
        secretary.email AS secretary_email,
        secretary.academic_rank AS secretary_rank
    FROM committees c
    LEFT JOIN program_staff chair ON c.chair_staff_id = chair.id
    LEFT JOIN program_staff secretary ON c.secretary_staff_id = secretary.id
    WHERE c.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$committee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$committee) {
    die("Committee not found.");
}

// ===============================
// Default arrays
// ===============================
$members = [];
$meetings = [];
$recommendations = [];
$tasks = [];

// ===============================
// Load members
// ===============================
if (tableExists($conn, "committee_members") && tableExists($conn, "program_staff")) {
    $memberStmt = $conn->prepare("
        SELECT
            cm.id,
            cm.member_role,
            cm.start_date,
            cm.end_date,
            cm.status,
            cm.notes,
            ps.id AS staff_id,
            ps.full_name,
            ps.academic_rank,
            ps.specialty,
            ps.email,
            ps.role_in_program
        FROM committee_members cm
        INNER JOIN program_staff ps ON cm.staff_id = ps.id
        WHERE cm.committee_id = :committee_id
        ORDER BY
            CASE
                WHEN cm.member_role = 'Chair' THEN 1
                WHEN cm.member_role = 'Secretary' THEN 2
                WHEN cm.member_role = 'Member' THEN 3
                ELSE 4
            END,
            ps.full_name ASC
    ");
    $memberStmt->execute([":committee_id" => $id]);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load meetings
// ===============================
if (tableExists($conn, "committee_meetings")) {
    $meetingStmt = $conn->prepare("
        SELECT
            id,
            meeting_title,
            meeting_date,
            meeting_time,
            location,
            agenda,
            minutes_summary,
            status,
            notes,
            created_at,
            updated_at
        FROM committee_meetings
        WHERE committee_id = :committee_id
        ORDER BY meeting_date DESC, meeting_time DESC, id DESC
        LIMIT 20
    ");
    $meetingStmt->execute([":committee_id" => $id]);
    $meetings = $meetingStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load recommendations
// ===============================
if (tableExists($conn, "committee_recommendations")) {
    $recStmt = $conn->prepare("
        SELECT
            r.id,
            r.meeting_id,
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
        WHERE r.committee_id = :committee_id
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
        LIMIT 30
    ");
    $recStmt->execute([":committee_id" => $id]);
    $recommendations = $recStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Load committee tasks
// ===============================
if (tableExists($conn, "committee_tasks")) {
    $taskStmt = $conn->prepare("
        SELECT
            ct.id,
            ct.assigned_to_staff_id,
            ct.linked_task_id,
            ct.task_title,
            ct.task_description,
            ct.due_date,
            ct.priority,
            ct.status,
            ct.notes,
            ps.full_name AS assigned_to_name
        FROM committee_tasks ct
        LEFT JOIN program_staff ps ON ct.assigned_to_staff_id = ps.id
        WHERE ct.committee_id = :committee_id
        ORDER BY
            CASE
                WHEN ct.status = 'Pending' THEN 1
                WHEN ct.status = 'In Progress' THEN 2
                WHEN ct.status = 'Completed' THEN 3
                WHEN ct.status = 'Cancelled' THEN 4
                ELSE 5
            END,
            ct.due_date ASC,
            ct.id DESC
        LIMIT 30
    ");
    $taskStmt->execute([":committee_id" => $id]);
    $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===============================
// Summary counts
// ===============================
$memberCount = count($members);
$meetingCount = count($meetings);
$recommendationCount = count($recommendations);
$taskCount = count($tasks);

$openRecommendations = 0;
foreach ($recommendations as $rec) {
    if (in_array($rec["status"], ["Open", "In Progress"], true)) {
        $openRecommendations++;
    }
}

$pendingTasks = 0;
foreach ($tasks as $task) {
    if (in_array($task["status"], ["Pending", "In Progress"], true)) {
        $pendingTasks++;
    }
}

$statusClass = getStatusClass($committee["status"] ?? "");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Committee Profile</title>
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
        <h1>Committee Profile</h1>
        <p>Committee formation, members, meetings, recommendations, and tasks.</p>
    </div>

    <div class="actions">
        <a href="committees.php" class="btn btn-secondary">Back to Committees</a>

        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
            <a href="edit_committee.php?id=<?php echo safeText($id); ?>" class="btn btn-edit">Edit Committee</a>
            <a href="manage_committee_members.php?id=<?php echo safeText($id); ?>" class="btn btn-purple">Manage Members</a>
        <?php endif; ?>

        <a href="program_staff.php" class="btn">Program Staff</a>
    </div>

    <div class="panel">
        <div class="profile-title">
            <?php echo safeText($committee["committee_name"]); ?>
        </div>

        <div class="profile-subtitle">
            <?php echo safeText($committee["committee_type"] ?? ""); ?>

            <?php if (!empty($committee["academic_year"])): ?>
                | Academic Year: <?php echo safeText($committee["academic_year"]); ?>
            <?php endif; ?>
        </div>

        <span class="badge <?php echo safeText($statusClass); ?>">
            <?php echo safeText($committee["status"] ?? ""); ?>
        </span>

        <br><br>

        <table>
            <tr>
                <th>Committee Type</th>
                <td><?php echo safeText($committee["committee_type"] ?? ""); ?></td>
                <th>Academic Year</th>
                <td><?php echo safeText($committee["academic_year"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Formation Reference</th>
                <td><?php echo safeText($committee["formation_reference"] ?? ""); ?></td>
                <th>Formation Date</th>
                <td><?php echo safeText($committee["formation_date"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Chair</th>
                <td>
                    <?php if (!empty($committee["chair_name"])): ?>
                        <?php echo safeText($committee["chair_name"]); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText($committee["chair_rank"] ?? ""); ?>
                            <?php if (!empty($committee["chair_email"])): ?>
                                | <?php echo safeText($committee["chair_email"]); ?>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="missing">Not assigned</span>
                    <?php endif; ?>
                </td>

                <th>Secretary</th>
                <td>
                    <?php if (!empty($committee["secretary_name"])): ?>
                        <?php echo safeText($committee["secretary_name"]); ?>
                        <br>
                        <span class="small-text">
                            <?php echo safeText($committee["secretary_rank"] ?? ""); ?>
                            <?php if (!empty($committee["secretary_email"])): ?>
                                | <?php echo safeText($committee["secretary_email"]); ?>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="missing">Not assigned</span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Mandate / Terms of Reference</th>
                <td colspan="3"><?php echo nl2br(safeText($committee["mandate"] ?? "")); ?></td>
            </tr>

            <tr>
                <th>Notes</th>
                <td colspan="3"><?php echo nl2br(safeText($committee["notes"] ?? "")); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($memberCount); ?></div>
            <div class="summary-label">Members</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($meetingCount); ?></div>
            <div class="summary-label">Meetings</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($recommendationCount); ?></div>
            <div class="summary-label">Recommendations</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($openRecommendations); ?></div>
            <div class="summary-label">Open Recommendations</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($taskCount); ?></div>
            <div class="summary-label">Tasks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($pendingTasks); ?></div>
            <div class="summary-label">Pending Tasks</div>
        </div>
    </div>

    <div class="panel">
        <h2>Committee Members</h2>

        <div class="section-actions">
            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                <a href="manage_committee_members.php?id=<?php echo safeText($id); ?>" class="btn btn-purple">Manage Members</a>
            <?php endif; ?>
        </div>

        <table>
            <tr>
                <th>Staff Member</th>
                <th>Academic Rank</th>
                <th>Specialty</th>
                <th>Member Role</th>
                <th>Start Date</th>
                <th>Status</th>
            </tr>

            <?php if (count($members) > 0): ?>
                <?php foreach ($members as $row): ?>
                    <tr>
                        <td>
                            <a href="program_staff_profile.php?id=<?php echo safeText($row["staff_id"]); ?>">
                                <?php echo safeText($row["full_name"]); ?>
                            </a>
                            <br>
                            <span class="small-text"><?php echo safeText($row["email"]); ?></span>
                        </td>
                        <td><?php echo safeText($row["academic_rank"]); ?></td>
                        <td><?php echo safeText($row["specialty"]); ?></td>
                        <td><?php echo safeText($row["member_role"]); ?></td>
                        <td><?php echo safeText($row["start_date"]); ?></td>
                        <td><?php echo safeText($row["status"]); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6">No committee members recorded.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Recent Meetings</h2>

        <div class="section-actions">
            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                <a href="create_committee_meeting.php?committee_id=<?php echo safeText($id); ?>" class="btn">Add Meeting</a>
            <?php endif; ?>
        </div>

        <table>
            <tr>
                <th>Meeting</th>
                <th>Date</th>
                <th>Time</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>

            <?php if (count($meetings) > 0): ?>
                <?php foreach ($meetings as $row): ?>
                    <tr>
                        <td><?php echo safeText($row["meeting_title"]); ?></td>
                        <td><?php echo safeText($row["meeting_date"]); ?></td>
                        <td><?php echo safeText($row["meeting_time"]); ?></td>
                        <td><?php echo safeText($row["location"]); ?></td>
                        <td>
                            <span class="badge <?php echo safeText(getStatusClass($row["status"])); ?>">
                                <?php echo safeText($row["status"]); ?>
                            </span>
                        </td>
                        <td>
    <a href="committee_meeting_profile.php?id=<?php echo safeText($row["id"]); ?>" class="btn">
        View
    </a>

    <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
        <a href="create_committee_recommendation.php?meeting_id=<?php echo safeText($row["id"]); ?>" class="btn btn-purple">
	<a href="committee_recommendations_dashboard.php" class="btn btn-purple">
    Recommendations Dashboard
            Add Recommendation
        </a>
    <?php endif; ?>
</td>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6">No meetings recorded.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Committee Recommendations</h2>

        <table>
            <tr>
                <th>Recommendation</th>
                <th>Responsible</th>
                <th>Target Date</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Linked Task / Evidence</th>
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
                                Task ID: <?php echo safeText($row["linked_task_id"]); ?><br>
                            <?php endif; ?>

                            <?php if (!empty($row["linked_evidence_id"])): ?>
                                Evidence ID: <?php echo safeText($row["linked_evidence_id"]); ?>
                            <?php endif; ?>

                            <?php if (empty($row["linked_task_id"]) && empty($row["linked_evidence_id"])): ?>
                                <span class="missing">Not linked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">No recommendations recorded.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Committee Tasks</h2>

        <table>
            <tr>
                <th>Task</th>
                <th>Assigned To</th>
                <th>Due Date</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Linked System Task</th>
            </tr>

            <?php if (count($tasks) > 0): ?>
                <?php foreach ($tasks as $row): ?>
                    <tr>
                        <td>
                            <strong><?php echo safeText($row["task_title"]); ?></strong>
                            <br>
                            <span class="small-text">
                                <?php echo safeText($row["task_description"]); ?>
                            </span>
                        </td>
                        <td><?php echo safeText($row["assigned_to_name"]); ?></td>
                        <td><?php echo safeText($row["due_date"]); ?></td>
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
                            <?php else: ?>
                                <span class="missing">Not linked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6">No committee tasks recorded.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>