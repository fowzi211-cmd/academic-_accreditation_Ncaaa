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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "committees")) {
    die("committees table does not exist.");
}

if (!tableExists($conn, "program_staff")) {
    die("program_staff table does not exist. Create program staff first.");
}

// ===============================
// Load committee
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM committees
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $id]);
$committee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$committee) {
    die("Committee not found.");
}

// ===============================
// Load active staff for chair/secretary
// ===============================
$staffStmt = $conn->query("
    SELECT id, full_name, academic_rank, role_in_program, employment_status
    FROM program_staff
    WHERE employment_status = 'Active'
    ORDER BY full_name ASC
");
$staffRows = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Form values
// ===============================
$form = [
    "committee_name" => $committee["committee_name"] ?? "",
    "committee_type" => $committee["committee_type"] ?? "",
    "academic_year" => $committee["academic_year"] ?? "",
    "formation_reference" => $committee["formation_reference"] ?? "",
    "formation_date" => $committee["formation_date"] ?? "",
    "chair_staff_id" => $committee["chair_staff_id"] ?? "",
    "secretary_staff_id" => $committee["secretary_staff_id"] ?? "",
    "mandate" => $committee["mandate"] ?? "",
    "status" => $committee["status"] ?? "Active",
    "notes" => $committee["notes"] ?? ""
];

// ===============================
// Handle submit
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $key => $value) {
        $form[$key] = trim($_POST[$key] ?? "");
    }

    $chair_staff_id = $form["chair_staff_id"] !== "" ? (int)$form["chair_staff_id"] : null;
    $secretary_staff_id = $form["secretary_staff_id"] !== "" ? (int)$form["secretary_staff_id"] : null;

    if ($form["committee_name"] === "") {
        $error = "Committee name is required.";
    } else {
        $updateStmt = $conn->prepare("
            UPDATE committees
            SET
                committee_name = :committee_name,
                committee_type = :committee_type,
                academic_year = :academic_year,
                formation_reference = :formation_reference,
                formation_date = :formation_date,
                chair_staff_id = :chair_staff_id,
                secretary_staff_id = :secretary_staff_id,
                mandate = :mandate,
                status = :status,
                notes = :notes
            WHERE id = :id
        ");

        $updateStmt->execute([
            ":committee_name" => $form["committee_name"],
            ":committee_type" => $form["committee_type"] !== "" ? $form["committee_type"] : null,
            ":academic_year" => $form["academic_year"] !== "" ? $form["academic_year"] : null,
            ":formation_reference" => $form["formation_reference"] !== "" ? $form["formation_reference"] : null,
            ":formation_date" => $form["formation_date"] !== "" ? $form["formation_date"] : null,
            ":chair_staff_id" => $chair_staff_id,
            ":secretary_staff_id" => $secretary_staff_id,
            ":mandate" => $form["mandate"] !== "" ? $form["mandate"] : null,
            ":status" => $form["status"] !== "" ? $form["status"] : "Active",
            ":notes" => $form["notes"] !== "" ? $form["notes"] : null,
            ":id" => $id
        ]);

        // Add chair/secretary as members if not already listed
        if (tableExists($conn, "committee_members")) {
            $checkMemberStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM committee_members
                WHERE committee_id = :committee_id
                  AND staff_id = :staff_id
            ");

            $insertMemberStmt = $conn->prepare("
                INSERT INTO committee_members
                (
                    committee_id,
                    staff_id,
                    member_role,
                    start_date,
                    status,
                    notes
                )
                VALUES
                (
                    :committee_id,
                    :staff_id,
                    :member_role,
                    :start_date,
                    :status,
                    :notes
                )
            ");

            if ($chair_staff_id !== null) {
                $checkMemberStmt->execute([
                    ":committee_id" => $id,
                    ":staff_id" => $chair_staff_id
                ]);

                if ((int)$checkMemberStmt->fetchColumn() === 0) {
                    $insertMemberStmt->execute([
                        ":committee_id" => $id,
                        ":staff_id" => $chair_staff_id,
                        ":member_role" => "Chair",
                        ":start_date" => $form["formation_date"] !== "" ? $form["formation_date"] : null,
                        ":status" => "Active",
                        ":notes" => "Automatically added as committee chair during committee update."
                    ]);
                }
            }

            if ($secretary_staff_id !== null && $secretary_staff_id !== $chair_staff_id) {
                $checkMemberStmt->execute([
                    ":committee_id" => $id,
                    ":staff_id" => $secretary_staff_id
                ]);

                if ((int)$checkMemberStmt->fetchColumn() === 0) {
                    $insertMemberStmt->execute([
                        ":committee_id" => $id,
                        ":staff_id" => $secretary_staff_id,
                        ":member_role" => "Secretary",
                        ":start_date" => $form["formation_date"] !== "" ? $form["formation_date"] : null,
                        ":status" => "Active",
                        ":notes" => "Automatically added as committee secretary during committee update."
                    ]);
                }
            }
        }

        logActivitySafe(
            $conn,
            "Update",
            "Committees",
            $id,
            "Committee updated: " . $form["committee_name"]
        );

        header("Location: committees.php");
        exit;
    }
}

$statusOptions = ["Active", "Draft", "Inactive", "Archived"];

$typeOptions = [
    "Program Committee",
    "Course Committee",
    "Quality Committee",
    "Curriculum Committee",
    "Assessment Committee",
    "Academic Advising Committee",
    "Exams Committee",
    "Training Committee",
    "Research Committee",
    "Community Service Committee",
    "Student Affairs Committee",
    "Other"
];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Committee</title>
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

        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
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
        <h1>Edit Committee</h1>
        <p>Update committee formation, leadership, mandate, and status.</p>
    </div>

    <div class="actions">
        <a href="committees.php" class="btn btn-secondary">Back to Committees</a>
        <a href="committee_profile.php?id=<?php echo safeText($id); ?>" class="btn">Committee Profile</a>
        <a href="manage_committee_members.php?id=<?php echo safeText($id); ?>" class="btn btn-purple">Manage Members</a>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <form method="POST" action="edit_committee.php?id=<?php echo safeText($id); ?>">

            <div class="form-section">
                <h2>Committee Identity</h2>

                <div class="form-grid">
                    <div>
                        <label>Committee Name <span style="color:red;">*</span></label>
                        <input type="text"
                               name="committee_name"
                               required
                               value="<?php echo safeText($form["committee_name"]); ?>">
                    </div>

                    <div>
                        <label>Committee Type</label>
                        <select name="committee_type">
                            <option value="">-- Select Type --</option>
                            <?php foreach ($typeOptions as $type): ?>
                                <option value="<?php echo safeText($type); ?>" <?php if ($form["committee_type"] === $type) echo "selected"; ?>>
                                    <?php echo safeText($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Academic Year</label>
                        <input type="text"
                               name="academic_year"
                               value="<?php echo safeText($form["academic_year"]); ?>"
                               placeholder="Example: 2026">
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
            </div>

            <div class="form-section">
                <h2>Formation Details</h2>

                <div class="form-grid">
                    <div>
                        <label>Formation Reference</label>
                        <input type="text"
                               name="formation_reference"
                               value="<?php echo safeText($form["formation_reference"]); ?>">
                    </div>

                    <div>
                        <label>Formation Date</label>
                        <input type="date"
                               name="formation_date"
                               value="<?php echo safeText($form["formation_date"]); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Committee Leadership</h2>

                <div class="form-grid">
                    <div>
                        <label>Chair</label>
                        <select name="chair_staff_id">
                            <option value="">-- Select Chair --</option>

                            <?php foreach ($staffRows as $staff): ?>
                                <option value="<?php echo safeText($staff["id"]); ?>" <?php if ((string)$form["chair_staff_id"] === (string)$staff["id"]) echo "selected"; ?>>
                                    <?php
                                    echo safeText(
                                        $staff["full_name"] .
                                        (!empty($staff["academic_rank"]) ? " | " . $staff["academic_rank"] : "") .
                                        (!empty($staff["role_in_program"]) ? " | " . $staff["role_in_program"] : "")
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Secretary</label>
                        <select name="secretary_staff_id">
                            <option value="">-- Select Secretary --</option>

                            <?php foreach ($staffRows as $staff): ?>
                                <option value="<?php echo safeText($staff["id"]); ?>" <?php if ((string)$form["secretary_staff_id"] === (string)$staff["id"]) echo "selected"; ?>>
                                    <?php
                                    echo safeText(
                                        $staff["full_name"] .
                                        (!empty($staff["academic_rank"]) ? " | " . $staff["academic_rank"] : "") .
                                        (!empty($staff["role_in_program"]) ? " | " . $staff["role_in_program"] : "")
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="hint">
                    If the selected chair or secretary is not already a committee member, the system will add them automatically.
                </div>
            </div>

            <div class="form-section">
                <h2>Mandate and Notes</h2>

                <label>Committee Mandate / Terms of Reference</label>
                <textarea name="mandate"><?php echo safeText($form["mandate"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($form["notes"]); ?></textarea>
            </div>

            <br>

            <button type="submit" class="btn">Update Committee</button>
            <a href="committees.php" class="btn btn-secondary">Cancel</a>

        </form>
    </div>

</div>

</body>
</html>