<?php
require_once 'db_config.php';
require_once 'auth.php'; // For check_login if needed, and other helpers

// --- Access Control (Example) ---
// check_login(['head_teacher', 'admin', 'IT']); // Or other appropriate roles

// --- HELPER FUNCTIONS (similar to student_details.php) ---
function get_current_academic_year_info_td($conn) {
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    return null;
}

// --- AJAX ACTION HANDLING ---
$requested_action = $_REQUEST['action'] ?? null;
if ($requested_action && $conn) {
    header('Content-Type: application/json');
    $ajax_response = ['success' => false, 'message' => 'Invalid action or database connection error.'];

    switch ($requested_action) {
        case 'get_subjects_for_class':
            $class_id_ajax = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
            if (!$class_id_ajax) { $ajax_response['message'] = 'Invalid Class ID.'; break; }
            
            $subjects_ajax = [];
            $stmt_subjects = $conn->prepare("
                SELECT s.id, s.subject_name, s.subject_code 
                FROM subjects s
                JOIN class_subjects cs ON s.id = cs.subject_id
                WHERE cs.class_id = ?
                ORDER BY s.subject_name
            ");
            if ($stmt_subjects) {
                $stmt_subjects->bind_param("i", $class_id_ajax);
                $stmt_subjects->execute();
                $result_subjects = $stmt_subjects->get_result();
                while ($row = $result_subjects->fetch_assoc()) {
                    $subjects_ajax[] = $row;
                }
                $stmt_subjects->close();
                $ajax_response = ['success' => true, 'subjects' => $subjects_ajax];
            } else {
                $ajax_response['message'] = 'DB query error for subjects: ' . $conn->error;
            }
            break;

        case 'get_papers_for_subject':
            $subject_id_ajax = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
            if (!$subject_id_ajax) { $ajax_response['message'] = 'Invalid Subject ID.'; break; }

            $papers_ajax = [];
            $stmt_papers = $conn->prepare("
                SELECT id, paper_name, paper_code 
                FROM subject_papers 
                WHERE subject_id = ? 
                ORDER BY paper_name
            ");
            if ($stmt_papers) {
                $stmt_papers->bind_param("i", $subject_id_ajax);
                $stmt_papers->execute();
                $result_papers = $stmt_papers->get_result();
                while ($row = $result_papers->fetch_assoc()) {
                    $papers_ajax[] = $row;
                }
                $stmt_papers->close();
                $ajax_response = ['success' => true, 'papers' => $papers_ajax];
            } else {
                $ajax_response['message'] = 'DB query error for papers: ' . $conn->error;
            }
            break;

        case 'assign_paper_to_teacher':
            $teacher_id_assign = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            $academic_year_id_assign = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
            $class_id_assign = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $subject_id_assign = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $subject_paper_id_assign = filter_input(INPUT_POST, 'subject_paper_id', FILTER_VALIDATE_INT);

            if (!$teacher_id_assign || !$academic_year_id_assign || !$class_id_assign || !$subject_id_assign || !$subject_paper_id_assign) {
                $ajax_response['message'] = 'All fields are required for assignment.';
                break;
            }

            $stmt_check = $conn->prepare("SELECT id FROM teacher_class_subject_papers WHERE teacher_id = ? AND class_id = ? AND subject_paper_id = ? AND academic_year_id = ?");
            if ($stmt_check) {
                $stmt_check->bind_param("iiii", $teacher_id_assign, $class_id_assign, $subject_paper_id_assign, $academic_year_id_assign);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $ajax_response['message'] = 'This teacher is already assigned this paper in this class for the selected academic year.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
            }

            $stmt_insert = $conn->prepare("INSERT INTO teacher_class_subject_papers (teacher_id, academic_year_id, class_id, subject_id, subject_paper_id) VALUES (?, ?, ?, ?, ?)");
            if ($stmt_insert) {
                $stmt_insert->bind_param("iiiii", $teacher_id_assign, $academic_year_id_assign, $class_id_assign, $subject_id_assign, $subject_paper_id_assign);
                if ($stmt_insert->execute()) {
                    $ajax_response = ['success' => true, 'message' => 'Subject paper assigned successfully.'];
                } else {
                    $ajax_response['message'] = 'Failed to assign subject paper: ' . $stmt_insert->error;
                     error_log("Error assigning paper: " . $stmt_insert->error . " SQL: INSERT INTO teacher_class_subject_papers (teacher_id, academic_year_id, class_id, subject_id, subject_paper_id) VALUES ($teacher_id_assign, $academic_year_id_assign, $class_id_assign, $subject_id_assign, $subject_paper_id_assign)");
                }
                $stmt_insert->close();
            } else {
                $ajax_response['message'] = 'Database query preparation failed for insert: ' . $conn->error;
            }
            break;
        
        case 'get_assigned_papers':
            $teacher_id_fetch = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
            $academic_year_id_fetch = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);

            if (!$teacher_id_fetch || !$academic_year_id_fetch) {
                $ajax_response['message'] = 'Teacher ID and Academic Year ID are required.';
                break;
            }
            $assigned_papers = [];
            $sql = "SELECT tcsp.id as assignment_id, 
                           ay.year_name, 
                           c.class_name, 
                           s.subject_name, 
                           sp.paper_name, 
                           sp.paper_code,
                           tcsp.class_id AS class_id_for_students,      -- Added for View Students button
                           tcsp.subject_id AS subject_id_for_students  -- Added for View Students button
                    FROM teacher_class_subject_papers tcsp
                    JOIN academic_years ay ON tcsp.academic_year_id = ay.id
                    JOIN classes c ON tcsp.class_id = c.id
                    JOIN subjects s ON tcsp.subject_id = s.id
                    JOIN subject_papers sp ON tcsp.subject_paper_id = sp.id
                    WHERE tcsp.teacher_id = ? AND tcsp.academic_year_id = ?
                    ORDER BY ay.year_name, c.class_name, s.subject_name, sp.paper_name";
            $stmt = $conn->prepare($sql);
            if($stmt){
                $stmt->bind_param("ii", $teacher_id_fetch, $academic_year_id_fetch);
                $stmt->execute();
                $result = $stmt->get_result();
                while($row = $result->fetch_assoc()){
                    $assigned_papers[] = $row;
                }
                $stmt->close();
                $ajax_response = ['success' => true, 'assigned_papers' => $assigned_papers];
            } else {
                $ajax_response['message'] = 'Error fetching assigned papers: '. $conn->error;
            }
            break;
        
        case 'remove_assigned_paper':
            $assignment_id_remove = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
            if (!$assignment_id_remove) {
                $ajax_response['message'] = 'Invalid Assignment ID.';
                break;
            }
            $stmt_remove = $conn->prepare("DELETE FROM teacher_class_subject_papers WHERE id = ?");
            if ($stmt_remove) {
                $stmt_remove->bind_param("i", $assignment_id_remove);
                if ($stmt_remove->execute()) {
                    $ajax_response = ['success' => true, 'message' => 'Assignment removed successfully.'];
                } else {
                    $ajax_response['message'] = 'Error removing assignment: ' . $stmt_remove->error;
                }
                $stmt_remove->close();
            } else {
                $ajax_response['message'] = 'DB query error for removing assignment: ' . $conn->error;
            }
            break;

        case 'get_students_for_subject_in_class':
            $class_id_stud = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
            $subject_id_stud = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
            $academic_year_id_stud = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);

            if (!$class_id_stud || !$subject_id_stud || !$academic_year_id_stud) {
                $ajax_response['message'] = 'Class ID, Subject ID, and Academic Year ID are required.';
                break;
            }

            $students_list = [];
            $sql_students = "
                SELECT DISTINCT s.id, s.full_name, s.admission_number, s.sex, s.photo
                FROM students s
                JOIN student_enrollments se ON s.id = se.student_id
                WHERE se.class_id = ? AND se.academic_year_id = ?
                AND se.status IN ('enrolled', 'promoted', 'repeated') 
                AND (
                    EXISTS (
                        SELECT 1 FROM class_subjects cs
                        JOIN subjects subj_comp ON cs.subject_id = subj_comp.id
                        WHERE cs.class_id = ? AND cs.subject_id = ? AND subj_comp.is_optional = 0
                    )
                    OR
                    EXISTS (
                        SELECT 1 FROM student_optional_subjects sos
                        WHERE sos.student_id = s.id AND sos.subject_id = ? AND sos.academic_year_id = ?
                    )
                )
                ORDER BY s.full_name ASC
            ";

            $stmt_stud = $conn->prepare($sql_students);
            if ($stmt_stud) {
                $stmt_stud->bind_param("iiiiii", $class_id_stud, $academic_year_id_stud, $class_id_stud, $subject_id_stud, $subject_id_stud, $academic_year_id_stud);
                $stmt_stud->execute();
                $result_stud = $stmt_stud->get_result();
                while ($row_stud = $result_stud->fetch_assoc()) {
                    $students_list[] = $row_stud;
                }
                $stmt_stud->close();
                $ajax_response = ['success' => true, 'students' => $students_list];
            } else {
                $ajax_response['message'] = 'DB query error for fetching students: ' . $conn->error;
                error_log("Error fetching students for subject in class: " . $conn->error);
            }
            break;
            

        default:
            $ajax_response['message'] = 'Unknown AJAX action requested.';
            break;
    }
    echo json_encode($ajax_response);
    exit;
}


