<?php
// student_overview.php 
require_once '../db_config.php';
require_once '../auth.php';

if (!function_exists('get_session_value')) {
    function get_session_value($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
}

// --- Access Control (Example - Adjust roles as needed) ---
/*
$allowed_roles = ['head_teacher', 'admin', 'class_teacher', 'secretary'];
if (!in_array(get_session_value('role'), $allowed_roles)) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized action.']);
        exit;
    }
    $_SESSION['error_message'] = "You are not authorized to access the student management page.";
    header('Location: dashboard.php');
    exit;
}
*/

$action = $_REQUEST['action'] ?? null;

// File upload configurations
$upload_paths_student = [
    'photo' => 'uploads/students/photos/',
    'disability_medical_report_path' => 'uploads/students/medical_reports/'
];
$allowed_image_types_student = ['image/jpeg', 'image/png', 'image/gif'];
$allowed_document_types_student = ['application/pdf', 'image/jpeg', 'image/png']; // Medical report can be PDF or image

// --- START OF PHP PROCESSING LOGIC ---
// THIS MUST BE AT THE VERY TOP, BEFORE ANY HTML OUTPUT IF IT SENDS HEADERS OR JSON

// Simplified File Upload Handler (adapt or use one from your auth.php)
function handle_student_file_upload($file_input, $subdir, $allowed_types) {
    if (!isset($file_input) || $file_input['error'] !== UPLOAD_ERR_OK) {
        if ($file_input['error'] !== UPLOAD_ERR_NO_FILE) { // Ignore "no file" errors, means field was left empty
             $_SESSION['form_error'] = "File upload error: " . $file_input['error'];
        }
        return null;
    }

    $target_dir_base = rtrim($subdir, '/') . '/';
    if (!file_exists($target_dir_base)) {
        mkdir($target_dir_base, 0777, true);
    }

    $file_type = mime_content_type($file_input['tmp_name']);
    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['form_error'] = "Invalid file type: " . htmlspecialchars($file_input['name']) . ". Allowed: " . implode(', ', $allowed_types);
        return null;
    }

    $file_ext = pathinfo($file_input['name'], PATHINFO_EXTENSION);
    $filename = uniqid('student_', true) . '.' . strtolower($file_ext); // More unique
    $target_file = $target_dir_base . $filename;

    if (move_uploaded_file($file_input['tmp_name'], $target_file)) {
        return $target_file;
    } else {
        $_SESSION['form_error'] = "Failed to move uploaded file: " . htmlspecialchars($file_input['name']);
        return null;
    }
}


