<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";
require_once "log_activity.php";

requireRole("Admin");

$error = "";
$created_by = $_SESSION["full_name"] ?? "";
$default_date = date("Y-m-d");

// ===============================
// Handle form submission
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $version_name = trim($_POST["version_name"] ?? "");
    $version_date = trim($_POST["version_date"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $backup_file_name = trim($_POST["backup_file_name"] ?? "");
    $folder_copy_name = trim($_POST["folder_copy_name"] ?? "");
    $notes = trim($_POST["notes"] ?? "");
    $created_by_input = trim($_POST["created_by"] ?? "");

    if ($version_name === "") {
        $error = "Version name is required.";
    } elseif ($version_date === "") {
        $error = "Version date is required.";
    } else {
        $sql = "
            INSERT INTO system_versions
            (
                version_name,
                version_date,
                description,
                backup_file_name,
                folder_copy_name,
                notes,
                created_by
            )
            VALUES
            (
                :version_name,
                :version_date,
                :description,
                :backup_file_name,
                :folder_copy_name,
                :notes,
                :created_by
            )
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":version_name" => $version_name,
            ":version_date" => $version_date,
            ":description" => $description,
            ":backup_file_name" => $backup_file_name,
            ":folder_copy_name" => $folder_copy_name,
            ":notes" => $notes,
            ":created_by" => $created_by_input
        ]);

        $newVersionId = $conn->lastInsertId();

        logActivity(
            $conn,
            "Create",
            "System Versions",
            $newVersionId,
            "System version recorded: " . $version_name
        );

        header("Location: versions.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Add System Version</title>
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

        .version-form {
            max-width: 1000px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(250px, 1fr));
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
            min-height: 120px;
        }

        .large-textarea {
            min-height: 150px;
        }

        .hint {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            color: #664d03;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            max-width: 1000px;
            box-sizing: border-box;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
            max-width: 1000px;
            box-sizing: border-box;
        }

        .button-row {
            margin-top: 18px;
        }

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .version-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Add System Version</h1>
        <p>Record a stable development version, backup file, folder copy location, and restoration notes.</p>
    </div>

    <div class="warning-box">
        Before saving a new version record, run <strong>backup_database.bat</strong> and copy the full
        <strong>C:\quality_system</strong> folder to a versioned folder.
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="panel">

        <form method="POST" action="create_version.php" class="version-form">

            <div class="form-section">
                <h2>Version Information</h2>

                <div class="form-grid">
                    <div>
                        <label>Version Name</label>
                        <input type="text" name="version_name" required
                               placeholder="Example: v8_ssr_builder_working">
                    </div>

                    <div>
                        <label>Version Date</label>
                        <input type="date" name="version_date" required
                               value="<?php echo htmlspecialchars($default_date); ?>">
                    </div>

                    <div>
                        <label>Backup File Name</label>
                        <input type="text" name="backup_file_name"
                               placeholder="Example: quality_system_2026-05-15_20-30-00.sql">
                        <div class="hint">
                            Use the exact backup file name from C:\quality_system_backups.
                        </div>
                    </div>

                    <div>
                        <label>Folder Copy Name</label>
                        <input type="text" name="folder_copy_name"
                               placeholder="Example: C:\quality_system_v8_ssr_builder_working">
                    </div>

                    <div>
                        <label>Created By</label>
                        <input type="text" name="created_by"
                               value="<?php echo htmlspecialchars($created_by); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Description and Notes</h2>

                <label>Description of Changes</label>
                <textarea name="description" class="large-textarea"
                          placeholder="Describe what was added, fixed, or stabilized in this version."></textarea>

                <label>Notes</label>
                <textarea name="notes"
                          placeholder="Any restore notes, warnings, or next-step reminders."></textarea>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Version</button>
                <a href="versions.php" class="btn btn-secondary">Back to Versions</a>
                <a href="maintenance.php" class="btn btn-purple">Maintenance</a>
            </div>

        </form>

    </div>

</div>

</body>
</html>