// --- REGULAR PAGE LOAD LOGIC ---
$current_academic_year_info_td = get_current_academic_year_info_td($conn);
$current_academic_year_id_td = $current_academic_year_info_td['id'] ?? null;
$current_academic_year_name_td = $current_academic_year_info_td['year_name'] ?? 'N/A';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message_tm'] = "Invalid teacher ID.";
    header("Location: teachers_management.php");
    exit();
}
$teacher_id_page = (int)$_GET['id'];
$teacher = null;

if ($conn) {
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("SQL prepare error teacher_details: " . $conn->error);
        $_SESSION['error_message_td'] = "Database error fetching teacher details.";
    } else {
        $stmt->bind_param("i", $teacher_id_page);
        $stmt->execute();
        $result = $stmt->get_result();
        $teacher = $result->fetch_assoc();
        $stmt->close();
    }
}

if (!$teacher) {
    $_SESSION['error_message_tm'] = "Teacher not found.";
    header("Location: teachers_management.php");
    exit();
}

$default_teacher_photo_url = 'https://ui-avatars.com/api/?name=' . urlencode($teacher['full_name']) . '&background=random&color=fff&size=150';
$teacher_photo_path_page = (!empty($teacher['photo']) && file_exists($teacher['photo'])) ? htmlspecialchars($teacher['photo']) : $default_teacher_photo_url;

