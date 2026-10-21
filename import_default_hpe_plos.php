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

function logActivitySafe($conn, $action, $module, $recordId, $description) {
    if (function_exists("logActivity")) {
        logActivity($conn, $action, $module, $recordId, $description);
    }
}

if (!tableExists($conn, "program_specifications")) {
    die("program_specifications table does not exist.");
}

if (!tableExists($conn, "program_specification_plos")) {
    die("program_specification_plos table does not exist.");
}

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

$defaultPLOs = [
    [
        "plo_code" => "K1",
        "plo_domain" => "Knowledge and Understanding",
        "plo_text" => "Describe different backgrounds, theories, concepts, and principles in public health and health promotion.",
        "display_order" => 1
    ],
    [
        "plo_code" => "K2",
        "plo_domain" => "Knowledge and Understanding",
        "plo_text" => "Demonstrate understanding of health promotion practices, challenges, and issues in different contexts.",
        "display_order" => 2
    ],
    [
        "plo_code" => "S1",
        "plo_domain" => "Skills",
        "plo_text" => "Develop evidence-based and best practices health promotion programs.",
        "display_order" => 3
    ],
    [
        "plo_code" => "S2",
        "plo_domain" => "Skills",
        "plo_text" => "Communicate effectively with relevant stakeholders.",
        "display_order" => 4
    ],
    [
        "plo_code" => "S3",
        "plo_domain" => "Skills",
        "plo_text" => "Conduct research activities in health promotion and allied fields.",
        "display_order" => 5
    ],
    [
        "plo_code" => "S4",
        "plo_domain" => "Skills",
        "plo_text" => "Use appropriate tools and technologies relevant to health promotion.",
        "display_order" => 6
    ],
    [
        "plo_code" => "S5",
        "plo_domain" => "Skills",
        "plo_text" => "Manage resources to enhance health promotion practices.",
        "display_order" => 7
    ],
    [
        "plo_code" => "V1",
        "plo_domain" => "Values, Autonomy, and Responsibility",
        "plo_text" => "Apply concepts of leadership, teamwork, time management, commitment, collaboration, and autonomy responsibly in health promotion practices.",
        "display_order" => 8
    ],
    [
        "plo_code" => "V2",
        "plo_domain" => "Values, Autonomy, and Responsibility",
        "plo_text" => "Adhere to ethical and legal principles in health promotion practices.",
        "display_order" => 9
    ]
];

try {
    $conn->beginTransaction();

    $checkStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM program_specification_plos
        WHERE program_specification_id = :program_specification_id
          AND plo_code = :plo_code
    ");

    $insertStmt = $conn->prepare("
        INSERT INTO program_specification_plos
        (
            program_specification_id,
            plo_code,
            plo_domain,
            plo_text,
            nqf_level,
            display_order,
            notes
        )
        VALUES
        (
            :program_specification_id,
            :plo_code,
            :plo_domain,
            :plo_text,
            :nqf_level,
            :display_order,
            :notes
        )
    ");

    $inserted = 0;
    $skipped = 0;

    foreach ($defaultPLOs as $plo) {
        $checkStmt->execute([
            ":program_specification_id" => $spec_id,
            ":plo_code" => $plo["plo_code"]
        ]);

        $exists = (int)$checkStmt->fetchColumn();

        if ($exists > 0) {
            $skipped++;
            continue;
        }

        $insertStmt->execute([
            ":program_specification_id" => $spec_id,
            ":plo_code" => $plo["plo_code"],
            ":plo_domain" => $plo["plo_domain"],
            ":plo_text" => $plo["plo_text"],
            ":nqf_level" => null,
            ":display_order" => $plo["display_order"],
            ":notes" => "Default locked HPE PLO imported."
        ]);

        $inserted++;
    }

    $conn->commit();

    logActivitySafe(
        $conn,
        "Import",
        "Program Specification PLOs",
        $spec_id,
        "Default HPE PLOs imported. Inserted: " . $inserted . " | Skipped: " . $skipped
    );

    header("Location: manage_program_specification_plos.php?id=" . $spec_id);
    exit;

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Import failed: " . safeText($e->getMessage()));
}