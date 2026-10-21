<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole("Admin");

$error = "";
$message = "";

// ===============================
// Load current settings
// ===============================
$stmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// ===============================
// Create settings row if missing
// ===============================
if (!$settings) {
    $conn->query("
        INSERT INTO system_settings
        (
            institution_name,
            college_name,
            department_name,
            program_name,
            default_academic_year,
            default_semester,
            quality_coordinator,
            program_leader
        )
        VALUES
        (
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        )
    ");

    $stmt = $conn->query("
        SELECT *
        FROM system_settings
        ORDER BY id ASC
        LIMIT 1
    ");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ===============================
// Handle update
// ===============================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $institution_name = trim($_POST["institution_name"] ?? "");
    $college_name = trim($_POST["college_name"] ?? "");
    $department_name = trim($_POST["department_name"] ?? "");
    $program_name = trim($_POST["program_name"] ?? "");

    $default_academic_year = trim($_POST["default_academic_year"] ?? "");
    $default_semester = trim($_POST["default_semester"] ?? "");

    $quality_coordinator = trim($_POST["quality_coordinator"] ?? "");
    $program_leader = trim($_POST["program_leader"] ?? "");

    if ($institution_name === "") {
        $error = "Institution name is required.";
    } elseif ($college_name === "") {
        $error = "College name is required.";
    } elseif ($department_name === "") {
        $error = "Department name is required.";
    } elseif ($program_name === "") {
        $error = "Program name is required.";
    } else {
        $sql = "
            UPDATE system_settings
            SET
                institution_name = :institution_name,
                college_name = :college_name,
                department_name = :department_name,
                program_name = :program_name,
                default_academic_year = :default_academic_year,
                default_semester = :default_semester,
                quality_coordinator = :quality_coordinator,
                program_leader = :program_leader
            WHERE id = :id
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":institution_name" => $institution_name,
            ":college_name" => $college_name,
            ":department_name" => $department_name,
            ":program_name" => $program_name,
            ":default_academic_year" => $default_academic_year,
            ":default_semester" => $default_semester,
            ":quality_coordinator" => $quality_coordinator,
            ":program_leader" => $program_leader,
            ":id" => $settings["id"]
        ]);

        $message = "Settings updated successfully.";

        // Reload settings
        $stmt = $conn->query("
            SELECT *
            FROM system_settings
            ORDER BY id ASC
            LIMIT 1
        ");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
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

        .settings-form {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
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

        .form-section h2 {
            margin-top: 0;
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

        .alert-success {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
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

            .settings-form {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>System Settings</h1>
        <p>Configure institution, college, department, program identity, academic period, and quality responsibilities.</p>
    </div>

    <?php if ($error !== ""): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
        <div class="alert-success">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <form method="POST" action="settings.php" class="settings-form">

            <div class="form-section">
                <h2>Institution and Program Identity</h2>

                <div class="form-grid">
                    <div>
                        <label>Institution Name</label>
                        <input type="text" name="institution_name" required
                               value="<?php echo htmlspecialchars($settings["institution_name"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>College Name</label>
                        <input type="text" name="college_name" required
                               value="<?php echo htmlspecialchars($settings["college_name"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Department Name</label>
                        <input type="text" name="department_name" required
                               value="<?php echo htmlspecialchars($settings["department_name"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Program Name</label>
                        <input type="text" name="program_name" required
                               value="<?php echo htmlspecialchars($settings["program_name"] ?? ""); ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Default Academic Period</h2>

                <div class="form-grid">
                    <div>
                        <label>Default Academic Year</label>
                        <input type="text" name="default_academic_year"
                               value="<?php echo htmlspecialchars($settings["default_academic_year"] ?? ""); ?>"
                               placeholder="Example: 2025-2026">
                        <div class="hint">
                            This can be used as the default value in reports and exports.
                        </div>
                    </div>

                    <div>
                        <label>Default Semester</label>
                        <select name="default_semester">
                            <?php
                            $semesters = ["", "First Semester", "Second Semester", "Third Semester", "Annual"];
                            foreach ($semesters as $semester):
                            ?>
                                <option value="<?php echo htmlspecialchars($semester); ?>"
                                    <?php if (($settings["default_semester"] ?? "") === $semester) echo "selected"; ?>>
                                    <?php echo $semester === "" ? "-- Select Default Semester --" : htmlspecialchars($semester); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h2>Quality Responsibilities</h2>

                <div class="form-grid">
                    <div>
                        <label>Quality Coordinator</label>
                        <input type="text" name="quality_coordinator"
                               value="<?php echo htmlspecialchars($settings["quality_coordinator"] ?? ""); ?>">
                    </div>

                    <div>
                        <label>Program Leader</label>
                        <input type="text" name="program_leader"
                               value="<?php echo htmlspecialchars($settings["program_leader"] ?? ""); ?>">
                    </div>
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Settings</button>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                <a href="maintenance.php" class="btn btn-purple">Maintenance</a>
            </div>

        </form>
    </div>

</div>

</body>
</html>