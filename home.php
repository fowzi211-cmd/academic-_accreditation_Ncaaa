<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

$userName = $_SESSION["full_name"] ?? "";
$userRole = $_SESSION["role"] ?? "";

// ===============================
// Quick counts
// ===============================
$totalEvidence = $conn->query("SELECT COUNT(*) FROM evidence_items")->fetchColumn();
$totalTasks = $conn->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$totalCourseReports = $conn->query("SELECT COUNT(*) FROM course_reports")->fetchColumn();
$totalSemesterReports = $conn->query("SELECT COUNT(*) FROM semester_reports")->fetchColumn();
$totalKpis = $conn->query("SELECT COUNT(*) FROM kpis")->fetchColumn();

$pendingTasks = $conn->query("
    SELECT COUNT(*)
    FROM tasks
    WHERE status IN ('Pending', 'In Progress')
")->fetchColumn();

$overdueTasks = $conn->query("
    SELECT COUNT(*)
    FROM tasks
    WHERE due_date IS NOT NULL
      AND due_date < CURDATE()
      AND status NOT IN ('Completed', 'Cancelled')
")->fetchColumn();

$missingEvidenceSubstandards = $conn->query("
    SELECT COUNT(*)
    FROM substandards ss
    LEFT JOIN evidence_items e ON e.substandard_id = ss.id
    WHERE e.id IS NULL
")->fetchColumn();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quality System Home</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .page-subtitle {
            color: #666;
            margin-bottom: 20px;
        }

        .panel {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .welcome-box {
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .welcome-title {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .welcome-meta {
            color: #555;
            font-size: 14px;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: white;
            padding: 16px;
            border: 1px solid #ddd;
            border-radius: 10px;
        }

        .stat-number {
            font-size: 30px;
            font-weight: bold;
            color: #007bff;
            line-height: 1;
        }

        .stat-number.warning {
            color: #dc3545;
        }

        .stat-number.pending {
            color: #fd7e14;
        }

        .stat-label {
            color: #555;
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.35;
        }

        .module-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(220px, 1fr));
            gap: 16px;
        }

        .module-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 18px;
            min-height: 130px;
        }

        .module-card h3 {
            margin-top: 0;
            margin-bottom: 8px;
            color: #222;
        }

        .module-card p {
            color: #555;
            font-size: 13px;
            min-height: 38px;
        }

        .module-actions {
            margin-top: 12px;
        }

        .admin-note {
            background: #fff3cd;
            border: 1px solid #ffe69c;
            color: #664d03;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        @media (max-width: 1100px) {
            .quick-stats {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }

            .module-grid {
                grid-template-columns: repeat(2, minmax(220px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .quick-stats,
            .module-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Quality Management System</h1>
        <p>Home page for program quality management, accreditation evidence, reports, KPIs, and improvement tracking.</p>
    </div>

    <div class="welcome-box">
        <div class="welcome-title">
            Welcome, <?php echo htmlspecialchars($userName); ?>
        </div>

        <div class="welcome-meta">
            Role:
            <strong><?php echo htmlspecialchars($userRole); ?></strong>
        </div>
    </div>

    <div class="quick-stats">
        <div class="stat-card">
            <div class="stat-number"><?php echo htmlspecialchars($totalEvidence); ?></div>
            <div class="stat-label">Evidence Items</div>
        </div>

        <div class="stat-card">
            <div class="stat-number"><?php echo htmlspecialchars($totalTasks); ?></div>
            <div class="stat-label">Total Tasks</div>
        </div>

        <div class="stat-card">
            <div class="stat-number pending"><?php echo htmlspecialchars($pendingTasks); ?></div>
            <div class="stat-label">Pending / In Progress Tasks</div>
        </div>

        <div class="stat-card">
            <div class="stat-number warning"><?php echo htmlspecialchars($overdueTasks); ?></div>
            <div class="stat-label">Overdue Tasks</div>
        </div>

        <div class="stat-card">
            <div class="stat-number"><?php echo htmlspecialchars($totalCourseReports); ?></div>
            <div class="stat-label">Course Reports</div>
        </div>

        <div class="stat-card">
            <div class="stat-number"><?php echo htmlspecialchars($totalSemesterReports); ?></div>
            <div class="stat-label">Semester Reports</div>
        </div>

        <div class="stat-card">
            <div class="stat-number"><?php echo htmlspecialchars($totalKpis); ?></div>
            <div class="stat-label">KPIs</div>
        </div>

        <div class="stat-card">
            <div class="stat-number warning"><?php echo htmlspecialchars($missingEvidenceSubstandards); ?></div>
            <div class="stat-label">Substandards Without Evidence</div>
        </div>
    </div>

    <?php if (hasRole("Admin")): ?>
        <div class="admin-note">
            Admin reminder: run regular backups using <strong>backup_database.bat</strong> and keep a copy of the full system folder.
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Main Modules</h2>

        <div class="module-grid">

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"])): ?>
                <div class="module-card">
                    <h3>Dashboard</h3>
                    <p>View overall quality indicators, evidence gaps, task status, KPI monitoring, and latest reports.</p>
                    <div class="module-actions">
                        <a href="dashboard.php" class="btn">Open Dashboard</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"])): ?>
                <div class="module-card">
                    <h3>Evidence Repository</h3>
                    <p>Manage evidence items, link them to substandards, upload files, and track review status.</p>
                    <div class="module-actions">
                        <a href="index.php" class="btn">Open Evidence</a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="create.php" class="btn btn-secondary">Add Evidence</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"])): ?>
                <div class="module-card">
                    <h3>My Tasks</h3>
                    <p>View assigned tasks, update task status, and monitor overdue or pending work.</p>
                    <div class="module-actions">
                        <a href="my_tasks.php" class="btn">Open My Tasks</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"])): ?>
                <div class="module-card">
                    <h3>Tasks</h3>
                    <p>Create and manage quality improvement tasks linked to standards, evidence, and responsible persons.</p>
                    <div class="module-actions">
                        <a href="tasks.php" class="btn">Open Tasks</a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="create_task.php" class="btn btn-secondary">Add Task</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"])): ?>
                <div class="module-card">
                    <h3>Course Reports</h3>
                    <p>Prepare, review, export, and analyze course reports and course-level improvement actions.</p>
                    <div class="module-actions">
                        <a href="course_reports.php" class="btn">Open Course Reports</a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty"])): ?>
                            <a href="create_course_report.php" class="btn btn-secondary">Add Report</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"])): ?>
                <div class="module-card">
                    <h3>Semester Reports</h3>
                    <p>Build semester program reports linked to evidence, course reports, KPI results, and tasks.</p>
                    <div class="module-actions">
                        <a href="semester_reports.php" class="btn">Open Semester Reports</a>

                        <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                            <a href="create_semester_report.php" class="btn btn-secondary">Add Report</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"])): ?>
                <div class="module-card">
                    <h3>KPIs</h3>
                    <p>Manage KPI definitions, KPI results, achievement levels, analysis, and improvement actions.</p>
                    <div class="module-actions">
                        <a href="kpis.php" class="btn">Open KPIs</a>
                        <a href="kpi_results.php" class="btn btn-secondary">KPI Results</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"])): ?>
                <div class="module-card">
                    <h3>Standard Evidence Report</h3>
                    <p>Generate accreditation evidence maps by standard with substandard coverage and related tasks.</p>
                    <div class="module-actions">
                        <a href="standard_evidence_report.php" class="btn">Open Report</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole("Admin")): ?>
                <div class="module-card">
                    <h3>Standards / Substandards</h3>
                    <p>Administer standards and substandards used for accreditation evidence mapping.</p>
                    <div class="module-actions">
                        <a href="standards.php" class="btn">Standards</a>
                        <a href="substandards.php" class="btn btn-secondary">Substandards</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole("Admin")): ?>
                <div class="module-card">
                    <h3>Users</h3>
                    <p>Manage user accounts, roles, and access permissions.</p>
                    <div class="module-actions">
                        <a href="users.php" class="btn">Open Users</a>
                        <a href="create_user.php" class="btn btn-secondary">Add User</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole(["Admin", "Quality Coordinator", "Program Leader"])): ?>
                <div class="module-card">
                    <h3>Activity Logs</h3>
                    <p>Review audit trail records for login, evidence, tasks, files, and KPI actions.</p>
                    <div class="module-actions">
                        <a href="activity_logs.php" class="btn">Open Logs</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (hasRole("Admin")): ?>
                <div class="module-card">
                    <h3>Settings</h3>
                    <p>Configure institution, college, department, program, academic year, and default semester.</p>
                    <div class="module-actions">
                        <a href="settings.php" class="btn">Open Settings</a>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>

</body>
</html>