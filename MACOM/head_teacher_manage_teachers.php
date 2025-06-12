<?php
// head_teacher_manage_teachers.php
require_once 'db_config.php';
require_once 'auth.php';

// --- Access Control: Head Teacher Only ---
check_login('head_teacher'); // Specific role check
$loggedInUserId = $_SESSION['user_id']; // For audit trail

$action = $_REQUEST['action'] ?? null;

// File upload configurations (same as before)
$upload_paths = [
    'photo' => 'uploads/teachers/photos/',
    'disability_medical_report_path' => 'uploads/teachers/medical_reports/',
    'application_form_path' => 'uploads/teachers/application_forms/',
    'national_id_path' => 'uploads/teachers/national_ids/',
    'academic_transcripts_path' => 'uploads/teachers/transcripts/',
    'other_documents_path' => 'uploads/teachers/other_documents/'
];
$allowed_image_types = ['image/jpeg', 'image/png', 'image/gif'];
$allowed_document_types = ['application/pdf'];
$allowed_archive_types = ['application/zip']; // Keep allowed types flexible

// --- AJAX: Get Teacher Details (No change needed here) ---
if ($action === 'get_teacher' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $teacher_id_ajax = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    // ... (rest of get_teacher AJAX logic remains the same as in your original file) ...
    // It's a read operation, so both HT and DoS can use a similar AJAX endpoint if needed.
    // For modularity, this AJAX could be moved to a general 'ajax_handler.php' later.
     if (!$teacher_id_ajax) { echo json_encode(['error' => 'Invalid Teacher ID.']); exit; }
    if (!$conn) { echo json_encode(['error' => 'DB connection error.']); exit; }
    $stmt_ajax = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
    if (!$stmt_ajax) { error_log("AJAX get_teacher prepare failed: ".$conn->error); echo json_encode(['error' => 'Query prep failed.']); exit; }
    $stmt_ajax->bind_param("i", $teacher_id_ajax);
    if (!$stmt_ajax->execute()) { error_log("AJAX get_teacher exec failed: ".$stmt_ajax->error); echo json_encode(['error' => 'Query exec failed.']); exit; }
    $result_ajax = $stmt_ajax->get_result();
    $teacher_ajax = $result_ajax ? $result_ajax->fetch_assoc() : null;
    $stmt_ajax->close();
    if ($teacher_ajax) { echo json_encode($teacher_ajax); }
    else { echo json_encode(['error' => 'Teacher not found.']); }
    exit;
}

