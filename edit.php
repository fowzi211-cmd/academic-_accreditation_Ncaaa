<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$error = "";

// ===============================
// Check that ID exists in URL
// ===============================
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    die("Invalid evidence ID.");
}

$id = (int)$_GET["id"];

// ===============================
// Load current evidence item
// ===============================
$stmt = $conn->prepare("
    SELECT *
    FROM evidence_items
    WHERE id = :id
");
$stmt->execute([":id" => $id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    die("Evidence item not found.");
}

// ===============================
// Load substandards for dropdown
// ===============================
$substandardSql = "
    SELECT 
        ss.id,
        ss.substandard_code,
        ss.substandard_text,
        s.standard_code,
        s.standard_title
    FROM substandards ss
    INNER JOIN standards s ON ss.standard_id = s.id
    ORDER BY s.id, ss.substandard_code
";

$substandardStmt = $conn->query($substandardSql);
$substandards = $substandardStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// Update record when form is submitted
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"] ?? "");
    $substandard_id = !empty($_POST["substandard_id"]) ? (int)$_POST["substandard_id"] : null;
    $evidence_type = trim($_POST["evidence_type"] ?? "");
    $responsible_person = trim($_POST["responsible_person"] ?? "");
    $status = trim($_POST["status"] ?? "");
    $academic_year = trim($_POST["academic_year"] ?? "");
    $semester = trim($_POST["semester"] ?? "");
    $review_status = trim($_POST["review_status"] ?? "");
    $reviewer_notes = trim($_POST["reviewer_notes"] ?? "");
    $notes = trim($_POST["notes"] ?? "");

    if ($title === "") {
        $error = "Evidence title is required.";
    } else {
        $sql = "
            UPDATE evidence_items
            SET 
                substandard_id = :substandard_id,
                title = :title,
                evidence_type = :evidence_type,
                responsible_person = :responsible_person,
                status = :status,
                academic_year = :academic_year,
                semester = :semester,
                review_status = :review_status,
                reviewer_notes = :reviewer_notes,
                notes = :notes
            WHERE id = :id
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":substandard_id" => $substandard_id,
            ":title" => $title,
            ":evidence_type" => $evidence_type,
            ":responsible_person" => $responsible_person,
            ":status" => $status,
            ":academic_year" => $academic_year,
            ":semester" => $semester,
            ":review_status" => $review_status,
            ":reviewer_notes" => $reviewer_notes,
            ":notes" => $notes,
            ":id" => $id
        ]);

        logActivity(
            $conn,
            "Update",
            "Evidence",
            $id,
            "Evidence item updated: " . $title
        );

        header("Location: index.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Evidence</title>
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

        .evidence-form {
            max-width: 950px;
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
            min-height: 110px;
        }

        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
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

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .evidence-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Edit Evidence</h1>
        <p>Update evidence details, substandard mapping, review status, reviewer notes, and general notes.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="edit.php?id=<?php echo htmlspecialchars($id); ?>" class="evidence-form">

            <div class="form-section">
                <h2>Basic Evidence Information</h2>

                <label>Evidence Title</label>
                <input type="text" name="title" required
                       value="<?php echo htmlspecialchars($item["title"] ?? ""); ?>">

                <label>Linked Substandard</label>
                <select name="substandard_id">
                    <option value="">-- Select Substandard --</option>

                    <?php foreach ($substandards as $sub): ?>
                        <option value="<?php echo htmlspecialchars($sub["id"]); ?>"
                            <?php if ((int)($item["substandard_id"] ?? 0) === (int)$sub["id"]) echo "selected"; ?>>
                            <?php
                                echo htmlspecialchars(
                                    $sub["standard_code"] . " - " .
                                    $sub["substandard_code"] . " | " .
                                    $sub["substandard_text"]
                                );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="hint">
                    This links the evidence directly to the accreditation requirement.
                </div>
            </div>

            <div class="form-section">
                <h2>Classification and Responsibility</h2>

                <div class="form-grid">

                    <div>
                        <label>Evidence Type</label>
                        <select name="evidence_type">
                            <?php
                            $types = ["", "Manual", "Policy", "Report", "Minutes", "Survey", "KPI", "Action Plan", "Matrix", "Dashboard", "Other"];
                            foreach ($types as $type):
                            ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"
                                    <?php if (($item["evidence_type"] ?? "") === $type) echo "selected"; ?>>
                                    <?php echo $type === "" ? "-- Select Type --" : htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Responsible Person</label>
                        <input type="text" name="responsible_person"
                               value="<?php echo htmlspecialchars($item["responsible_person"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <?php
                            $statuses = ["Missing", "Draft", "Uploaded", "Reviewed", "Approved"];
                            foreach ($statuses as $status):
                            ?>
                                <option value="<?php echo htmlspecialchars($status); ?>"
                                    <?php if (($item["status"] ?? "") === $status) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Review Status</label>
                        <select name="review_status">
                            <?php
                            $reviewStatuses = ["", "Not Reviewed", "Needs Revision", "Accepted", "Rejected"];
                            foreach ($reviewStatuses as $review):
                            ?>
                                <option value="<?php echo htmlspecialchars($review); ?>"
                                    <?php if (($item["review_status"] ?? "") === $review) echo "selected"; ?>>
                                    <?php echo $review === "" ? "-- Select Review Status --" : htmlspecialchars($review); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>

            <div class="form-section">
                <h2>Academic Period</h2>

                <div class="form-grid">

                    <div>
                        <label>Academic Year</label>
                        <input type="text" name="academic_year"
                               value="<?php echo htmlspecialchars($item["academic_year"] ?? ""); ?>"
                               placeholder="Example: 2025-2026">
                    </div>

                    <div>
                        <label>Semester</label>
                        <select name="semester">
                            <?php
                            $semesters = ["", "First Semester", "Second Semester", "Third Semester", "Annual"];
                            foreach ($semesters as $semester):
                            ?>
                                <option value="<?php echo htmlspecialchars($semester); ?>"
                                    <?php if (($item["semester"] ?? "") === $semester) echo "selected"; ?>>
                                    <?php echo $semester === "" ? "-- Select Semester --" : htmlspecialchars($semester); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>

            <div class="form-section">
                <h2>Reviewer Notes and General Notes</h2>

                <label>Reviewer Notes</label>
                <textarea name="reviewer_notes" placeholder="Write reviewer comments here."><?php echo htmlspecialchars($item["reviewer_notes"] ?? ""); ?></textarea>

                <label>General Notes</label>
                <textarea name="notes" placeholder="Write short notes about this evidence."><?php echo htmlspecialchars($item["notes"] ?? ""); ?></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Update Evidence</button>
                <a href="index.php" class="btn btn-secondary">Back to Evidence Repository</a>
                <a href="upload_file.php?id=<?php echo htmlspecialchars($id); ?>" class="btn btn-purple">Upload/View Files</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>