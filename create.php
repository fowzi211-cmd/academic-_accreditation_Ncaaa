<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader"]);

$error = "";

// ===============================
// Load system settings for default values
// ===============================
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

$default_academic_year = $settings["default_academic_year"] ?? "";
$default_semester = $settings["default_semester"] ?? "";

// ===============================
// Get substandards for dropdown
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
// Handle form submission
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
    $notes = trim($_POST["notes"] ?? "");

    if ($title === "") {
        $error = "Evidence title is required.";
    } else {
        $sql = "
            INSERT INTO evidence_items 
            (
                substandard_id,
                title,
                evidence_type,
                responsible_person,
                status,
                academic_year,
                semester,
                review_status,
                notes
            )
            VALUES 
            (
                :substandard_id,
                :title,
                :evidence_type,
                :responsible_person,
                :status,
                :academic_year,
                :semester,
                :review_status,
                :notes
            )
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
            ":notes" => $notes
        ]);

        $newEvidenceId = $conn->lastInsertId();

        logActivity(
            $conn,
            "Create",
            "Evidence",
            $newEvidenceId,
            "Evidence item created: " . $title
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
    <title>Add Evidence</title>
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
        <h1>Add New Evidence</h1>
        <p>Create a new accreditation evidence item and link it to the relevant substandard.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create.php" class="evidence-form">

            <div class="form-section">
                <h2>Basic Evidence Information</h2>

                <label>Evidence Title</label>
                <input type="text" name="title" required placeholder="Example: Program Manual">

                <label>Linked Substandard</label>
                <select name="substandard_id">
                    <option value="">-- Select Substandard --</option>

                    <?php foreach ($substandards as $sub): ?>
                        <option value="<?php echo htmlspecialchars($sub["id"]); ?>">
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
                            <option value="">-- Select Type --</option>
                            <option value="Manual">Manual</option>
                            <option value="Policy">Policy</option>
                            <option value="Report">Report</option>
                            <option value="Minutes">Minutes</option>
                            <option value="Survey">Survey</option>
                            <option value="KPI">KPI</option>
                            <option value="Action Plan">Action Plan</option>
                            <option value="Matrix">Matrix</option>
                            <option value="Dashboard">Dashboard</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label>Responsible Person</label>
                        <input type="text" name="responsible_person" placeholder="Example: Quality Coordinator">
                    </div>

                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="Missing">Missing</option>
                            <option value="Draft">Draft</option>
                            <option value="Uploaded">Uploaded</option>
                            <option value="Reviewed">Reviewed</option>
                            <option value="Approved">Approved</option>
                        </select>
                    </div>

                    <div>
                        <label>Review Status</label>
                        <select name="review_status">
                            <option value="">-- Select Review Status --</option>
                            <option value="Not Reviewed">Not Reviewed</option>
                            <option value="Needs Revision">Needs Revision</option>
                            <option value="Accepted">Accepted</option>
                            <option value="Rejected">Rejected</option>
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
                               value="<?php echo htmlspecialchars($default_academic_year); ?>"
                               placeholder="Example: 2025-2026">
                    </div>

                    <div>
                        <label>Semester</label>
                        <select name="semester">
                            <option value="">-- Select Semester --</option>

                            <?php
                            $semesters = ["First Semester", "Second Semester", "Third Semester", "Annual"];
                            foreach ($semesters as $sem):
                            ?>
                                <option value="<?php echo htmlspecialchars($sem); ?>"
                                    <?php if ($default_semester === $sem) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($sem); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>

            <div class="form-section">
                <h2>Notes</h2>

                <label>Notes</label>
                <textarea name="notes" placeholder="Write short notes about this evidence."></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Evidence</button>
                <a href="index.php" class="btn btn-secondary">Back to Evidence Repository</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>