// --- POST Request Handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$conn) {
        $_SESSION['error_message_ht_mt'] = "Database connection failed.";
        header('Location: head_teacher_manage_teachers.php'); exit;
    }
    // Basic trim for all POST data
    foreach ($_POST as $key => $value) { if (is_string($value)) $_POST[$key] = trim($value); }

    switch ($action) {
        case 'add_teacher':
        case 'edit_teacher':
            $is_edit = ($action === 'edit_teacher');
            $teacher_id = $is_edit ? filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT) : null;

            if ($is_edit && !$teacher_id) {
                $_SESSION['error_message_ht_mt'] = "Invalid teacher ID for edit."; // Use role-specific session keys
                header('Location: head_teacher_manage_teachers.php'); exit;
            }

            // --- Collect and Validate Data (Same as original, HT has full edit rights on these fields) ---
            $full_name = $_POST['full_name'];
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
            $phone_number = $_POST['phone_number'];
            $nin_number = !empty($_POST['nin_number']) ? $_POST['nin_number'] : null;
            $subject_specialization = !empty($_POST['subject_specialization']) ? $_POST['subject_specialization'] : null; // HT can set initial specialization
            $join_date = !empty($_POST['join_date']) ? $_POST['join_date'] : null;
            $address = !empty($_POST['address']) ? $_POST['address'] : null;
            $has_disability = $_POST['has_disability'] ?? 'No';
            $disability_type = ($has_disability === 'Yes' && !empty($_POST['disability_type'])) ? $_POST['disability_type'] : null;
            $salary = !empty($_POST['salary']) ? filter_var($_POST['salary'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;
            
            // HT sets the initial role. Could be 'teacher' or specialized like 'head_of_department' if your system supports it.
            // For simplicity, assuming HT usually adds 'teacher'. If other roles are added via this form, ensure dropdown reflects that.
            $role_from_form = $_POST['role'] ?? 'teacher';

            $password_provided = null; // For new teachers
            if (!$is_edit) {
                $password_provided = $_POST['password'] ?? '';
            }
            // For edit, password is changed via a separate modal/action.

            $errors = []; // Validation errors (same as original)
            if (empty($full_name)) $errors[] = "Full Name is required.";
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid Email is required.";
            // Add other necessary validations from your original file...

            // Email uniqueness check (same as original)
            $email_check_sql = $is_edit ? "SELECT id FROM teachers WHERE email = ? AND id != ?" : "SELECT id FROM teachers WHERE email = ?";
            $stmt_check_email = $conn->prepare($email_check_sql);
            if ($is_edit) $stmt_check_email->bind_param("si", $email, $teacher_id);
            else $stmt_check_email->bind_param("s", $email);
            $stmt_check_email->execute();
            $stmt_check_email->store_result();
            if ($stmt_check_email->num_rows > 0) $errors[] = "This email address is already in use by another teacher.";
            $stmt_check_email->close();

            if (!empty($errors)) {
                $_SESSION['error_message_ht_mt'] = "Validation errors: <br>" . implode("<br>", $errors);
                $_SESSION['form_data_ht_mt'] = $_POST;
                header('Location: head_teacher_manage_teachers.php' . ($is_edit ? '?edit_attempt=' . $teacher_id : '?add_attempt=1')); exit;
            }

            // --- File Upload Handling (Same as original) ---
            $current_files = [];
            if ($is_edit) { /* Fetch current file paths for comparison */
                $stmt_curr_files = $conn->prepare("SELECT photo, disability_medical_report_path, application_form_path, national_id_path, academic_transcripts_path, other_documents_path FROM teachers WHERE id = ?");
                if ($stmt_curr_files) {
                    $stmt_curr_files->bind_param("i", $teacher_id);
                    $stmt_curr_files->execute();
                    $res_curr_files = $stmt_curr_files->get_result();
                    if ($res_curr_files) $current_files = $res_curr_files->fetch_assoc();
                    $stmt_curr_files->close();
                } else { error_log("HT Manage Teachers - Error fetching current files: " . $conn->error); }
            }
            $db_file_paths = [];
            $_SESSION['form_error_ht_mt_file'] = null; // Clear previous file error for this session key
            foreach ($upload_paths as $field_name => $subdir) {
                $db_file_paths[$field_name] = $current_files[$field_name] ?? null;
                if (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] == UPLOAD_ERR_OK) {
                    $allowed_types_for_field = ($field_name == 'photo' || $field_name == 'national_id_path' || $field_name == 'disability_medical_report_path') ? array_merge($allowed_image_types, $allowed_document_types) : (($field_name == 'other_documents_path') ? array_merge($allowed_document_types, $allowed_archive_types) : $allowed_document_types);
                    
                    // Use a temporary session key for handle_file_upload's error reporting
                    $_SESSION['form_error'] = null; // Reset global one if handle_file_upload uses it
                    $new_file_path = handle_file_upload($_FILES[$field_name], $subdir, $allowed_types_for_field); // handle_file_upload from auth.php
                    
                    if ($new_file_path) {
                        if ($is_edit && !empty($current_files[$field_name]) && file_exists($current_files[$field_name]) && $current_files[$field_name] !== $new_file_path) {
                           if(!unlink($current_files[$field_name])) error_log("Could not delete old file: ".$current_files[$field_name]);
                        }
                        $db_file_paths[$field_name] = $new_file_path;
                    } elseif (isset($_SESSION['form_error'])) { // Check if handle_file_upload set an error
                         $_SESSION['form_error_ht_mt_file'] = ($_SESSION['form_error_ht_mt_file'] ?? '') . "<br>File (" . htmlspecialchars($_FILES[$field_name]['name']) ."): " . $_SESSION['form_error'];
                         unset($_SESSION['form_error']); // Clear the global one
                    }
                }
            }
            if (!empty($_SESSION['form_error_ht_mt_file'])) {
                $_SESSION['error_message_ht_mt'] = ($_SESSION['error_message_ht_mt'] ?? '') . $_SESSION['form_error_ht_mt_file'];
                unset($_SESSION['form_error_ht_mt_file']);
                $_SESSION['form_data_ht_mt'] = $_POST;
                header('Location: head_teacher_manage_teachers.php' . ($is_edit ? '?edit_attempt=' . $teacher_id : '?add_attempt=1')); exit;
            }
            // --- End File Upload Handling ---

            // --- Database Operation with Audit ---
            if ($is_edit) {
                $sql = "UPDATE teachers SET full_name=?, date_of_birth=?, email=?, phone_number=?, nin_number=?, photo=?, subject_specialization=?, join_date=?, address=?, has_disability=?, disability_type=?, disability_medical_report_path=?, application_form_path=?, national_id_path=?, academic_transcripts_path=?, other_documents_path=?, salary=?, role=?, updated_by=?, updated_at=NOW() WHERE id=?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) { /* Handle prepare error */ $_SESSION['error_message_ht_mt'] = "DB Error (Update Prepare): " . $conn->error; header('Location: head_teacher_manage_teachers.php'); exit;}
                $stmt->bind_param("ssssssssssssssssdsiii",
                    $full_name, $date_of_birth, $email, $phone_number, $nin_number,
                    $db_file_paths['photo'], $subject_specialization, $join_date, $address,
                    $has_disability, $disability_type, $db_file_paths['disability_medical_report_path'],
                    $db_file_paths['application_form_path'], $db_file_paths['national_id_path'],
                    $db_file_paths['academic_transcripts_path'], $db_file_paths['other_documents_path'],
                    $salary, $role_from_form, $loggedInUserId, $teacher_id);
            } else {
                if (empty($password_provided)) { // Should have been caught by validation, but double check
                    $_SESSION['error_message_ht_mt'] = "Password is required for new teachers.";
                    $_SESSION['form_data_ht_mt'] = $_POST;
                    header('Location: head_teacher_manage_teachers.php?add_attempt=1'); exit;
                }
                $hashed_password = password_hash($password_provided, PASSWORD_DEFAULT);
                $sql = "INSERT INTO teachers (full_name, date_of_birth, email, phone_number, nin_number, photo, subject_specialization, join_date, address, has_disability, disability_type, disability_medical_report_path, application_form_path, national_id_path, academic_transcripts_path, other_documents_path, salary, password, role, created_by, updated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
                $stmt = $conn->prepare($sql);
                if (!$stmt) { /* Handle prepare error */ $_SESSION['error_message_ht_mt'] = "DB Error (Insert Prepare): " . $conn->error; header('Location: head_teacher_manage_teachers.php'); exit;}
                $stmt->bind_param("ssssssssssssssssdssii",
                    $full_name, $date_of_birth, $email, $phone_number, $nin_number,
                    $db_file_paths['photo'], $subject_specialization, $join_date, $address,
                    $has_disability, $disability_type, $db_file_paths['disability_medical_report_path'],
                    $db_file_paths['application_form_path'], $db_file_paths['national_id_path'],
                    $db_file_paths['academic_transcripts_path'], $db_file_paths['other_documents_path'],
                    $salary, $hashed_password, $role_from_form, $loggedInUserId, $loggedInUserId);
            }

            if ($stmt->execute()) {
                $_SESSION['success_message_ht_mt'] = "Teacher '" . htmlspecialchars($full_name) . "' " . ($is_edit ? "updated" : "added") . " successfully by " . $_SESSION['username'] . ".";
                unset($_SESSION['form_data_ht_mt']);
            } else {
                $_SESSION['error_message_ht_mt'] = "Error " . ($is_edit ? "updating" : "adding") . " teacher: " . $stmt->error;
                if (!$is_edit) { /* Attempt to cleanup newly uploaded files on add failure */
                    foreach ($db_file_paths as $path_val) { if ($path_val && file_exists($path_val)) { if(!unlink($path_val)) error_log("Could not delete new file on add failure: ".$path_val); } }
                }
                $_SESSION['form_data_ht_mt'] = $_POST;
                header('Location: head_teacher_manage_teachers.php' . ($is_edit ? '?edit_attempt=' . $teacher_id : '?add_attempt=1')); exit;
            }
            $stmt->close();
            header('Location: head_teacher_manage_teachers.php'); exit;

        case 'delete_teacher':
            $teacher_id_del = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            if (!$teacher_id_del) { $_SESSION['error_message_ht_mt'] = "Invalid teacher ID."; header('Location: head_teacher_manage_teachers.php'); exit; }
            
            // Before deleting teacher, consider implications:
            // - teacher_class_subject_papers: Assignments will be orphaned or should be deleted/reassigned.
            // - classes.class_teacher_id: If this teacher is a class teacher, set it to NULL.
            $conn->begin_transaction();
            try {
                // 1. Nullify class_teacher_id in classes table
                $stmt_null_ct = $conn->prepare("UPDATE classes SET class_teacher_id = NULL WHERE class_teacher_id = ?");
                if(!$stmt_null_ct) throw new Exception("Prepare nullify class teacher failed: ".$conn->error);
                $stmt_null_ct->bind_param("i", $teacher_id_del);
                if(!$stmt_null_ct->execute()) throw new Exception("Execute nullify class teacher failed: ".$stmt_null_ct->error);
                $stmt_null_ct->close();

                // 2. Delete assignments from teacher_class_subject_papers
                //    (Alternatively, you might want to archive or mark them inactive)
                $stmt_del_assign = $conn->prepare("DELETE FROM teacher_class_subject_papers WHERE teacher_id = ?");
                 if(!$stmt_del_assign) throw new Exception("Prepare delete assignments failed: ".$conn->error);
                $stmt_del_assign->bind_param("i", $teacher_id_del);
                if(!$stmt_del_assign->execute()) throw new Exception("Execute delete assignments failed: ".$stmt_del_assign->error);
                $stmt_del_assign->close();

                // 3. Fetch file paths for deletion
                $stmt_files = $conn->prepare("SELECT photo, disability_medical_report_path, application_form_path, national_id_path, academic_transcripts_path, other_documents_path, full_name FROM teachers WHERE id = ?");
                if(!$stmt_files) throw new Exception("Prepare fetch files failed: ".$conn->error);
                $stmt_files->bind_param("i", $teacher_id_del); $stmt_files->execute();
                $teacher_data_del_res = $stmt_files->get_result();
                $teacher_data_del = $teacher_data_del_res ? $teacher_data_del_res->fetch_assoc() : null;
                $stmt_files->close();

                // 4. Delete the teacher record
                $stmt_del = $conn->prepare("DELETE FROM teachers WHERE id = ?");
                if(!$stmt_del) throw new Exception("Prepare delete teacher failed: ".$conn->error);
                $stmt_del->bind_param("i", $teacher_id_del);
                if ($stmt_del->execute()) {
                    if($teacher_data_del) { 
                        foreach ($upload_paths as $key_path => $value_path) {
                            if (!empty($teacher_data_del[$key_path]) && file_exists($teacher_data_del[$key_path])) {
                                if(!unlink($teacher_data_del[$key_path])) error_log("Could not delete teacher file: ".$teacher_data_del[$key_path]);
                            }
                        }
                    }
                    $_SESSION['success_message_ht_mt'] = "Teacher '" . htmlspecialchars($teacher_data_del['full_name'] ?? "ID: $teacher_id_del") . "' and their assignments deleted by " . $_SESSION['username'] . ".";
                    $conn->commit();
                } else { throw new Exception("Execute delete teacher failed: ".$stmt_del->error); }
                $stmt_del->close();
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message_ht_mt'] = "Error deleting teacher: " . $e->getMessage();
            }
            header('Location: head_teacher_manage_teachers.php'); exit;

        case 'update_password': // HT can update teacher passwords
            $teacher_id_pass = filter_input(INPUT_POST, 'teacher_id_password', FILTER_VALIDATE_INT);
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_new_password'];

            if (!$teacher_id_pass) $_SESSION['error_message_ht_mt'] = "Invalid ID for password update.";
            elseif (empty($new_password) || strlen($new_password) < 8) $_SESSION['error_message_ht_mt'] = "Password must be at least 8 characters.";
            elseif ($new_password !== $confirm_password) $_SESSION['error_message_ht_mt'] = "Passwords do not match.";
            else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt_pass = $conn->prepare("UPDATE teachers SET password = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                if (!$stmt_pass) { $_SESSION['error_message_ht_mt'] = "DB Error (Password Update Prepare): " . $conn->error;}
                else {
                    $stmt_pass->bind_param("sii", $hashed_password, $loggedInUserId, $teacher_id_pass);
                    if ($stmt_pass->execute()) $_SESSION['success_message_ht_mt'] = "Password updated for teacher ID: $teacher_id_pass by " . $_SESSION['username'] . ".";
                    else $_SESSION['error_message_ht_mt'] = "Error updating password: " . $stmt_pass->error;
                    $stmt_pass->close();
                }
            }
            header('Location: head_teacher_manage_teachers.php'); exit;
        default:
             if (!empty($action)) $_SESSION['error_message_ht_mt'] = "Invalid POST action: " . htmlspecialchars($action);
             header('Location: head_teacher_manage_teachers.php'); exit;
    }
}

