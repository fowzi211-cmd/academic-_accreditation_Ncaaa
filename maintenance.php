<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole("Admin");

// ===============================
// Basic system counts
// ===============================
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalEvidence = $conn->query("SELECT COUNT(*) FROM evidence_items")->fetchColumn();
$totalFiles = $conn->query("SELECT COUNT(*) FROM evidence_files")->fetchColumn();
$totalTasks = $conn->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$totalCourseReports = $conn->query("SELECT COUNT(*) FROM course_reports")->fetchColumn();
$totalSemesterReports = $conn->query("SELECT COUNT(*) FROM semester_reports")->fetchColumn();
$totalKpis = $conn->query("SELECT COUNT(*) FROM kpis")->fetchColumn();
$totalKpiResults = $conn->query("SELECT COUNT(*) FROM kpi_results")->fetchColumn();
$totalLogs = $conn->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

// ===============================
// Settings
// ===============================
$settingsStmt = $conn->query("
    SELECT *
    FROM system_settings
    ORDER BY id ASC
    LIMIT 1
");
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

// ===============================
// Latest activity
// ===============================
$latestLogsStmt = $conn->query("
    SELECT *
    FROM activity_logs
    ORDER BY id DESC
    LIMIT 10
");
$latestLogs = $latestLogsStmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// PHP / MySQL info
// ===============================
$phpVersion = phpversion();
$serverSoftware = $_SERVER["SERVER_SOFTWARE"] ?? "PHP built-in server";
$databaseName = "quality_system";
$systemPath = "C:\\quality_system";
$backupPath = "C:\\quality_system_backups";
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Maintenance</title>
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

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
        }

        .card-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
            line-height: 1;
        }

        .card-label {
            color: #555;
            margin-top: 8px;
            font-size: 13px;
        }

        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        code,
        pre {
            background: #f1f3f5;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 8px;
            display: block;
            overflow-x: auto;
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            color: #664d03;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .danger-box {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 1100px) {
            .cards {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }

            .two-column {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Admin Maintenance</h1>
        <p>System health, backup instructions, database information, and key administrative links.</p>
    </div>

    <div class="danger-box">
        <strong>Important:</strong> This page is for Admin users only. Do not share database passwords, backup files, or system folders with unauthorized users.
    </div>

    <div class="panel">
        <h2>Quick Maintenance Actions</h2>

        <a href="activity_logs.php" class="btn btn-purple">View Activity Logs</a>
        <a href="users.php" class="btn btn-purple">Manage Users</a>
        <a href="settings.php" class="btn btn-purple">System Settings</a>
        <a href="health_check.php" class="btn">Health Check</a>
        <a href="versions.php" class="btn">System Versions</a>
        <a href="dashboard.php" class="btn">Dashboard</a>
        <a href="home.php" class="btn btn-secondary">Home</a>
    </div>

    <div class="panel">
        <h2>System Summary</h2>

        <div class="cards">
            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalUsers); ?></div>
                <div class="card-label">Users</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalEvidence); ?></div>
                <div class="card-label">Evidence Items</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalFiles); ?></div>
                <div class="card-label">Uploaded Files</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalTasks); ?></div>
                <div class="card-label">Tasks</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalCourseReports); ?></div>
                <div class="card-label">Course Reports</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalSemesterReports); ?></div>
                <div class="card-label">Semester Reports</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalKpis); ?></div>
                <div class="card-label">KPIs</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalKpiResults); ?></div>
                <div class="card-label">KPI Results</div>
            </div>

            <div class="card">
                <div class="card-number"><?php echo htmlspecialchars($totalLogs); ?></div>
                <div class="card-label">Activity Logs</div>
            </div>
        </div>
    </div>

    <div class="two-column">
        <div class="panel">
            <h2>System Information</h2>

            <table>
                <tr>
                    <th>Item</th>
                    <th>Value</th>
                </tr>

                <tr>
                    <td>System Path</td>
                    <td><?php echo htmlspecialchars($systemPath); ?></td>
                </tr>

                <tr>
                    <td>Database Name</td>
                    <td><?php echo htmlspecialchars($databaseName); ?></td>
                </tr>

                <tr>
                    <td>Backup Folder</td>
                    <td><?php echo htmlspecialchars($backupPath); ?></td>
                </tr>

                <tr>
                    <td>PHP Version</td>
                    <td><?php echo htmlspecialchars($phpVersion); ?></td>
                </tr>

                <tr>
                    <td>Server Software</td>
                    <td><?php echo htmlspecialchars($serverSoftware); ?></td>
                </tr>
            </table>
        </div>

        <div class="panel">
            <h2>Program Identity</h2>

            <table>
                <tr>
                    <th>Item</th>
                    <th>Value</th>
                </tr>

                <tr>
                    <td>Institution</td>
                    <td><?php echo htmlspecialchars($settings["institution_name"] ?? ""); ?></td>
                </tr>

                <tr>
                    <td>College</td>
                    <td><?php echo htmlspecialchars($settings["college_name"] ?? ""); ?></td>
                </tr>

                <tr>
                    <td>Department</td>
                    <td><?php echo htmlspecialchars($settings["department_name"] ?? ""); ?></td>
                </tr>

                <tr>
                    <td>Program</td>
                    <td><?php echo htmlspecialchars($settings["program_name"] ?? ""); ?></td>
                </tr>

                <tr>
                    <td>Default Academic Year</td>
                    <td><?php echo htmlspecialchars($settings["default_academic_year"] ?? ""); ?></td>
                </tr>

                <tr>
                    <td>Default Semester</td>
                    <td><?php echo htmlspecialchars($settings["default_semester"] ?? ""); ?></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>How to Start the System</h2>

        <p>Run this in Command Prompt:</p>

        <pre>cd C:\quality_system
