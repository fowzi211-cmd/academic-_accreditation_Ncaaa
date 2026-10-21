<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];
$error = "";

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

function addColumnIfMissing($conn, $tableName, $columnName, $definition) {
    if (!columnExists($conn, $tableName, $columnName)) {
        requireSchemaColumn($conn, $tableName, $columnName);
    }
}

function nullIfEmpty($value) {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
}

function numberValue($value) {
    $value = trim((string)$value);
    if ($value === "") {
        return 0;
    }

    $value = str_replace(",", ".", $value);
    return is_numeric($value) ? (float)$value : 0;
}

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_tracks")) {
    die("program_specification_tracks table does not exist.");
}

if (!tableExists($conn, "program_specification_exit_points")) {
    die("program_specification_exit_points table does not exist.");
}

/*
    Safety upgrade for older tables.
*/
addColumnIfMissing($conn, "program_specification_tracks", "track_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_tracks", "credit_hours", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_tracks", "professions_jobs", "TEXT NULL");
addColumnIfMissing($conn, "program_specification_tracks", "notes", "TEXT NULL");

addColumnIfMissing($conn, "program_specification_exit_points", "exit_point_name", "VARCHAR(255) NULL");
addColumnIfMissing($conn, "program_specification_exit_points", "credit_hours", "DECIMAL(6,2) DEFAULT 0");
addColumnIfMissing($conn, "program_specification_exit_points", "notes", "TEXT NULL");

$specStmt = $conn->prepare("
    SELECT *
    FROM program_specifications
    WHERE id = :id
    LIMIT 1
");
$specStmt->execute([":id" => $spec_id]);
$spec = $specStmt->fetch(PDO::FETCH_ASSOC);

if (!$spec) {
    die("Program specification not found.");
}

$canEdit = hasRole(["Admin", "Quality Coordinator", "Program Leader"]);

$trackForm = [
    "track_id" => "",
    "track_name" => "",
    "credit_hours" => "0",
    "professions_jobs" => "",
    "notes" => ""
];

$exitForm = [
    "exit_id" => "",
    "exit_point_name" => "",
    "credit_hours" => "0",
    "notes" => ""
];

/* ===============================
   Delete Track
   =============================== */
if ($canEdit && isset($_GET["delete_track"]) && is_numeric($_GET["delete_track"])) {
    $delete_id = (int)$_GET["delete_track"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_tracks
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ":id" => $delete_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $deleteStmt = $conn->prepare("
            DELETE FROM program_specification_tracks
            WHERE id = :id
              AND program_specification_id = :program_specification_id
        ");
        $deleteStmt->execute([
            ":id" => $delete_id,
            ":program_specification_id" => $spec_id
        ]);

        logActivitySafe(
            $conn,
            "Delete",
            "Program Specification Tracks",
            $delete_id,
            "Deleted track: " . ($row["track_name"] ?? "")
        );

        header("Location: manage_program_specification_tracks_exit_points.php?id=" . $spec_id);
        exit;
    }
}

/* ===============================
   Delete Exit Point
   =============================== */
if ($canEdit && isset($_GET["delete_exit"]) && is_numeric($_GET["delete_exit"])) {
    $delete_id = (int)$_GET["delete_exit"];

    $loadStmt = $conn->prepare("
        SELECT *
        FROM program_specification_exit_points
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $loadStmt->execute([
        ":id" => $delete_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $loadStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $deleteStmt = $conn->prepare("
            DELETE FROM program_specification_exit_points
            WHERE id = :id
              AND program_specification_id = :program_specification_id
        ");
        $deleteStmt->execute([
            ":id" => $delete_id,
            ":program_specification_id" => $spec_id
        ]);

        logActivitySafe(
            $conn,
            "Delete",
            "Program Specification Exit Points",
            $delete_id,
            "Deleted exit point: " . ($row["exit_point_name"] ?? "")
        );

        header("Location: manage_program_specification_tracks_exit_points.php?id=" . $spec_id);
        exit;
    }
}

/* ===============================
   Load Track for Edit
   =============================== */
if (isset($_GET["edit_track"]) && is_numeric($_GET["edit_track"])) {
    $edit_id = (int)$_GET["edit_track"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_tracks
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $editStmt->execute([
        ":id" => $edit_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $trackForm = [
            "track_id" => $row["id"] ?? "",
            "track_name" => $row["track_name"] ?? "",
            "credit_hours" => $row["credit_hours"] ?? "0",
            "professions_jobs" => $row["professions_jobs"] ?? "",
            "notes" => $row["notes"] ?? ""
        ];
    }
}

/* ===============================
   Load Exit Point for Edit
   =============================== */
if (isset($_GET["edit_exit"]) && is_numeric($_GET["edit_exit"])) {
    $edit_id = (int)$_GET["edit_exit"];

    $editStmt = $conn->prepare("
        SELECT *
        FROM program_specification_exit_points
        WHERE id = :id
          AND program_specification_id = :program_specification_id
        LIMIT 1
    ");
    $editStmt->execute([
        ":id" => $edit_id,
        ":program_specification_id" => $spec_id
    ]);
    $row = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $exitForm = [
            "exit_id" => $row["id"] ?? "",
            "exit_point_name" => $row["exit_point_name"] ?? "",
            "credit_hours" => $row["credit_hours"] ?? "0",
            "notes" => $row["notes"] ?? ""
        ];
    }
}

/* ===============================
   Handle POST
   =============================== */
if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST") {
    $formType = trim($_POST["form_type"] ?? "");

    if ($formType === "track") {
        foreach ($trackForm as $key => $value) {
            $trackForm[$key] = trim($_POST[$key] ?? "");
        }

        if ($trackForm["track_name"] === "") {
            $error = "Track name is required.";
        } else {
            if ($trackForm["track_id"] !== "" && is_numeric($trackForm["track_id"])) {
                $updateStmt = $conn->prepare("
                    UPDATE program_specification_tracks
                    SET
                        track_name = :track_name,
                        credit_hours = :credit_hours,
                        professions_jobs = :professions_jobs,
                        notes = :notes
                    WHERE id = :id
                      AND program_specification_id = :program_specification_id
                ");

                $updateStmt->execute([
                    ":track_name" => $trackForm["track_name"],
                    ":credit_hours" => numberValue($trackForm["credit_hours"]),
                    ":professions_jobs" => nullIfEmpty($trackForm["professions_jobs"]),
                    ":notes" => nullIfEmpty($trackForm["notes"]),
                    ":id" => (int)$trackForm["track_id"],
                    ":program_specification_id" => $spec_id
                ]);

                logActivitySafe(
                    $conn,
                    "Update",
                    "Program Specification Tracks",
                    (int)$trackForm["track_id"],
                    "Updated track: " . $trackForm["track_name"]
                );

                header("Location: manage_program_specification_tracks_exit_points.php?id=" . $spec_id);
                exit;
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO program_specification_tracks
                    (
                        program_specification_id,
                        track_name,
                        credit_hours,
                        professions_jobs,
                        notes
                    )
                    VALUES
                    (
                        :program_specification_id,
                        :track_name,
                        :credit_hours,
                        :professions_jobs,
                        :notes
                    )
                ");

                $insertStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":track_name" => $trackForm["track_name"],
                    ":credit_hours" => numberValue($trackForm["credit_hours"]),
                    ":professions_jobs" => nullIfEmpty($trackForm["professions_jobs"]),
                    ":notes" => nullIfEmpty($trackForm["notes"])
                ]);

                $newId = (int)$conn->lastInsertId();

                logActivitySafe(
                    $conn,
                    "Create",
                    "Program Specification Tracks",
                    $newId,
                    "Created track: " . $trackForm["track_name"]
                );

                header("Location: manage_program_specification_tracks_exit_points.php?id=" . $spec_id);
                exit;
            }
        }
    }

    if ($formType === "exit") {
        foreach ($exitForm as $key => $value) {
            $exitForm[$key] = trim($_POST[$key] ?? "");
        }

        if ($exitForm["exit_point_name"] === "") {
            $error = "Exit point name is required.";
        } else {
            if ($exitForm["exit_id"] !== "" && is_numeric($exitForm["exit_id"])) {
                $updateStmt = $conn->prepare("
                    UPDATE program_specification_exit_points
                    SET
                        exit_point_name = :exit_point_name,
                        credit_hours = :credit_hours,
                        notes = :notes
                    WHERE id = :id
                      AND program_specification_id = :program_specification_id
                ");

                $updateStmt->execute([
                    ":exit_point_name" => $exitForm["exit_point_name"],
                    ":credit_hours" => numberValue($exitForm["credit_hours"]),
                    ":notes" => nullIfEmpty($exitForm["notes"]),
                    ":id" => (int)$exitForm["exit_id"],
                    ":program_specification_id" => $spec_id
                ]);

                logActivitySafe(
                    $conn,
                    "Update",
                    "Program Specification Exit Points",
                    (int)$exitForm["exit_id"],
                    "Updated exit point: " . $exitForm["exit_point_name"]
                );

                header("Location: manage_program_specification_tracks_exit_points.php?id=" . $spec_id);
                exit;
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO program_specification_exit_points
                    (
                        program_specification_id,
                        exit_point_name,
                        credit_hours,
                        notes
                    )
                    VALUES
                    (
                        :program_specification_id,
                        :exit_point_name,
                        :credit_hours,
                        :notes
                    )
                ");

                $insertStmt->execute([
                    ":program_specification_id" => $spec_id,
                    ":exit_point_name" => $exitForm["exit_point_name"],
                    ":credit_hours" => numberValue($exitForm["credit_hours"]),
                    ":notes" => nullIfEmpty($exitForm["notes"])
                ]);

                $newId = (int)$conn->lastInsertId();

                logActivitySafe(
                    $conn,
                    "Create",
                    "Program Specification Exit Points",
                    $newId,
                    "Created exit point: " . $exitForm["exit_point_name"]
                );

                header("Location: manage_program_specification_tracks_exit_points.php?id=" . $spec_id);
                exit;
            }
        }
    }
}

/* ===============================
   Load Rows
   =============================== */
$tracksStmt = $conn->prepare("
    SELECT *
    FROM program_specification_tracks
    WHERE program_specification_id = :program_specification_id
    ORDER BY id ASC
");
$tracksStmt->execute([":program_specification_id" => $spec_id]);
$tracks = $tracksStmt->fetchAll(PDO::FETCH_ASSOC);

$exitStmt = $conn->prepare("
    SELECT *
    FROM program_specification_exit_points
    WHERE program_specification_id = :program_specification_id
    ORDER BY credit_hours ASC, id ASC
");
$exitStmt->execute([":program_specification_id" => $spec_id]);
$exitPoints = $exitStmt->fetchAll(PDO::FETCH_ASSOC);

$totalTrackCredits = 0;
foreach ($tracks as $row) {
    $totalTrackCredits += (float)($row["credit_hours"] ?? 0);
}

$totalExitCredits = 0;
foreach ($exitPoints as $row) {
    $totalExitCredits += (float)($row["credit_hours"] ?? 0);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Tracks and Exit Points</title>
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
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
            gap: 14px;
        }

        textarea {
            min-height: 110px;
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

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 18px;
        }

        .actions a {
            display: inline-block;
            width: auto;
            text-align: center;
            margin-bottom: 0;
        }

        .wide-table-wrapper {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .wide-table {
            min-width: 900px;
            width: 100%;
            border-collapse: collapse;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 900px) {
            .summary-grid,
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
        <h1>Manage Tracks and Exit Points</h1>
        <p>Manage program tracks/pathways and exit points used in the program specification.</p>
    </div>

    <div class="actions">
        <a href="program_specification_profile.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
            Back to Specification
        </a>

        <a href="program_specification_validation.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Validate Specification
        </a>

        <a href="import_default_hpe_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-purple">
            Import Default HPE Tracks / Exit Points
        </a>
    </div>

    <div class="panel">
        <h2>Program Specification</h2>

        <table>
            <tr>
                <th>Specification</th>
                <td><?php echo safeText($spec["specification_title"] ?? ""); ?></td>
                <th>Program</th>
                <td><?php echo safeText($spec["program_name"] ?? ""); ?></td>
            </tr>

            <tr>
                <th>Program Code</th>
                <td><?php echo safeText($spec["program_code"] ?? ""); ?></td>
                <th>Total Credit Hours</th>
                <td><?php echo safeText($spec["total_credit_hours"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($tracks)); ?></div>
            <div class="summary-label">Tracks</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalTrackCredits); ?></div>
            <div class="summary-label">Track Credits</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText(count($exitPoints)); ?></div>
            <div class="summary-label">Exit Points</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalExitCredits); ?></div>
            <div class="summary-label">Exit Credits Total</div>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo safeText($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="panel">
            <h2><?php echo $trackForm["track_id"] !== "" ? "Edit Track / Pathway" : "Add Track / Pathway"; ?></h2>

            <form method="POST" action="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>">
                <input type="hidden" name="form_type" value="track">
                <input type="hidden" name="track_id" value="<?php echo safeText($trackForm["track_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Track / Pathway Name <span style="color:red;">*</span></label>
                        <input type="text" name="track_name" required value="<?php echo safeText($trackForm["track_name"]); ?>">
                    </div>

                    <div>
                        <label>Credit Hours</label>
                        <input type="number" step="0.01" name="credit_hours" value="<?php echo safeText($trackForm["credit_hours"]); ?>">
                    </div>
                </div>

                <label>Professions / Jobs</label>
                <textarea name="professions_jobs"><?php echo safeText($trackForm["professions_jobs"]); ?></textarea>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($trackForm["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $trackForm["track_id"] !== "" ? "Update Track" : "Add Track"; ?>
                </button>

                <?php if ($trackForm["track_id"] !== ""): ?>
                    <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Track Edit
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="panel">
            <h2><?php echo $exitForm["exit_id"] !== "" ? "Edit Exit Point" : "Add Exit Point"; ?></h2>

            <form method="POST" action="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>">
                <input type="hidden" name="form_type" value="exit">
                <input type="hidden" name="exit_id" value="<?php echo safeText($exitForm["exit_id"]); ?>">

                <div class="form-grid">
                    <div>
                        <label>Exit Point / Awarded Degree <span style="color:red;">*</span></label>
                        <input type="text" name="exit_point_name" required value="<?php echo safeText($exitForm["exit_point_name"]); ?>">
                    </div>

                    <div>
                        <label>Credit Hours</label>
                        <input type="number" step="0.01" name="credit_hours" value="<?php echo safeText($exitForm["credit_hours"]); ?>">
                    </div>
                </div>

                <label>Notes</label>
                <textarea name="notes"><?php echo safeText($exitForm["notes"]); ?></textarea>

                <br>

                <button type="submit" class="btn">
                    <?php echo $exitForm["exit_id"] !== "" ? "Update Exit Point" : "Add Exit Point"; ?>
                </button>

                <?php if ($exitForm["exit_id"] !== ""): ?>
                    <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>" class="btn btn-secondary">
                        Cancel Exit Point Edit
                    </a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Tracks / Pathways</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Track Name</th>
                    <th>Credit Hours</th>
                    <th>Professions / Jobs</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($tracks) > 0): ?>
                    <?php foreach ($tracks as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["track_name"] ?? ""); ?></td>
                            <td><?php echo safeText($row["credit_hours"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["professions_jobs"] ?? "")); ?></td>
                            <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>&edit_track=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>&delete_track=<?php echo safeText($row["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this track?');">
                                        Delete
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No tracks entered yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>Exit Points / Awarded Degrees</h2>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Exit Point / Awarded Degree</th>
                    <th>Credit Hours</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>

                <?php if (count($exitPoints) > 0): ?>
                    <?php foreach ($exitPoints as $row): ?>
                        <tr>
                            <td><?php echo safeText($row["exit_point_name"] ?? ""); ?></td>
                            <td><?php echo safeText($row["credit_hours"] ?? ""); ?></td>
                            <td><?php echo nl2br(safeText($row["notes"] ?? "")); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>&edit_exit=<?php echo safeText($row["id"]); ?>" class="btn btn-edit">
                                        Edit
                                    </a>

                                    <a href="manage_program_specification_tracks_exit_points.php?id=<?php echo safeText($spec_id); ?>&delete_exit=<?php echo safeText($row["id"]); ?>"
                                       class="btn btn-delete"
                                       onclick="return confirm('Delete this exit point?');">
                                        Delete
                                    </a>
                                <?php else: ?>
                                    <span class="small-text">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">No exit points entered yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