if ($action === 'get_student' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $student_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$student_id) {
        echo json_encode(['error' => 'Invalid Student ID provided.']);
        exit;
    }

    if (!$conn) {
        echo json_encode(['error' => 'Database connection failed.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON s.current_class_id = c.id WHERE s.id = ?");
    if (!$stmt) {
        error_log("Prepare failed for get_student: " . $conn->error);
        echo json_encode(['error' => 'Database query preparation failed.']);
        exit;
    }
    $stmt->bind_param("i", $student_id);
    if (!$stmt->execute()) {
        error_log("Execute failed for get_student: " . $stmt->error);
        echo json_encode(['error' => 'Database query execution failed.']);
        exit;
    }
    $result = $stmt->get_result();
    $student_data = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($student_data) {
        echo json_encode($student_data);
    } else {
        echo json_encode(['error' => 'Student not found for ID: ' . $student_id]);
    }
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        if (is_string($value)) $_POST[$key] = trim($value);
    }

    switch ($action) {
        case 'add_student':
        case 'edit_student':
            $is_edit = ($action === 'edit_student');
            $student_id = $is_edit ? filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT) : null;

            if ($is_edit && !$student_id) {
                $_SESSION['error_message'] = "Invalid student ID for edit.";
                header('Location: student_overview.php'); exit;
            }

            // Collect data from POST, aligning with DB schema
            $admission_number = $_POST['admission_number'] ?? null;
            $full_name = $_POST['full_name'];
            $sex = $_POST['sex'];
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            $current_class_id = !empty($_POST['current_class_id']) ? filter_var($_POST['current_class_id'], FILTER_VALIDATE_INT) : null;
            $address = $_POST['address'] ?? null;
            $father_name = $_POST['father_name'] ?? null;
            $father_contact = $_POST['father_contact'] ?? null;
            $mother_name = $_POST['mother_name'] ?? null;
            $mother_contact = $_POST['mother_contact'] ?? null;
            $guardian_name = $_POST['guardian_name'] ?? null;
            $guardian_contact = $_POST['guardian_contact'] ?? null;
            $student_contact = $_POST['student_contact'] ?? null;
            $parent_guardian_name = $_POST['parent_guardian_name'] ?? null; // Could be derived or separate
            $previous_school = $_POST['previous_school'] ?? null;
            $nin_number = $_POST['nin_number'] ?? null;
            $lin_number = $_POST['lin_number'] ?? null;
            $has_disability = $_POST['has_disability'] ?? 'No';
            $disability_type = ($has_disability === 'Yes' && !empty($_POST['disability_type'])) ? $_POST['disability_type'] : null;
            $student_comment = $_POST['student_comment'] ?? null;
            $enrollment_status = $_POST['enrollment_status'] ?? 'active';
            $admission_date = !empty($_POST['admission_date']) ? $_POST['admission_date'] : date('Y-m-d'); // Default to today if not set

            $errors = [];
            if (empty($full_name)) $errors[] = "Full Name is required.";
            if (empty($sex)) $errors[] = "Sex is required.";
            if (empty($current_class_id)) $errors[] = "Class is required.";
            if (empty($enrollment_status)) $errors[] = "Enrollment status is required.";
             if (empty($admission_date)) $errors[] = "Admission date is required.";

            // Optional: Validate admission number uniqueness
            if (!empty($admission_number)) {
                $adm_check_sql = $is_edit ? "SELECT id FROM students WHERE admission_number = ? AND id != ?" : "SELECT id FROM students WHERE admission_number = ?";
                $stmt_check_adm = $conn->prepare($adm_check_sql);
                if ($is_edit) $stmt_check_adm->bind_param("si", $admission_number, $student_id);
                else $stmt_check_adm->bind_param("s", $admission_number);
                $stmt_check_adm->execute();
                $stmt_check_adm->store_result();
                if ($stmt_check_adm->num_rows > 0) $errors[] = "This Admission Number is already in use.";
                $stmt_check_adm->close();
            }

            if (!empty($errors)) {
                $_SESSION['error_message'] = "Validation errors: <br>" . implode("<br>", $errors);
                $_SESSION['form_data'] = $_POST;
                header('Location: student_overview.php' . ($is_edit ? '?edit_attempt=' . $student_id : '?add_attempt=1')); exit;
            }

            $current_files = [];
            if ($is_edit) {
                $stmt_curr_files = $conn->prepare("SELECT photo, disability_medical_report_path FROM students WHERE id = ?");
                if ($stmt_curr_files) {
                    $stmt_curr_files->bind_param("i", $student_id);
                    $stmt_curr_files->execute();
                    $res_curr_files = $stmt_curr_files->get_result();
                    if ($res_curr_files) $current_files = $res_curr_files->fetch_assoc();
                    $stmt_curr_files->close();
                }
            }

            $db_file_paths = [];
            foreach ($upload_paths_student as $field_name => $subdir) {
                $db_file_paths[$field_name] = $current_files[$field_name] ?? null;
                if (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] == UPLOAD_ERR_OK) {
                    $allowed_types_for_field = ($field_name == 'photo') ? $allowed_image_types_student : $allowed_document_types_student;
                    $new_file_path = handle_student_file_upload($_FILES[$field_name], $subdir, $allowed_types_for_field);

                    if ($new_file_path) {
                        if ($is_edit && !empty($current_files[$field_name]) && file_exists($current_files[$field_name]) && $current_files[$field_name] !== $new_file_path) {
                            unlink($current_files[$field_name]);
                        }
                        $db_file_paths[$field_name] = $new_file_path;
                    } elseif (isset($_SESSION['form_error'])) {
                         $_SESSION['error_message'] = ($_SESSION['error_message'] ?? '') . "<br>File (" . htmlspecialchars($field_name) ."): " . $_SESSION['form_error'];
                         unset($_SESSION['form_error']);
                    }
                }
            }
             if (isset($_SESSION['error_message']) && strpos($_SESSION['error_message'], 'File') !== false && !empty($errors)) {
                // If there was a file error AND other validation errors, redirect now
                $_SESSION['form_data'] = $_POST;
                header('Location: student_overview.php' . ($is_edit ? '?edit_attempt=' . $student_id : '?add_attempt=1')); exit;
            }


            if ($is_edit) {
                $sql = "UPDATE students SET admission_number=?, full_name=?, sex=?, date_of_birth=?, current_class_id=?, photo=?, address=?, father_name=?, father_contact=?, mother_name=?, mother_contact=?, guardian_name=?, guardian_contact=?, student_contact=?, parent_guardian_name=?, previous_school=?, nin_number=?, lin_number=?, has_disability=?, disability_type=?, disability_medical_report_path=?, student_comment=?, enrollment_status=?, admission_date=?, updated_at=NOW() WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssissssssssssssssssssssi",
                    $admission_number, $full_name, $sex, $date_of_birth, $current_class_id, $db_file_paths['photo'], $address,
                    $father_name, $father_contact, $mother_name, $mother_contact, $guardian_name, $guardian_contact, $student_contact,
                    $parent_guardian_name, $previous_school, $nin_number, $lin_number, $has_disability, $disability_type,
                    $db_file_paths['disability_medical_report_path'], $student_comment, $enrollment_status, $admission_date, $student_id);
            } else {
                $sql = "INSERT INTO students (admission_number, full_name, sex, date_of_birth, current_class_id, photo, address, father_name, father_contact, mother_name, mother_contact, guardian_name, guardian_contact, student_contact, parent_guardian_name, previous_school, nin_number, lin_number, has_disability, disability_type, disability_medical_report_path, student_comment, enrollment_status, admission_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssissssssssssssssssssss",
                    $admission_number, $full_name, $sex, $date_of_birth, $current_class_id, $db_file_paths['photo'], $address,
                    $father_name, $father_contact, $mother_name, $mother_contact, $guardian_name, $guardian_contact, $student_contact,
                    $parent_guardian_name, $previous_school, $nin_number, $lin_number, $has_disability, $disability_type,
                    $db_file_paths['disability_medical_report_path'], $student_comment, $enrollment_status, $admission_date);
            }

            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Student '" . htmlspecialchars($full_name) . "' " . ($is_edit ? "updated" : "added") . " successfully!";
                unset($_SESSION['form_data']);
            } else {
                $_SESSION['error_message'] = "Error " . ($is_edit ? "updating" : "adding") . " student: " . $stmt->error;
                 if (!$is_edit) {
                    foreach ($db_file_paths as $path_val) { if ($path_val && file_exists($path_val)) unlink($path_val); }
                 }
                $_SESSION['form_data'] = $_POST; // Keep form data on error
                header('Location: student_overview.php' . ($is_edit ? '?edit_attempt=' . $student_id : '?add_attempt=1')); exit;
            }
            $stmt->close();
            header('Location: student_overview.php'); exit;

        case 'delete_student':
            $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            if (!$student_id) {
                $_SESSION['error_message'] = "Invalid student ID for deletion.";
                header('Location: student_overview.php'); exit;
            }

            // Fetch file paths and name before deleting
            $stmt_files = $conn->prepare("SELECT photo, disability_medical_report_path, full_name FROM students WHERE id = ?");
            $stmt_files->bind_param("i", $student_id);
            $stmt_files->execute();
            $student_data_del_res = $stmt_files->get_result();
            $student_data_del = $student_data_del_res ? $student_data_del_res->fetch_assoc() : null;
            $stmt_files->close();

            $stmt_del = $conn->prepare("DELETE FROM students WHERE id = ?");
            $stmt_del->bind_param("i", $student_id);
            if ($stmt_del->execute()) {
                if ($student_data_del) {
                    if (!empty($student_data_del['photo']) && file_exists($student_data_del['photo'])) {
                        unlink($student_data_del['photo']);
                    }
                    if (!empty($student_data_del['disability_medical_report_path']) && file_exists($student_data_del['disability_medical_report_path'])) {
                        unlink($student_data_del['disability_medical_report_path']);
                    }
                }
                $_SESSION['success_message'] = "Student '" . htmlspecialchars($student_data_del['full_name'] ?? "ID: $student_id") . "' deleted successfully.";
            } else {
                $_SESSION['error_message'] = "Error deleting student: " . $stmt_del->error;
            }
            $stmt_del->close();
            header('Location: student_overview.php'); exit;
        default:
             if (!empty($action)) $_SESSION['error_message'] = "Invalid POST action: " . htmlspecialchars($action);
             header('Location: student_overview.php'); exit;
    }
}
// --- END OF PHP PROCESSING LOGIC ---

