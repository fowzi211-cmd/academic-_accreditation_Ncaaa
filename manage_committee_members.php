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
// Validate committee ID
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid committee ID.");
}

$committee_id = (int)$_GET["id"];
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
if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

if (!tableExists($conn, "committee_members")) {
    die("committee_members table does not exist.");
}

if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist.");
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
// Handle add member
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_member"])) {
    $staff_id = !empty($_POST["staff_id"]) ? (int)$_POST["staff_id"] : 0;
    $member_role = trim($_POST["member_role"] ?? "Member");
    $start_date = trim($_POST["start_date"] ?? "");
    $end_date = trim($_POST["end_date"] ?? "");
    $status = trim($_POST["status"] ?? "Active");
    $notes = trim($_POST["notes"] ?? "");

    if ($staff_id <= 0) {
        $error = "Please select a staff member.";
    } else {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM committee_members
            WHERE committee_id = :committee_id
              AND staff_id = :staff_id
        ");
        $checkStmt->execute([
            ":committee_id" => $committee_id,
            ":staff_id" => $staff_id
        ]);

        if ((int)$checkStmt->fetchColumn() > 0) {
            $error = "This staff member is already listed in this committee.";
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO committee_members
                (
                    committee_id,
                    staff_id,
                    member_role,
                    start_date,
                    end_date,
                    status,
                    notes
                )
                VALUES
                (
                    :committee_id,
                    :staff_id,
                    :member_role,
                    :start_date,
                    :end_date,
                    :status,
                    :notes
                )
            ");

            $insertStmt->execute([
                ":committee_id" => $committee_id,
                ":staff_id" => $staff_id,
                ":member_role" => $member_role !== "" ? $member_role : "Member",
                ":start_date" => $start_date !== "" ? $start_date : null,
                ":end_date" => $end_date !== "" ? $end_date : null,
                ":status" => $status !== "" ? $status : "Active",
                ":notes" => $notes !== "" ? $notes : null
            ]);

            logActivitySafe(
                $conn,
                "Create",
                "Committee Members",
                $committee_id,
                "Committee member added to committee: " . $committee["committee_name"]
            );

            header("Location: manage_committee_members.php?id=" . $committee_id);
            exit;
        }
    }
}

// ===============================
// Handle update member
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_member"])) {
    $member_id = !empty($_POST["member_id"]) ? (int)$_POST["member_id"] : 0;
    $member_role = trim($_POST["member_role"] ?? "Member");
    $start_date = trim($_POST["start_date"] ?? "");
    $end_date = trim($_POST["end_date"] ?? "");
    $status = trim($_POST["status"] ?? "Active");
    $notes = trim($_POST["notes"] ?? "");

    if ($member_id <= 0) {
        $error = "Invalid committee member record.";
    } else {
        $updateStmt = $conn->prepare("
            UPDATE committee_members
            SET
                member_role = :member_role,
                start_date = :start_date,
                end_date = :end_date,
                status = :status,
                notes = :notes
            WHERE id = :id
              AND committee_id = :committee_id
        ");

        $updateStmt->execute([
            ":member_role" => $member_role !== "" ? $member_role : "Member",
            ":start_date" => $start_date !== "" ? $start_date : null,
            ":end_date" => $end_date !== "" ? $end_date : null,
            ":status" => $status !== "" ? $status : "Active",
            ":notes" => $notes !== "" ? $notes : null,
            ":id" => $member_id,
            ":committee_id" => $committee_id
        ]);

        logActivitySafe(
            $conn,
            "Update",
            "Committee Members",
            $member_id,
            "Committee member updated in committee: " . $committee["committee_name"]
        );

        header("Location: manage_committee_members.php?id=" . $committee_id);
        exit;
    }
}

