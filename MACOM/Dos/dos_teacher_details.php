"<?php
// dos_teacher_details.php
require_once '../db_config.php';
require_once '../auth.php';

check_login('director_of_studies');
$loggedInUserId = $_SESSION['user_id']; // For audit trail of assignments

// --- Helper Function (same) ---
function get_current_academic_year_info_dos_td($conn) { /* ... same as HT version ... */ 
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    return null;
}

// --- AJAX ACTION HANDLING (This is where DoS manages assignments) ---
$requested_action = $_REQUEST['action'] ?? null;
if ($requested_action && $conn) {
    header('Content-Type: application/json');
    $ajax_response = ['success' => false, 'message' => 'Invalid action or DB error.'];

    switch ($requested_action) {
        case 'get_subjects_for_class':
            // ... (Copy this entire case block from your original teacher_details.php)
            $class_id_ajax = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
            if (!$class_id_ajax) { $ajax_response['message'] = 'Invalid Class ID.'; break; }
            $subjects_ajax = [];
            $stmt_subjects = $conn->prepare("SELECT s.id, s.subject_name, s.subject_code FROM subjects s JOIN class_subjects cs ON s.id = cs.subject_id WHERE cs.class_id = ? ORDER BY s.subject_name");
            if ($stmt_subjects) { $stmt_subjects->bind_param("i", $class_id_ajax); $stmt_subjects->execute(); $result_subjects = $stmt_subjects->get_result(); while ($row = $result_subjects->fetch_assoc()) { $subjects_ajax[] = $row; } $stmt_subjects->close(); $ajax_response = ['success' => true, 'subjects' => $subjects_ajax]; }
            else { $ajax_response['message'] = 'DB error subjects: ' . $conn->error; error_log("DoS TD - subjects: " . $conn->error); }
            break;

        case 'get_papers_for_subject':
            // ... (Copy this entire case block from your original teacher_details.php)
            $subject_id_ajax = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
            if (!$subject_id_ajax) { $ajax_response['message'] = 'Invalid Subject ID.'; break; }
            $papers_ajax = [];
            $stmt_papers = $conn->prepare("SELECT id, paper_name, paper_code FROM subject_papers WHERE subject_id = ? ORDER BY paper_name");
            if ($stmt_papers) { $stmt_papers->bind_param("i", $subject_id_ajax); $stmt_papers->execute(); $result_papers = $stmt_papers->get_result(); while ($row = $result_papers->fetch_assoc()) { $papers_ajax[] = $row; } $stmt_papers->close(); $ajax_response = ['success' => true, 'papers' => $papers_ajax];}
            else { $ajax_response['message'] = 'DB error papers: ' . $conn->error; error_log("DoS TD - papers: " . $conn->error); }
            break;

        case 'assign_paper_to_teacher': // DoS performs this action
            $teacher_id_assign = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            $academic_year_id_assign = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
            $class_id_assign = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $subject_id_assign = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $subject_paper_id_assign = filter_input(INPUT_POST, 'subject_paper_id', FILTER_VALIDATE_INT);

            if (!$teacher_id_assign || !$academic_year_id_assign || !$class_id_assign || !$subject_id_assign || !$subject_paper_id_assign) {
                $ajax_response['message'] = 'All fields are required for assignment.'; break;
            }
            // Check for existing assignment (same logic)
            $stmt_check = $conn->prepare("SELECT id FROM teacher_class_subject_papers WHERE teacher_id = ? AND class_id = ? AND subject_paper_id = ? AND academic_year_id = ?");
            if ($stmt_check) { $stmt_check->bind_param("iiii", $teacher_id_assign, $class_id_assign, $subject_paper_id_assign, $academic_year_id_assign); $stmt_check->execute(); $stmt_check->store_result(); if ($stmt_check->num_rows > 0) { $ajax_response['message'] = 'This teacher is already assigned this paper in this class for the selected academic year.'; $stmt_check->close(); break; } $stmt_check->close(); }

            // INSERT with created_by and updated_by for audit
            $stmt_insert = $conn->prepare("INSERT INTO teacher_class_subject_papers (teacher_id, academic_year_id, class_id, subject_id, subject_paper_id, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt_insert) {
                $stmt_insert->bind_param("iiiiiis", $teacher_id_assign, $academic_year_id_assign, $class_id_assign, $subject_id_assign, $subject_paper_id_assign, $loggedInUserId, $loggedInUserId);
                if ($stmt_insert->execute()) { $ajax_response = ['success' => true, 'message' => 'Subject paper assigned successfully by DoS.']; }
                else { $ajax_response['message'] = 'Failed to assign: ' . $stmt_insert->error; error_log("DoS TD Assign Paper: " . $stmt_insert->error); }
                $stmt_insert->close();
            } else { $ajax_response['message'] = 'DB query prep failed: ' . $conn->error; error_log("DoS TD Assign Paper Prep: " . $conn->error); }
            break;
        
        case 'get_assigned_papers': // For DoS to view a specific teacher's assignments
            // ... (Copy this entire case block from your original teacher_details.php)
            // This will be used to populate the list of assignments for the current teacher being viewed by DoS.
            $teacher_id_fetch = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT); // This will be the teacher ID from URL
            $academic_year_id_fetch = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);
            if (!$teacher_id_fetch || !$academic_year_id_fetch) { $ajax_response['message'] = 'Teacher ID and Academic Year ID are required.'; break; }
            $assigned_papers = [];
            $sql_get_assigned = "SELECT tcsp.id as assignment_id, ay.year_name, c.class_name, s.subject_name, sp.paper_name, sp.paper_code, tcsp.class_id AS class_id_for_students, tcsp.subject_id AS subject_id_for_students, u_creator.username as assigned_by_username
                                 FROM teacher_class_subject_papers tcsp
                                 JOIN academic_years ay ON tcsp.academic_year_id = ay.id
                                 JOIN classes c ON tcsp.class_id = c.id
                                 JOIN subjects s ON tcsp.subject_id = s.id
                                 JOIN subject_papers sp ON tcsp.subject_paper_id = sp.id
                                 LEFT JOIN users u_creator ON tcsp.created_by = u_creator.id
                                 WHERE tcsp.teacher_id = ? AND tcsp.academic_year_id = ?
                                 ORDER BY ay.year_name, c.class_name, s.subject_name, sp.paper_name";
            $stmt_get_assigned = $conn->prepare($sql_get_assigned);
            if($stmt_get_assigned){ $stmt_get_assigned->bind_param("ii", $teacher_id_fetch, $academic_year_id_fetch); $stmt_get_assigned->execute(); $result_get_assigned = $stmt_get_assigned->get_result(); while($row_get_assigned = $result_get_assigned->fetch_assoc()){ $assigned_papers[] = $row_get_assigned; } $stmt_get_assigned->close(); $ajax_response = ['success' => true, 'assigned_papers' => $assigned_papers];}
            else { $ajax_response['message'] = 'Error fetching assignments: '. $conn->error; error_log("DoS TD - Error fetching assigned papers: " . $conn->error);}
            break;
        
        case 'remove_assigned_paper': // DoS can remove assignments
            $assignment_id_remove = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
            if (!$assignment_id_remove) { $ajax_response['message'] = 'Invalid Assignment ID.'; break; }
            
            // Optional: Add a check to ensure the DoS is allowed to remove this, or log it specifically
            // For now, direct delete.
            $stmt_remove = $conn->prepare("DELETE FROM teacher_class_subject_papers WHERE id = ?");
            if ($stmt_remove) {
                $stmt_remove->bind_param("i", $assignment_id_remove);
                if ($stmt_remove->execute()) { $ajax_response = ['success' => true, 'message' => 'Assignment removed successfully by DoS.']; }
                else { $ajax_response['message'] = 'Error removing: ' . $stmt_remove->error; }
                $stmt_remove->close();
            } else { $ajax_response['message'] = 'DB error removing: ' . $conn->error; }
            break;

        case 'get_students_for_subject_in_class': // DoS can view students for an assignment
            // ... (Copy this entire case block from your original teacher_details.php)
            $class_id_stud = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT); $subject_id_stud = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT); $academic_year_id_stud = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);
            if (!$class_id_stud || !$subject_id_stud || !$academic_year_id_stud) { $ajax_response['message'] = 'Class, Subject, and Year IDs required.'; break;}
            $students_list = [];
            $sql_students = "SELECT DISTINCT s.id, s.full_name, s.admission_number, s.sex, s.photo FROM students s JOIN student_enrollments se ON s.id = se.student_id WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status IN ('enrolled', 'promoted', 'repeated') AND (EXISTS (SELECT 1 FROM class_subjects cs JOIN subjects subj_comp ON cs.subject_id = subj_comp.id WHERE cs.class_id = ? AND cs.subject_id = ? AND subj_comp.is_optional = 0) OR EXISTS (SELECT 1 FROM student_optional_subjects sos WHERE sos.student_id = s.id AND sos.subject_id = ? AND sos.academic_year_id = ?)) ORDER BY s.full_name ASC";
            $stmt_stud = $conn->prepare($sql_students);
            if ($stmt_stud) { $stmt_stud->bind_param("iiiiii", $class_id_stud, $academic_year_id_stud, $class_id_stud, $subject_id_stud, $subject_id_stud, $academic_year_id_stud); $stmt_stud->execute(); $result_stud = $stmt_stud->get_result(); while ($row_stud = $result_stud->fetch_assoc()) { $students_list[] = $row_stud;} $stmt_stud->close(); $ajax_response = ['success' => true, 'students' => $students_list];}
            else { $ajax_response['message'] = 'DB error fetching students: ' . $conn->error; error_log("DoS TD - students fetch: " . $conn->error);}
            break;

        default: $ajax_response['message'] = 'Unknown AJAX action for DoS teacher details.'; break;
    }
    echo json_encode($ajax_response);
    exit;
}