// Fetch students for display
$students_display_list = [];
if ($conn) {
    // Join with classes table to get class_name
    $sql_students = "SELECT s.*, c.class_name 
                     FROM students s 
                     LEFT JOIN classes c ON s.current_class_id = c.id 
                     ORDER BY s.full_name ASC";
    $students_result_display = $conn->query($sql_students);
    if ($students_result_display && $students_result_display->num_rows > 0) {
        while ($row = $students_result_display->fetch_assoc()) {
            $students_display_list[] = $row;
        }
    }
} else {
    $_SESSION['error_message'] = ($_SESSION['error_message'] ?? '') . "<br>Database connection is not available to fetch students list.";
}

// Fetch classes for form dropdowns
$classes_list_for_form = [];
if ($conn) {
    $classes_result = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
    if ($classes_result && $classes_result->num_rows > 0) {
        while ($row = $classes_result->fetch_assoc()) {
            $classes_list_for_form[] = $row; // Contains arrays like ['id' => 1, 'class_name' => 'S1']
        }
    }
}


// Stats
$total_students = count($students_display_list);
$male_students = 0;
$female_students = 0;
$other_sex_students = 0;
$active_classes_array = [];

foreach ($students_display_list as $student_item) {
    if (strtolower($student_item['sex']) === 'male') $male_students++;
    elseif (strtolower($student_item['sex']) === 'female') $female_students++;
    else $other_sex_students++;

    if (!empty($student_item['class_name'])) {
        $active_classes_array[] = $student_item['class_name'];
    }
}
$distinct_classes_count = count(array_unique($active_classes_array));


$success_message = get_session_value('success_message');
$error_message = get_session_value('error_message');
$form_data_session = get_session_value('form_data', []);

if ($success_message) unset($_SESSION['success_message']);
if ($error_message) unset($_SESSION['error_message']);
if (!empty($form_data_session)) unset($_SESSION['form_data']); // Clear after use

$auto_open_add_modal = isset($_GET['add_attempt']);
$auto_open_edit_modal_id = isset($_GET['edit_attempt']) ? (int)$_GET['edit_attempt'] : null;