// ===============================
// Handle delete member
// ===============================
if (isset($_GET["delete_member"]) && is_numeric($_GET["delete_member"])) {
    $member_id = (int)$_GET["delete_member"];

    $loadMemberStmt = $conn->prepare("
        SELECT cm.*, ps.full_name
        FROM committee_members cm
        LEFT JOIN program_staff ps ON cm.staff_id = ps.id
        WHERE cm.id = :id
          AND cm.committee_id = :committee_id
        LIMIT 1
    ");
    $loadMemberStmt->execute([
        ":id" => $member_id,
        ":committee_id" => $committee_id
    ]);
    $memberToDelete = $loadMemberStmt->fetch(PDO::FETCH_ASSOC);

    if (!$memberToDelete) {
        die("Committee member record not found.");
    }

    $deleteStmt = $conn->prepare("
        DELETE FROM committee_members
        WHERE id = :id
          AND committee_id = :committee_id
    ");
    $deleteStmt->execute([
        ":id" => $member_id,
        ":committee_id" => $committee_id
    ]);

    logActivitySafe(
        $conn,
        "Delete",
        "Committee Members",
        $member_id,
        "Committee member removed: " . ($memberToDelete["full_name"] ?? "") . " from " . $committee["committee_name"]
    );

    header("Location: manage_committee_members.php?id=" . $committee_id);
    exit;
}

// ===============================
// Load active staff not already members
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
          FROM committee_members
          WHERE committee_id = :committee_id
      )
    ORDER BY ps.full_name ASC
");
$availableStmt->execute([":committee_id" => $committee_id]);
$availableStaff = $availableStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Load current members
// ===============================
$membersStmt = $conn->prepare("
    SELECT
        cm.id,
        cm.staff_id,
        cm.member_role,
        cm.start_date,
        cm.end_date,
        cm.status,
        cm.notes,
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
$membersStmt->execute([":committee_id" => $committee_id]);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

$memberRoles = ["Chair", "Secretary", "Member", "Reviewer", "Coordinator", "Administrative Support"];
$statusOptions = ["Active", "Inactive", "On Leave", "Completed"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Committee Members</title>
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
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 12px;
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

        .missing {
            color: #999;
            font-style: italic;
        }

        .inline-form {
            display: grid;
            grid-template-columns: 120px 130px 130px 120px 1fr 90px;
            gap: 6px;
            align-items: start;
        }

        .inline-form input,
        .inline-form select,
        .inline-form textarea {
            font-size: 12px;
            padding: 6px;
        }

        .inline-form textarea {
            min-height: 38px;
        }

        @media (max-width: 1100px) {
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
        <h1>Manage Committee Members</h1>
        <p>Add, update, or remove members for the selected committee.</p>
    </div>

    <div class="actions">
        <a href="committee_profile.php?id=<?php echo safeText($committee_id); ?>" class="btn btn-secondary">Back to Committee Profile</a>
        <a href="committees.php" class="btn">Committees</a>
        <a href="program_staff.php" class="btn">Program Staff</a>
        <a href="edit_committee.php?id=<?php echo safeText($committee_id); ?>" class="btn btn-edit">Edit Committee</a>
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
        <h2>Add Committee Member</h2>

        <form method="POST" action="manage_committee_members.php?id=<?php echo safeText($committee_id); ?>">
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
                    <label>Member Role</label>
                    <select name="member_role">
                        <?php foreach ($memberRoles as $role): ?>
                            <option value="<?php echo safeText($role); ?>" <?php if ($role === "Member") echo "selected"; ?>>
                                <?php echo safeText($role); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo safeText($status); ?>" <?php if ($status === "Active") echo "selected"; ?>>
                                <?php echo safeText($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo safeText($committee["formation_date"] ?? ""); ?>">
                </div>

                <div>
                    <label>End Date</label>
                    <input type="date" name="end_date">
                </div>

                <div>
                    <label>Notes</label>
                    <input type="text" name="notes">
                </div>
            </div>

            <br>

            <button type="submit" name="add_member" class="btn">Add Member</button>
        </form>
    </div>

    <div class="panel">
        <h2>Current Committee Members</h2>

        <table>
            <tr>
                <th>Staff Member</th>
                <th>Details</th>
                <th>Update Membership</th>
                <th>Actions</th>
            </tr>

            <?php if (count($members) > 0): ?>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="program_staff_profile.php?id=<?php echo safeText($member["staff_id"]); ?>">
                                    <?php echo safeText($member["full_name"]); ?>
                                </a>
                            </strong>
                            <br>
                            <span class="small-text"><?php echo safeText($member["email"]); ?></span>
                        </td>

                        <td>
                            <?php echo safeText($member["academic_rank"]); ?><br>
                            <span class="small-text">
                                <?php echo safeText($member["specialty"]); ?>
                                <?php if (!empty($member["role_in_program"])): ?>
                                    | <?php echo safeText($member["role_in_program"]); ?>
                                <?php endif; ?>
                            </span>
                        </td>

                        <td>
                            <form method="POST"
                                  action="manage_committee_members.php?id=<?php echo safeText($committee_id); ?>"
                                  class="inline-form">

                                <input type="hidden" name="member_id" value="<?php echo safeText($member["id"]); ?>">

                                <select name="member_role">
                                    <?php foreach ($memberRoles as $role): ?>
                                        <option value="<?php echo safeText($role); ?>" <?php if (($member["member_role"] ?? "") === $role) echo "selected"; ?>>
                                            <?php echo safeText($role); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="date" name="start_date" value="<?php echo safeText($member["start_date"]); ?>">

                                <input type="date" name="end_date" value="<?php echo safeText($member["end_date"]); ?>">

                                <select name="status">
                                    <?php foreach ($statusOptions as $status): ?>
                                        <option value="<?php echo safeText($status); ?>" <?php if (($member["status"] ?? "") === $status) echo "selected"; ?>>
                                            <?php echo safeText($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <textarea name="notes"><?php echo safeText($member["notes"]); ?></textarea>

                                <button type="submit" name="update_member" class="btn btn-small">Update</button>
                            </form>
                        </td>

                        <td>
                            <a href="manage_committee_members.php?id=<?php echo safeText($committee_id); ?>&delete_member=<?php echo safeText($member["id"]); ?>"
                               class="btn btn-delete"
                               onclick="return confirm('Remove this member from the committee?');">
                                Remove
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No members recorded for this committee.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>