php -S localhost:8000</pre>

        <p>Then open:</p>

        <pre>http://localhost:8000/login.php</pre>

        <p>Or double-click:</p>

        <pre>C:\quality_system\start_quality_system.bat</pre>
    </div>

    <div class="panel">
        <h2>Backup Instructions</h2>

        <div class="warning-box">
            Run a backup before major changes, before testing new modules, and at the end of each work session.
        </div>

        <p>Run this in Command Prompt:</p>

        <pre>cd C:\quality_system
backup_database.bat</pre>

        <p>Backup files are saved in:</p>

        <pre>C:\quality_system_backups</pre>

        <p>Recommended folder copy after stable versions:</p>

        <pre>C:\quality_system_v6_standard_report_working
C:\quality_system_v7_maintenance_working</pre>
    </div>

    <div class="panel">
        <h2>Restore Instructions</h2>

        <div class="danger-box">
            Restore can overwrite current database content. Use only when needed and only with the correct backup file.
        </div>

        <p>Run:</p>

        <pre>cd C:\quality_system
restore_database.bat</pre>
    </div>

    <div class="panel">
        <h2>Do Not Delete These Core Files</h2>

        <table>
            <tr>
                <th>File</th>
                <th>Purpose</th>
            </tr>

            <tr>
                <td>db.php</td>
                <td>Database connection</td>
            </tr>

            <tr>
                <td>auth_check.php</td>
                <td>Login protection</td>
            </tr>

            <tr>
                <td>role_check.php</td>
                <td>Role permissions</td>
            </tr>

            <tr>
                <td>nav.php</td>
                <td>Shared navigation menu</td>
            </tr>

            <tr>
                <td>log_activity.php</td>
                <td>Activity logging function</td>
            </tr>

            <tr>
                <td>uploads folder</td>
                <td>Uploaded evidence files</td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>Latest Activity Logs</h2>

        <?php if (count($latestLogs) > 0): ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Description</th>
                </tr>

                <?php foreach ($latestLogs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log["id"]); ?></td>
                        <td><?php echo htmlspecialchars($log["created_at"]); ?></td>
                        <td><?php echo htmlspecialchars($log["user_name"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($log["user_role"] ?? ""); ?></td>
                        <td><?php echo htmlspecialchars($log["action_type"]); ?></td>
                        <td><?php echo htmlspecialchars($log["module_name"]); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($log["description"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No activity logs found.</p>
        <?php endif; ?>

        <a href="activity_logs.php" class="btn btn-purple">Open Full Activity Logs</a>
    </div>

</div>

</body>
</html>