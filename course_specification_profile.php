<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "auth_check.php";
require_once "role_check.php";
require_once "db.php";

requireRole(["Admin", "Quality Coordinator", "Program Leader", "Reviewer"]);

$course_specification_id = isset($_GET["id"]) && is_numeric($_GET["id"]) ? (int)$_GET["id"] : 3;

function safeText($text) {
    return htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8");
}

function nlText($text) {
    return nl2br(htmlspecialchars((string)($text ?? ""), ENT_QUOTES, "UTF-8"));
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

function countChildRows($conn, $tableName, $course_specification_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM $tableName
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return (int)$stmt->fetchColumn();
}

function sumChildColumn($conn, $tableName, $columnName, $course_specification_id) {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, $columnName)) {
        return 0;
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM($columnName), 0)
        FROM $tableName
        WHERE course_specification_id = :id
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return (float)$stmt->fetchColumn();
}

function loadChildRows($conn, $tableName, $course_specification_id, $orderBy = "id ASC") {
    if (!tableExists($conn, $tableName)) {
        return [];
    }

    if (!columnExists($conn, $tableName, "course_specification_id")) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM $tableName
        WHERE course_specification_id = :id
        ORDER BY $orderBy
    ");
    $stmt->execute([":id" => $course_specification_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!tableExists($conn, "course_specifications")) {
    die("course_specifications table does not exist.");
}

$stmt = $conn->prepare("
    SELECT *
    FROM course_specifications
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([":id" => $course_specification_id]);
$courseSpec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$courseSpec) {
    die("Course specification not found.");
}

$program_specification_id = (int)($courseSpec["program_specification_id"] ?? 3);

$clos = loadChildRows($conn, "course_specification_clos", $course_specification_id, "display_order ASC, id ASC");
$topics = loadChildRows($conn, "course_specification_topics", $course_specification_id, "display_order ASC, topic_no ASC, id ASC");
$assessments = loadChildRows($conn, "course_specification_assessments", $course_specification_id, "display_order ASC, assessment_no ASC, id ASC");
$resources = loadChildRows($conn, "course_specification_resources", $course_specification_id, "display_order ASC, id ASC");
$facilities = loadChildRows($conn, "course_specification_facilities", $course_specification_id, "display_order ASC, id ASC");
$qualityRows = loadChildRows($conn, "course_specification_quality", $course_specification_id, "display_order ASC, id ASC");
$approvalRows = loadChildRows($conn, "course_specification_approval", $course_specification_id, "id ASC");

$files = [];
if (tableExists($conn, "course_specification_files")) {
    $filesStmt = $conn->prepare("
        SELECT *
        FROM course_specification_files
        WHERE course_specification_id = :course_specification_id
        ORDER BY upload_date DESC, id DESC
    ");
    $filesStmt->execute([":course_specification_id" => $course_specification_id]);
    $files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalTopicHours = sumChildColumn($conn, "course_specification_topics", "contact_hours", $course_specification_id);
$totalAssessmentPercentage = sumChildColumn($conn, "course_specification_assessments", "percentage", $course_specification_id);

$cloCount = count($clos);
$topicCount = count($topics);
$assessmentCount = count($assessments);
$fileCount = count($files);

$domainCounts = [
    "Knowledge and understanding" => 0,
    "Skills" => 0,
    "Values, autonomy, and responsibility" => 0,
    "Other" => 0
];

foreach ($clos as $clo) {
    $domain = strtolower(trim((string)($clo["clo_domain"] ?? "")));

    if (strpos($domain, "knowledge") !== false) {
        $domainCounts["Knowledge and understanding"]++;
    } elseif (strpos($domain, "skill") !== false) {
        $domainCounts["Skills"]++;
    } elseif (strpos($domain, "value") !== false || strpos($domain, "autonomy") !== false || strpos($domain, "responsibility") !== false) {
        $domainCounts["Values, autonomy, and responsibility"]++;
    } else {
        $domainCounts["Other"]++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Specification Profile</title>
    <link rel="stylesheet" href="assets/style.css">

    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
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
            min-width: 1100px;
            width: 100%;
            border-collapse: collapse;
        }

        .section-title {
            background: #d9eaf7;
            border: 1px solid #9dbbd4;
            padding: 10px;
            font-size: 18px;
            font-weight: bold;
            margin-top: 22px;
            margin-bottom: 12px;
            color: #1f4e79;
        }

        .subheading-row {
            background: #f8f9fa;
            font-weight: bold;
        }

        .badge-ok {
            background: #d1e7dd;
            color: #0f5132;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-warning {
            background: #fff3cd;
            color: #664d03;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-danger {
            background: #f8d7da;
            color: #842029;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .small-text {
            font-size: 12px;
            color: #555;
        }

        @media (max-width: 1100px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }
        }

        @media (max-width: 700px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<?php include "nav.php"; ?>

<div class="container">

    <div class="page-header">
        <h1>Course Specification Profile</h1>
        <p>Structured view of the imported NCAAA-style course specification.</p>
    </div>

    <div class="actions">
        <a href="course_specifications.php" class="btn btn-secondary">
            Course Specifications
        </a>

        <a href="upload_course_specification_file.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn">
            Upload Course File
        </a>

        <a href="import_course_specification_from_docx.php?program_specification_id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Import DOCX
        </a>

        <a href="program_specification_dashboard.php?id=<?php echo safeText($program_specification_id); ?>" class="btn btn-purple">
            Program Spec Dashboard
        </a>
	<a href="export_course_specification_word.php?id=<?php echo safeText($spec["id"]); ?>" class="btn btn-purple">
    Export Word
</a>
    </div>

    <div class="panel">
        <h2><?php echo safeText($courseSpec["course_code"] ?? ""); ?> - <?php echo safeText($courseSpec["course_title"] ?? ""); ?></h2>

        <table>
            <tr>
                <th>Course Specification ID</th>
                <td><?php echo safeText($course_specification_id); ?></td>
                <th>Program Specification ID</th>
                <td><?php echo safeText($program_specification_id); ?></td>
            </tr>
            <tr>
                <th>Course Code</th>
                <td><?php echo safeText($courseSpec["course_code"] ?? ""); ?></td>
                <th>Course Title</th>
                <td><?php echo safeText($courseSpec["course_title"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Program</th>
                <td><?php echo safeText($courseSpec["program_name"] ?? ""); ?></td>
                <th>Department</th>
                <td><?php echo safeText($courseSpec["department_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>College</th>
                <td><?php echo safeText($courseSpec["college_name"] ?? ""); ?></td>
                <th>Institution</th>
                <td><?php echo safeText($courseSpec["institution_name"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Version</th>
                <td><?php echo safeText($courseSpec["version_no"] ?? ""); ?></td>
                <th>Last Revision Date</th>
                <td><?php echo safeText($courseSpec["last_revision_date_text"] ?? ($courseSpec["last_revision_date"] ?? "")); ?></td>
            </tr>
            <tr>
                <th>Status</th>
                <td><?php echo safeText($courseSpec["status"] ?? ""); ?></td>
                <th>Approval Status</th>
                <td><?php echo safeText($courseSpec["approval_status"] ?? ""); ?></td>
            </tr>
        </table>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($cloCount); ?></div>
            <div class="summary-label">CLOs</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($topicCount); ?></div>
            <div class="summary-label">Topics</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalTopicHours); ?></div>
            <div class="summary-label">Topic Contact Hours</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($totalAssessmentPercentage); ?>%</div>
            <div class="summary-label">Assessment Total</div>
        </div>

        <div class="summary-card">
            <div class="summary-number"><?php echo safeText($fileCount); ?></div>
            <div class="summary-label">Uploaded Files</div>
        </div>
    </div>

    <div class="section-title">A. General Information About the Course</div>

    <div class="panel">
        <h2>Course Identification</h2>

        <table>
            <tr>
                <th>Credit Hours</th>
                <td><?php echo safeText($courseSpec["credit_hours"] ?? ""); ?></td>
                <th>Theoretical Hours</th>
                <td><?php echo safeText($courseSpec["theoretical_hours"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Level / Year</th>
                <td><?php echo safeText($courseSpec["course_level_year"] ?? ""); ?></td>
                <th>Course Type</th>
                <td><?php echo safeText($courseSpec["course_type_scope"] ?? ""); ?> / <?php echo safeText($courseSpec["requirement_type"] ?? ""); ?></td>
            </tr>
            <tr>
                <th>Pre-requisites</th>
                <td><?php echo nlText($courseSpec["pre_requisites"] ?? ""); ?></td>
                <th>Co-requisites</th>
                <td><?php echo nlText($courseSpec["co_requisites"] ?? ""); ?></td>
            </tr>
        </table>

        <h3>Course General Description</h3>
        <p><?php echo nlText($courseSpec["course_general_description"] ?? ""); ?></p>

        <h3>Course Main Objectives</h3>
        <p><?php echo nlText($courseSpec["course_main_objectives"] ?? ""); ?></p>
    </div>

    <div class="panel">
        <h2>Contact Hours</h2>

        <table>
            <tr>
                <th>Lectures</th>
                <th>Laboratory / Studio</th>
                <th>Field</th>
                <th>Tutorial</th>
                <th>Others</th>
                <th>Total</th>
            </tr>
            <tr>
                <td><?php echo safeText($courseSpec["contact_lectures"] ?? "0"); ?></td>
                <td><?php echo safeText($courseSpec["contact_laboratory"] ?? "0"); ?></td>
                <td><?php echo safeText($courseSpec["contact_field"] ?? "0"); ?></td>
                <td><?php echo safeText($courseSpec["contact_tutorial"] ?? "0"); ?></td>
                <td><?php echo safeText($courseSpec["contact_others"] ?? "0"); ?></td>
                <td><strong><?php echo safeText($courseSpec["contact_total"] ?? "0"); ?></strong></td>
            </tr>
        </table>
    </div>

    <div class="section-title">B. Course Learning Outcomes, Teaching Strategies, and Assessment Methods</div>

    <div class="panel">
        <h2>CLO Summary by Domain</h2>

        <table>
            <tr>
                <th>Knowledge and Understanding</th>
                <th>Skills</th>
                <th>Values, Autonomy, and Responsibility</th>
                <th>Other / Unclassified</th>
            </tr>
            <tr>
                <td><?php echo safeText($domainCounts["Knowledge and understanding"]); ?></td>
                <td><?php echo safeText($domainCounts["Skills"]); ?></td>
                <td><?php echo safeText($domainCounts["Values, autonomy, and responsibility"]); ?></td>
                <td><?php echo safeText($domainCounts["Other"]); ?></td>
            </tr>
        </table>

        <div class="wide-table-wrapper">
            <table class="wide-table">
                <tr>
                    <th>Code</th>
                    <th>Domain</th>
                    <th>Course Learning Outcome</th>
                    <th>Aligned PLO</th>
                    <th>Teaching Strategies</th>
                    <th>Assessment Methods</th>
                </tr>

                <?php if (count($clos) > 0): ?>
                    <?php foreach ($clos as $clo): ?>
                        <tr>
                            <td><?php echo safeText($clo["clo_code"] ?? ""); ?></td>
                            <td><?php echo safeText($clo["clo_domain"] ?? ""); ?></td>
                            <td><?php echo nlText($clo["clo_text"] ?? ""); ?></td>
                            <td><?php echo safeText($clo["aligned_plo_code"] ?? ""); ?></td>
                            <td><?php echo nlText($clo["teaching_strategies"] ?? ""); ?></td>
                            <td><?php echo nlText($clo["assessment_methods"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No CLOs imported yet.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="section-title">C. Course Content</div>

    <div class="panel">
        <h2>Course Topics</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>List of Topics</th>
                <th>Contact Hours</th>
            </tr>

            <?php if (count($topics) > 0): ?>
                <?php foreach ($topics as $topic): ?>
                    <tr>
                        <td><?php echo safeText($topic["topic_no"] ?? ""); ?></td>
                        <td><?php echo nlText($topic["topic_title"] ?? ""); ?></td>
                        <td><?php echo safeText($topic["contact_hours"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="subheading-row">
                    <td colspan="2">Total</td>
                    <td><?php echo safeText($totalTopicHours); ?></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="3">No course topics imported yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section-title">D. Students Assessment Activities</div>

    <div class="panel">
        <h2>Assessment Activities</h2>

        <table>
            <tr>
                <th>No.</th>
                <th>Assessment Activity</th>
                <th>Assessment Timing</th>
                <th>Percentage</th>
            </tr>

            <?php if (count($assessments) > 0): ?>
                <?php foreach ($assessments as $assessment): ?>
                    <tr>
                        <td><?php echo safeText($assessment["assessment_no"] ?? ""); ?></td>
                        <td><?php echo safeText($assessment["assessment_activity"] ?? ""); ?></td>
                        <td><?php echo safeText($assessment["assessment_timing"] ?? ""); ?></td>
                        <td><?php echo safeText($assessment["percentage"] ?? ""); ?>%</td>
                    </tr>
                <?php endforeach; ?>

                <tr class="subheading-row">
                    <td colspan="3">Total</td>
                    <td>
                        <?php echo safeText($totalAssessmentPercentage); ?>%
                        <?php if (abs($totalAssessmentPercentage - 100) <= 0.01): ?>
                            <span class="badge-ok">OK</span>
                        <?php else: ?>
                            <span class="badge-warning">Check</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="4">No assessment activities imported yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section-title">E. Learning Resources and Facilities</div>

    <div class="panel">
        <h2>References and Learning Resources</h2>

        <table>
            <tr>
                <th>Resource Category</th>
                <th>Resource Details</th>
            </tr>

            <?php if (count($resources) > 0): ?>
                <?php foreach ($resources as $resource): ?>
                    <tr>
                        <td>
                            <?php
                            $resourceCategory = $resource["resource_category"] ?? ($resource["resource_type"] ?? "");
                            echo safeText($resourceCategory);
                            ?>
                        </td>
                        <td><?php echo nlText($resource["resource_details"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="2">No learning resources imported yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="panel">
        <h2>Required Facilities and Equipment</h2>

        <table>
            <tr>
                <th>Item</th>
                <th>Resources</th>
            </tr>

            <?php if (count($facilities) > 0): ?>
                <?php foreach ($facilities as $facility): ?>
                    <tr>
                        <td>
                            <?php
                            $facilityCategory = $facility["facility_category"] ?? ($facility["facility_item"] ?? "");
                            echo safeText($facilityCategory);
                            ?>
                        </td>
                        <td><?php echo nlText($facility["required_resources"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="2">No facilities imported yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section-title">F. Assessment of Course Quality</div>

    <div class="panel">
        <h2>Course Quality Assessment</h2>

        <table>
            <tr>
                <th>Assessment Area / Issue</th>
                <th>Assessor</th>
                <th>Assessment Method</th>
            </tr>

            <?php if (count($qualityRows) > 0): ?>
                <?php foreach ($qualityRows as $quality): ?>
                    <tr>
                        <td><?php echo nlText($quality["assessment_area"] ?? ""); ?></td>
                        <td><?php echo nlText($quality["assessor"] ?? ""); ?></td>
                        <td><?php echo nlText($quality["assessment_method"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">No quality assessment rows imported yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section-title">G. Specification Approval</div>

    <div class="panel">
        <h2>Approval Data</h2>

        <table>
            <tr>
                <th>Council / Committee</th>
                <th>Reference No.</th>
                <th>Date</th>
                <th>Notes</th>
            </tr>

            <?php if (count($approvalRows) > 0): ?>
                <?php foreach ($approvalRows as $approval): ?>
                    <tr>
                        <td><?php echo safeText($approval["council_committee"] ?? ""); ?></td>
                        <td><?php echo safeText($approval["reference_no"] ?? ""); ?></td>
                        <td><?php echo safeText($approval["approval_date_text"] ?? ($approval["approval_date"] ?? "")); ?></td>
                        <td><?php echo nlText($approval["notes"] ?? ""); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No approval data imported yet.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="section-title">Uploaded Course Specification Files</div>

    <div class="panel">
        <h2>Attached Files</h2>

        <table>
            <tr>
                <th>Original File</th>
                <th>Type</th>
                <th>Size</th>
                <th>Upload Date</th>
                <th>Open</th>
            </tr>

            <?php if (count($files) > 0): ?>
                <?php foreach ($files as $file): ?>
                    <tr>
                        <td><?php echo safeText($file["original_filename"] ?? ""); ?></td>
                        <td><?php echo safeText($file["file_type"] ?? ""); ?></td>
                        <td><?php echo safeText(round(((int)($file["file_size"] ?? 0)) / 1024, 1)); ?> KB</td>
                        <td><?php echo safeText($file["upload_date"] ?? ""); ?></td>
                        <td>
                            <?php if (!empty($file["file_path"])): ?>
                                <a href="download_file.php?type=course_specification&amp;id=<?php echo urlencode((string)$file['id']); ?>" class="btn" target="_blank">
                                    Open
                                </a>
                            <?php else: ?>
                                <span class="small-text">No file path</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No attached course specification file found.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

</div>

</body>
</html>