// --- Fetch Data for Display ---
$teachers_display_list = [];
$creator_names = []; $updater_names = []; // For audit display
if ($conn) {
    $sql_fetch = "SELECT t.*, u_creator.username as creator_username, u_updater.username as updater_username
                  FROM teachers t
                  LEFT JOIN users u_creator ON t.created_by = u_creator.id
                  LEFT JOIN users u_updater ON t.updated_by = u_updater.id
                  ORDER BY t.full_name ASC";
    $teachers_result_display = $conn->query($sql_fetch);
    if ($teachers_result_display) {
        while ($row = $teachers_result_display->fetch_assoc()) {
            $teachers_display_list[] = $row;
        }
    } else {
        $_SESSION['error_message_ht_mt'] = "DB Error fetching teachers list: " . $conn->error;
    }
} else {
    $_SESSION['error_message_ht_mt'] = "Database connection error on page load.";
}

$success_message = $_SESSION['success_message_ht_mt'] ?? null; // Use role-specific session keys
$error_message = $_SESSION['error_message_ht_mt'] ?? null;
$form_data_session = $_SESSION['form_data_ht_mt'] ?? [];

if ($success_message) unset($_SESSION['success_message_ht_mt']);
if ($error_message) unset($_SESSION['error_message_ht_mt']);
if (!empty($form_data_session)) unset($_SESSION['form_data_ht_mt']);