// --- REGULAR PAGE LOAD LOGIC for DoS ---
$current_academic_year_info_dos = get_current_academic_year_info_dos_td($conn);
$current_academic_year_id_dos = $current_academic_year_info_dos['id'] ?? null;
$current_academic_year_name_dos = $current_academic_year_info_dos['year_name'] ?? 'N/A';

$all_academic_years_dos_td = [];
$all_classes_dos_td = [];
if ($conn) {
    $year_res_dos = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC");
    if ($year_res_dos) { while($row_dos_y = $year_res_dos->fetch_assoc()) { $all_academic_years_dos_td[] = $row_dos_y; } }
    $class_res_dos = $conn->query("SELECT id, class_name, class_level FROM classes ORDER BY class_name ASC");
    if ($class_res_dos) { while($row_dos_c = $class_res_dos->fetch_assoc()) { $all_classes_dos_td[] = $row_dos_c; } }
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message_dos_mt'] = "Invalid teacher ID."; // Use DoS manage teachers session key
    header("Location: dos_manage_teachers.php");
    exit();
}
$teacher_id_page_dos = (int)$_GET['id'];
$teacher_dos = null; // Data for the teacher being viewed
$teacher_creator_name_dos = 'N/A';
$teacher_updater_name_dos = 'N/A';