$enrollment_statuses = ['active', 'graduated', 'dropped_out', 'transferred_out', 'suspended']; // For dropdown
$sex_options = ['Male', 'Female', 'Other']; // For dropdown
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet">
    <style>
        /* Styles from teacher_management.php, adapted for students */
        .wrapper { display: flex; min-height: 100vh; }
        #content { flex-grow: 1; padding: 1rem; }
        .dashboard-content { padding: 1.5rem; background-color: #fff; border-radius: .5rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075); }
        
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .nav-tabs .nav-link { color: #495057; border-bottom-width: 2px;}
        .nav-tabs .nav-link.active { color: var(--bs-primary); border-color: var(--bs-primary) var(--bs-primary) #fff; font-weight: 500; }
        .tab-content { padding-top: 1rem; }
        .tab-content h5.text-muted { font-size: 0.95rem; font-weight: 500; border-bottom: 1px solid #eee; padding-bottom: 0.5rem; margin-top: 1rem; margin-bottom: 1rem;}
        .tab-content > .tab-pane > .row:first-child > h5.text-muted { margin-top: 0; }

        .current-file-link a { word-break: break-all; font-size: 0.85em; }
        .photo-preview-modal { max-width: 120px; max-height: 120px; object-fit: cover; border: 1px solid #ddd; margin-top: 10px; border-radius: 4px;}
        
        .student-card .card-header { background-color: #0d6efd; color: white; }
        .student-card .card-footer { background-color: #f8f9fa; border-top: 1px solid #e9ecef;}
        .student-card .student-info i { width: 20px; text-align: center; }
        
        .student-photo-thumb-table { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .view-toggle-btn.active { background-color: #0d6efd; color: white; }
        #tableView { display: none; /* Default to grid view, can be changed by JS */ }
        .horizontal-scroll-wrapper { display: flex; flex-wrap: nowrap; overflow-x: auto; padding-bottom:1rem;}
        .student-card-item { flex: 0 0 auto; width: 280px; margin-right: 15px; } /* For horizontal scroll */

        .stats-card { display: flex; align-items: center; padding: 15px; border-radius: 10px; background-color: white; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom:1rem;}
        .stats-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px; color: white; font-size: 1.5rem; }
        .stats-info h5 { margin-bottom: 5px; font-size: 0.9rem; color: #6c757d;}
        .stats-info h3 { margin-bottom: 0; font-weight: 600; }
    </style>
</head>
<body>

    <div class="wrapper">
        
    <?php include 'nav&sidebar_dos.php'; ?>
        <div class="container-fluid dashboard-content">
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo nl2br(htmlspecialchars($error_message)); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Student Management</h2>
                <div>
                    <div class="btn-group me-2" role="group">
                        <button type="button" class="btn btn-outline-secondary view-toggle-btn active" id="gridViewBtn"><i class="fas fa-th-large"></i> Grid</button>
                        <button type="button" class="btn btn-outline-secondary view-toggle-btn" id="tableViewBtn"><i class="fas fa-table"></i> Table</button>
                    </div>
                  <!--  <button class="btn btn-primary" onclick="prepareAddModal()">
                        <i class="fas fa-user-plus me-2"></i>Add Student
                    </button> -->
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon bg-primary"><i class="fas fa-users"></i></div>
                        <div class="stats-info"><h5>Total Students</h5><h3><?php echo $total_students; ?></h3></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon bg-info"><i class="fas fa-male"></i></div>
                        <div class="stats-info"><h5>Male</h5><h3 id="maleStudentsStat"><?php echo $male_students; ?></h3></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon bg-danger"><i class="fas fa-female"></i></div>
                        <div class="stats-info"><h5>Female</h5><h3 id="femaleStudentsStat"><?php echo $female_students; ?></h3></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon bg-warning"><i class="fas fa-school"></i></div>
                        <div class="stats-info"><h5>Classes</h5><h3 id="activeClassesStat"><?php echo $distinct_classes_count; ?></h3></div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" id="searchBarStudent" class="form-control global-search" placeholder="Search students by name, admission no, class...">
                            </div>
                        </div>
                        <div class="col-md-6 d-flex justify-content-end">
                            <div class="btn-group" role="group">
                                 <button type="button" class="btn btn-outline-secondary"><i class="fas fa-filter"></i> Filter</button> 
                                 <button type="button" class="btn btn-outline-secondary"><i class="fas fa-sort"></i> Sort</button> 
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grid View -->
            <div id="gridViewContainer">
                <div class="horizontal-scroll-wrapper" id="studentsDisplayGrid">
                    <?php if (!empty($students_display_list)): ?>
                        <?php foreach ($students_display_list as $student): ?>
                        <div class="student-card-item" data-search-payload="<?php echo strtolower(htmlspecialchars($student['full_name'] . ' ' . $student['admission_number'] . ' ' . $student['class_name'] . ' ' . $student['nin_number'] . ' ' . $student['lin_number'])); ?>">
                            <div class="card h-100 student-card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 fs-6 text-truncate" title="<?php echo htmlspecialchars($student['full_name']); ?>"><?php echo htmlspecialchars($student['full_name']); ?></h5>
                                    <span class="badge bg-light text-dark small"><?php echo htmlspecialchars($student['class_name'] ?: 'N/A'); ?></span>
                                </div>
                                <div class="card-body text-center">
                                    <img src="<?php echo htmlspecialchars(!empty($student['photo']) && file_exists($student['photo']) ? $student['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($student['full_name']).'&background=random&color=fff&size=100'); ?>"
                                         class="rounded-circle border mb-2" style="width: 100px; height: 100px; object-fit: cover;"
                                         alt="<?php echo htmlspecialchars($student['full_name']); ?>">
                                    <div class="student-info text-start small">
                                        <p class="mb-1"><i class="fas fa-id-card fa-fw me-2 text-muted"></i>Adm No: <?php echo htmlspecialchars($student['admission_number'] ?: 'N/A'); ?></p>
                                        <p class="mb-1"><i class="fas fa-venus-mars fa-fw me-2 text-muted"></i>Sex: <?php echo htmlspecialchars($student['sex']); ?></p>
                                        <p class="mb-1"><i class="fas fa-phone fa-fw me-2 text-muted"></i>Guardian: <?php echo htmlspecialchars($student['guardian_contact'] ?: ($student['father_contact'] ?: ($student['mother_contact'] ?: 'N/A'))); ?></p>
                                        <p class="mb-1"><i class="fas fa-info-circle fa-fw me-2 text-muted"></i>Status: <span class="badge bg-<?php echo $student['enrollment_status'] == 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($student['enrollment_status'])); ?></span></p>
                                    </div>
                                </div>
                                <div class="card-footer d-flex justify-content-around align-items-center py-2">
                                    
                                    <a href="student_profile.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-info flex-fill mx-1">
                                        <i class="fas fa-eye"></i> <span class="d-none d-md-inline">View</span>
                                    </a>
                                    <button class="btn btn-sm btn-outline-warning flex-fill ms-1" onclick="requestStudentAction('edit', <?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')">
                                        <i class="fas fa-flag"></i> <span class="d-none d-md-inline">Req. Edit</span>
                                    </button>
                                    
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                         <div class="col-12"><div class="alert alert-info text-center">No students found. Please add one.</div></div>
                    <?php endif; ?>
                </div>
                 <div class="col-12 mt-3" id="noStudentsFoundMessageGrid" style="display: none;">
                    <div class="alert alert-warning text-center">No students match your search criteria.</div>
                </div>
            </div>

            <!-- Table View -->
            <div id="tableViewContainer" style="display:none;">
                <div class="table-responsive">
                    <table class="table table-hover table-striped">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Full Name</th>
                                <th>Adm No.</th>
                                <th>Class</th>
                                <th>Sex</th>
                                <th>Guardian Contact</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="studentsDisplayTableBody">
                            <?php if (!empty($students_display_list)): ?>
                                <?php foreach ($students_display_list as $student): ?>
                                <tr data-search-payload-table="<?php echo strtolower(htmlspecialchars($student['full_name'] . ' ' . $student['admission_number'] . ' ' . $student['class_name'] . ' ' . $student['nin_number'] . ' ' . $student['lin_number'])); ?>">
                                    <td>
                                        <img src="<?php echo htmlspecialchars(!empty($student['photo']) && file_exists($student['photo']) ? $student['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($student['full_name']).'&background=random&color=fff&size=40'); ?>"
                                             class="student-photo-thumb-table" alt="<?php echo htmlspecialchars($student['full_name']); ?>">
                                    </td>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['admission_number'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['class_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['sex']); ?></td>
                                    <td><?php echo htmlspecialchars($student['guardian_contact'] ?: ($student['father_contact'] ?: ($student['mother_contact'] ?: 'N/A'))); ?></td>
                                    <td><span class="badge bg-<?php echo $student['enrollment_status'] == 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($student['enrollment_status'])); ?></span></td>
                                    <td>
                                        
                                        
                                        <a href="dos_student_details.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
                                        <button class="btn btn-sm btn-outline-warning" onclick="requestStudentAction('edit', <?php echo $student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['full_name'])); ?>')"><i class="fas fa-flag"></i></button>
                                        
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center">No students found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-12 mt-3" id="noStudentsFoundMessageTable" style="display: none;">
                    <div class="alert alert-warning text-center">No students match your search criteria.</div>
                </div>
            </div>
        </div> <!-- End dashboard-content -->
    </div> <!-- End wrapper -->

    <!-- Universal Student Add/Edit Modal -->
    <div class="modal fade" id="studentFormModal" tabindex="-1" aria-labelledby="studentFormModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"> <!-- modal-xl for more space -->
            <div class="modal-content">
                <form id="studentForm" action="student_overview.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="form_action_student">
                    <input type="hidden" name="student_id" id="form_student_id">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="studentFormModalLabel">Student Information</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="studentFormTabs" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" id="s-personal-tab" data-bs-toggle="tab" data-bs-target="#s-personal-pane" type="button"><i class="fas fa-user-circle me-1"></i>Personal</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="s-academic-tab" data-bs-toggle="tab" data-bs-target="#s-academic-pane" type="button"><i class="fas fa-graduation-cap me-1"></i>Academic</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="s-guardian-tab" data-bs-toggle="tab" data-bs-target="#s-guardian-pane" type="button"><i class="fas fa-users me-1"></i>Guardian & Contact</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="s-health-tab" data-bs-toggle="tab" data-bs-target="#s-health-pane" type="button"><i class="fas fa-heartbeat me-1"></i>Health & Docs</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="s-other-tab" data-bs-toggle="tab" data-bs-target="#s-other-pane" type="button"><i class="fas fa-info-circle me-1"></i>Other Info</button></li>
                        </ul>
                        <div class="tab-content" id="studentFormTabsContent">
                            <!-- Personal Details Tab -->
                            <div class="tab-pane fade show active" id="s-personal-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Basic Information</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-8"><label for="s_full_name" class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="s_full_name" name="full_name" value="<?php echo htmlspecialchars($form_data_session['full_name'] ?? ''); ?>" required></div>
                                    <div class="col-md-4"><label for="s_sex" class="form-label">Sex <span class="text-danger">*</span></label>
                                        <select class="form-select" id="s_sex" name="sex" required>
                                            <option value="">Select Sex...</option>
                                            <?php foreach ($sex_options as $option): ?>
                                            <option value="<?php echo $option; ?>" <?php echo (isset($form_data_session['role']) && $form_data_session['role'] == $option) ? 'selected' : ''; ?>><?php echo $option; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4"><label for="s_date_of_birth" class="form-label">Date of Birth</label><input type="date" class="form-control" id="s_date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($form_data_session['date_of_birth'] ?? ''); ?>"></div>
                                    <div class="col-md-4"><label for="s_nin_number" class="form-label">NIN Number</label><input type="text" class="form-control" id="s_nin_number" name="nin_number" value="<?php echo htmlspecialchars($form_data_session['nin_number'] ?? ''); ?>"></div>
                                    <div class="col-md-4"><label for="s_lin_number" class="form-label">LIN Number</label><input type="text" class="form-control" id="s_lin_number" name="lin_number" value="<?php echo htmlspecialchars($form_data_session['lin_number'] ?? ''); ?>"></div>
                                </div>
                                <h5 class="text-muted">Location</h5>
                                <div class="row g-3">
                                    <div class="col-md-12"><label for="s_address" class="form-label">Residential Address</label><textarea class="form-control" id="s_address" name="address" rows="2"><?php echo htmlspecialchars($form_data_session['address'] ?? ''); ?></textarea></div>
                                </div>
                            </div>
                            <!-- Academic Info Tab -->
                            <div class="tab-pane fade" id="s-academic-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Enrollment Details</h5>
                                <div class="row g-3">
                                    <div class="col-md-4"><label for="s_admission_number" class="form-label">Admission Number</label><input type="text" class="form-control" id="s_admission_number" name="admission_number" value="<?php echo htmlspecialchars($form_data_session['admission_number'] ?? ''); ?>"></div>
                                    <div class="col-md-4"><label for="s_current_class_id" class="form-label">Current Class <span class="text-danger">*</span></label>
                                        <select class="form-select" id="s_current_class_id" name="current_class_id" required>
                                            <option value="">Select Class...</option>
                                            <?php foreach ($classes_list_for_form as $class_item): ?>
                                            <option value="<?php echo $class_item['id']; ?>" <?php echo (isset($form_data_session['current_class_id']) && $form_data_session['current_class_id'] == $class_item['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($class_item['class_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4"><label for="s_admission_date" class="form-label">Admission Date <span class="text-danger">*</span></label><input type="date" class="form-control" id="s_admission_date" name="admission_date" value="<?php echo htmlspecialchars($form_data_session['admission_date'] ?? date('Y-m-d')); ?>" required></div>
                                    <div class="col-md-8"><label for="s_previous_school" class="form-label">Previous School</label><input type="text" class="form-control" id="s_previous_school" name="previous_school" value="<?php echo htmlspecialchars($form_data_session['previous_school'] ?? ''); ?>"></div>
                                    <div class="col-md-4"><label for="s_enrollment_status" class="form-label">Enrollment Status <span class="text-danger">*</span></label>
                                        <select class="form-select" id="s_enrollment_status" name="enrollment_status" required>
                                            <?php foreach ($enrollment_statuses as $status): ?>
                                            <option value="<?php echo $status; ?>" <?php echo ((isset($form_data_session['enrollment_status']) && $form_data_session['enrollment_status'] == $status) || (!isset($form_data_session['enrollment_status']) && $status == 'active')) ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <!-- Guardian & Contact Tab -->
                            <div class="tab-pane fade" id="s-guardian-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Father's Details</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6"><label for="s_father_name" class="form-label">Father's Name</label><input type="text" class="form-control" id="s_father_name" name="father_name" value="<?php echo htmlspecialchars($form_data_session['father_name'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="s_father_contact" class="form-label">Father's Contact</label><input type="tel" class="form-control" id="s_father_contact" name="father_contact" value="<?php echo htmlspecialchars($form_data_session['father_contact'] ?? ''); ?>"></div>
                                </div>
                                <h5 class="text-muted">Mother's Details</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6"><label for="s_mother_name" class="form-label">Mother's Name</label><input type="text" class="form-control" id="s_mother_name" name="mother_name" value="<?php echo htmlspecialchars($form_data_session['mother_name'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="s_mother_contact" class="form-label">Mother's Contact</label><input type="tel" class="form-control" id="s_mother_contact" name="mother_contact" value="<?php echo htmlspecialchars($form_data_session['mother_contact'] ?? ''); ?>"></div>
                                </div>
                                <h5 class="text-muted">Guardian's Details (if different)</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6"><label for="s_guardian_name" class="form-label">Guardian's Name</label><input type="text" class="form-control" id="s_guardian_name" name="guardian_name" value="<?php echo htmlspecialchars($form_data_session['guardian_name'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="s_guardian_contact" class="form-label">Guardian's Contact</label><input type="tel" class="form-control" id="s_guardian_contact" name="guardian_contact" value="<?php echo htmlspecialchars($form_data_session['guardian_contact'] ?? ''); ?>"></div>
                                </div>
                                <h5 class="text-muted">Other Contacts</h5>
                                <div class="row g-3">
                                     <div class="col-md-6"><label for="s_student_contact" class="form-label">Student's Contact (if any)</label><input type="tel" class="form-control" id="s_student_contact" name="student_contact" value="<?php echo htmlspecialchars($form_data_session['student_contact'] ?? ''); ?>"></div>
                                     <div class="col-md-6"><label for="s_parent_guardian_name" class="form-label">Primary Parent/Guardian Name <small>(for records)</small></label><input type="text" class="form-control" id="s_parent_guardian_name" name="parent_guardian_name" value="<?php echo htmlspecialchars($form_data_session['parent_guardian_name'] ?? ''); ?>"></div>
                                </div>
                            </div>
                            <!-- Health & Documents Tab -->
                            <div class="tab-pane fade" id="s-health-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Health Information</h5>
                                 <div class="row g-3 mb-3">
                                    <div class="col-md-4"><label for="s_has_disability" class="form-label">Has Disability?</label>
                                        <select class="form-select" id="s_has_disability" name="has_disability" onchange="toggleStudentDisabilityFields(this.value)">
                                            <option value="No" <?php echo ((isset($form_data_session['has_disability']) && $form_data_session['has_disability'] == 'No') || !isset($form_data_session['has_disability'])) ? 'selected' : ''; ?>>No</option>
                                            <option value="Yes" <?php echo (isset($form_data_session['has_disability']) && $form_data_session['has_disability'] == 'Yes') ? 'selected' : ''; ?>>Yes</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8 form_student_disability_type_field" style="display:none;"><label for="s_disability_type" class="form-label">Type of Disability</label><input type="text" class="form-control" id="s_disability_type" name="disability_type" value="<?php echo htmlspecialchars($form_data_session['disability_type'] ?? ''); ?>"></div>
                                 </div>
                                 <h5 class="text-muted">Document Uploads</h5>
                                 <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="s_photo" class="form-label">Student Photo</label>
                                        <input type="file" class="form-control" id="s_photo" name="photo" accept="image/*" onchange="previewModalImageStudent(this, 's_photo_preview_modal')">
                                        <img id="s_photo_preview_modal" src="#" alt="Photo Preview" class="photo-preview-modal mt-2" style="display:none;"/>
                                        <small id="current_s_photo" class="form-text text-muted current-file-link"></small>
                                    </div>
                                    <div class="col-md-6 form_student_disability_report_field" style="display:none;">
                                        <label for="s_disability_medical_report_path" class="form-label">Medical Report (if any)</label>
                                        <input type="file" class="form-control" id="s_disability_medical_report_path" name="disability_medical_report_path" accept=".pdf,.jpg,.png,.jpeg">
                                        <small id="current_s_disability_medical_report_path" class="form-text text-muted current-file-link"></small>
                                    </div>
                                 </div>
                            </div>
                             <!-- Other Info Tab -->
                            <div class="tab-pane fade" id="s-other-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Student Comments</h5>
                                <div class="row g-3">
                                     <div class="col-md-12"><label for="s_student_comment" class="form-label">Additional Comments/Notes</label><textarea class="form-control" id="s_student_comment" name="student_comment" rows="4"><?php echo htmlspecialchars($form_data_session['student_comment'] ?? ''); ?></textarea></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveStudentBtn"><i class="fas fa-save me-2"></i>Save Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const studentFormModalEl = document.getElementById('studentFormModal');
        const studentFormModalInstance = new bootstrap.Modal(studentFormModalEl);
        const studentForm = document.getElementById('studentForm');
        const formActionStudentInput = document.getElementById('form_action_student');
        const formStudentIdInput = document.getElementById('form_student_id');
        const studentFormModalLabel = document.getElementById('studentFormModalLabel');
        const saveStudentBtn = document.getElementById('saveStudentBtn');

        function resetStudentForm() {
            studentForm.reset();
            document.querySelectorAll('.current-file-link').forEach(el => el.innerHTML = '');
            const photoPreview = document.getElementById('s_photo_preview_modal');
            if(photoPreview) { photoPreview.style.display = 'none'; photoPreview.src = '#'; }
            toggleStudentDisabilityFields('No'); // Default to No
            // Ensure first tab is active
            const firstTabEl = document.getElementById('s-personal-tab');
            if(firstTabEl) new bootstrap.Tab(firstTabEl).show();
        }

        function prepareAddModal() {
            resetStudentForm();
            formActionStudentInput.value = 'add_student';
            formStudentIdInput.value = '';
            studentFormModalLabel.textContent = 'Add New Student';
            saveStudentBtn.innerHTML = '<i class="fas fa-user-plus me-2"></i>Add Student';
            saveStudentBtn.className = 'btn btn-primary';
            // Pre-fill admission date for new student
            const admissionDateEl = document.getElementById('s_admission_date');
            if(admissionDateEl && !admissionDateEl.value) {
                admissionDateEl.valueAsDate = new Date();
            }
            studentFormModalInstance.show();
        }

        async function prepareEditModal(studentId) {
            resetStudentForm();
            try {
                const response = await fetch(`student_overview.php?action=get_student&id=${studentId}`);
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const student = await response.json();

                if (student && !student.error) {
                    formActionStudentInput.value = 'edit_student';
                    formStudentIdInput.value = student.id;
                    studentFormModalLabel.textContent = 'Edit Student: ' + (student.full_name || 'N/A');
                    saveStudentBtn.innerHTML = '<i class="fas fa-save me-2"></i>Update Student';
                    saveStudentBtn.className = 'btn btn-success';

                    // Populate form fields (using 's_' prefix for IDs)
                    document.getElementById('s_admission_number').value = student.admission_number || '';
                    document.getElementById('s_full_name').value = student.full_name || '';
                    document.getElementById('s_sex').value = student.sex || '';
                    document.getElementById('s_date_of_birth').value = student.date_of_birth || '';
                    document.getElementById('s_current_class_id').value = student.current_class_id || '';
                    document.getElementById('s_address').value = student.address || '';
                    document.getElementById('s_father_name').value = student.father_name || '';
                    document.getElementById('s_father_contact').value = student.father_contact || '';
                    document.getElementById('s_mother_name').value = student.mother_name || '';
                    document.getElementById('s_mother_contact').value = student.mother_contact || '';
                    document.getElementById('s_guardian_name').value = student.guardian_name || '';
                    document.getElementById('s_guardian_contact').value = student.guardian_contact || '';
                    document.getElementById('s_student_contact').value = student.student_contact || '';
                    document.getElementById('s_parent_guardian_name').value = student.parent_guardian_name || '';
                    document.getElementById('s_previous_school').value = student.previous_school || '';
                    document.getElementById('s_nin_number').value = student.nin_number || '';
                    document.getElementById('s_lin_number').value = student.lin_number || '';
                    
                    const hasDisabilityVal = student.has_disability || 'No';
                    document.getElementById('s_has_disability').value = hasDisabilityVal;
                    toggleStudentDisabilityFields(hasDisabilityVal);
                    document.getElementById('s_disability_type').value = student.disability_type || '';
                    
                    document.getElementById('s_student_comment').value = student.student_comment || '';
                    document.getElementById('s_enrollment_status').value = student.enrollment_status || 'active';
                    document.getElementById('s_admission_date').value = student.admission_date || '';

                    // File fields
                    const photoPreviewEl = document.getElementById('s_photo_preview_modal');
                    const currentPhotoLink = document.getElementById('current_s_photo');
                    if (student.photo && student.photo.length > 0) {
                        photoPreviewEl.src = student.photo; photoPreviewEl.style.display = 'block';
                        currentPhotoLink.innerHTML = `Current: <a href="${student.photo}" target="_blank">${student.photo.split('/').pop()}</a>`;
                    } else {
                        photoPreviewEl.style.display = 'none'; photoPreviewEl.src = '#';
                        currentPhotoLink.innerHTML = '';
                    }

                    const currentDisabilityReportLink = document.getElementById('current_s_disability_medical_report_path');
                    if (student.disability_medical_report_path && student.disability_medical_report_path.length > 0) {
                        currentDisabilityReportLink.innerHTML = `Current: <a href="${student.disability_medical_report_path}" target="_blank">${student.disability_medical_report_path.split('/').pop()}</a>`;
                    } else {
                        currentDisabilityReportLink.innerHTML = '';
                    }
                    
                    studentFormModalInstance.show();
                } else { 
                    alert('Error: ' + (student.error || 'Could not load student data.')); 
                }
            } catch (error) { 
                console.error('JS error in prepareEditModal:', error); 
                alert('JavaScript error preparing edit form. Check console.'); 
            }
        }
        
        function toggleStudentDisabilityFields(value) {
            const typeFieldDiv = document.querySelector('.form_student_disability_type_field');
            const reportFieldDiv = document.querySelector('.form_student_disability_report_field'); // Ensure this class is on the div
            const typeInput = document.getElementById('s_disability_type');
            const displayStyle = (value === 'Yes') ? 'block' : 'none';
            
            if(typeFieldDiv) typeFieldDiv.style.display = displayStyle;
            if(reportFieldDiv) reportFieldDiv.style.display = displayStyle; // Also toggle report field based on Yes/No
            
            if(typeInput) typeInput.required = (value === 'Yes');
            if(value !== 'Yes' && typeInput) typeInput.value = '';
        }

        <?php // Initialize disability fields based on session data if available, or default
        if (!empty($form_data_session['has_disability'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            toggleStudentDisabilityFields('<?php echo htmlspecialchars($form_data_session['has_disability']); ?>');
        });
        <?php else: ?>
        document.addEventListener('DOMContentLoaded', function() {
             // If not editing, it defaults to 'No' in prepareAddModal
             // If editing, it's handled by prepareEditModal
            const initialHasDisabilityValue = document.getElementById('s_has_disability')?.value || 'No';
            toggleStudentDisabilityFields(initialHasDisabilityValue);
        });
        <?php endif; ?>


        function previewModalImageStudent(input, previewElementId) {
            const preview = document.getElementById(previewElementId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            } else { preview.src = '#'; preview.style.display = 'none'; }
        }

        function confirmDeleteStudentDialog(studentId, studentName) {
            if (confirm(`Are you sure you want to delete student: ${studentName} (ID: ${studentId})? This action is irreversible.`)) {
                const form = document.createElement('form');
                form.method = 'POST'; 
                form.action = 'student_overview.php';
                form.innerHTML = `<input type="hidden" name="action" value="delete_student"><input type="hidden" name="student_id" value="${studentId}">`;
                document.body.appendChild(form); 
                form.submit();
            }
        }

        <?php if ($auto_open_add_modal && !empty($form_data_session)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            prepareAddModal(); // This will call reset, then studentForm.reset() might be redundant
            // Repopulate form with $form_data_session (PHP part already does this with value attributes)
            // For select, file, etc., additional JS might be needed if PHP value attributes aren't enough
             const hasDisabilityValRepop = '<?php echo htmlspecialchars($form_data_session['has_disability'] ?? 'No'); ?>';
             document.getElementById('s_has_disability').value = hasDisabilityValRepop;
             toggleStudentDisabilityFields(hasDisabilityValRepop);
             document.getElementById('s_disability_type').value = '<?php echo htmlspecialchars($form_data_session['disability_type'] ?? ''); ?>';
        });
        <?php elseif ($auto_open_edit_modal_id && !empty($form_data_session)): // Edit attempt failed, repopulate
       // document.addEventListener('DOMContentLoaded', function() {
          //   prepareEditModal(<?php echo json_encode($auto_open_edit_modal_id); ?>).then(() => {
                // After AJAX data is loaded, override with form_data_session if it exists
                // This is tricky because prepareEditModal is async. For simplicity, PHP values are used.
                // If a more complex repopulation for failed edit is needed, it'd be more involved here.
                // The current PHP will set values from $form_data_session in the HTML directly.
                // The current_file_links will be handled by prepareEditModal.
                 const hasDisabilityValRepop = '<?php echo htmlspecialchars($form_data_session['has_disability'] ?? 'No'); ?>';
                 document.getElementById('s_has_disability').value = hasDisabilityValRepop;
                 toggleStudentDisabilityFields(hasDisabilityValRepop);
                 document.getElementById('s_disability_type').value = '<?php echo htmlspecialchars($form_data_session['disability_type'] ?? ''); ?>';
            });
        //});
        <?php elseif ($auto_open_edit_modal_id): // Fresh edit attempt, no session data needed here ?>
        document.addEventListener('DOMContentLoaded', () => prepareEditModal(<?php echo $auto_open_edit_modal_id; ?>));
        <?php endif; ?>

        studentFormModalEl.addEventListener('hidden.bs.modal', function () {
            resetStudentForm(); // Reset form when modal is closed
        });

        // View Toggle Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const gridViewBtn = document.getElementById('gridViewBtn');
            const tableViewBtn = document.getElementById('tableViewBtn');
            const gridViewContainer = document.getElementById('gridViewContainer');
            const tableViewContainer = document.getElementById('tableViewContainer');
            
            function setActiveView(view) {
                if (view === 'grid') {
                    gridViewBtn.classList.add('active');
                    tableViewBtn.classList.remove('active');
                    gridViewContainer.style.display = 'block';
                    tableViewContainer.style.display = 'none';
                    localStorage.setItem('studentViewPreference', 'grid');
                } else {
                    tableViewBtn.classList.add('active');
                    gridViewBtn.classList.remove('active');
                    gridViewContainer.style.display = 'none';
                    tableViewContainer.style.display = 'block';
                    localStorage.setItem('studentViewPreference', 'table');
                }
            }

            gridViewBtn.addEventListener('click', () => setActiveView('grid'));
            tableViewBtn.addEventListener('click', () => setActiveView('table'));
            
            const savedView = localStorage.getItem('studentViewPreference') || 'grid'; // Default to grid
            setActiveView(savedView);
        });

        // Search functionality
        document.getElementById('searchBarStudent')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            let gridVisibleCount = 0;
            let tableVisibleCount = 0;

            // Search Grid View
            document.querySelectorAll('#studentsDisplayGrid .student-card-item').forEach(card => {
                const searchPayload = card.dataset.searchPayload || "";
                const isVisible = searchPayload.includes(searchTerm);
                card.style.display = isVisible ? '' : 'none';
                if (isVisible) gridVisibleCount++;
            });
             document.getElementById('noStudentsFoundMessageGrid').style.display = gridVisibleCount === 0 && searchTerm !== '' ? 'block' : 'none';


            // Search Table View
            document.querySelectorAll('#studentsDisplayTableBody tr').forEach(row => {
                // Check if it's a data row (not a "no students" message row)
                if (row.hasAttribute('data-search-payload-table')) {
                    const searchPayloadTable = row.dataset.searchPayloadTable || "";
                    const isVisible = searchPayloadTable.includes(searchTerm);
                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) tableVisibleCount++;
                }
            });
            document.getElementById('noStudentsFoundMessageTable').style.display = tableVisibleCount === 0 && searchTerm !== '' && document.querySelectorAll('#studentsDisplayTableBody tr[data-search-payload-table]').length > 0 ? 'block' : 'none';
        });
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
            
        }

    </script>
</body>
</html>