<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid program specification ID.");
}

$spec_id = (int)$_GET["id"];

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

/*
    Default HPE Tracks and Exit Points

    Tracks:
    - The HPE program has no separate tracks/pathways.

    Exit Points:
    - Explicit N/A row can be used if the approved program specification does not include formal exit awards.
    - Bachelor degree final award is recorded for clarity.
*/

$defaultTracks = [
    [
        "track_name" => "N/A - No Separate Tracks",
        "credit_hours" => 134,
        "professions_jobs" => "The program is offered as one unified Health Promotion and Education pathway without separate tracks.",
        "notes" => "Documented to clarify that the program has no separate tracks or pathways."
    ]
];

$defaultExitPoints = [
    [
        "exit_point_name" => "N/A - No Formal Early Exit Award",
        "credit_hours" => 0,
        "notes" => "No formal early exit award is currently documented for this program. Keep this row if the approved specification does not include diploma or associate diploma exit points."
    ],
    [
        "exit_point_name" => "Bachelor of Health Promotion and Education",
        "credit_hours" => 134,
        "notes" => "Final program award after successful completion of all approved program requirements."
    ]
];

try {
    $conn->beginTransaction();

    $deleteTracksStmt = $conn->prepare("
        DELETE FROM program_specification_tracks
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteTracksStmt->execute([
        ":program_specification_id" => $spec_id
    ]);

    $deleteExitStmt = $conn->prepare("
        DELETE FROM program_specification_exit_points
        WHERE program_specification_id = :program_specification_id
    ");
    $deleteExitStmt->execute([
        ":program_specification_id" => $spec_id
    ]);

    $insertTrackStmt = $conn->prepare("
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

    $trackInserted = 0;

    foreach ($defaultTracks as $row) {
        $insertTrackStmt->execute([
            ":program_specification_id" => $spec_id,
            ":track_name" => $row["track_name"],
            ":credit_hours" => $row["credit_hours"],
            ":professions_jobs" => $row["professions_jobs"],
            ":notes" => $row["notes"]
        ]);

        $trackInserted++;
    }

    $insertExitStmt = $conn->prepare("
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

    $exitInserted = 0;

    foreach ($defaultExitPoints as $row) {
        $insertExitStmt->execute([
            ":program_specification_id" => $spec_id,
            ":exit_point_name" => $row["exit_point_name"],
            ":credit_hours" => $row["credit_hours"],
            ":notes" => $row["notes"]
        ]);

        $exitInserted++;
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification Tracks and Exit Points",
        $spec_id,
        "Default HPE tracks and exit points imported. Tracks: " . $trackInserted . " | Exit points: " . $exitInserted
    );

    header("Location: manage_program_specification_tracks_exit_points.php?id=" . $spec_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}