if ($conn) {
    $stmt_dos_td = $conn->prepare("SELECT t.*, u_creator.username as creator, u_updater.username as updater
                               FROM teachers t
                               LEFT JOIN users u_creator ON t.created_by = u_creator.id
                               LEFT JOIN users u_updater ON t.updated_by = u_updater.id
                               WHERE t.id = ?");
    if (!$stmt_dos_td) { /* handle error */ } else {
        $stmt_dos_td->bind_param("i", $teacher_id_page_dos);
        $stmt_dos_td->execute();
        $result_dos_td = $stmt_dos_td->get_result();
        $teacher_dos = $result_dos_td->fetch_assoc();
        if($teacher_dos){
            $teacher_creator_name_dos = $teacher_dos['creator'] ?: 'System/Unknown';
            $teacher_updater_name_dos = $teacher_dos['updater'] ?: 'N/A';
        }
        $stmt_dos_td->close();
    }
}
if (!$teacher_dos) { $_SESSION['error_message_dos_mt'] = "Teacher not found."; header("Location: dos_manage_teachers.php"); exit(); }

$default_teacher_photo_url_dos = 'https://ui-avatars.com/api/?name=' . urlencode($teacher_dos['full_name']) . '&background=087990&color=fff&size=150'; // DoS color
$teacher_photo_path_page_dos = (!empty($teacher_dos['photo']) && file_exists($teacher_dos['photo'])) ? htmlspecialchars($teacher_dos['photo']) : $default_teacher_photo_url_dos;

$success_message_dos_td = $_SESSION['success_message_dos_td'] ?? null;
$error_message_dos_td = $_SESSION['error_message_dos_td'] ?? null;
if ($success_message_dos_td) unset($_SESSION['success_message_dos_td']);
if ($error_message_dos_td) unset($_SESSION['error_message_dos_td']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($teacher_dos['full_name']); ?> - Teacher Details (DoS) | MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet"> <!-- Ensure correct path -->
    <style>
        :root { --primary-accent: #0dcaf0; /* DoS's primary color (info) */ }
        /* Reuse styles from HT version, primary-accent will override color specifics */
        .profile-container { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); }
        .profile-pic { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary-accent); box-shadow: 0 4px 10px rgba(0,0,0,0.1); background-color: var(--bs-secondary-bg); }
        .details-panel .nav-tabs .nav-link.active { color: var(--primary-accent); border-bottom-color: var(--primary-accent); }
        .details-panel .tab-content { border-left: 5px solid var(--primary-accent); }
        .details-section-card .card-header { color: var(--primary-accent); }
        .student-photo-thumb-modal { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; background-color: var(--bs-secondary-bg); }
        .audit-info { font-size: 0.75rem; color: #6c757d; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar_dos.php'; // Assumes DoS specific nav/sidebar ?>
        <div class="flex-grow-1 p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0 h3"><i class="fas fa-user-tie me-2 text-info"></i>Teacher Details <small class="text-muted fw-normal">(DoS View & Assign)</small></h2>
                <div><button class="btn btn-sm btn-outline-secondary me-2" onclick="window.location.href='dos_manage_teachers.php'"><i class="fas fa-arrow-left me-1"></i> Back to List</button></div>
            </div>

             <?php if ($success_message_dos_td): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_dos_td); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error_message_dos_td): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo nl2br(htmlspecialchars($error_message_dos_td)); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <div class="row mb-4">
                <!-- Teacher Info Panel (Read-only for DoS) -->
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="profile-container p-3 p-md-4 h-100">
                        <div class="d-flex flex-column align-items-center text-center">
                            <img src="<?php echo $teacher_photo_path_page_dos; ?>" alt="Teacher Photo" class="profile-pic mb-3">
                             <h3 class="h5 mb-1 fw-bold"><?php echo htmlspecialchars($teacher_dos['full_name']); ?></h3>
                            <p class="text-secondary mb-1 small"><i class="fas fa-user-tag opacity-75 me-1"></i> Role: <?php echo htmlspecialchars(ucfirst($teacher_dos['role'])); ?></p>
                            <p class="text-secondary mb-2 small"><i class="fas fa-book-open opacity-75 me-1"></i> Specialization: <?php echo htmlspecialchars($teacher_dos['subject_specialization'] ?: 'N/A'); ?></p>
                            <hr class="w-75 my-3">
                            <div class="info-item w-100 text-start"><span class="info-label small">Email:</span> <span class="info-value small text-truncate" title="<?php echo htmlspecialchars($teacher_dos['email']); ?>"><?php echo htmlspecialchars($teacher_dos['email']); ?></span></div>
                            <div class="info-item w-100 text-start"><span class="info-label small">Phone:</span> <span class="info-value small"><?php echo htmlspecialchars($teacher_dos['phone_number']); ?></span></div>
                             <div class="info-item w-100 text-start"><span class="info-label small">Joined:</span> <span class="info-value small"><?php echo htmlspecialchars($teacher_dos['join_date'] ? date('d M, Y', strtotime($teacher_dos['join_date'])) : 'N/A'); ?></span></div>
                             <hr class="w-75 my-2">
                            <div class="audit-info text-start w-100">
                                Created: <?php echo date('d M Y H:i', strtotime($teacher_dos['created_at'])); ?> by <?php echo htmlspecialchars($teacher_creator_name_dos); ?><br>
                                Last Updated: <?php echo date('d M Y H:i', strtotime($teacher_dos['updated_at'])); ?> by <?php echo htmlspecialchars($teacher_updater_name_dos); ?>
                            </div>
                        </div>
                    </div>
                </div>
                 <div class="col-lg-8">
                    <div class="details-panel">
                        <!-- Tabs for Personal/Professional, Documents (Read-Only) -->
                        <ul class="nav nav-tabs" id="teacherInfoTabsDos" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" id="t-personal-dos-tab" data-bs-toggle="tab" data-bs-target="#t-personal-dos-pane" type="button"><i class="fas fa-id-card-alt me-1"></i>Details</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="t-documents-dos-tab" data-bs-toggle="tab" data-bs-target="#t-documents-dos-pane" type="button"><i class="fas fa-file-archive me-1"></i>Documents</button></li>
                        </ul>
                        <div class="tab-content" id="teacherInfoTabsContentDos">
                            <div class="tab-pane fade show active" id="t-personal-dos-pane" role="tabpanel">
                                <!-- All fields from HT view, but just displayed, not editable -->
                                <h5 class="section-title">Basic Information</h5>
                                <!-- ... Display all relevant fields from $teacher_dos ... -->
                                <div class="info-item"><span class="info-label">Full Name:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['full_name']); ?></span></div>
                                <div class="info-item"><span class="info-label">Date of Birth:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['date_of_birth'] ? date('d M Y', strtotime($teacher_dos['date_of_birth'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">NIN Number:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['nin_number'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Address:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['address'] ?: 'N/A'); ?></span></div>

                                <h5 class="section-title mt-3">Contact</h5>
                                <div class="info-item"><span class="info-label">Email:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['email']); ?></span></div>
                                <div class="info-item"><span class="info-label">Phone:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['phone_number']); ?></span></div>

                                <h5 class="section-title mt-3">Employment</h5>
                                <div class="info-item"><span class="info-label">Role:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($teacher_dos['role'])); ?></span></div>
                                <div class="info-item"><span class="info-label">Joined:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['join_date'] ? date('d M Y', strtotime($teacher_dos['join_date'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Specialization:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['subject_specialization'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Salary (UGX):</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['salary'] ? number_format($teacher_dos['salary'], 2) : 'N/A'); ?></span></div>

                                <h5 class="section-title mt-3">Disability</h5>
                                <div class="info-item"><span class="info-label">Has Disability:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($teacher_dos['has_disability'])); ?></span></div>
                                <?php if ($teacher_dos['has_disability'] === 'Yes'): ?>
                                    <div class="info-item"><span class="info-label">Type:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_dos['disability_type'] ?: 'Not specified'); ?></span></div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="t-documents-dos-pane" role="tabpanel">
                                <h5 class="section-title">Uploaded Documents</h5>
                                <?php
                                $doc_fields_dos = [ /* same as HT */ 
                                    'photo' => 'Photo', 'disability_medical_report_path' => 'Disability Medical Report',
                                    'application_form_path' => 'Application Form', 'national_id_path' => 'National ID',
                                    'academic_transcripts_path' => 'Academic Transcripts', 'other_documents_path' => 'Other Documents'
                                ];
                                $has_docs_dos = false;
                                foreach ($doc_fields_dos as $field_key => $label) {
                                    if (!empty($teacher_dos[$field_key]) && file_exists($teacher_dos[$field_key])) {
                                        $has_docs_dos = true;
                                        echo '<div class="info-item"><span class="info-label">' . htmlspecialchars($label) . ':</span><span class="info-value"><a href="' . htmlspecialchars($teacher_dos[$field_key]) . '" target="_blank" class="file-link"><i class="fas fa-external-link-alt fa-xs me-1"></i>View Document</a></span></div>';
                                    }
                                }
                                if (!$has_docs_dos) echo '<p class="text-muted">No documents available.</p>';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons for DoS: Only "Request Profile Update" (conceptual) -->
            <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
                 <button class="btn btn-outline-secondary action-btn" onclick="requestTeacherProfileUpdateFromDos(<?php echo $teacher_id_page_dos; ?>)">
                    <i class="fas fa-flag me-1"></i> Request Profile Update from HT
                </button>
            </div>


            <!-- Assignment Management Section (Primary focus for DoS on this page) -->
            <div class="details-section-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-tasks me-2"></i>Manage Subject Paper Assignments</span>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#assignPaperModalDos">
                        <i class="fas fa-plus me-1"></i> New Assignment
                    </button>
                </div>
                <div class="card-body p-3 p-md-4">
                    <div class="mb-3">
                        <label for="filterAcademicYearAssignmentsDos" class="form-label">Filter by Academic Year:</label>
                        <select id="filterAcademicYearAssignmentsDos" class="form-select form-select-sm" style="max-width: 300px;">
                             <?php if (empty($all_academic_years_dos_td)): ?> <option value="">No academic years</option>
                            <?php else: foreach ($all_academic_years_dos_td as $year_dos): ?>
                                <option value="<?php echo $year_dos['id']; ?>" <?php echo ($year_dos['id'] == $current_academic_year_id_dos) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year_dos['year_name']); ?>
                                </option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <div id="assignedPapersListContainerDos"><p class="text-muted">Select academic year to view/manage assignments.</p></div>
                </div>
            </div>
        </div> 
    </div> 

    <!-- Assign Paper Modal (FOR DoS) - Same as your original teacher_details.php modal -->
    <div class="modal fade" id="assignPaperModalDos" tabindex="-1" aria-labelledby="assignPaperModalLabelDos" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignPaperModalLabelDos">Assign Subject Paper to <?php echo htmlspecialchars($teacher_dos['full_name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="assignPaperFormDos">
                        <input type="hidden" name="teacher_id" value="<?php echo $teacher_id_page_dos; ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="assign_academic_year_id_dos" class="form-label">Academic Year <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_academic_year_id_dos" name="academic_year_id" required>
                                    <option value="">Select Academic Year...</option>
                                    <?php foreach ($all_academic_years_dos_td as $year): ?>
                                        <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_academic_year_id_dos) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="assign_class_id_dos" class="form-label">Class <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_class_id_dos" name="class_id" required>
                                    <option value="">Select Class...</option>
                                    <?php foreach ($all_classes_dos_td as $class_item): ?>
                                        <option value="<?php echo $class_item['id']; ?>"><?php echo htmlspecialchars($class_item['class_name']) . " (" . htmlspecialchars($class_item['class_level']) . ")"; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="assign_subject_id_dos" class="form-label">Subject <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_subject_id_dos" name="subject_id" required disabled><option value="">Select Class First...</option></select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="assign_subject_paper_id_dos" class="form-label">Subject Paper <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_subject_paper_id_dos" name="subject_paper_id" required disabled><option value="">Select Subject First...</option></select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitAssignmentBtnDos"><i class="fas fa-check-circle me-1"></i>Assign Paper</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Students Modal (FOR DoS) - Same as your original -->
    <div class="modal fade" id="viewAssignedStudentsModalDos" tabindex="-1" aria-labelledby="viewAssignedStudentsModalLabelDos" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="viewAssignedStudentsModalLabelDos">Students for Subject Paper</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><div id="studentsListForPaperContainerDos"><p class="text-center">Loading students...</p></div></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="javascript/script.js"></script> <!-- Ensure correct path -->
    <script>
        const teacherIdPageDos = <?php echo $teacher_id_page_dos; ?>;
        const ajaxUrlDosTd = '<?php echo $_SERVER["PHP_SELF"]; ?>'; 
        const currentAcademicYearIdDosPage = <?php echo $current_academic_year_id_dos ? $current_academic_year_id_dos : 'null'; ?>;

        $(document).ready(function() {
            // --- Event listeners for DoS assignment modal ---
            $('#assign_class_id_dos').on('change', function() {
                const classId = $(this).val(); const subjectSelect = $('#assign_subject_id_dos'); const paperSelect = $('#assign_subject_paper_id_dos');
                subjectSelect.html('<option value="">Loading...</option>').prop('disabled', true); paperSelect.html('<option value="">Select Subject...</option>').prop('disabled', true);
                if (classId) {
                    $.ajax({ url: ajaxUrlDosTd, type: 'GET', data: { action: 'get_subjects_for_class', class_id: classId }, dataType: 'json',
                        success: function(r) { subjectSelect.html('<option value="">Select Subject...</option>'); if (r.success && r.subjects.length > 0) { r.subjects.forEach(s => subjectSelect.append(new Option(s.subject_name + (s.subject_code ? ` (${s.subject_code})` : ''), s.id))); subjectSelect.prop('disabled', false); } else subjectSelect.html('<option value="">No subjects</option>'); },
                        error: function() { subjectSelect.html('<option value="">Error</option>'); }
                    });
                }
            });
            $('#assign_subject_id_dos').on('change', function() {
                const subjectId = $(this).val(); const paperSelect = $('#assign_subject_paper_id_dos');
                paperSelect.html('<option value="">Loading...</option>').prop('disabled', true);
                if (subjectId) {
                    $.ajax({ url: ajaxUrlDosTd, type: 'GET', data: { action: 'get_papers_for_subject', subject_id: subjectId }, dataType: 'json',
                        success: function(r) { paperSelect.html('<option value="">Select Paper...</option>'); if (r.success && r.papers.length > 0) { r.papers.forEach(p => paperSelect.append(new Option(p.paper_name + (p.paper_code ? ` (${p.paper_code})` : ''), p.id))); paperSelect.prop('disabled', false); } else paperSelect.html('<option value="">No papers</option>'); },
                        error: function() { paperSelect.html('<option value="">Error</option>'); }
                    });
                }
            });
            $('#submitAssignmentBtnDos').on('click', function() {
                const form = $('#assignPaperFormDos'); const btn = $(this);
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Assigning...');
                $.ajax({ url: ajaxUrlDosTd, type: 'POST', data: form.serialize() + '&action=assign_paper_to_teacher', dataType: 'json',
                    success: function(r) { if (r.success) { alert(r.message); $('#assignPaperModalDos').modal('hide'); form[0].reset(); /* Reset dependent dropdowns */ $('#assign_subject_id_dos').html('<option value="">Select Class First...</option>').prop('disabled', true); $('#assign_subject_paper_id_dos').html('<option value="">Select Subject First...</option>').prop('disabled', true); loadAssignedPapersDos($('#filterAcademicYearAssignmentsDos').val()); } else { alert('Error: ' + r.message); }},
                    error: function() { alert('AJAX error.'); },
                    complete: function() { btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i>Assign Paper');}
                });
            });
            
            if(currentAcademicYearIdDosPage) { loadAssignedPapersDos(currentAcademicYearIdDosPage); }
            $('#filterAcademicYearAssignmentsDos').on('change', function(){ loadAssignedPapersDos($(this).val()); });
        });

        function loadAssignedPapersDos(academicYearId) {
            const container = $('#assignedPapersListContainerDos');
            container.html('<p class="text-center"><span class="spinner-border spinner-border-sm"></span> Loading...</p>');
            if (!academicYearId) { container.html('<p class="text-muted">Please select an academic year.</p>'); return; }

            $.ajax({
                url: ajaxUrlDosTd, type: 'GET',
                data: { action: 'get_assigned_papers', teacher_id: teacherIdPageDos, academic_year_id: academicYearId },
                dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.assigned_papers.length > 0) {
                        const table = $('<table class="table table-sm table-hover table-striped"><thead><tr><th>Class</th><th>Subject</th><th>Paper</th><th>Code</th><th>Assigned By</th><th>Actions</th></tr></thead><tbody></tbody></table>');
                        response.assigned_papers.forEach(item => {
                            const row = $('<tr></tr>');
                            row.append($('<td></td>').text(item.class_name));
                            row.append($('<td></td>').text(item.subject_name));
                            row.append($('<td></td>').text(item.paper_name));
                            row.append($('<td></td>').text(item.paper_code || 'N/A'));
                            row.append($('<td></td>').text(item.assigned_by_username || 'N/A').addClass('small text-muted'));

                            const actions = $('<td></td>');
                            const viewStudentsBtn = $('<button class="btn btn-xs btn-outline-info me-1" title="View Students"><i class="fas fa-users"></i></button>');
                            viewStudentsBtn.on('click', function(){ openViewStudentsModalDos(item.class_id_for_students, item.subject_id_for_students, academicYearId, item.class_name, item.subject_name, item.paper_name); });
                            actions.append(viewStudentsBtn);
                            actions.append($('<button class="btn btn-xs btn-outline-danger" title="Remove Assignment"><i class="fas fa-trash-alt"></i></button>').on('click', function(){ removeAssignmentDos(item.assignment_id); }));
                            row.append(actions); table.find('tbody').append(row);
                        });
                        container.append(table);
                    } else if (response.success) { container.html('<div class="alert alert-info">No assignments for this teacher for this year.</div>');
                    } else { container.html('<div class="alert alert-danger">Error: ' + (response.message || 'Could not load.') + '</div>'); }
                },
                error: function() { container.html('<div class="alert alert-danger">Failed to load assignments.</div>'); }
            });
        }
        
        function removeAssignmentDos(assignmentId) {
            if (!confirm('Are you sure you want to remove this assignment?')) return;
            $.ajax({
                url: ajaxUrlDosTd, type: 'POST', data: { action: 'remove_assigned_paper', assignment_id: assignmentId }, dataType: 'json',
                success: function(r){ if(r.success){ alert(r.message); loadAssignedPapersDos($('#filterAcademicYearAssignmentsDos').val()); } else { alert('Error: ' + r.message); }},
                error: function(){ alert('AJAX error removing assignment.'); }
            });
        }

        function openViewStudentsModalDos(classId, subjectId, academicYearId, className, subjectName, paperName) {
            const modal = $('#viewAssignedStudentsModalDos'); const container = $('#studentsListForPaperContainerDos');
            const modalLabel = $('#viewAssignedStudentsModalLabelDos');
            modalLabel.text(`Students in ${className} taking ${subjectName} (Paper: ${paperName})`);
            container.html('<p class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Loading...</p>');
            modal.modal('show');
            $.ajax({ url: ajaxUrlDosTd, type: 'GET', data: { action: 'get_students_for_subject_in_class', class_id: classId, subject_id: subjectId, academic_year_id: academicYearId }, dataType: 'json',
                success: function(r) { container.empty(); if (r.success && r.students && r.students.length > 0) { const table = $('<table class="table table-sm table-hover"><thead><tr><th>Photo</th><th>Adm No.</th><th>Full Name</th><th>Sex</th></tr></thead><tbody></tbody></table>'); r.students.forEach(s => { let photo = s.photo ? s.photo : `https://ui-avatars.com/api/?name=${encodeURIComponent(s.full_name)}&size=35&bg=random&color=fff`; table.find('tbody').append(`<tr><td><img src="${photo}" class="student-photo-thumb-modal" alt="${s.full_name}"></td><td>${s.admission_number || 'N/A'}</td><td>${s.full_name}</td><td>${s.sex || 'N/A'}</td></tr>`); }); container.append(table); } else if (r.success) { container.html('<div class="alert alert-info">No students found.</div>'); } else { container.html('<div class="alert alert-danger">Error: ' + (r.message || 'Could not load.') + '</div>');}},
                error: function() { container.html('<div class="alert alert-danger">Failed to load.</div>');}
            });
        }
        function requestTeacherProfileUpdateFromDos(teacherId){
            alert(`A request to update profile for Teacher ID ${teacherId} would be sent to the Head Teacher.`);
            // Future: Implement actual notification system.
        }
    </script>
</body>
</html>"