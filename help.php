<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Faculty", "Reviewer"]);

$userRole = $_SESSION["role"] ?? "";
$userName = $_SESSION["full_name"] ?? "";
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Help / User Guide</title>
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

        .guide-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(280px, 1fr));
            gap: 18px;
        }

        .guide-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
        }

        .guide-card h3 {
            margin-top: 0;
            margin-bottom: 6px;
            color: #333;
        }

        ol, ul {
            margin-top: 8px;
        }

        li {
            margin-bottom: 6px;
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

        .role-box {
            background: #e9ecef;
            border: 1px solid #ced4da;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
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

        @media (max-width: 900px) {
            .guide-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Help / User Guide</h1>
        <p>Practical guide for using the Quality Management System.</p>
    </div>

    <div class="role-box">
        Logged in as:
        <strong><?php echo htmlspecialchars($userName); ?></strong>
        |
        Role:
        <strong><?php echo htmlspecialchars($userRole); ?></strong>
    </div>

    <div class="panel">
        <h2>1. System Access</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>How to start the system</h3>
                <ol>
                    <li>Open Command Prompt.</li>
                    <li>Run:</li>
                </ol>

                <pre>cd C:\quality_system
php -S localhost:8000</pre>

                <ol start="3">
                    <li>Open the browser.</li>
                    <li>Go to:</li>
                </ol>

                <pre>http://localhost:8000/login.php</pre>
            </div>

            <div class="guide-card">
                <h3>How to login</h3>
                <ol>
                    <li>Open the login page.</li>
                    <li>Enter your email and password.</li>
                    <li>Click Login.</li>
                    <li>You will go to the Home page.</li>
                </ol>

                <p class="small-text">
                    If login fails, check that your user account is Active and that the email/password are correct.
                </p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>2. Main User Roles</h2>

        <table>
            <tr>
                <th>Role</th>
                <th>Main Access</th>
            </tr>

            <tr>
                <td>Admin</td>
                <td>Full system access, users, settings, standards, maintenance, versions, health check.</td>
            </tr>

            <tr>
                <td>Quality Coordinator</td>
                <td>Evidence, tasks, reports, KPIs, activity logs, and quality workflow management.</td>
            </tr>

            <tr>
                <td>Program Leader</td>
                <td>Evidence review, tasks, reports, KPIs, SSR sections, and program-level quality review.</td>
            </tr>

            <tr>
                <td>Faculty</td>
                <td>Evidence viewing/uploading, My Tasks, course reports, and task updates.</td>
            </tr>

            <tr>
                <td>Reviewer</td>
                <td>View evidence, reports, KPIs, standard evidence reports, and SSR sections.</td>
            </tr>
        </table>
    </div>

    <div class="panel">
        <h2>3. Evidence Repository</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>Add evidence</h3>
                <ol>
                    <li>Go to <strong>Evidence Repository</strong>.</li>
                    <li>Click <strong>Add Evidence</strong>.</li>
                    <li>Enter the evidence title.</li>
                    <li>Select the linked substandard.</li>
                    <li>Choose evidence type and status.</li>
                    <li>Save.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Upload evidence files</h3>
                <ol>
                    <li>Go to <strong>Evidence Repository</strong>.</li>
                    <li>Click <strong>Upload/View</strong> beside the evidence item.</li>
                    <li>Select a PDF, Word, Excel, PowerPoint, JPG, or PNG file.</li>
                    <li>Click Upload.</li>
                </ol>

                <p class="small-text">
                    Maximum file size is currently 10 MB.
                </p>
            </div>

            <div class="guide-card">
                <h3>Filter evidence</h3>
                <ol>
                    <li>Use the filter box at the top of Evidence Repository.</li>
                    <li>Filter by standard, substandard, status, academic year, semester, or responsible person.</li>
                    <li>Click Apply Filters.</li>
                    <li>Click Clear Filters to reset.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Export evidence</h3>
                <ol>
                    <li>Apply filters if needed.</li>
                    <li>Click <strong>Export Evidence CSV</strong>.</li>
                    <li>Open the downloaded CSV in Excel.</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>4. Tasks and My Tasks</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>Create a task</h3>
                <ol>
                    <li>Go to <strong>Tasks</strong>.</li>
                    <li>Click <strong>Add New Task</strong>.</li>
                    <li>Enter task title and description.</li>
                    <li>Link to a substandard or evidence item if applicable.</li>
                    <li>Set responsible person, due date, priority, and status.</li>
                    <li>Save.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Use My Tasks</h3>
                <ol>
                    <li>Go to <strong>My Tasks</strong>.</li>
                    <li>Review assigned tasks.</li>
                    <li>Use the quick status update to change task status.</li>
                    <li>Use Full Edit for detailed updates.</li>
                </ol>

                <p class="small-text">
                    Faculty users see tasks where the responsible person matches their logged-in name.
                </p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>5. Course Reports</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>Create course report</h3>
                <ol>
                    <li>Go to <strong>Course Reports</strong>.</li>
                    <li>Click <strong>Add Course Report</strong>.</li>
                    <li>Enter course code, title, academic year, semester, and coordinator.</li>
                    <li>Add student results, CLO achievement, assessment summary, feedback, and improvement actions.</li>
                    <li>Save.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Export course report</h3>
                <ol>
                    <li>Open a course report.</li>
                    <li>Click <strong>Export Word</strong> to download a Word document.</li>
                    <li>Use <strong>Print / Save as PDF</strong> if needed.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Filter and export course reports</h3>
                <ol>
                    <li>Use filters by academic year, semester, status, coordinator, or search.</li>
                    <li>Click <strong>Export Course Reports CSV</strong>.</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>6. Semester Program Reports</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>Create semester report</h3>
                <ol>
                    <li>Go to <strong>Semester Reports</strong>.</li>
                    <li>Click <strong>Add Semester Report</strong>.</li>
                    <li>Enter academic year, semester, report title, and prepared by.</li>
                    <li>Write summaries for strengths, weaknesses, KPIs, course reports, student feedback, assessment, resources, and improvement actions.</li>
                    <li>Save.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Link supporting items</h3>
                <ol>
                    <li>Go to <strong>Semester Reports</strong>.</li>
                    <li>Click <strong>Link Items</strong>.</li>
                    <li>Link evidence, tasks, course reports, and KPI results.</li>
                    <li>Open the report view to see linked items.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Export semester report</h3>
                <ol>
                    <li>Open the semester report.</li>
                    <li>Click <strong>Export Word</strong>.</li>
                    <li>The export includes linked evidence, tasks, course reports, and KPI results.</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>7. KPIs and KPI Results</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>Add KPI</h3>
                <ol>
                    <li>Go to <strong>KPIs</strong>.</li>
                    <li>Click <strong>Add KPI</strong>.</li>
                    <li>Enter KPI code, name, description, measurement method, target, unit, and responsible person.</li>
                    <li>Save.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Add KPI result</h3>
                <ol>
                    <li>Go to <strong>KPIs</strong> or <strong>KPI Results</strong>.</li>
                    <li>Click <strong>Add KPI Result</strong>.</li>
                    <li>Select KPI, academic year, and semester.</li>
                    <li>Enter target and actual value.</li>
                    <li>The system calculates achievement percentage.</li>
                    <li>Add analysis and improvement action.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>KPI dashboard monitoring</h3>
                <ol>
                    <li>Go to <strong>Dashboard</strong>.</li>
                    <li>Review KPI Achievement Monitoring.</li>
                    <li>KPIs without results show as missing.</li>
                    <li>Below-target KPI results are highlighted.</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>8. Standard Evidence Report</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>Generate evidence map</h3>
                <ol>
                    <li>Go to <strong>Standard Evidence Report</strong>.</li>
                    <li>Select a standard.</li>
                    <li>Click Generate Report.</li>
                    <li>Review substandard coverage, evidence items, file counts, and related tasks.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Export evidence map</h3>
                <ol>
                    <li>Generate the standard report.</li>
                    <li>Click <strong>Export Word</strong>.</li>
                    <li>Use the document for accreditation evidence review.</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>9. SSR Builder</h2>

        <div class="guide-grid">
            <div class="guide-card">
                <h3>Create SSR section</h3>
                <ol>
                    <li>Go to <strong>SSR Builder</strong>.</li>
                    <li>Click <strong>Create SSR</strong> beside a standard.</li>
                    <li>Write evidence-based explanation, strengths, areas for improvement, action plan, and conclusion.</li>
                    <li>Save.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Export SSR section</h3>
                <ol>
                    <li>Open an SSR section.</li>
                    <li>Click <strong>Export Word</strong>.</li>
                    <li>The export includes narrative, evidence summary, substandards, evidence items, and tasks.</li>
                </ol>
            </div>

            <div class="guide-card">
                <h3>Export full SSR</h3>
                <ol>
                    <li>Go to <strong>SSR Builder</strong>.</li>
                    <li>Click <strong>Export Full SSR Word</strong>.</li>
                    <li>The document includes all standards and available SSR sections.</li>
                </ol>
            </div>
        </div>
    </div>

    <?php if (hasRole("Admin")): ?>
        <div class="panel">
            <h2>10. Admin Functions</h2>

            <div class="guide-grid">
                <div class="guide-card">
                    <h3>Users</h3>
                    <ol>
                        <li>Go to <strong>Users</strong>.</li>
                        <li>Add, edit, activate, deactivate, or delete users.</li>
                        <li>Assign appropriate roles.</li>
                    </ol>
                </div>

                <div class="guide-card">
                    <h3>Settings</h3>
                    <ol>
                        <li>Go to <strong>Settings</strong>.</li>
                        <li>Update institution, college, department, program name, academic year, and semester.</li>
                        <li>These settings appear in exports and default form values.</li>
                    </ol>
                </div>

                <div class="guide-card">
                    <h3>Activity Logs</h3>
                    <ol>
                        <li>Go to <strong>Activity Logs</strong>.</li>
                        <li>Review login, create, update, delete, upload, and system version actions.</li>
                        <li>Use filters to find specific activity.</li>
                    </ol>
                </div>

                <div class="guide-card">
                    <h3>Health Check</h3>
                    <ol>
                        <li>Go to <strong>Health Check</strong>.</li>
                        <li>Review missing files, folders, tables, settings, and admin account status.</li>
                        <li>Fix failed checks before continuing development.</li>
                    </ol>
                </div>

                <div class="guide-card">
                    <h3>Maintenance</h3>
                    <ol>
                        <li>Go to <strong>Maintenance</strong>.</li>
                        <li>Review backup instructions, core files, system summary, and latest activity logs.</li>
                    </ol>
                </div>

                <div class="guide-card">
                    <h3>Versions</h3>
                    <ol>
                        <li>Go to <strong>Versions</strong>.</li>
                        <li>Record stable system versions after backup.</li>
                        <li>Store backup file name and copied folder name.</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>11. Backup and Restore</h2>

            <div class="warning-box">
                Always run backup before major changes, after completing a module, and before testing risky edits.
            </div>

            <h3>Backup command</h3>

            <pre>cd C:\quality_system
backup_database.bat</pre>

            <h3>Backup folder</h3>

            <pre>C:\quality_system_backups</pre>

            <h3>Restore command</h3>

            <pre>cd C:\quality_system
restore_database.bat</pre>

            <div class="danger-box">
                Restore can overwrite current database content. Use only when necessary and only with the correct backup file.
            </div>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>12. Common Problems</h2>

        <table>
            <tr>
                <th>Problem</th>
                <th>Cause</th>
                <th>Fix</th>
            </tr>

            <tr>
                <td>PHP code appears as text</td>
                <td>Missing <code>&lt;?php</code> at top of file or wrong file content</td>
                <td>Replace the full file with the correct code.</td>
            </tr>

            <tr>
                <td>Page says Access Denied</td>
                <td>Your role does not have permission</td>
                <td>Login as the correct user or ask Admin to change your role.</td>
            </tr>

            <tr>
                <td>Database connection failed</td>
                <td>MySQL not running or wrong password in db.php</td>
                <td>Start MySQL and check db.php credentials.</td>
            </tr>

            <tr>
                <td>File upload fails</td>
                <td>Wrong file type, file too large, or uploads folder missing</td>
                <td>Use allowed file type under 10 MB and check uploads folder.</td>
            </tr>

            <tr>
                <td>Dashboard looks empty</td>
                <td>No records entered yet</td>
                <td>Add evidence, tasks, course reports, semester reports, or KPI results.</td>
            </tr>

            <tr>
                <td>Health Check fails</td>
                <td>Missing table, file, folder, or settings row</td>
                <td>Review the failed check and recreate the missing item.</td>
            </tr>
        </table>
    </div>

</div>

</body>
</html>