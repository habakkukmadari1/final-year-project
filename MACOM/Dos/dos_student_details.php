<?php
require_once '../db_config.php';
require_once '../auth.php'; // Connects to DB, starts session

// --- HELPER FUNCTIONS ---
function calculate_age($dob) {
    if (empty($dob) || $dob === '0000-00-00') return 'N/A';
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        if ($birthDate > $today) return 'N/A (Future DOB)';
        $age = $today->diff($birthDate)->y;
        return $age . ' years';
    } catch (Exception $e) {
        return 'N/A (Invalid Date)';
    }
}

function get_current_academic_year_info($conn) {
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    // Fallback to the latest academic year if no 'is_current' is set
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    return null;
}

// --- AJAX ACTION HANDLING ---
$requested_action = $_REQUEST['action'] ?? null;
if ($requested_action) {
    if ($requested_action !== 'get_student_current_optional_subjects_html') { header('Content-Type: application/json'); }
    $ajax_response = ['success' => false, 'message' => 'Invalid action or database connection error.'];
    if (!$conn) {
        if ($requested_action !== 'get_student_current_optional_subjects_html') { echo json_encode($ajax_response); }
        else { echo '<div class="alert alert-danger">Database connection error.</div>'; }
        exit;
    }
    switch ($requested_action) {
        case 'get_available_optional_subjects_for_student':
            $student_id_ajax = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $academic_year_id_ajax = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
            $student_class_id_ajax = filter_input(INPUT_POST, 'student_class_id', FILTER_VALIDATE_INT); // Added this

            if (!$student_id_ajax || !$academic_year_id_ajax) {
                $ajax_response['message'] = 'Missing student ID or academic year ID.';
                break;
            }

            if (!$student_class_id_ajax) { // Check if student is in a class
                 $ajax_response = [
                    'success' => true,
                    'subjects' => [],
                    'message' => 'Student is not currently assigned to a class. Optional subjects cannot be determined.'
                ];
                break; 
            }

            $taken_subjects_ids_ajax = [];
            $stmt_taken_ajax = $conn->prepare("SELECT subject_id FROM student_optional_subjects WHERE student_id = ? AND academic_year_id = ?");
            if ($stmt_taken_ajax) {
                $stmt_taken_ajax->bind_param("ii", $student_id_ajax, $academic_year_id_ajax);
                $stmt_taken_ajax->execute();
                $result_taken_ajax = $stmt_taken_ajax->get_result();
                while ($row_taken_ajax = $result_taken_ajax->fetch_assoc()) {
                    $taken_subjects_ids_ajax[] = $row_taken_ajax['subject_id'];
                }
                $stmt_taken_ajax->close();
            } else {
                error_log("Error preparing statement for taken subjects: " . $conn->error);
            }

            $available_class_optional_subjects_ajax = [];
            $sql_class_optional_subjects_ajax = "
                SELECT s.id, s.subject_name, s.subject_code, s.level_offered
                FROM subjects s
                JOIN class_subjects cs ON s.id = cs.subject_id
                WHERE cs.class_id = ? AND s.is_optional = 1
                ORDER BY s.subject_name
            ";

            $stmt_class_optional_ajax = $conn->prepare($sql_class_optional_subjects_ajax);
            if ($stmt_class_optional_ajax) {
                $stmt_class_optional_ajax->bind_param("i", $student_class_id_ajax);
                $stmt_class_optional_ajax->execute();
                $result_class_optional_ajax = $stmt_class_optional_ajax->get_result();
                while ($subject_ajax = $result_class_optional_ajax->fetch_assoc()) {
                    $subject_ajax['is_taken'] = in_array($subject_ajax['id'], $taken_subjects_ids_ajax);
                    $available_class_optional_subjects_ajax[] = $subject_ajax;
                }
                $stmt_class_optional_ajax->close();
                $ajax_response = ['success' => true, 'subjects' => $available_class_optional_subjects_ajax];

                if (empty($available_class_optional_subjects_ajax)) {
                     $ajax_response['message'] = 'No optional subjects are assigned to this student\'s current class, or all available have been selected.';
                }
            } else {
                $ajax_response['message'] = 'Error preparing statement for class optional subjects: ' . $conn->error;
                error_log("AJAX get_available_optional_subjects: Error preparing statement for class optional subjects: " . $conn->error);
            }
            break;
        case 'save_student_optional_subjects':
            $student_id_ajax = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $academic_year_id_ajax = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
            $subject_ids_raw_ajax = $_POST['subject_ids'] ?? []; $subject_ids_ajax = [];
            if (is_array($subject_ids_raw_ajax)) { foreach ($subject_ids_raw_ajax as $sid_ajax) { if (filter_var($sid_ajax, FILTER_VALIDATE_INT)) $subject_ids_ajax[] = (int)$sid_ajax;}}
            if (!$student_id_ajax || !$academic_year_id_ajax) { $ajax_response['message'] = 'Missing student ID or academic year ID.'; break;}
            $conn->begin_transaction();
            try {
                $stmt_delete_ajax = $conn->prepare("DELETE FROM student_optional_subjects WHERE student_id = ? AND academic_year_id = ?");
                if (!$stmt_delete_ajax) throw new Exception("Prepare delete failed: " . $conn->error);
                $stmt_delete_ajax->bind_param("ii", $student_id_ajax, $academic_year_id_ajax);
                if (!$stmt_delete_ajax->execute()) throw new Exception("Execute delete failed: " . $stmt_delete_ajax->error);
                $stmt_delete_ajax->close();
                if (!empty($subject_ids_ajax)) {
                    $stmt_insert_ajax = $conn->prepare("INSERT INTO student_optional_subjects (student_id, subject_id, academic_year_id) VALUES (?, ?, ?)");
                    if (!$stmt_insert_ajax) throw new Exception("Prepare insert failed: " . $conn->error);
                    foreach ($subject_ids_ajax as $subject_id_ajax_loop) {
                        $stmt_insert_ajax->bind_param("iii", $student_id_ajax, $subject_id_ajax_loop, $academic_year_id_ajax);
                        if (!$stmt_insert_ajax->execute()) throw new Exception("Execute insert for ID $subject_id_ajax_loop: " . $stmt_insert_ajax->error);
                    }
                    $stmt_insert_ajax->close();
                }
                $conn->commit(); $ajax_response = ['success' => true, 'message' => 'Optional subjects updated successfully.'];
            } catch (Exception $e) { $conn->rollback(); $ajax_response['message'] = 'Error updating subjects: ' . $e->getMessage(); error_log("AJAX save_student_optional_subjects: " . $e->getMessage()); }
            break;
        case 'get_student_current_optional_subjects_html':
            header('Content-Type: text/html'); ob_start();
            $student_id_ajax = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $academic_year_id_ajax = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
            if ($student_id_ajax && $academic_year_id_ajax) {
                $student_optional_subjects_updated_ajax = [];
                $stmt_updated_optional_ajax = $conn->prepare("SELECT sub.id, sub.subject_name, sub.subject_code FROM student_optional_subjects sos JOIN subjects sub ON sos.subject_id = sub.id WHERE sos.student_id = ? AND sos.academic_year_id = ? ORDER BY sub.subject_name");
                if ($stmt_updated_optional_ajax) {
                    $stmt_updated_optional_ajax->bind_param("ii", $student_id_ajax, $academic_year_id_ajax); $stmt_updated_optional_ajax->execute();
                    $result_updated_optional_ajax = $stmt_updated_optional_ajax->get_result();
                    while ($row_updated_ajax = $result_updated_optional_ajax->fetch_assoc()) { $student_optional_subjects_updated_ajax[] = $row_updated_ajax;}
                    $stmt_updated_optional_ajax->close();
                }
                if (!empty($student_optional_subjects_updated_ajax)): ?>
                    <div class="d-flex flex-wrap">
                        <?php foreach ($student_optional_subjects_updated_ajax as $os_ajax): ?>
                            <span class="subject-badge style-subject-badge-optional">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($os_ajax['subject_name']); ?> (<?php echo htmlspecialchars($os_ajax['subject_code'] ?: 'N/A'); ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?><div class="alert alert-info mb-0">No optional subjects currently selected for this academic year.</div><?php endif;
            } else { echo '<div class="alert alert-warning mb-0">Could not load subjects. Student ID or Academic Year missing.</div>';}
            echo ob_get_clean(); exit;
        default: break;
    }
    echo json_encode($ajax_response); exit;
}

// --- REGULAR PAGE LOAD LOGIC ---
$current_academic_year_info = get_current_academic_year_info($conn);
$current_academic_year_id = $current_academic_year_info['id'] ?? null;
$current_academic_year_name = $current_academic_year_info['year_name'] ?? 'N/A';

if (!$current_academic_year_id && !isset($_SESSION['warning_message_sd'])) {
    $_SESSION['warning_message_sd'] = "Current academic year is not set. Some functionalities like managing optional subjects might be limited.";
}


if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { $_SESSION['error_message'] = "Invalid student ID."; header("Location: student_overview.php"); exit(); }
$student_id = (int)$_GET['id'];
$student = null;
if ($conn) {
    $stmt = $conn->prepare("SELECT s.*, c.class_name, c.class_level FROM students s LEFT JOIN classes c ON s.current_class_id = c.id WHERE s.id = ?");
    if (!$stmt) { $_SESSION['error_message_sd'] = "Database query error preparing student data."; error_log("SQL prepare error student_details: " . $conn->error); }
    else {
        $stmt->bind_param("i", $student_id); $stmt->execute(); $result = $stmt->get_result(); $student = $result->fetch_assoc(); $stmt->close();
    }
}
if (!$student) { $_SESSION['error_message'] = "Student not found."; header("Location: student_overview.php"); exit(); }

$student_optional_subjects = [];
if ($conn && $current_academic_year_id) {
    $stmt_optional = $conn->prepare("SELECT sub.id, sub.subject_name, sub.subject_code FROM student_optional_subjects sos JOIN subjects sub ON sos.subject_id = sub.id WHERE sos.student_id = ? AND sos.academic_year_id = ? ORDER BY sub.subject_name");
    if ($stmt_optional) { $stmt_optional->bind_param("ii", $student_id, $current_academic_year_id); $stmt_optional->execute(); $result_optional = $stmt_optional->get_result(); while ($row = $result_optional->fetch_assoc()) $student_optional_subjects[] = $row; $stmt_optional->close(); }
    else error_log("SQL optional subjects error: " . $conn->error);
}
$student_compulsory_subjects = [];
if ($conn && $student['current_class_id']) {
    $stmt_compulsory = $conn->prepare("SELECT sub.id, sub.subject_name, sub.subject_code FROM class_subjects cs JOIN subjects sub ON cs.subject_id = sub.id WHERE cs.class_id = ? AND sub.is_optional = 0 ORDER BY sub.subject_name");
     if ($stmt_compulsory) { $stmt_compulsory->bind_param("i", $student['current_class_id']); $stmt_compulsory->execute(); $result_compulsory = $stmt_compulsory->get_result(); while ($row = $result_compulsory->fetch_assoc()) $student_compulsory_subjects[] = $row; $stmt_compulsory->close(); }
     else error_log("SQL compulsory subjects error: " . $conn->error);
}
$enrollment_history = [];
if ($conn) {
    $stmt_enroll = $conn->prepare("SELECT se.*, c.class_name, ay.year_name FROM student_enrollments se JOIN classes c ON se.class_id = c.id JOIN academic_years ay ON se.academic_year_id = ay.id WHERE se.student_id = ? ORDER BY ay.start_date DESC, se.enrollment_date DESC");
    if ($stmt_enroll) { $stmt_enroll->bind_param("i", $student_id); $stmt_enroll->execute(); $result_enroll = $stmt_enroll->get_result(); while ($row = $result_enroll->fetch_assoc()) $enrollment_history[] = $row; $stmt_enroll->close(); }
    else error_log("SQL enrollment history error: " . $conn->error);
}
$student_age = calculate_age($student['date_of_birth']);
$default_photo_url = 'https://ui-avatars.com/api/?name='.urlencode($student['full_name']).'&background=0D6EFD&color=fff&size=150&font-size=0.33';
$student_photo_path = !empty($student['photo']) && file_exists($student['photo']) ? htmlspecialchars($student['photo']) : $default_photo_url;

$success_message_sd = $_SESSION['success_message_sd'] ?? null;
$error_message_sd = $_SESSION['error_message_sd'] ?? null;
$warning_message_sd = $_SESSION['warning_message_sd'] ?? null;
if ($success_message_sd) unset($_SESSION['success_message_sd']);
if ($error_message_sd) unset($_SESSION['error_message_sd']);
if ($warning_message_sd) unset($_SESSION['warning_message_sd']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($student['full_name']); ?> - Student Details | MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet">
    <style>
        :root {
            --primary-accent: #0d6efd;
        }
        body { background-color: var(--bs-body-bg); color: var(--bs-body-color); transition: background-color 0.3s ease, color 0.3s ease; }
        .profile-container { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); }
        .profile-pic { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary-accent); box-shadow: 0 4px 10px rgba(0,0,0,0.1); background-color: var(--bs-secondary-bg); }
        .student-details-panel { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); padding: 0; height: 100%; }
        .student-details-panel .nav-tabs { border-bottom: 1px solid var(--bs-border-color); padding: 0.5rem 1rem 0 1rem; }
        .student-details-panel .nav-tabs .nav-link { border: none; border-bottom: 3px solid transparent; color: var(--bs-secondary-color); padding: 0.75rem 1rem; font-weight: 500; }
        .student-details-panel .nav-tabs .nav-link.active, .student-details-panel .nav-tabs .nav-link:hover { color: var(--primary-accent); border-bottom-color: var(--primary-accent); background-color: transparent; }
        .student-details-panel .tab-content { padding: 1.5rem; border-left: 5px solid var(--primary-accent); border-radius: 0 0 0.70rem 0; min-height: 300px; }
        .student-details-panel .tab-content h5.section-title { font-size: 0.9rem; font-weight: 600; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px dashed var(--bs-border-color-translucent); }
        .student-details-panel .info-item { display: flex; margin-bottom: 0.75rem; font-size: 0.95rem; }
        .student-details-panel .info-label { font-weight: 500; color: var(--bs-body-color); width: 150px; flex-shrink: 0; }
        .student-details-panel .info-value { color: var(--bs-secondary-color); }
        .details-section-card { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); margin-bottom: 1.5rem; }
        .details-section-card .card-header { background-color: var(--bs-secondary-bg); border-bottom: 1px solid var(--bs-border-color); font-size: 1.1rem; font-weight: 500; padding: .75rem 1.25rem; color: var(--primary-accent); }
        .action-btn { border-radius: .375rem; padding: .5rem 1rem; font-weight: 500; transition: all .2s ease; }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,.1); }
        .style-subject-badge { background-color: var(--primary-accent) !important; color: white !important; border-radius: .375rem; padding: .5em .8em; font-size: .85em; margin: .25rem; display: inline-flex; align-items: center; }
        .style-subject-badge-optional { background-color: var(--bs-success-bg-subtle) !important; color: var(--bs-success-text-emphasis) !important; border: 1px solid var(--bs-success-border-subtle) !important; border-radius: .375rem; padding: .5em .8em; font-size: .85em; margin: .25rem; display: inline-flex; align-items: center; }
        @media (max-width: 991px) { .profile-container .d-flex { flex-direction: column; text-align: center; } .profile-pic { margin-right: 0 !important; margin-bottom: 1rem; } }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar_dos.php'; ?>
        <div class="flex-grow-1 p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0 h3"><i class="fas fa-user-graduate me-2 text-primary"></i>Student Details</h2>
                <div>
                    <button class="btn btn-sm btn-outline-secondary me-2" onclick="window.history.back()"><i class="fas fa-arrow-left me-1"></i> Back</button>
                    <!-- Theme toggle button if you use it on this page -->
                </div>
            </div>

            <?php if ($success_message_sd): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_sd); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error_message_sd): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo nl2br(htmlspecialchars($error_message_sd)); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($warning_message_sd): ?><div class="alert alert-warning alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($warning_message_sd); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>


            <!-- Main Profile Section -->
            <div class="row mb-4">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="profile-container p-3 p-md-4 h-100">
                        <div class="d-flex flex-column align-items-center text-center">
                            <img src="<?php echo $student_photo_path; ?>" alt="Student Photo" class="profile-pic mb-3">
                            <h3 class="h5 mb-1 fw-bold"><?php echo htmlspecialchars($student['full_name']); ?></h3>
                            <p class="text-secondary mb-1 small">
                                <i class="fas fa-school opacity-75 me-1"></i> <?php echo htmlspecialchars($student['class_name'] ?: 'N/A'); ?>
                                (<?php echo htmlspecialchars($student['class_level'] ?: 'N/A'); ?>)
                            </p>
                            <p class="text-secondary mb-2 small">
                                <i class="fas fa-id-badge opacity-75 me-1"></i> Adm No: <?php echo htmlspecialchars($student['admission_number'] ?: 'N/A'); ?>
                            </p>
                            <span class="badge fs-08rem bg-<?php echo strtolower($student['sex']) === 'male' ? 'info-subtle text-info-emphasis' : (strtolower($student['sex']) === 'female' ? 'danger-subtle text-danger-emphasis' : 'secondary-subtle text-secondary-emphasis'); ?>">
                                <i class="fas fa-<?php echo strtolower($student['sex']) === 'male' ? 'mars' : (strtolower($student['sex']) === 'female' ? 'venus' : 'genderless'); ?> me-1"></i>
                                <?php echo htmlspecialchars(ucfirst($student['sex'])); ?>
                            </span>
                            <hr class="w-75 my-3">
                             <div class="info-item w-100 text-start"><span class="info-label small">Status:</span> <span class="info-value small"><span class="badge text-bg-<?php echo $student['enrollment_status'] == 'active' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars(ucfirst($student['enrollment_status'])); ?></span></span></div>
                             <div class="info-item w-100 text-start"><span class="info-label small">Adm Date:</span> <span class="info-value small"><?php echo htmlspecialchars($student['admission_date'] ? date('d M, Y', strtotime($student['admission_date'])) : 'N/A'); ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="student-details-panel">
                        <ul class="nav nav-tabs" id="studentInfoTabs" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" id="s-personal-tab" data-bs-toggle="tab" data-bs-target="#s-personal-pane" type="button" role="tab" aria-controls="s-personal-pane" aria-selected="true"><i class="fas fa-user-circle me-1"></i>Personal</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="s-academic-tab" data-bs-toggle="tab" data-bs-target="#s-academic-pane" type="button" role="tab" aria-controls="s-academic-pane" aria-selected="false"><i class="fas fa-graduation-cap me-1"></i>Academic</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="s-guardian-tab" data-bs-toggle="tab" data-bs-target="#s-guardian-pane" type="button" role="tab" aria-controls="s-guardian-pane" aria-selected="false"><i class="fas fa-users me-1"></i>Guardian</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="s-health-tab" data-bs-toggle="tab" data-bs-target="#s-health-pane" type="button" role="tab" aria-controls="s-health-pane" aria-selected="false"><i class="fas fa-notes-medical me-1"></i>Health & Docs</button></li>
                        </ul>
                        <div class="tab-content" id="studentInfoTabsContent">
                            <div class="tab-pane fade show active" id="s-personal-pane" role="tabpanel" aria-labelledby="s-personal-tab" tabindex="0">
                                <h5 class="section-title">Basic Information</h5>
                                <div class="info-item"><span class="info-label">Full Name:</span> <span class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></span></div>
                                <div class="info-item"><span class="info-label">Date of Birth:</span> <span class="info-value"><?php echo htmlspecialchars($student['date_of_birth'] ? date('d M Y', strtotime($student['date_of_birth'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Age:</span> <span class="info-value"><?php echo $student_age; ?></span></div>
                                <div class="info-item"><span class="info-label">Sex:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($student['sex'])); ?></span></div>
                                <div class="info-item"><span class="info-label">Address:</span> <span class="info-value"><?php echo htmlspecialchars($student['address'] ?: 'N/A'); ?></span></div>
                                <h5 class="section-title mt-4">Identification</h5>
                                <div class="info-item"><span class="info-label">NIN Number:</span> <span class="info-value"><?php echo htmlspecialchars($student['nin_number'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">LIN Number:</span> <span class="info-value"><?php echo htmlspecialchars($student['lin_number'] ?: 'N/A'); ?></span></div>
                            </div>
                            <div class="tab-pane fade" id="s-academic-pane" role="tabpanel" aria-labelledby="s-academic-tab" tabindex="0">
                                <h5 class="section-title">Enrollment Details</h5>
                                <div class="info-item"><span class="info-label">Admission No:</span> <span class="info-value"><?php echo htmlspecialchars($student['admission_number'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Current Class:</span> <span class="info-value"><?php echo htmlspecialchars($student['class_name'] ?: 'N/A'); ?> (<?php echo htmlspecialchars($student['class_level'] ?: 'N/A'); ?>)</span></div>
                                <div class="info-item"><span class="info-label">Admission Date:</span> <span class="info-value"><?php echo htmlspecialchars($student['admission_date'] ? date('d M Y', strtotime($student['admission_date'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Enroll. Status:</span> <span class="info-value"><span class="badge text-bg-<?php echo $student['enrollment_status'] == 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($student['enrollment_status'])); ?></span></span></div>
                                <div class="info-item"><span class="info-label">Previous School:</span> <span class="info-value"><?php echo htmlspecialchars($student['previous_school'] ?: 'N/A'); ?></span></div>
                                <?php if(!empty($student['student_comment'])): ?>
                                <h5 class="section-title mt-4">Additional Comments</h5>
                                <p class="info-value fst-italic">"<?php echo nl2br(htmlspecialchars($student['student_comment'])); ?>"</p>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="s-guardian-pane" role="tabpanel" aria-labelledby="s-guardian-tab" tabindex="0">
                                <h5 class="section-title">Father's Details</h5>
                                <div class="info-item"><span class="info-label">Name:</span><span class="info-value"><?php echo htmlspecialchars($student['father_name'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Contact:</span><span class="info-value"><?php echo htmlspecialchars($student['father_contact'] ?: 'N/A'); ?></span></div>
                                <h5 class="section-title mt-4">Mother's Details</h5>
                                <div class="info-item"><span class="info-label">Name:</span><span class="info-value"><?php echo htmlspecialchars($student['mother_name'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Contact:</span><span class="info-value"><?php echo htmlspecialchars($student['mother_contact'] ?: 'N/A'); ?></span></div>
                                <h5 class="section-title mt-4">Guardian's Details</h5>
                                <div class="info-item"><span class="info-label">Name:</span><span class="info-value"><?php echo htmlspecialchars($student['guardian_name'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Contact:</span><span class="info-value"><?php echo htmlspecialchars($student['guardian_contact'] ?: 'N/A'); ?></span></div>
                                <h5 class="section-title mt-4">Other Contacts</h5>
                                <div class="info-item"><span class="info-label">Student Contact:</span><span class="info-value"><?php echo htmlspecialchars($student['student_contact'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Primary P/G Name:</span><span class="info-value"><?php echo htmlspecialchars($student['parent_guardian_name'] ?: 'N/A'); ?></span></div>
                            </div>
                            <div class="tab-pane fade" id="s-health-pane" role="tabpanel" aria-labelledby="s-health-tab" tabindex="0">
                                <h5 class="section-title">Health Status</h5>
                                <div class="info-item"><span class="info-label">Has Disability:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($student['has_disability'])); ?></span></div>
                                <?php if ($student['has_disability'] === 'Yes'): ?>
                                    <div class="info-item"><span class="info-label">Disability Type:</span> <span class="info-value"><?php echo htmlspecialchars($student['disability_type'] ?: 'Not specified'); ?></span></div>
                                    <div class="info-item"><span class="info-label">Medical Report:</span>
                                        <span class="info-value">
                                            <?php if (!empty($student['disability_medical_report_path']) && file_exists($student['disability_medical_report_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($student['disability_medical_report_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-file-medical me-1"></i> View Report</a>
                                            <?php else: ?> No report uploaded. <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <h5 class="section-title mt-4">Uploaded Documents</h5>
                                <div class="info-item"><span class="info-label">Student Photo:</span>
                                    <span class="info-value">
                                    <?php if (!empty($student['photo']) && file_exists($student['photo']) && htmlspecialchars($student['photo']) !== $default_photo_url ): ?>
                                        <a href="<?php echo htmlspecialchars($student['photo']); ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-image me-1"></i> View Photo
                                        </a>
                                    <?php else: ?> No distinct photo uploaded. <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
                
                <?php
                $canManageOptionalSubjects = $current_academic_year_id &&
                                             !empty($student['current_class_id']) && // Student must be in a class
                                             !empty($student['class_level']) &&    // Class must have a level
                                             in_array($student['class_level'], ['O-Level', 'A-Level']);
                $disableTitle = '';
                if (!$current_academic_year_id) {
                    $disableTitle = 'Current academic year not set. Cannot manage optional subjects.';
                } elseif (empty($student['current_class_id'])) {
                    $disableTitle = 'Student is not assigned to any class. Please assign to a class first.';
                } elseif (empty($student['class_level']) || !in_array($student['class_level'], ['O-Level', 'A-Level'])) {
                    $disableTitle = 'Student\'s class level is not set or invalid (must be O-Level or A-Level).';
                }
                ?>
                <button class="btn btn-success action-btn" data-bs-toggle="modal" data-bs-target="#manageSubjectsModal"
                        <?php echo !$canManageOptionalSubjects ? 'disabled' : ''; ?>
                        <?php echo !$canManageOptionalSubjects && !empty($disableTitle) ? 'title="' . htmlspecialchars($disableTitle) . '"' : ''; ?>>
                    <i class="fas fa-plus me-1"></i> Manage Optional Subjects
                </button>
                <button class="btn btn-sm btn-outline-warning flex-fill ms-1" onclick="requestStudentAction('edit', <?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                    <i class="fas fa-flag"></i> <span class="d-none d-md-inline">Req. Edit</span>
                </button>
            </div>

            <!-- Subjects Card -->
            <div class="details-section-card">
                <div class="card-header"><i class="fas fa-layer-group me-2"></i>Subjects Overview
                    <small class="text-muted fw-normal">(<?php echo htmlspecialchars($current_academic_year_name); ?>)</small>
                </div>
                <div class="card-body p-3 p-md-4">
                    <h6 class="mb-2 small text-uppercase text-secondary fw-bold">Compulsory Subjects (for <?php echo htmlspecialchars($student['class_name'] ?: 'N/A'); ?>)</h6>
                    <?php if (!empty($student_compulsory_subjects)): ?>
                        <div class="d-flex flex-wrap mb-3">
                            <?php foreach ($student_compulsory_subjects as $cs): ?>
                                <span class="style-subject-badge"><i class="fas fa-book me-2 opacity-75"></i><?php echo htmlspecialchars($cs['subject_name']); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?> <p class="text-muted small mb-3">No compulsory subjects assigned to this class or student has no class.</p> <?php endif; ?>

                    <hr class="my-3">
                    <h6 class="mb-2 small text-uppercase text-secondary fw-bold">Optional Subjects</h6>
                    <div id="studentOptionalSubjectsList">
                        <?php if (!empty($student_optional_subjects)): ?>
                            <div class="d-flex flex-wrap">
                                <?php foreach ($student_optional_subjects as $os): ?>
                                    <span class="style-subject-badge-optional"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($os['subject_name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($current_academic_year_id): ?> <p class="text-muted small">No optional subjects currently selected for this academic year.</p>
                        <?php else: ?> <p class="text-warning small">Cannot display optional subjects as current academic year is not set.</p> <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Enrollment History Card -->
            <div class="details-section-card">
                <div class="card-header"><i class="fas fa-history me-2"></i>Enrollment History</div>
                <div class="card-body p-3 p-md-4">
                <?php if (!empty($enrollment_history)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr><th>Academic Year</th><th>Class</th><th>Enrollment Date</th><th>Status</th><th>Final Grade/Assessment</th><th style="min-width: 150px;">Notes</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrollment_history as $enroll): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($enroll['year_name']); ?></td>
                                    <td><?php echo htmlspecialchars($enroll['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($enroll['enrollment_date'] ? date('d M Y', strtotime($enroll['enrollment_date'])) : 'N/A'); ?></td>
                                    <td><span class="badge text-bg-info bg-opacity-75"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $enroll['status']))); ?></span></td>
                                    <td><?php echo htmlspecialchars($enroll['final_grade_or_assessment'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($enroll['notes'] ?: 'N/A'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?> <p class="text-muted small">No enrollment history found for this student.</p> <?php endif; ?>
                </div>
            </div>

            <!-- Performance/Attendance Section (placeholder) -->
            <div class="details-section-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-chart-line me-2"></i>Performance & Attendance</span>
                    <div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary">Monthly</button><button class="btn btn-outline-secondary active">Termly</button><button class="btn btn-outline-secondary">Yearly</button></div>
                </div>
                <div class="card-body p-3 p-md-4"><div class="alert alert-warning mb-0 d-flex align-items-center"><i class="fas fa-exclamation-triangle fa-lg me-2"></i> Performance and attendance tracking will be available in a future update.</div></div>
            </div>
        </div> <!-- End flex-grow-1 -->
    </div> <!-- End .wrapper -->

    <div class="modal fade" id="manageSubjectsModal" tabindex="-1" aria-labelledby="manageSubjectsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="manageSubjectsModalLabel">Manage Optional Subjects for <?php echo htmlspecialchars($student['full_name']); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">Academic Year: <strong><?php echo htmlspecialchars($current_academic_year_name); ?></strong> | Class: <strong><?php echo htmlspecialchars($student['class_name'] ?: 'N/A'); ?></strong> (Level: <?php echo htmlspecialchars($student['class_level'] ?: 'N/A'); ?>)</p>
                    <div id="availableOptionalSubjectsCheckboxes"><div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading...</p></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="saveStudentOptionalSubjectsBtn"><i class="fas fa-save me-1"></i>Save Changes</button></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const studentId = <?php echo $student_id; ?>;
        const currentAcademicYearId = <?php echo $current_academic_year_id ? $current_academic_year_id : 'null'; ?>;
        const studentClassLevel = '<?php echo htmlspecialchars($student['class_level'] ?? ''); ?>';
        const studentClassId = <?php echo $student['current_class_id'] ? $student['current_class_id'] : 'null'; ?>; // Added this
        const ajaxUrl = '<?php echo $_SERVER["PHP_SELF"]; ?>';

        function setThemePreference(theme) { document.documentElement.setAttribute('data-bs-theme', theme); localStorage.setItem('themePreferenceMACOM', theme); }
        function toggleTheme() { const currentTheme = document.documentElement.getAttribute('data-bs-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); const newTheme = currentTheme === 'dark' ? 'light' : 'dark'; setThemePreference(newTheme); }

        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('themePreferenceMACOM');
            if (savedTheme) { setThemePreference(savedTheme); }
            else { setThemePreference(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); }

            const manageSubjectsModalEl = document.getElementById('manageSubjectsModal');
            if (manageSubjectsModalEl) {
                manageSubjectsModalEl.addEventListener('show.bs.modal', loadAvailableOptionalSubjects);
            }
        });

        function loadAvailableOptionalSubjects() {
            if (!currentAcademicYearId) {
                $('#availableOptionalSubjectsCheckboxes').html('<div class="alert alert-warning mb-0">Current academic year not set. Cannot load subjects.</div>');
                return;
            }
            if (!studentClassId) {
                $('#availableOptionalSubjectsCheckboxes').html('<div class="alert alert-warning mb-0">Student is not currently assigned to a class. Optional subjects cannot be determined. Please assign the student to a class first.</div>');
                return;
            }
            // Optional: Keep a less critical check for class level if needed for display, but primary filter is class_id
            if (!studentClassLevel || (studentClassLevel !== 'O-Level' && studentClassLevel !== 'A-Level')) {
                console.warn('Student class level is not O-Level or A-Level. Filtering by class ID will proceed.');
            }

            $('#availableOptionalSubjectsCheckboxes').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading available optional subjects for the class...</p></div>');
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action: 'get_available_optional_subjects_for_student',
                    student_id: studentId,
                    academic_year_id: currentAcademicYearId,
                    student_class_id: studentClassId // Pass the student's current class ID
                },
                dataType: 'json',
                success: function(response) {
                    $('#availableOptionalSubjectsCheckboxes').empty();
                    if (response.success && response.subjects && response.subjects.length > 0) {
                        response.subjects.forEach(subject => {
                            $('#availableOptionalSubjectsCheckboxes').append(
                                `<div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" value="${subject.id}" id="subjectOpt_${subject.id}" ${subject.is_taken ? 'checked' : ''}>
                                    <label class="form-check-label" for="subjectOpt_${subject.id}">
                                        ${subject.subject_name} (${subject.subject_code || 'N/A'})
                                        <small class="text-muted fst-italic"> - Offered at: ${subject.level_offered}</small>
                                    </label>
                                 </div>`
                            );
                        });
                    } else if (response.success && response.subjects && response.subjects.length === 0) {
                        const message = response.message || 'No optional subjects are configured for this student\'s current class, or all available options have been selected.';
                        $('#availableOptionalSubjectsCheckboxes').html(`<div class="alert alert-info mb-0">${message}</div>`);
                    } else {
                        const errorMessage = response.message || 'Failed to load subjects. Please try again.';
                        $('#availableOptionalSubjectsCheckboxes').html(`<div class="alert alert-danger mb-0">${errorMessage}</div>`);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    $('#availableOptionalSubjectsCheckboxes').html('<div class="alert alert-danger mb-0">Error loading subjects. Please check the console for details and try again.</div>');
                }
            });
        }

        $('#saveStudentOptionalSubjectsBtn').click(function() {
            const btn = $(this); const selectedSubjectIds = [];
            $('#availableOptionalSubjectsCheckboxes input[type=checkbox]:checked').each(function() { selectedSubjectIds.push($(this).val()); });
            if (!currentAcademicYearId) { alert('Current academic year not set.'); return; }
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving...');
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: { action: 'save_student_optional_subjects', student_id: studentId, academic_year_id: currentAcademicYearId, subject_ids: selectedSubjectIds },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert(response.message || 'Subjects updated successfully!');
                        $('#manageSubjectsModal').modal('hide');
                        updateDisplayedOptionalSubjects();
                    } else { alert(response.message || 'Failed to update subjects.'); }
                },
                error: function() { alert('An error occurred while saving. Please try again.'); },
                complete: function() { btn.prop('disabled', false).html('<i class="fas fa-save me-1"></i>Save Changes'); }
            });
        });

        function updateDisplayedOptionalSubjects() {
            $('#studentOptionalSubjectsList').html('<div class="text-center text-muted small py-2"><div class="spinner-border spinner-border-sm text-primary"></div> Refreshing...</div>');
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: { action: 'get_student_current_optional_subjects_html', student_id: studentId, academic_year_id: currentAcademicYearId },
                dataType: 'html',
                success: function(htmlResponse) { $('#studentOptionalSubjectsList').html(htmlResponse); },
                error: function() { $('#studentOptionalSubjectsList').html('<p class="text-danger small">Failed to refresh subjects list.</p>'); }
            });
        }

        function confirmDeleteStudent(id, name) {
            if (confirm(`ARE YOU SURE you want to delete student: ${name} (ID: ${id})?\n\nThis action is IRREVERSIBLE and will delete related records such as enrollments and optional subject choices.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'student_overview.php';
                form.innerHTML = `<input type="hidden" name="action" value="delete_student"><input type="hidden" name="student_id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        function openEditStudentModal(id) {
            window.location.href = `student_overview.php?edit_attempt=${id}`;
        }
        // Request Action Functionality
        function requestStudentAction(actionType, studentId, studentName) {
            let actionText = '';
            if (actionType === 'edit') {
                actionText = 'edit the profile of';
            } else if (actionType === 'delete') {
                actionText = 'delete the profile of';
            } else {
                return;
            }
            
            alert(`A request has been sent to the Head Teacher to ${actionText} student: ${studentName} (ID: ${studentId}).`);
            
            // In a real application, this would be an AJAX call:
            /*
            $.post('../notifications.php', {
                action: 'create_request',
                recipient_role: 'head_teacher',
                message: `DoS requests to ${actionText} student: ${studentName} (ID: ${studentId}).`,
                related_item_id: studentId,
                related_item_type: 'student'
            }, function(response) {
                if(response.success) {
                    alert('Your request has been sent to the Head Teacher.');
                } else {
                    alert('Could not send the request.');
                }
            });
            */
    </script>
</body>
</html>