$auto_open_add_modal = isset($_GET['add_attempt']);
$auto_open_edit_modal_id = isset($_GET['edit_attempt']) ? (int)$_GET['edit_attempt'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Management (HT) - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <link href="style/styles.css" rel="stylesheet"> <!-- Ensure this path is correct -->
    <style>
        /* Styles are mostly the same as your original, just ensure they are linked correctly */
        .wrapper { display: flex; min-height: 100vh; }
        /* #content assumed to be styled by styles.css or nav&sidebar.php */
        .dashboard-content { padding: 1.5rem; background-color: var(--bs-body-bg); border-radius: .5rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075); }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .nav-tabs .nav-link.active { color: var(--bs-primary); border-color: var(--bs-primary) var(--bs-primary) var(--bs-body-bg); }
        .teacher-card .card-header { background-color: var(--bs-primary); color: white; }
        .teacher-card .card-footer { background-color: var(--bs-tertiary-bg); }
        .password-modal-profile img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px; border: 2px solid var(--bs-border-color); }
        .current-file-link a { word-break: break-all; font-size: 0.85em; }
        .photo-preview-modal { max-width: 120px; max-height: 120px; object-fit: cover; border: 1px solid var(--bs-border-color); margin-top: 10px; border-radius: 4px;}
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar.php'; // Or nav_sidebar_headteacher.php if you create it ?>

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
                <h2 class="mb-0">Teacher Management <small class="text-muted fs-6 fw-normal">(Head Teacher)</small></h2>
                <button class="btn btn-primary" onclick="prepareAddModalHt()">
                    <i class="fas fa-user-plus me-2"></i>Add New Teacher
                </button>
            </div>

            <!-- Stats Cards (same as original) -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card shadow-sm">
                        <div class="stats-icon bg-primary"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="stats-info"><h5>Total Teachers</h5><h3><?php echo count($teachers_display_list); ?></h3></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card shadow-sm">
                        <div class="stats-icon bg-success"><i class="fas fa-book"></i></div>
                        <div class="stats-info"><h5>Subjects Specializations</h5><h3 id="subjectsOfferedStatHt">0</h3></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card shadow-sm">
                        <div class="stats-icon bg-warning"><i class="fas fa-user-tie"></i></div>
                        <div class="stats-info"><h5>Active Roles</h5><h3 id="activeRolesStatHt">0</h3></div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter (same as original) -->
            <div class="card mb-4 shadow-sm">
                <div class="card-body">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchBarHt" class="form-control global-search" placeholder="Search teachers by name, email, subject...">
                    </div>
                </div>
            </div>

            <!-- Teachers Grid (displaying audit info if available) -->
            <div class="row gy-4" id="teachersDisplayGridHt">
                <?php if (!empty($teachers_display_list)): ?>
                    <?php foreach ($teachers_display_list as $teacher_item): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 teacher-card-item" data-search-payload="<?php echo strtolower(htmlspecialchars($teacher_item['full_name'] . ' ' . $teacher_item['email'] . ' ' . ($teacher_item['subject_specialization'] ?? ''))); ?>">
                        <div class="card h-100 teacher-card shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fs-6 text-truncate" title="<?php echo htmlspecialchars($teacher_item['full_name']); ?>"><?php echo htmlspecialchars($teacher_item['full_name']); ?></h5>
                                <span class="badge bg-light text-dark small"><?php echo htmlspecialchars($teacher_item['subject_specialization'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="card-body text-center">
                                <!-- ... (teacher image and basic info same as original) ... -->
                                <img src="<?php echo htmlspecialchars(!empty($teacher_item['photo']) && file_exists($teacher_item['photo']) ? $teacher_item['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($teacher_item['full_name']).'&background=random&color=fff&size=100'); ?>"
                                     class="rounded-circle border" style="width: 100px; height: 100px; object-fit: cover;"
                                     alt="<?php echo htmlspecialchars($teacher_item['full_name']); ?>">
                                <div class="mt-2 teacher-info text-start small">
                                    <div class="mb-1"><i class="fas fa-envelope fa-fw me-2 text-muted"></i><span class="text-truncate" title="<?php echo htmlspecialchars($teacher_item['email']); ?>"><?php echo htmlspecialchars($teacher_item['email']); ?></span></div>
                                    <div class="mb-1"><i class="fas fa-phone fa-fw me-2 text-muted"></i><span><?php echo htmlspecialchars($teacher_item['phone_number'] ?: 'N/A'); ?></span></div>
                                    <div class="mb-1"><i class="fas fa-id-card fa-fw me-2 text-muted"></i><span>NIN: <?php echo htmlspecialchars($teacher_item['nin_number'] ?: 'N/A'); ?></span></div>
                                    <div class="mb-1"><i class="fas fa-money-bill-wave fa-fw me-2 text-muted"></i><span>Salary: UGX <?php echo number_format($teacher_item['salary'] ?: 0, 0); ?></span></div>
                                    <div class="mb-1"><i class="fas fa-user-tag fa-fw me-2 text-muted"></i><span>Role: <?php echo htmlspecialchars(ucfirst($teacher_item['role'])); ?></span></div>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-around align-items-center py-2">
                                <button class="btn btn-sm btn-outline-primary flex-fill me-1" onclick="prepareEditModalHt(<?php echo $teacher_item['id']; ?>)">
                                    <i class="fas fa-edit"></i> <span class="d-none d-md-inline">Edit</span>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary flex-fill mx-1" onclick="openPasswordModalHt(<?php echo $teacher_item['id']; ?>, '<?php echo htmlspecialchars(addslashes($teacher_item['full_name'])); ?>', '<?php echo htmlspecialchars(!empty($teacher_item['photo']) && file_exists($teacher_item['photo']) ? $teacher_item['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($teacher_item['full_name']).'&background=random&color=fff&size=50'); ?>')">
                                    <i class="fas fa-key"></i> <span class="d-none d-md-inline">Pwd</span>
                                </button>
                                <!-- HT views details on a different page (teacher_details_ht.php) -->
                                <a href="head_teacher_teacher_details.php?id=<?php echo $teacher_item['id']; ?>" class="btn btn-sm btn-outline-info flex-fill mx-1" title="View Details">
                                    <i class="fas fa-eye"></i> <span class="d-none d-md-inline">Details</span>
                                </a>
                                <button class="btn btn-sm btn-outline-danger flex-fill ms-1" onclick="confirmDeleteDialogHt(<?php echo $teacher_item['id']; ?>, '<?php echo htmlspecialchars(addslashes($teacher_item['full_name'])); ?>')">
                                    <i class="fas fa-trash"></i> <span class="d-none d-md-inline">Del</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                     <div class="col-12"><div class="alert alert-info text-center">No teachers found. Please add one.</div></div>
                <?php endif; ?>
                <div class="col-12" id="noTeachersFoundMessageHt" style="display: none;">
                    <div class="alert alert-warning text-center">No teachers match your search criteria.</div>
                </div>
            </div>
        </div> <!-- End .dashboard-content -->
    </div> <!-- End .wrapper -->

    <!-- Universal Teacher Add/Edit Modal (FOR HT - FULL EDIT) -->
    <div class="modal fade" id="teacherFormModalHt" tabindex="-1" aria-labelledby="teacherFormModalLabelHt" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form id="teacherFormHt" action="head_teacher_manage_teachers.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="form_action_ht">
                    <input type="hidden" name="teacher_id" id="form_teacher_id_ht">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="teacherFormModalLabelHt">Teacher Information</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Tabs: Personal, Contact, Professional, Disability, Documents, Credentials -->
                        <!-- ALL fields are editable by HT in this modal -->
                        <ul class="nav nav-tabs mb-3" id="teacherFormTabsHt" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" id="personal-tab-ht" data-bs-toggle="tab" data-bs-target="#personal-tab-pane-ht" type="button"><i class="fas fa-user-circle me-1"></i>Personal</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="contact-tab-ht" data-bs-toggle="tab" data-bs-target="#contact-tab-pane-ht" type="button"><i class="fas fa-address-book me-1"></i>Contact</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="professional-tab-ht" data-bs-toggle="tab" data-bs-target="#professional-tab-pane-ht" type="button"><i class="fas fa-briefcase me-1"></i>Professional</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="disability-tab-ht" data-bs-toggle="tab" data-bs-target="#disability-tab-pane-ht" type="button"><i class="fas fa-wheelchair me-1"></i>Disability</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="documents-tab-ht" data-bs-toggle="tab" data-bs-target="#documents-tab-pane-ht" type="button"><i class="fas fa-folder-open me-1"></i>Documents</button></li>
                            <li class="nav-item" id="credentials-tab-item-ht" role="presentation"><button class="nav-link" id="credentials-tab-ht" data-bs-toggle="tab" data-bs-target="#credentials-tab-pane-ht" type="button"><i class="fas fa-key me-1"></i>Credentials</button></li>
                        </ul>
                        <div class="tab-content" id="teacherFormTabsContentHt">
                            <!-- Personal Tab Pane -->
                            <div class="tab-pane fade show active" id="personal-tab-pane-ht" role="tabpanel">
                                <h5 class="text-muted">Basic Details</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6"><label for="full_name_ht" class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="full_name_ht" name="full_name" value="<?php echo htmlspecialchars($form_data_session['full_name'] ?? ''); ?>" required></div>
                                    <div class="col-md-6"><label for="date_of_birth_ht" class="form-label">Date of Birth</label><input type="date" class="form-control" id="date_of_birth_ht" name="date_of_birth" value="<?php echo htmlspecialchars($form_data_session['date_of_birth'] ?? ''); ?>"></div>
                                </div>
                                <h5 class="text-muted">Location</h5>
                                <div class="row g-3"><div class="col-md-12"><label for="address_ht" class="form-label">Residential Address</label><textarea class="form-control" id="address_ht" name="address" rows="2"><?php echo htmlspecialchars($form_data_session['address'] ?? ''); ?></textarea></div></div>
                            </div>
                            <!-- Contact Tab Pane -->
                            <div class="tab-pane fade" id="contact-tab-pane-ht" role="tabpanel">
                                <h5 class="text-muted">Contact Information</h5>
                                <div class="row g-3">
                                     <div class="col-md-6"><label for="email_ht" class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="email_ht" name="email" value="<?php echo htmlspecialchars($form_data_session['email'] ?? ''); ?>" required></div>
                                    <div class="col-md-6"><label for="phone_number_ht" class="form-label">Phone <span class="text-danger">*</span></label><input type="tel" class="form-control" id="phone_number_ht" name="phone_number" value="<?php echo htmlspecialchars($form_data_session['phone_number'] ?? ''); ?>" required></div>
                                </div>
                            </div>
                            <!-- Professional Tab Pane -->
                            <div class="tab-pane fade" id="professional-tab-pane-ht" role="tabpanel">
                                <h5 class="text-muted">Professional Details</h5>
                                <div class="row g-3">
                                     <div class="col-md-6"><label for="nin_number_ht" class="form-label">NIN Number</label><input type="text" class="form-control" id="nin_number_ht" name="nin_number" value="<?php echo htmlspecialchars($form_data_session['nin_number'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="join_date_ht" class="form-label">Joining Date</label><input type="date" class="form-control" id="join_date_ht" name="join_date" value="<?php echo htmlspecialchars($form_data_session['join_date'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="subject_specialization_ht" class="form-label">Subject Specialization</label><input type="text" class="form-control" id="subject_specialization_ht" name="subject_specialization" value="<?php echo htmlspecialchars($form_data_session['subject_specialization'] ?? ''); ?>" placeholder="e.g., Maths, Physics"></div>
                                    <div class="col-md-6"><label for="salary_ht" class="form-label">Salary (UGX)</label><input type="number" step="0.01" class="form-control" id="salary_ht" name="salary" value="<?php echo htmlspecialchars($form_data_session['salary'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="role_ht" class="form-label">Role <span class="text-danger">*</span></label>
                                        <select class="form-select" id="role_ht" name="role" required>
                                            <option value="teacher" <?php echo (($_POST['role'] ?? 'teacher') == 'teacher') ? 'selected' : ''; ?>>Teacher</option>
                                            <option value="head_of_department" <?php echo (($_POST['role'] ?? '') == 'head_of_department') ? 'selected' : ''; ?>>Head of Department</option>
                                            <option value="class_teacher" <?php echo (($_POST['role'] ?? '') == 'class_teacher') ? 'selected' : ''; ?>>Class Teacher</option>
                                            <!-- Add other teacher-related roles if managed here -->
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <!-- Disability Tab Pane -->
                            <div class="tab-pane fade" id="disability-tab-pane-ht" role="tabpanel">
                                 <h5 class="text-muted">Disability Information</h5>
                                 <div class="row g-3">
                                    <div class="col-md-4"><label for="has_disability_ht" class="form-label">Has Disability?</label><select class="form-select" id="has_disability_ht" name="has_disability" onchange="toggleDisabilityFieldsOnFormHt(this.value)"><option value="No" <?php echo ((isset($form_data_session['has_disability']) && $form_data_session['has_disability'] == 'No') || !isset($form_data_session['has_disability'])) ? 'selected' : ''; ?>>No</option><option value="Yes" <?php echo (isset($form_data_session['has_disability']) && $form_data_session['has_disability'] == 'Yes') ? 'selected' : ''; ?>>Yes</option></select></div>
                                    <div class="col-md-8 form_disability_type_field_ht" style="display:none;"><label for="disability_type_ht" class="form-label">Type of Disability</label><input type="text" class="form-control" id="disability_type_ht" name="disability_type" value="<?php echo htmlspecialchars($form_data_session['disability_type'] ?? ''); ?>"></div>
                                    <div class="col-md-12 form_disability_report_field_ht" style="display:none;"><label for="disability_medical_report_path_ht" class="form-label">Medical Report</label><input type="file" class="form-control" id="disability_medical_report_path_ht" name="disability_medical_report_path" accept=".pdf,.jpg,.png"><small id="current_disability_medical_report_path_ht" class="form-text text-muted current-file-link"></small></div>
                                 </div>
                            </div>
                            <!-- Documents Tab Pane -->
                            <div class="tab-pane fade" id="documents-tab-pane-ht" role="tabpanel">
                                <h5 class="text-muted">Document Uploads</h5>
                                <div class="row g-3">
                                    <div class="col-md-6"><label for="photo_ht" class="form-label">Photo</label><input type="file" class="form-control" id="photo_ht" name="photo" accept="image/*" onchange="previewModalImageHt(this, 'photo_preview_modal_ht')"><img id="photo_preview_modal_ht" src="#" alt="Preview" class="photo-preview-modal mt-2" style="display:none;"/><small id="current_photo_ht" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-6"><label for="application_form_path_ht" class="form-label">Application Form (PDF)</label><input type="file" class="form-control" id="application_form_path_ht" name="application_form_path" accept=".pdf"><small id="current_application_form_path_ht" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-6"><label for="national_id_path_ht" class="form-label">National ID (PDF/Img)</label><input type="file" class="form-control" id="national_id_path_ht" name="national_id_path" accept=".pdf,.jpg,.png"><small id="current_national_id_path_ht" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-6"><label for="academic_transcripts_path_ht" class="form-label">Transcripts (PDF)</label><input type="file" class="form-control" id="academic_transcripts_path_ht" name="academic_transcripts_path" accept=".pdf"><small id="current_academic_transcripts_path_ht" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-12"><label for="other_documents_path_ht" class="form-label">Other Docs (PDF/ZIP)</label><input type="file" class="form-control" id="other_documents_path_ht" name="other_documents_path" accept=".pdf,.zip"><small id="current_other_documents_path_ht" class="form-text text-muted current-file-link"></small></div>
                                </div>
                            </div>
                            <!-- Credentials Tab Pane (Only for Add New Teacher) -->
                            <div class="tab-pane fade" id="credentials-tab-pane-ht" role="tabpanel">
                                 <h5 class="text-muted">Account Credentials</h5>
                                 <div class="row g-3">
                                    <div class="col-md-8"><label for="password_form_field_ht" class="form-label">Password <span class="text-danger">*</span></label><div class="input-group"><input type="password" class="form-control" id="password_form_field_ht" name="password" minlength="8"><button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibilityHt('password_form_field_ht')"><i class="fas fa-eye"></i></button></div></div>
                                    <div class="col-md-4 align-self-end"><button type="button" class="btn btn-secondary w-100" onclick="generateMACOMPasswordHt('password_form_field_ht')"><i class="fas fa-cogs me-1"></i>Generate</button></div>
                                 </div><small class="form-text text-muted">For new teachers. Min 8 chars. (Password change for existing teachers is done via 'Pwd' button on main list)</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveTeacherBtnHt"><i class="fas fa-save me-2"></i>Save Teacher</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Password Management Modal (FOR HT) -->
    <div class="modal fade" id="passwordUpdateModalHt" tabindex="-1" aria-labelledby="passwordUpdateModalLabelHt" aria-hidden="true">
        <!-- Same structure as your original passwordUpdateModal, just ensure IDs are unique if needed -->
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="passwordUpdateModalLabelHt"><i class="fas fa-shield-alt me-2"></i>Update Teacher Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="passwordUpdateFormHt" action="head_teacher_manage_teachers.php" method="POST">
                    <input type="hidden" name="action" value="update_password">
                    <input type="hidden" name="teacher_id_password" id="password_update_teacher_id_ht">
                    <div class="modal-body">
                        <div class="password-modal-profile">
                            <img id="password_modal_teacher_photo_ht" src="" alt="Photo">
                            <span id="password_modal_teacher_name_ht"></span>
                        </div>
                        <div class="mb-3"><label for="new_password_update_ht" class="form-label">New Password <span class="text-danger">*</span></label><div class="input-group"><input type="password" class="form-control" id="new_password_update_ht" name="new_password" required minlength="8"><button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibilityHt('new_password_update_ht')"><i class="fas fa-eye"></i></button></div></div>
                        <div class="mb-3"><label for="confirm_new_password_update_ht" class="form-label">Confirm <span class="text-danger">*</span></label><input type="password" class="form-control" id="confirm_new_password_update_ht" name="confirm_new_password" required minlength="8"></div>
                        <button type="button" class="btn btn-sm btn-secondary w-100" onclick="generateMACOMPasswordHt('new_password_update_ht', 'confirm_new_password_update_ht')"><i class="fas fa-cogs me-1"></i>Generate MACOM Password</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save me-2"></i>Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // HT specific JS, functions renamed with Ht suffix
        const teacherFormModalElHt = document.getElementById('teacherFormModalHt');
        const teacherFormModalInstanceHt = new bootstrap.Modal(teacherFormModalElHt);
        // ... (all other JS const declarations from original, suffixed with Ht)
        const teacherFormHt = document.getElementById('teacherFormHt');
        const formActionInputHt = document.getElementById('form_action_ht');
        const formTeacherIdInputHt = document.getElementById('form_teacher_id_ht');
        const teacherFormModalLabelHt = document.getElementById('teacherFormModalLabelHt');
        const saveTeacherBtnHt = document.getElementById('saveTeacherBtnHt');
        const credentialsTabItemHt = document.getElementById('credentials-tab-item-ht');
        const passwordInputOnFormHt = document.getElementById('password_form_field_ht');


        function prepareAddModalHt() {
            teacherFormHt.reset();
            formActionInputHt.value = 'add_teacher';
            formTeacherIdInputHt.value = '';
            teacherFormModalLabelHt.textContent = 'Add New Teacher (HT)';
            saveTeacherBtnHt.innerHTML = '<i class="fas fa-user-plus me-2"></i>Add Teacher';
            saveTeacherBtnHt.className = 'btn btn-primary';
            if(credentialsTabItemHt) credentialsTabItemHt.style.display = 'list-item'; // Show credentials tab for add
            if(passwordInputOnFormHt) passwordInputOnFormHt.required = true;
            document.querySelectorAll('#teacherFormModalHt .current-file-link').forEach(el => el.innerHTML = '');
            const photoPreviewHt = document.getElementById('photo_preview_modal_ht');
            if(photoPreviewHt) { photoPreviewHt.style.display = 'none'; photoPreviewHt.src = '#'; }
            toggleDisabilityFieldsOnFormHt('No');
            const firstTabElHt = document.getElementById('personal-tab-ht');
            if(firstTabElHt) new bootstrap.Tab(firstTabElHt).show();
            teacherFormModalInstanceHt.show();
        }

        async function prepareEditModalHt(teacherId) {
            teacherFormHt.reset(); // Reset form
            try {
                // Use the same AJAX endpoint, no need to change action name for GET
                const response = await fetch(`head_teacher_manage_teachers.php?action=get_teacher&id=${teacherId}`);
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const teacher = await response.json();

                if (teacher && !teacher.error) {
                    formActionInputHt.value = 'edit_teacher';
                    formTeacherIdInputHt.value = teacher.id;
                    teacherFormModalLabelHt.textContent = 'Edit Teacher (HT): ' + (teacher.full_name || 'N/A');
                    saveTeacherBtnHt.innerHTML = '<i class="fas fa-save me-2"></i>Update Teacher';
                    saveTeacherBtnHt.className = 'btn btn-success';

                    // Populate form fields (using _ht suffix for IDs)
                    document.getElementById('full_name_ht').value = teacher.full_name || '';
                    document.getElementById('date_of_birth_ht').value = teacher.date_of_birth || '';
                    document.getElementById('address_ht').value = teacher.address || '';
                    document.getElementById('email_ht').value = teacher.email || '';
                    document.getElementById('phone_number_ht').value = teacher.phone_number || '';
                    document.getElementById('nin_number_ht').value = teacher.nin_number || '';
                    document.getElementById('join_date_ht').value = teacher.join_date || '';
                    document.getElementById('subject_specialization_ht').value = teacher.subject_specialization || '';
                    document.getElementById('salary_ht').value = teacher.salary || '';
                    document.getElementById('role_ht').value = teacher.role || 'teacher';
                    
                    const hasDisabilityVal = teacher.has_disability || 'No';
                    document.getElementById('has_disability_ht').value = hasDisabilityVal;
                    toggleDisabilityFieldsOnFormHt(hasDisabilityVal); // Use HT specific toggle
                    document.getElementById('disability_type_ht').value = teacher.disability_type || '';

                    // File fields
                    const fileFieldsHt = {
                        'photo': 'current_photo_ht', 
                        'disability_medical_report_path': 'current_disability_medical_report_path_ht',
                        'application_form_path': 'current_application_form_path_ht', 
                        'national_id_path': 'current_national_id_path_ht',
                        'academic_transcripts_path': 'current_academic_transcripts_path_ht', 
                        'other_documents_path': 'current_other_documents_path_ht'
                    };
                    for (const [dbField, displayId] of Object.entries(fileFieldsHt)) {
                        const displayElement = document.getElementById(displayId);
                        const photoPreviewEl = document.getElementById('photo_preview_modal_ht'); // HT specific preview
                        if (teacher[dbField] && teacher[dbField].length > 0) { // Check if path is not empty
                            if (dbField === 'photo' && photoPreviewEl) { photoPreviewEl.src = teacher[dbField]; photoPreviewEl.style.display = 'block'; }
                            if(displayElement) displayElement.innerHTML = `Current: <a href="${teacher[dbField]}" target="_blank">${teacher[dbField].split('/').pop()}</a>`;
                        } else {
                            if (dbField === 'photo' && photoPreviewEl) { photoPreviewEl.style.display = 'none'; photoPreviewEl.src = '#'; }
                             if(displayElement) displayElement.innerHTML = '';
                        }
                    }
                    if(credentialsTabItemHt) credentialsTabItemHt.style.display = 'none'; // Hide credentials tab for edit
                    if(passwordInputOnFormHt) passwordInputOnFormHt.required = false;

                    const firstTabElHt = document.getElementById('personal-tab-ht');
                    if(firstTabElHt) new bootstrap.Tab(firstTabElHt).show();
                    teacherFormModalInstanceHt.show();
                } else { 
                    alert('Error using fetched teacher data (HT): ' + (teacher.error || 'Format Error')); 
                }
            } catch (error) { 
                console.error('JS error in prepareEditModalHt:', error); 
                alert('JS error preparing edit form (HT). Check console.'); 
            }
        }

        // HT specific password generation
        function generateMACOMPasswordHt(passwordFieldId, confirmFieldId = null) {
            const randomPartLength = 6;
            const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789*#@&';
            let randomPart = '';
            for (let i = 0; i < randomPartLength; i++) randomPart += characters.charAt(Math.floor(Math.random() * characters.length));
            const newPassword = `MACOM-EMP-${randomPart}`; // EMP for employee
            const passField = document.getElementById(passwordFieldId);
            passField.value = newPassword;
            if (passField.type === 'password') { passField.type = 'text'; setTimeout(() => { passField.type = 'password'; }, 2000); }
            if (confirmFieldId) document.getElementById(confirmFieldId).value = newPassword;
        }

        // HT specific toggle password visibility
        function togglePasswordVisibilityHt(fieldId) {
            const field = document.getElementById(fieldId);
            const group = field.closest('.input-group');
            if (!group) return; const icon = group.querySelector('button i'); if (!icon) return;
            field.type = (field.type === "password") ? "text" : "password";
            icon.classList.toggle('fa-eye'); icon.classList.toggle('fa-eye-slash');
        }
        
        // HT specific toggle disability fields
        function toggleDisabilityFieldsOnFormHt(value) {
            const typeFieldDiv = document.querySelector('#teacherFormModalHt .form_disability_type_field_ht');
            const reportFieldDiv = document.querySelector('#teacherFormModalHt .form_disability_report_field_ht');
            const typeInput = document.getElementById('disability_type_ht');
            const displayStyle = (value === 'Yes') ? 'block' : 'none';
            if(typeFieldDiv) typeFieldDiv.style.display = displayStyle;
            if(reportFieldDiv) reportFieldDiv.style.display = displayStyle;
            if(typeInput) typeInput.required = (value === 'Yes');
            if(value !== 'Yes' && typeInput) typeInput.value = '';
        }
        <?php if (!empty($form_data_session['has_disability'])): // For repopulation on failed attempt ?>
        toggleDisabilityFieldsOnFormHt('<?php echo htmlspecialchars($form_data_session['has_disability']); ?>');
        <?php else: ?>
        // toggleDisabilityFieldsOnFormHt('No'); // Default state for add modal handled by prepareAddModalHt
        <?php endif; ?>

        // HT specific preview image
        function previewModalImageHt(input, previewElementId) {
            const preview = document.getElementById(previewElementId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            } else { preview.src = '#'; preview.style.display = 'none'; }
        }

        // HT specific open password modal
        const passwordUpdateModalElHt = document.getElementById('passwordUpdateModalHt');
        const passwordUpdateModalInstanceHt = new bootstrap.Modal(passwordUpdateModalElHt);
        function openPasswordModalHt(teacherId, teacherName, teacherPhoto) {
            document.getElementById('password_update_teacher_id_ht').value = teacherId;
            document.getElementById('password_modal_teacher_name_ht').textContent = teacherName;
            document.getElementById('password_modal_teacher_photo_ht').src = teacherPhoto || `https://ui-avatars.com/api/?name=${encodeURIComponent(teacherName)}&size=50&background=random`;
            document.getElementById('passwordUpdateFormHt').reset();
            passwordUpdateModalInstanceHt.show();
        }
        
        document.getElementById('passwordUpdateFormHt')?.addEventListener('submit', function(event) {
            const newP = document.getElementById('new_password_update_ht').value;
            const confP = document.getElementById('confirm_new_password_update_ht').value;
            if (newP !== confP) { alert("Passwords don't match!"); event.preventDefault(); }
            else if (newP.length < 8) { alert("Password min 8 chars."); event.preventDefault(); }
        });

        // HT specific confirm delete
        function confirmDeleteDialogHt(teacherId, teacherName) {
            if (confirm(`Are you sure you want to delete teacher: ${teacherName} (ID: ${teacherId})? This will also remove their assignments and unassign them as class teacher if applicable. This action is irreversible.`)) {
                const form = document.createElement('form');
                form.method = 'POST'; form.action = 'head_teacher_manage_teachers.php';
                form.innerHTML = `<input type="hidden" name="action" value="delete_teacher"><input type="hidden" name="teacher_id" value="${teacherId}">`;
                document.body.appendChild(form); form.submit();
            }
        }

        <?php if ($auto_open_add_modal && !empty($form_data_session)): ?>
        document.addEventListener('DOMContentLoaded', () => {
            prepareAddModalHt();
            // Additional repopulation if value attributes in PHP weren't enough
            // For example, for select elements if not handled by PHP value attribute logic
            <?php if (isset($form_data_session['role'])): ?>
                document.getElementById('role_ht').value = '<?php echo htmlspecialchars($form_data_session['role']); ?>';
            <?php endif; ?>
            <?php if (isset($form_data_session['has_disability'])): ?>
                document.getElementById('has_disability_ht').value = '<?php echo htmlspecialchars($form_data_session['has_disability']); ?>';
                toggleDisabilityFieldsOnFormHt('<?php echo htmlspecialchars($form_data_session['has_disability']); ?>');
                document.getElementById('disability_type_ht').value = '<?php echo htmlspecialchars($form_data_session['disability_type'] ?? ''); ?>';
            <?php endif; ?>
        });
        <?php elseif ($auto_open_edit_modal_id): ?>
        document.addEventListener('DOMContentLoaded', () => {
            prepareEditModalHt(<?php echo $auto_open_edit_modal_id; ?>)
            <?php if(!empty($form_data_session)): // If edit failed and we have session data ?>
            .then(() => {
                // Override with form_data_session if it exists, after AJAX has populated
                // This part needs careful integration if AJAX values should be default and session values override
                // For simplicity, the PHP value attributes mostly handle this. This is a fallback.
                 <?php foreach ($form_data_session as $key => $value): ?>
                    <?php if(in_array($key, ['full_name', 'date_of_birth', 'address', 'email', 'phone_number', 'nin_number', 'join_date', 'subject_specialization', 'salary', 'role', 'disability_type'])): ?>
                        const field = document.getElementById('<?php echo $key; ?>_ht');
                        if(field) field.value = '<?php echo htmlspecialchars($value); ?>';
                    <?php elseif($key === 'has_disability'): ?>
                        const hasDisabilityField = document.getElementById('has_disability_ht');
                        if(hasDisabilityField) {
                            hasDisabilityField.value = '<?php echo htmlspecialchars($value); ?>';
                            toggleDisabilityFieldsOnFormHt('<?php echo htmlspecialchars($value); ?>');
                        }
                    <?php endif; ?>
                 <?php endforeach; ?>
            });
            <?php endif; ?>
        });
        <?php endif; ?>

        teacherFormModalElHt?.addEventListener('hidden.bs.modal', function () {
            teacherFormHt.reset();
            const photoPreviewHt = document.getElementById('photo_preview_modal_ht');
            if(photoPreviewHt) { photoPreviewHt.style.display = 'none'; photoPreviewHt.src = '#'; }
            document.querySelectorAll('#teacherFormModalHt .current-file-link').forEach(el => el.innerHTML = '');
            toggleDisabilityFieldsOnFormHt('No');
            const firstTabElHt = document.getElementById('personal-tab-ht');
            if (firstTabElHt) new bootstrap.Tab(firstTabElHt).show();
        });

        // HT specific search bar
        document.getElementById('searchBarHt')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const teacherCards = document.querySelectorAll('#teachersDisplayGridHt .teacher-card-item');
            let visibleCount = 0;
            teacherCards.forEach(card => {
                const searchPayload = card.dataset.searchPayload || "";
                card.style.display = searchPayload.includes(searchTerm) ? '' : 'none';
                if (card.style.display !== 'none') visibleCount++;
            });
            document.getElementById('noTeachersFoundMessageHt').style.display = visibleCount === 0 && searchTerm !== '' ? 'block' : 'none';
        });

        // HT specific stats calculation
        document.addEventListener('DOMContentLoaded', function() {
            const uniqueSubjectsHt = new Set();
            document.querySelectorAll('#teachersDisplayGridHt .teacher-card .badge.bg-light').forEach(badge => {
                if (badge.textContent.trim() !== 'N/A') {
                    uniqueSubjectsHt.add(badge.textContent.trim());
                }
            });
            const subjectsOfferedStatEl = document.getElementById('subjectsOfferedStatHt');
            if(subjectsOfferedStatEl) subjectsOfferedStatEl.textContent = uniqueSubjectsHt.size;

            const uniqueRolesHt = new Set();
            document.querySelectorAll('#teachersDisplayGridHt .teacher-card-item').forEach(card => { // Corrected selector
                const roleElement = card.querySelector('.teacher-info .fa-user-tag')?.nextElementSibling;
                if (roleElement) {
                    const roleText = roleElement.textContent.replace('Role: ', '').trim();
                    if (roleText && roleText !== 'N/A') uniqueRolesHt.add(roleText);
                }
            });
            const activeRolesStatEl = document.getElementById('activeRolesStatHt');
            if(activeRolesStatEl) activeRolesStatEl.textContent = uniqueRolesHt.size;
        });
    </script>
</body>
</html>