$all_academic_years = [];
$all_classes = [];

if ($conn) {
    $year_res = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC");
    if ($year_res) while($row = $year_res->fetch_assoc()) $all_academic_years[] = $row;

    $class_res = $conn->query("SELECT id, class_name, class_level FROM classes ORDER BY class_name ASC");
    if ($class_res) while($row = $class_res->fetch_assoc()) $all_classes[] = $row;
}


$success_message_td = $_SESSION['success_message_td'] ?? null;
$error_message_td = $_SESSION['error_message_td'] ?? null;
$warning_message_td = $_SESSION['warning_message_td'] ?? null;
if ($success_message_td) unset($_SESSION['success_message_td']);
if ($error_message_td) unset($_SESSION['error_message_td']);
if ($warning_message_td) unset($_SESSION['warning_message_td']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($teacher['full_name']); ?> - Teacher Details | MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="style/styles.css" rel="stylesheet">
    <style>
        :root { --primary-accent: #0d6efd; }
        body { background-color: var(--bs-body-bg); color: var(--bs-body-color); }
        .profile-container { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); }
        .profile-pic { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary-accent); box-shadow: 0 4px 10px rgba(0,0,0,0.1); background-color: var(--bs-secondary-bg); }
        .details-panel { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); padding: 0; height: 100%; }
        .details-panel .nav-tabs { border-bottom: 1px solid var(--bs-border-color); padding: 0.5rem 1rem 0 1rem; }
        .details-panel .nav-tabs .nav-link { border: none; border-bottom: 3px solid transparent; color: var(--bs-secondary-color); padding: 0.75rem 1rem; font-weight: 500; }
        .details-panel .nav-tabs .nav-link.active, .details-panel .nav-tabs .nav-link:hover { color: var(--primary-accent); border-bottom-color: var(--primary-accent); background-color: transparent; }
        .details-panel .tab-content { padding: 1.5rem; border-left: 5px solid var(--primary-accent); border-radius: 0 0 0.70rem 0; min-height: 300px; }
        .details-panel .tab-content h5.section-title { font-size: 0.9rem; font-weight: 600; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px dashed var(--bs-border-color-translucent); }
        .details-panel .info-item { display: flex; margin-bottom: 0.75rem; font-size: 0.95rem; }
        .details-panel .info-label { font-weight: 500; color: var(--bs-body-color); width: 180px; flex-shrink: 0; }
        .details-panel .info-value { color: var(--bs-secondary-color); }
        .details-section-card { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); margin-bottom: 1.5rem; }
        .details-section-card .card-header { background-color: var(--bs-secondary-bg); border-bottom: 1px solid var(--bs-border-color); font-size: 1.1rem; font-weight: 500; padding: .75rem 1.25rem; color: var(--primary-accent); }
        .action-btn { border-radius: .375rem; padding: .5rem 1rem; font-weight: 500; }
        .file-link { font-size: 0.85em; color: var(--bs-link-color); }
        .file-link:hover { text-decoration: underline; }
        .student-photo-thumb-modal { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; background-color: var(--bs-secondary-bg); }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar.php'; ?>
        <div class="flex-grow-1 p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0 h3"><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Teacher Details</h2>
                <div>
                    <button class="btn btn-sm btn-outline-secondary me-2" onclick="window.location.href='teachers_management.php'"><i class="fas fa-arrow-left me-1"></i> Back to List</button>
                </div>
            </div>

            <?php if ($success_message_td): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_td); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error_message_td): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo nl2br(htmlspecialchars($error_message_td)); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($warning_message_td): ?><div class="alert alert-warning alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($warning_message_td); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <div class="row mb-4">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="profile-container p-3 p-md-4 h-100">
                        <div class="d-flex flex-column align-items-center text-center">
                            <img src="<?php echo $teacher_photo_path_page; ?>" alt="Teacher Photo" class="profile-pic mb-3">
                            <h3 class="h5 mb-1 fw-bold"><?php echo htmlspecialchars($teacher['full_name']); ?></h3>
                            <p class="text-secondary mb-1 small">
                                <i class="fas fa-user-tie opacity-75 me-1"></i> Role: <?php echo htmlspecialchars(ucfirst($teacher['role'])); ?>
                            </p>
                            <p class="text-secondary mb-2 small">
                                <i class="fas fa-book-reader opacity-75 me-1"></i> Specialization: <?php echo htmlspecialchars($teacher['subject_specialization'] ?: 'N/A'); ?>
                            </p>
                            <hr class="w-75 my-3">
                            <div class="info-item w-100 text-start"><span class="info-label small">Email:</span> <span class="info-value small text-truncate" title="<?php echo htmlspecialchars($teacher['email']); ?>"><?php echo htmlspecialchars($teacher['email']); ?></span></div>
                            <div class="info-item w-100 text-start"><span class="info-label small">Phone:</span> <span class="info-value small"><?php echo htmlspecialchars($teacher['phone_number']); ?></span></div>
                            <div class="info-item w-100 text-start"><span class="info-label small">Joined:</span> <span class="info-value small"><?php echo htmlspecialchars($teacher['join_date'] ? date('d M, Y', strtotime($teacher['join_date'])) : 'N/A'); ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="details-panel">
                        <ul class="nav nav-tabs" id="teacherInfoTabs" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" id="t-personal-tab" data-bs-toggle="tab" data-bs-target="#t-personal-pane" type="button"><i class="fas fa-user-circle me-1"></i>Personal & Professional</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="t-documents-tab" data-bs-toggle="tab" data-bs-target="#t-documents-pane" type="button"><i class="fas fa-folder-open me-1"></i>Documents</button></li>
                        </ul>
                        <div class="tab-content" id="teacherInfoTabsContent">
                            <div class="tab-pane fade show active" id="t-personal-pane" role="tabpanel" aria-labelledby="t-personal-tab" tabindex="0">
                                <h5 class="section-title">Basic Information</h5>
                                <div class="info-item"><span class="info-label">Full Name:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['full_name']); ?></span></div>
                                <div class="info-item"><span class="info-label">Date of Birth:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['date_of_birth'] ? date('d M Y', strtotime($teacher['date_of_birth'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Email:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['email']); ?></span></div>
                                <div class="info-item"><span class="info-label">Phone Number:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['phone_number']); ?></span></div>
                                <div class="info-item"><span class="info-label">Address:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['address'] ?: 'N/A'); ?></span></div>
                                <h5 class="section-title mt-4">Professional Details</h5>
                                <div class="info-item"><span class="info-label">NIN Number:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['nin_number'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Subject Specialization:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['subject_specialization'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Joining Date:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['join_date'] ? date('d M Y', strtotime($teacher['join_date'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Salary (UGX):</span> <span class="info-value"><?php echo htmlspecialchars($teacher['salary'] ? number_format($teacher['salary'], 2) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Role:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($teacher['role'])); ?></span></div>
                                <h5 class="section-title mt-4">Disability Information</h5>
                                <div class="info-item"><span class="info-label">Has Disability:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($teacher['has_disability'])); ?></span></div>
                                <?php if ($teacher['has_disability'] === 'Yes'): ?>
                                    <div class="info-item"><span class="info-label">Disability Type:</span> <span class="info-value"><?php echo htmlspecialchars($teacher['disability_type'] ?: 'Not specified'); ?></span></div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="t-documents-pane" role="tabpanel" aria-labelledby="t-documents-tab" tabindex="0">
                                <h5 class="section-title">Uploaded Documents</h5>
                                <?php
                                $doc_fields = [
                                    'photo' => 'Photo',
                                    'disability_medical_report_path' => 'Disability Medical Report',
                                    'application_form_path' => 'Application Form',
                                    'national_id_path' => 'National ID',
                                    'academic_transcripts_path' => 'Academic Transcripts',
                                    'other_documents_path' => 'Other Documents'
                                ];
                                $has_docs = false;
                                foreach ($doc_fields as $field_key => $label) {
                                    if (!empty($teacher[$field_key]) && file_exists($teacher[$field_key])) {
                                        $has_docs = true;
                                        echo '<div class="info-item">';
                                        echo '<span class="info-label">' . htmlspecialchars($label) . ':</span>';
                                        echo '<span class="info-value"><a href="' . htmlspecialchars($teacher[$field_key]) . '" target="_blank" class="file-link"><i class="fas fa-external-link-alt fa-xs me-1"></i>View Document</a></span>';
                                        echo '</div>';
                                    }
                                }
                                if (!$has_docs) {
                                    echo '<p class="text-muted">No documents uploaded for this teacher.</p>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
                <button class="btn btn-primary action-btn" onclick="redirectToEditTeacher(<?php echo $teacher_id_page; ?>)">
                    <i class="fas fa-edit me-1"></i> Edit Teacher Profile
                </button>
                <button class="btn btn-warning action-btn text-dark" onclick="openPasswordModalTeacherPage(<?php echo $teacher_id_page; ?>, '<?php echo htmlspecialchars(addslashes($teacher['full_name'])); ?>', '<?php echo $teacher_photo_path_page; ?>')">
                    <i class="fas fa-key me-1"></i> Change Password
                </button>
            </div>

            <div class="details-section-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-tasks me-2"></i>Assigned Subject Papers</span>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#assignPaperModal">
                        <i class="fas fa-plus me-1"></i> New Assignment
                    </button>
                </div>
                <div class="card-body p-3 p-md-4">
                    <div class="mb-3">
                        <label for="filterAcademicYearAssignments" class="form-label">Filter by Academic Year:</label>
                        <select id="filterAcademicYearAssignments" class="form-select form-select-sm" style="max-width: 300px;">
                            <?php if (empty($all_academic_years)): ?>
                                <option value="">No academic years found</option>
                            <?php else: ?>
                                <?php foreach ($all_academic_years as $year): ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_academic_year_id_td) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div id="assignedPapersListContainer">
                        <p class="text-muted">Select an academic year to view assignments.</p>
                    </div>
                </div>
            </div>
            
        </div> 
    </div> 

    <div class="modal fade" id="assignPaperModal" tabindex="-1" aria-labelledby="assignPaperModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignPaperModalLabel">Assign Subject Paper to <?php echo htmlspecialchars($teacher['full_name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="assignPaperForm">
                        <input type="hidden" name="teacher_id" value="<?php echo $teacher_id_page; ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="assign_academic_year_id" class="form-label">Academic Year <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_academic_year_id" name="academic_year_id" required>
                                    <option value="">Select Academic Year...</option>
                                    <?php foreach ($all_academic_years as $year): ?>
                                        <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_academic_year_id_td) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year['year_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="assign_class_id" class="form-label">Class <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_class_id" name="class_id" required>
                                    <option value="">Select Class...</option>
                                    <?php foreach ($all_classes as $class_item): ?>
                                        <option value="<?php echo $class_item['id']; ?>">
                                            <?php echo htmlspecialchars($class_item['class_name']) . " (" . htmlspecialchars($class_item['class_level']) . ")"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="assign_subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_subject_id" name="subject_id" required disabled>
                                    <option value="">Select Class First...</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="assign_subject_paper_id" class="form-label">Subject Paper <span class="text-danger">*</span></label>
                                <select class="form-select" id="assign_subject_paper_id" name="subject_paper_id" required disabled>
                                    <option value="">Select Subject First...</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitAssignmentBtn"><i class="fas fa-check-circle me-1"></i>Assign Paper</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="viewAssignedStudentsModal" tabindex="-1" aria-labelledby="viewAssignedStudentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewAssignedStudentsModalLabel">Students for Subject Paper</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="studentsListForPaperContainer">
                        <p class="text-center">Loading students...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const teacherIdPage = <?php echo $teacher_id_page; ?>;
        const ajaxUrlTd = '<?php echo $_SERVER["PHP_SELF"]; ?>'; 
        const currentAcademicYearIdTd = <?php echo $current_academic_year_id_td ? $current_academic_year_id_td : 'null'; ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('themePreferenceMACOM');
            if (savedTheme) document.documentElement.setAttribute('data-bs-theme', savedTheme);
            else document.documentElement.setAttribute('data-bs-theme', window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

            $('#assign_class_id').on('change', function() {
                const classId = $(this).val();
                const subjectSelect = $('#assign_subject_id');
                const paperSelect = $('#assign_subject_paper_id');
                
                subjectSelect.html('<option value="">Loading Subjects...</option>').prop('disabled', true);
                paperSelect.html('<option value="">Select Subject First...</option>').prop('disabled', true);

                if (classId) {
                    $.ajax({
                        url: ajaxUrlTd, type: 'GET', data: { action: 'get_subjects_for_class', class_id: classId }, dataType: 'json',
                        success: function(response) {
                            subjectSelect.html('<option value="">Select Subject...</option>');
                            if (response.success && response.subjects.length > 0) {
                                response.subjects.forEach(subject => { subjectSelect.append(new Option(subject.subject_name + (subject.subject_code ? ` (${subject.subject_code})` : ''), subject.id)); });
                                subjectSelect.prop('disabled', false);
                            } else { subjectSelect.html('<option value="">No subjects for this class</option>'); }
                        },
                        error: function() { subjectSelect.html('<option value="">Error loading subjects</option>'); }
                    });
                }
            });

            $('#assign_subject_id').on('change', function() {
                const subjectId = $(this).val();
                const paperSelect = $('#assign_subject_paper_id');
                paperSelect.html('<option value="">Loading Papers...</option>').prop('disabled', true);

                if (subjectId) {
                    $.ajax({
                        url: ajaxUrlTd, type: 'GET', data: { action: 'get_papers_for_subject', subject_id: subjectId }, dataType: 'json',
                        success: function(response) {
                            paperSelect.html('<option value="">Select Paper...</option>');
                            if (response.success && response.papers.length > 0) {
                                response.papers.forEach(paper => { paperSelect.append(new Option(paper.paper_name + (paper.paper_code ? ` (${paper.paper_code})` : ''), paper.id)); });
                                paperSelect.prop('disabled', false);
                            } else { paperSelect.html('<option value="">No papers for this subject</option>'); }
                        },
                        error: function() { paperSelect.html('<option value="">Error loading papers</option>'); }
                    });
                }
            });

            $('#submitAssignmentBtn').on('click', function() {
                const form = $('#assignPaperForm'); const btn = $(this);
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Assigning...');
                $.ajax({
                    url: ajaxUrlTd, type: 'POST', data: form.serialize() + '&action=assign_paper_to_teacher', dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.message); $('#assignPaperModal').modal('hide'); form[0].reset();
                            $('#assign_subject_id').html('<option value="">Select Class First...</option>').prop('disabled', true);
                            $('#assign_subject_paper_id').html('<option value="">Select Subject First...</option>').prop('disabled', true);
                            loadAssignedPapers($('#filterAcademicYearAssignments').val());
                        } else { alert('Error: ' + response.message); }
                    },
                    error: function() { alert('An AJAX error occurred.'); },
                    complete: function() { btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i>Assign Paper');}
                });
            });
            
            if(currentAcademicYearIdTd) { loadAssignedPapers(currentAcademicYearIdTd); }
            $('#filterAcademicYearAssignments').on('change', function(){ loadAssignedPapers($(this).val()); });
        });

        function loadAssignedPapers(academicYearId) {
            const container = $('#assignedPapersListContainer');
            container.html('<p class="text-center"><span class="spinner-border spinner-border-sm"></span> Loading assignments...</p>');
            if (!academicYearId) {
                container.html('<p class="text-muted">Please select an academic year to view assignments.</p>'); return;
            }

            $.ajax({
                url: ajaxUrlTd, type: 'GET', data: { action: 'get_assigned_papers', teacher_id: teacherIdPage, academic_year_id: academicYearId }, dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.assigned_papers.length > 0) {
                        const table = $('<table class="table table-sm table-hover table-striped"><thead><tr><th>Class</th><th>Subject</th><th>Paper</th><th>Code</th><th>Actions</th></tr></thead><tbody></tbody></table>');
                        response.assigned_papers.forEach(item => {
                            const row = $('<tr></tr>');
                            row.append($('<td></td>').text(item.class_name));
                            row.append($('<td></td>').text(item.subject_name));
                            row.append($('<td></td>').text(item.paper_name));
                            row.append($('<td></td>').text(item.paper_code || 'N/A'));
                            
                            const actions = $('<td></td>');
                            const viewStudentsBtn = $('<button class="btn btn-xs btn-outline-info me-1" title="View Students"><i class="fas fa-users"></i></button>');
                            viewStudentsBtn.on('click', function(){ 
                                openViewStudentsModal(item.class_id_for_students, item.subject_id_for_students, academicYearId, item.class_name, item.subject_name, item.paper_name);
                            });
                            actions.append(viewStudentsBtn);
                            
                            actions.append($('<button class="btn btn-xs btn-outline-danger" title="Remove Assignment"><i class="fas fa-trash-alt"></i></button>').on('click', function(){ removeAssignment(item.assignment_id); }));
                            row.append(actions);
                            table.find('tbody').append(row);
                        });
                        container.append(table);
                    } else if (response.success) {
                        container.html('<div class="alert alert-info">No subject papers assigned to this teacher for the selected academic year.</div>');
                    } else {
                        container.html('<div class="alert alert-danger">Error: ' + (response.message || 'Could not load assignments.') + '</div>');
                    }
                },
                error: function() { container.html('<div class="alert alert-danger">Failed to load assigned papers.</div>'); }
            });
        }
        
        function removeAssignment(assignmentId) {
            if (!confirm('Are you sure you want to remove this assignment?')) return;
            $.ajax({
                url: ajaxUrlTd, type: 'POST', data: { action: 'remove_assigned_paper', assignment_id: assignmentId }, dataType: 'json',
                success: function(response){
                    if(response.success){ alert(response.message); loadAssignedPapers($('#filterAcademicYearAssignments').val()); } 
                    else { alert('Error: ' + response.message); }
                },
                error: function(){ alert('AJAX error removing assignment.'); }
            });
        }

        function openViewStudentsModal(classId, subjectId, academicYearId, className, subjectName, paperName) {
            const modal = $('#viewAssignedStudentsModal'); const container = $('#studentsListForPaperContainer');
            const modalLabel = $('#viewAssignedStudentsModalLabel');
            modalLabel.text(`Students in ${className} taking ${subjectName} (Paper: ${paperName})`);
            container.html('<p class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Loading students...</p>');
            modal.modal('show');

            $.ajax({
                url: ajaxUrlTd, type: 'GET',
                data: { action: 'get_students_for_subject_in_class', class_id: classId, subject_id: subjectId, academic_year_id: academicYearId },
                dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.students && response.students.length > 0) {
                        const studentTable = $('<table class="table table-sm table-hover table-striped"><thead><tr><th>Photo</th><th>Adm No.</th><th>Full Name</th><th>Sex</th></tr></thead><tbody></tbody></table>');
                        response.students.forEach(student => {
                            let photoSrc = student.photo ? student.photo : `https://ui-avatars.com/api/?name=${encodeURIComponent(student.full_name)}&size=35&background=random&color=fff`;
                            const studentRow = `
                                <tr>
                                    <td><img src="${photoSrc}" class="student-photo-thumb-modal" alt="${student.full_name}"></td>
                                    <td>${student.admission_number || 'N/A'}</td>
                                    <td>${student.full_name}</td>
                                    <td>${student.sex || 'N/A'}</td>
                                </tr>`;
                            studentTable.find('tbody').append(studentRow);
                        });
                        container.append(studentTable);
                    } else if (response.success) {
                        container.html('<div class="alert alert-info">No students found in this class taking this subject for the selected academic year.</div>');
                    } else { container.html('<div class="alert alert-danger">Error: ' + (response.message || 'Could not load student list.') + '</div>'); }
                },
                error: function() { container.html('<div class="alert alert-danger">Failed to load student list due to an AJAX error.</div>');}
            });
        }

        function redirectToEditTeacher(teacherId) { window.location.href = `teachers_management.php?edit_attempt=${teacherId}`; }
        function openPasswordModalTeacherPage(teacherId, teacherName, teacherPhoto) {
            // This would typically redirect to teachers_management.php to open its password modal,
            // or you'd have a global password modal accessible from any page.
            // For simplicity, we'll just alert for now.
            // In a real app, you'd call the same function from teachers_management.php or have a shared modal system.
            alert(`Password change for ${teacherName} (ID: ${teacherId}) would be handled here or by redirecting to teachers_management.php to use its password modal.`);
            // Example if you had a global password modal function (you don't have one set up this way currently):
            // if (typeof window.parent.openGlobalPasswordModal === 'function') {
            //     window.parent.openGlobalPasswordModal(teacherId, teacherName, teacherPhoto);
            // }
        }
    </script>
</body>
</html>