<?php
// teachers_management.php (Complete & Refined)
require_once 'db_config.php'; // Connects to DB, starts session
require_once 'auth.php';     // Auth functions, file upload handler

// --- Access Control (Adjust roles as needed) ---
$allowed_roles = ['head_teacher', 'admin', 'IT_admin', 'IT']; // Added 'IT' as an example
if (!in_array(get_session_value('role'), $allowed_roles)) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized action.']);
        exit;
    }
    $_SESSION['error_message'] = "You are not authorized to access the teacher management page.";
    header('Location: dashboard.php');
    exit;
}

$action = $_REQUEST['action'] ?? null; // Use $_REQUEST to catch both GET and POST

// File upload configurations
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
$allowed_archive_types = ['application/zip'];

// --- START OF PHP PROCESSING LOGIC ---
// THIS MUST BE AT THE VERY TOP, BEFORE ANY HTML OUTPUT IF IT SENDS HEADERS OR JSON

if ($action === 'get_teacher' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $teacher_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$teacher_id) {
        echo json_encode(['error' => 'Invalid Teacher ID provided.']);
        exit;
    }

    if (!$conn) { // Check if $conn is valid
        echo json_encode(['error' => 'Database connection failed.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for get_teacher: " . $conn->error);
        echo json_encode(['error' => 'Database query preparation failed.']);
        exit;
    }
    $stmt->bind_param("i", $teacher_id);
    if (!$stmt->execute()) {
        error_log("Execute failed for get_teacher: " . $stmt->error);
        echo json_encode(['error' => 'Database query execution failed.']);
        exit;
    }
    $result = $stmt->get_result();
    $teacher = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($teacher) {
        echo json_encode($teacher);
    } else {
        echo json_encode(['error' => 'Teacher not found for ID: ' . $teacher_id]);
    }
    exit; // CRUCIAL: Stop script execution after sending JSON
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) { // Basic trim for all POST data
        if (is_string($value)) $_POST[$key] = trim($value);
    }

    switch ($action) {
        case 'add_teacher':
        case 'edit_teacher':
            $is_edit = ($action === 'edit_teacher');
            $teacher_id = $is_edit ? filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT) : null;

            if ($is_edit && !$teacher_id) {
                $_SESSION['error_message'] = "Invalid teacher ID for edit.";
                header('Location: teachers_management.php'); exit;
            }

            // Collect data
            $full_name = $_POST['full_name'];
            $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
            $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
            $phone_number = $_POST['phone_number'];
            $nin_number = !empty($_POST['nin_number']) ? $_POST['nin_number'] : null;
            $subject_specialization = !empty($_POST['subject_specialization']) ? $_POST['subject_specialization'] : null;
            $join_date = !empty($_POST['join_date']) ? $_POST['join_date'] : null;
            $address = !empty($_POST['address']) ? $_POST['address'] : null;
            $has_disability = $_POST['has_disability'] ?? 'No';
            $disability_type = ($has_disability === 'Yes' && !empty($_POST['disability_type'])) ? $_POST['disability_type'] : null;
            $salary = !empty($_POST['salary']) ? filter_var($_POST['salary'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;
            $role = $_POST['role'] ?? 'teacher';
            $password_provided = !$is_edit ? ($_POST['password'] ?? '') : null;

            $errors = [];
            if (empty($full_name)) $errors[] = "Full Name is required.";
            if (empty($email)) $errors[] = "Email is required.";
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";
            if (empty($phone_number)) $errors[] = "Phone Number is required.";
            if (empty($role)) $errors[] = "Role is required.";
            if (!$is_edit && empty($password_provided)) $errors[] = "Password is required for new teachers.";
            elseif (!$is_edit && strlen($password_provided) < 8) $errors[] = "Password must be at least 8 characters.";


            $email_check_sql = $is_edit ? "SELECT id FROM teachers WHERE email = ? AND id != ?" : "SELECT id FROM teachers WHERE email = ?";
            $stmt_check_email = $conn->prepare($email_check_sql);
            if ($is_edit) $stmt_check_email->bind_param("si", $email, $teacher_id);
            else $stmt_check_email->bind_param("s", $email);
            $stmt_check_email->execute();
            $stmt_check_email->store_result();
            if ($stmt_check_email->num_rows > 0) $errors[] = "This email address is already in use.";
            $stmt_check_email->close();

            if (!empty($errors)) {
                $_SESSION['error_message'] = "Validation errors: <br>" . implode("<br>", $errors);
                $_SESSION['form_data'] = $_POST; // Repopulate form
                header('Location: teachers_management.php' . ($is_edit ? '?edit_attempt=' . $teacher_id : '?add_attempt=1')); exit;
            }

            $current_files = [];
            if ($is_edit) { /* Fetch current file paths for comparison */ 
                $stmt_curr_files = $conn->prepare("SELECT photo, disability_medical_report_path, application_form_path, national_id_path, academic_transcripts_path, other_documents_path FROM teachers WHERE id = ?");
                if ($stmt_curr_files) {
                    $stmt_curr_files->bind_param("i", $teacher_id);
                    $stmt_curr_files->execute();
                    $res_curr_files = $stmt_curr_files->get_result();
                    if ($res_curr_files) $current_files = $res_curr_files->fetch_assoc();
                    $stmt_curr_files->close();
                }
            }

            $db_file_paths = [];
            foreach ($upload_paths as $field_name => $subdir) {
                $db_file_paths[$field_name] = $current_files[$field_name] ?? null; // Keep old if no new upload
                if (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] == UPLOAD_ERR_OK) {
                    $allowed_types_for_field = ($field_name == 'photo' || $field_name == 'national_id_path' || $field_name == 'disability_medical_report_path') ? array_merge($allowed_image_types, $allowed_document_types) : (($field_name == 'other_documents_path') ? array_merge($allowed_document_types, $allowed_archive_types) : $allowed_document_types);
                    $new_file_path = handle_file_upload($_FILES[$field_name], $subdir, $allowed_types_for_field);
                    if ($new_file_path) {
                        if ($is_edit && !empty($current_files[$field_name]) && file_exists($current_files[$field_name]) && $current_files[$field_name] !== $new_file_path) {
                            unlink($current_files[$field_name]); // Delete old file
                        }
                        $db_file_paths[$field_name] = $new_file_path;
                    } elseif (isset($_SESSION['form_error'])) { // handle_file_upload sets this on error
                         $_SESSION['error_message'] = ($_SESSION['error_message'] ?? '') . "<br>File (" . htmlspecialchars($field_name) ."): " . $_SESSION['form_error'];
                         unset($_SESSION['form_error']);
                    }
                }
            }
            
            if ($is_edit) {
                $sql = "UPDATE teachers SET full_name=?, date_of_birth=?, email=?, phone_number=?, nin_number=?, photo=?, subject_specialization=?, join_date=?, address=?, has_disability=?, disability_type=?, disability_medical_report_path=?, application_form_path=?, national_id_path=?, academic_transcripts_path=?, other_documents_path=?, salary=?, role=?, updated_at=NOW() WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssssssssssdsi", $full_name, $date_of_birth, $email, $phone_number, $nin_number, $db_file_paths['photo'], $subject_specialization, $join_date, $address, $has_disability, $disability_type, $db_file_paths['disability_medical_report_path'], $db_file_paths['application_form_path'], $db_file_paths['national_id_path'], $db_file_paths['academic_transcripts_path'], $db_file_paths['other_documents_path'], $salary, $role, $teacher_id);
            } else {
                $hashed_password = password_hash($password_provided, PASSWORD_DEFAULT);
                $sql = "INSERT INTO teachers (full_name, date_of_birth, email, phone_number, nin_number, photo, subject_specialization, join_date, address, has_disability, disability_type, disability_medical_report_path, application_form_path, national_id_path, academic_transcripts_path, other_documents_path, salary, password, role, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssssssssssdss", $full_name, $date_of_birth, $email, $phone_number, $nin_number, $db_file_paths['photo'], $subject_specialization, $join_date, $address, $has_disability, $disability_type, $db_file_paths['disability_medical_report_path'], $db_file_paths['application_form_path'], $db_file_paths['national_id_path'], $db_file_paths['academic_transcripts_path'], $db_file_paths['other_documents_path'], $salary, $hashed_password, $role);
            }

            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Teacher '" . htmlspecialchars($full_name) . "' " . ($is_edit ? "updated" : "added") . " successfully!";
                unset($_SESSION['form_data']);
            } else {
                $_SESSION['error_message'] = "Error " . ($is_edit ? "updating" : "adding") . " teacher: " . $stmt->error;
                 if (!$is_edit) { /* Attempt to cleanup newly uploaded files on add failure */
                    foreach ($db_file_paths as $path_val) { if ($path_val && file_exists($path_val)) unlink($path_val); }
                 }
                $_SESSION['form_data'] = $_POST;
                header('Location: teachers_management.php' . ($is_edit ? '?edit_attempt=' . $teacher_id : '?add_attempt=1')); exit;
            }
            $stmt->close();
            header('Location: teachers_management.php'); exit;

        case 'delete_teacher':
            $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            if (!$teacher_id) { $_SESSION['error_message'] = "Invalid teacher ID."; header('Location: teachers_management.php'); exit; }
            
            $stmt_files = $conn->prepare("SELECT photo, disability_medical_report_path, application_form_path, national_id_path, academic_transcripts_path, other_documents_path, full_name FROM teachers WHERE id = ?");
            $stmt_files->bind_param("i", $teacher_id);
            $stmt_files->execute();
            $teacher_data_del_res = $stmt_files->get_result();
            $teacher_data_del = $teacher_data_del_res ? $teacher_data_del_res->fetch_assoc() : null;
            $stmt_files->close();

            $stmt_del = $conn->prepare("DELETE FROM teachers WHERE id = ?");
            $stmt_del->bind_param("i", $teacher_id);
            if ($stmt_del->execute()) {
                if($teacher_data_del) { 
                    foreach ($upload_paths as $key_path => $value_path) { // Use keys from $upload_paths to check $teacher_data_del
                        if (!empty($teacher_data_del[$key_path]) && file_exists($teacher_data_del[$key_path])) {
                            unlink($teacher_data_del[$key_path]);
                        }
                    }
                }
                $_SESSION['success_message'] = "Teacher '" . htmlspecialchars($teacher_data_del['full_name'] ?? "ID: $teacher_id") . "' deleted.";
            } else { $_SESSION['error_message'] = "Error deleting teacher: " . $stmt_del->error; }
            $stmt_del->close();
            header('Location: teachers_management.php'); exit;

        case 'update_password':
            $teacher_id = filter_input(INPUT_POST, 'teacher_id_password', FILTER_VALIDATE_INT);
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_new_password'];
            if (!$teacher_id) $_SESSION['error_message'] = "Invalid ID for password update.";
            elseif (empty($new_password) || strlen($new_password) < 8) $_SESSION['error_message'] = "Password min 8 characters.";
            elseif ($new_password !== $confirm_password) $_SESSION['error_message'] = "Passwords do not match.";
            else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt_pass = $conn->prepare("UPDATE teachers SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt_pass->bind_param("si", $hashed_password, $teacher_id);
                if ($stmt_pass->execute()) $_SESSION['success_message'] = "Password updated for teacher ID: $teacher_id.";
                else $_SESSION['error_message'] = "Error updating password: " . $stmt_pass->error;
                $stmt_pass->close();
            }
            header('Location: teachers_management.php'); exit;
        default:
             if (!empty($action)) $_SESSION['error_message'] = "Invalid POST action: " . htmlspecialchars($action);
             header('Location: teachers_management.php'); exit;
    }
}
// --- END OF PHP PROCESSING LOGIC ---

// Fetch teachers for display (using $conn from db_config.php)
$teachers_display_list = [];
if ($conn) { // Check if $conn is valid before querying
    $teachers_result_display = $conn->query("SELECT id, full_name, email, phone_number, nin_number, photo, subject_specialization, salary, role FROM teachers ORDER BY full_name ASC");
    if ($teachers_result_display && $teachers_result_display->num_rows > 0) {
        while ($row = $teachers_result_display->fetch_assoc()) {
            $teachers_display_list[] = $row;
        }
    }
} else {
    $_SESSION['error_message'] = ($_SESSION['error_message'] ?? '') . "<br>Database connection is not available to fetch teachers list.";
}


$success_message = get_session_value('success_message');
$error_message = get_session_value('error_message');
$form_data_session = get_session_value('form_data', []); // Renamed to avoid conflict

if ($success_message) unset($_SESSION['success_message']);
if ($error_message) unset($_SESSION['error_message']);
if (!empty($form_data_session)) unset($_SESSION['form_data']);

$auto_open_add_modal = isset($_GET['add_attempt']);
$auto_open_edit_modal_id = isset($_GET['edit_attempt']) ? (int)$_GET['edit_attempt'] : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Management - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <link href="style/styles.css" rel="stylesheet">
    <style>
        .wrapper { display: flex; min-height: 100vh; }
        #content { flex-grow: 1; padding: 1rem; } /* This is your main content area */
        .dashboard-content { padding: 1.5rem; background-color: #fff; border-radius: .5rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075); }
        
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); } /* For dark modal headers if you use them */
        .nav-tabs .nav-link { color: #495057; border-bottom-width: 2px;}
        .nav-tabs .nav-link.active { color: var(--bs-primary); border-color: var(--bs-primary) var(--bs-primary) #fff; font-weight: 500; }
        .tab-content { padding-top: 1rem; } /* Reduced padding a bit */
        .tab-content h5.text-muted { font-size: 0.95rem; font-weight: 500; border-bottom: 1px solid #eee; padding-bottom: 0.5rem; margin-top: 1rem; margin-bottom: 1rem;}
        .tab-content > .tab-pane > .row:first-child > h5.text-muted { margin-top: 0; } /* No top margin for first section header */


        .password-modal-profile { display: flex; align-items: center; margin-bottom: 15px; }
        .password-modal-profile img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px; border: 2px solid #eee;}
        .current-file-link a { word-break: break-all; font-size: 0.85em; }
        .photo-preview-modal { max-width: 120px; max-height: 120px; object-fit: cover; border: 1px solid #ddd; margin-top: 10px; border-radius: 4px;}
        
        .teacher-card .card-header { background-color: #0d6efd; color: white; }
        .teacher-card .card-footer { background-color: #f8f9fa; border-top: 1px solid #e9ecef;} /* Lighter footer */
        .teacher-card .teacher-info i { width: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar.php'; ?>

          <!-- <div id="content"> -->
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
                    <h2 class="mb-0">Teacher Management</h2>
                    <button class="btn btn-primary" onclick="prepareAddModal()">
                        <i class="fas fa-plus me-2"></i>Add Teacher
                    </button>
                </div>

                <!-- Stats Cards (Your original HTML) -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="stats-icon bg-primary"><i class="fas fa-chalkboard-teacher"></i></div>
                            <div class="stats-info"><h5>Total Teachers</h5><h3><?php echo count($teachers_display_list); ?></h3></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="stats-icon bg-success"><i class="fas fa-book"></i></div>
                            <div class="stats-info"><h5>Subjects Specializations</h5><h3 id="subjectsOfferedStat">0</h3></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="stats-icon bg-warning"><i class="fas fa-user-tie"></i></div>
                            <div class="stats-info"><h5>Active Roles</h5><h3 id="activeRolesStat">0</h3></div>
                        </div>
                    </div>
                </div>

                <!-- Search and Filter (Your original HTML) -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" id="searchBar" class="form-control global-search" placeholder="Search teachers by name, email, subject...">
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

                <!-- Teachers Grid (Your original HTML, adapted slightly for data consistency) -->
                <div class="row gy-4" id="teachersDisplayGrid">
                    <?php if (!empty($teachers_display_list)): ?>
                        <?php foreach ($teachers_display_list as $teacher_item): // Renamed $teacher to $teacher_item to avoid conflict ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 teacher-card-item" data-search-payload="<?php echo strtolower(htmlspecialchars($teacher_item['full_name'] . ' ' . $teacher_item['email'] . ' ' . ($teacher_item['subject_specialization'] ?? ''))); ?>">
                            <div class="card h-100 teacher-card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 fs-6"><?php echo htmlspecialchars($teacher_item['full_name']); ?></h5>
                                    <span class="badge bg-light text-dark small"><?php echo htmlspecialchars($teacher_item['subject_specialization'] ?: 'N/A'); ?></span>
                                </div>
                                <div class="card-body text-center">
                                    <div class="position-relative mb-3">
                                        <img src="<?php echo htmlspecialchars(!empty($teacher_item['photo']) ? $teacher_item['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($teacher_item['full_name']).'&background=random&color=fff&size=100'); ?>" 
                                             class="rounded-circle border" 
                                             style="width: 100px; height: 100px; object-fit: cover;"
                                             alt="<?php echo htmlspecialchars($teacher_item['full_name']); ?>">
                                        <span class="position-absolute bottom-0 end-0 bg-success rounded-circle p-1 border border-2 border-white" style="width: 15px; height: 15px; transform: translate(-5px, -5px);"></span>
                                    </div>
                                    <div class="teacher-info text-start small">
                                        <div class="mb-1"><i class="fas fa-envelope me-2 text-muted"></i><span><?php echo htmlspecialchars($teacher_item['email']); ?></span></div>
                                        <div class="mb-1"><i class="fas fa-phone me-2 text-muted"></i><span><?php echo htmlspecialchars($teacher_item['phone_number'] ?: 'N/A'); ?></span></div>
                                        <div class="mb-1"><i class="fas fa-id-card me-2 text-muted"></i><span>NIN: <?php echo htmlspecialchars($teacher_item['nin_number'] ?: 'N/A'); ?></span></div>
                                        <div class="mb-1"><i class="fas fa-money-bill-wave me-2 text-muted"></i><span>Salary: UGX <?php echo number_format($teacher_item['salary'] ?: 0, 0); ?></span></div>
                                    </div>
                                </div>
                                <div class="card-footer d-flex justify-content-around align-items-center py-2">
                                    <button class="btn btn-sm btn-outline-primary flex-fill me-1" onclick="prepareEditModal(<?php echo $teacher_item['id']; ?>)">
                                        <i class="fas fa-edit"></i> <span class="d-none d-md-inline">Edit</span>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary flex-fill mx-1" onclick="openPasswordModal(<?php echo $teacher_item['id']; ?>, '<?php echo htmlspecialchars(addslashes($teacher_item['full_name'])); ?>', '<?php echo htmlspecialchars(!empty($teacher_item['photo']) ? $teacher_item['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($teacher_item['full_name']).'&background=random&color=fff&size=50'); ?>')">
                                        <i class="fas fa-key"></i> <span class="d-none d-md-inline">Pwd</span>
                                    </button>
                                    <a href="teacher_details.php?id=<?php echo $teacher_item['id']; ?>" class="btn btn-sm btn-outline-info flex-fill mx-1" title="View Details">
                                        <i class="fas fa-eye"></i> <span class="d-none d-md-inline">Details</span>
                                    </a>
                                    <button class="btn btn-sm btn-outline-danger flex-fill ms-1" onclick="confirmDeleteDialog(<?php echo $teacher_item['id']; ?>, '<?php echo htmlspecialchars(addslashes($teacher_item['full_name'])); ?>')">
                                        <i class="fas fa-trash"></i> <span class="d-none d-md-inline">Del</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                         <div class="col-12"><div class="alert alert-info text-center">No teachers found. Please add one.</div></div>
                    <?php endif; ?>
                    <div class="col-12" id="noTeachersFoundMessage" style="display: none;">
                        <div class="alert alert-warning text-center">No teachers match your search criteria.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Universal Teacher Add/Edit Modal (modal-lg, refined tabs) -->
    <div class="modal fade" id="teacherFormModal" tabindex="-1" aria-labelledby="teacherFormModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"> <!-- Changed to modal-lg -->
            <div class="modal-content">
                <form id="teacherForm" action="teachers_management.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="form_action">
                    <input type="hidden" name="teacher_id" id="form_teacher_id">
                    <div class="modal-header bg-primary text-white"> <!-- Added bg for modal header -->
                        <h5 class="modal-title" id="teacherFormModalLabel">Teacher Information</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="nav nav-tabs mb-3" id="teacherFormTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal-tab-pane" type="button" role="tab"><i class="fas fa-user-circle me-1"></i>Personal</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact-tab-pane" type="button" role="tab"><i class="fas fa-address-book me-1"></i>Contact</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="professional-tab" data-bs-toggle="tab" data-bs-target="#professional-tab-pane" type="button" role="tab"><i class="fas fa-briefcase me-1"></i>Professional</button>
                            </li>
                             <li class="nav-item" role="presentation">
                                <button class="nav-link" id="disability-tab" data-bs-toggle="tab" data-bs-target="#disability-tab-pane" type="button" role="tab"><i class="fas fa-wheelchair me-1"></i>Disability</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents-tab-pane" type="button" role="tab"><i class="fas fa-folder-open me-1"></i>Documents</button>
                            </li>
                            <li class="nav-item" id="credentials-tab-item" role="presentation">
                                <button class="nav-link" id="credentials-tab" data-bs-toggle="tab" data-bs-target="#credentials-tab-pane" type="button" role="tab"><i class="fas fa-key me-1"></i>Credentials</button>
                            </li>
                        </ul>
                        <div class="tab-content" id="teacherFormTabsContent">
                            <div class="tab-pane fade show active" id="personal-tab-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Basic Details</h5>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6"><label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($form_data_session['full_name'] ?? ''); ?>" required></div>
                                    <div class="col-md-6"><label for="date_of_birth" class="form-label">Date of Birth</label><input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($form_data_session['date_of_birth'] ?? ''); ?>"></div>
                                </div>
                                <h5 class="text-muted">Location</h5>
                                <div class="row g-3">
                                    <div class="col-md-12"><label for="address" class="form-label">Residential Address</label><textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($form_data_session['address'] ?? ''); ?></textarea></div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="contact-tab-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Contact Information</h5>
                                <div class="row g-3">
                                     <div class="col-md-6"><label for="email" class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($form_data_session['email'] ?? ''); ?>" required></div>
                                    <div class="col-md-6"><label for="phone_number" class="form-label">Phone <span class="text-danger">*</span></label><input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($form_data_session['phone_number'] ?? ''); ?>" required></div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="professional-tab-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Professional Details</h5>
                                <div class="row g-3">
                                     <div class="col-md-6"><label for="nin_number" class="form-label">NIN Number</label><input type="text" class="form-control" id="nin_number" name="nin_number" value="<?php echo htmlspecialchars($form_data_session['nin_number'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="join_date" class="form-label">Joining Date</label><input type="date" class="form-control" id="join_date" name="join_date" value="<?php echo htmlspecialchars($form_data_session['join_date'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="subject_specialization" class="form-label">Subject Specialization</label><input type="text" class="form-control" id="subject_specialization" name="subject_specialization" value="<?php echo htmlspecialchars($form_data_session['subject_specialization'] ?? ''); ?>" placeholder="e.g., Maths, Physics"></div>
                                    <div class="col-md-6"><label for="salary" class="form-label">Salary (UGX)</label><input type="number" step="0.01" class="form-control" id="salary" name="salary" value="<?php echo htmlspecialchars($form_data_session['salary'] ?? ''); ?>"></div>
                                    <div class="col-md-6"><label for="role" class="form-label">Role <span class="text-danger">*</span></label><input type="text" class="form-control" id="role" name="role" value="<?php echo htmlspecialchars($form_data_session['role'] ?? 'teacher'); ?>" required></div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="disability-tab-pane" role="tabpanel" tabindex="0">
                                 <h5 class="text-muted">Disability Information</h5>
                                 <div class="row g-3">
                                    <div class="col-md-4"><label for="has_disability" class="form-label">Has Disability?</label><select class="form-select" id="has_disability" name="has_disability" onchange="toggleDisabilityFieldsOnForm(this.value)"><option value="No" <?php echo ((isset($form_data_session['has_disability']) && $form_data_session['has_disability'] == 'No') || !isset($form_data_session['has_disability'])) ? 'selected' : ''; ?>>No</option><option value="Yes" <?php echo (isset($form_data_session['has_disability']) && $form_data_session['has_disability'] == 'Yes') ? 'selected' : ''; ?>>Yes</option></select></div>
                                    <div class="col-md-8 form_disability_type_field" style="display:none;"><label for="disability_type" class="form-label">Type of Disability</label><input type="text" class="form-control" id="disability_type" name="disability_type" value="<?php echo htmlspecialchars($form_data_session['disability_type'] ?? ''); ?>"></div>
                                    <div class="col-md-12 form_disability_report_field" style="display:none;"><label for="disability_medical_report_path" class="form-label">Medical Report</label><input type="file" class="form-control" id="disability_medical_report_path" name="disability_medical_report_path" accept=".pdf,.jpg,.png"><small id="current_disability_medical_report_path" class="form-text text-muted current-file-link"></small></div>
                                 </div>
                            </div>
                            <div class="tab-pane fade" id="documents-tab-pane" role="tabpanel" tabindex="0">
                                <h5 class="text-muted">Document Uploads</h5>
                                <div class="row g-3">
                                    <div class="col-md-6"><label for="photo" class="form-label">Photo</label><input type="file" class="form-control" id="photo" name="photo" accept="image/*" onchange="previewModalImage(this, 'photo_preview_modal')"><img id="photo_preview_modal" src="#" alt="Preview" class="photo-preview-modal mt-2" style="display:none;"/><small id="current_photo" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-6"><label for="application_form_path" class="form-label">Application Form (PDF)</label><input type="file" class="form-control" id="application_form_path" name="application_form_path" accept=".pdf"><small id="current_application_form_path" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-6"><label for="national_id_path" class="form-label">National ID (PDF/Img)</label><input type="file" class="form-control" id="national_id_path" name="national_id_path" accept=".pdf,.jpg,.png"><small id="current_national_id_path" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-6"><label for="academic_transcripts_path" class="form-label">Transcripts (PDF)</label><input type="file" class="form-control" id="academic_transcripts_path" name="academic_transcripts_path" accept=".pdf"><small id="current_academic_transcripts_path" class="form-text text-muted current-file-link"></small></div>
                                    <div class="col-md-12"><label for="other_documents_path" class="form-label">Other Docs (PDF/ZIP)</label><input type="file" class="form-control" id="other_documents_path" name="other_documents_path" accept=".pdf,.zip"><small id="current_other_documents_path" class="form-text text-muted current-file-link"></small></div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="credentials-tab-pane" role="tabpanel" tabindex="0">
                                 <h5 class="text-muted">Account Credentials</h5>
                                 <div class="row g-3">
                                    <div class="col-md-8"><label for="password_form_field" class="form-label">Password <span class="text-danger">*</span></label><div class="input-group"><input type="password" class="form-control" id="password_form_field" name="password" minlength="8"><button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('password_form_field')"><i class="fas fa-eye"></i></button></div></div>
                                    <div class="col-md-4 align-self-end"><button type="button" class="btn btn-secondary w-100" onclick="generateMACOMPassword('password_form_field')"><i class="fas fa-cogs me-1"></i>Generate</button></div>
                                 </div><small class="form-text text-muted">For new teachers. Min 8 chars.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveTeacherBtn"><i class="fas fa-save me-2"></i>Save Teacher</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Password Management Modal -->
    <div class="modal fade" id="passwordUpdateModal" tabindex="-1" aria-labelledby="passwordUpdateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="passwordUpdateModalLabel"><i class="fas fa-shield-alt me-2"></i>Update Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="passwordUpdateForm" action="teachers_management.php" method="POST">
                    <input type="hidden" name="action" value="update_password">
                    <input type="hidden" name="teacher_id_password" id="password_update_teacher_id">
                    <div class="modal-body">
                        <div class="password-modal-profile">
                            <img id="password_modal_teacher_photo" src="" alt="Photo">
                            <span id="password_modal_teacher_name"></span>
                        </div>
                        <div class="mb-3"><label for="new_password_update" class="form-label">New Password <span class="text-danger">*</span></label><div class="input-group"><input type="password" class="form-control" id="new_password_update" name="new_password" required minlength="8"><button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('new_password_update')"><i class="fas fa-eye"></i></button></div></div>
                        <div class="mb-3"><label for="confirm_new_password_update" class="form-label">Confirm <span class="text-danger">*</span></label><input type="password" class="form-control" id="confirm_new_password_update" name="confirm_new_password" required minlength="8"></div>
                        <button type="button" class="btn btn-sm btn-secondary w-100" onclick="generateMACOMPassword('new_password_update', 'confirm_new_password_update')"><i class="fas fa-cogs me-1"></i>Generate MACOM Password</button>
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
        const teacherFormModalEl = document.getElementById('teacherFormModal');
        const teacherFormModalInstance = new bootstrap.Modal(teacherFormModalEl);
        const teacherForm = document.getElementById('teacherForm');
        const formActionInput = document.getElementById('form_action');
        const formTeacherIdInput = document.getElementById('form_teacher_id');
        const teacherFormModalLabel = document.getElementById('teacherFormModalLabel');
        const saveTeacherBtn = document.getElementById('saveTeacherBtn');
        const credentialsTabItem = document.getElementById('credentials-tab-item');
        const passwordInputOnForm = document.getElementById('password_form_field'); // ID changed for clarity

        function prepareAddModal() {
            teacherForm.reset();
            formActionInput.value = 'add_teacher';
            formTeacherIdInput.value = '';
            teacherFormModalLabel.textContent = 'Add New Teacher';
            saveTeacherBtn.innerHTML = '<i class="fas fa-user-plus me-2"></i>Add Teacher';
            saveTeacherBtn.className = 'btn btn-primary';
            if(credentialsTabItem) credentialsTabItem.style.display = 'list-item';
            if(passwordInputOnForm) passwordInputOnForm.required = true;
            document.querySelectorAll('.current-file-link').forEach(el => el.innerHTML = '');
            const photoPreview = document.getElementById('photo_preview_modal');
            if(photoPreview) { photoPreview.style.display = 'none'; photoPreview.src = '#'; }
            toggleDisabilityFieldsOnForm('No');
            // Ensure first tab is active
            const firstTabEl = document.getElementById('personal-tab');
            if(firstTabEl) new bootstrap.Tab(firstTabEl).show();
            teacherFormModalInstance.show();
        }

        async function prepareEditModal(teacherId) {
            console.log("prepareEditModal called with ID:", teacherId);
            teacherForm.reset();
            try {
                const fetchURL = `teachers_management.php?action=get_teacher&id=${teacherId}`;
                console.log("Fetching from URL:", fetchURL);
                const response = await fetch(fetchURL);
                console.log("Fetch response status:", response.status, "Type:", response.headers.get('Content-Type'));

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error("Fetch error! Status:", response.status, "Response text:", errorText);
                    alert(`Error fetching teacher data. Server responded with status ${response.status}. Check console.`);
                    return;
                }
                const teacher = await response.json();
                console.log("Fetched teacher data:", teacher);

                if (teacher && !teacher.error) {
                    formActionInput.value = 'edit_teacher';
                    formTeacherIdInput.value = teacher.id;
                    teacherFormModalLabel.textContent = 'Edit Teacher: ' + (teacher.full_name || 'N/A');
                    saveTeacherBtn.innerHTML = '<i class="fas fa-save me-2"></i>Update Teacher';
                    saveTeacherBtn.className = 'btn btn-success';

                    document.getElementById('full_name').value = teacher.full_name || '';
                    document.getElementById('date_of_birth').value = teacher.date_of_birth || '';
                    document.getElementById('address').value = teacher.address || '';
                    document.getElementById('email').value = teacher.email || '';
                    document.getElementById('phone_number').value = teacher.phone_number || '';
                    document.getElementById('nin_number').value = teacher.nin_number || '';
                    document.getElementById('join_date').value = teacher.join_date || '';
                    document.getElementById('subject_specialization').value = teacher.subject_specialization || '';
                    document.getElementById('salary').value = teacher.salary || '';
                    document.getElementById('role').value = teacher.role || 'teacher';
                    
                    const hasDisabilityVal = teacher.has_disability || 'No';
                    document.getElementById('has_disability').value = hasDisabilityVal;
                    toggleDisabilityFieldsOnForm(hasDisabilityVal);
                    document.getElementById('disability_type').value = teacher.disability_type || '';

                    const fileFields = {
                        'photo': 'current_photo', 'disability_medical_report_path': 'current_disability_medical_report_path',
                        'application_form_path': 'current_application_form_path', 'national_id_path': 'current_national_id_path',
                        'academic_transcripts_path': 'current_academic_transcripts_path', 'other_documents_path': 'current_other_documents_path'
                    };
                    for (const [dbField, displayId] of Object.entries(fileFields)) {
                        const displayElement = document.getElementById(displayId);
                        const photoPreviewEl = document.getElementById('photo_preview_modal');
                        if (teacher[dbField]) {
                            if (dbField === 'photo' && photoPreviewEl) { photoPreviewEl.src = teacher[dbField]; photoPreviewEl.style.display = 'block'; }
                            if(displayElement) displayElement.innerHTML = `Current: <a href="${teacher[dbField]}" target="_blank">${teacher[dbField].split('/').pop()}</a>`;
                        } else {
                            if (dbField === 'photo' && photoPreviewEl) { photoPreviewEl.style.display = 'none'; photoPreviewEl.src = '#'; }
                            if(displayElement) displayElement.innerHTML = '';
                        }
                    }
                    if(credentialsTabItem) credentialsTabItem.style.display = 'none';
                    if(passwordInputOnForm) passwordInputOnForm.required = false;
                    const firstTabEl = document.getElementById('personal-tab');
                    if(firstTabEl) new bootstrap.Tab(firstTabEl).show();
                    teacherFormModalInstance.show();
                } else { 
                    console.error('Error in fetched data:', teacher.error || 'Data format error');
                    alert('Error using fetched teacher data: ' + (teacher.error || 'Format Error')); 
                }
            } catch (error) { 
                console.error('JS catch in prepareEditModal:', error); 
                alert('JS error preparing edit form. Check console.'); 
            }
        }

        function generateMACOMPassword(passwordFieldId, confirmFieldId = null) {
            const randomPartLength = 6;
            const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789*#@&'; // Simplified for less confusion
            let randomPart = '';
            for (let i = 0; i < randomPartLength; i++) randomPart += characters.charAt(Math.floor(Math.random() * characters.length));
            const newPassword = `MACOM-TEACHER-${randomPart}`;
            const passField = document.getElementById(passwordFieldId);
            passField.value = newPassword;
            if (passField.type === 'password') { passField.type = 'text'; setTimeout(() => { passField.type = 'password'; }, 2000); }
            if (confirmFieldId) document.getElementById(confirmFieldId).value = newPassword;
        }

        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            const group = field.closest('.input-group');
            if (!group) return; const icon = group.querySelector('button i'); if (!icon) return;
            field.type = (field.type === "password") ? "text" : "password";
            icon.classList.toggle('fa-eye'); icon.classList.toggle('fa-eye-slash');
        }
        
        function toggleDisabilityFieldsOnForm(value) {
            const typeFieldDiv = document.querySelector('.form_disability_type_field');
            const reportFieldDiv = document.querySelector('.form_disability_report_field');
            const typeInput = document.getElementById('disability_type');
            const displayStyle = (value === 'Yes') ? 'block' : 'none';
            if(typeFieldDiv) typeFieldDiv.style.display = displayStyle;
            if(reportFieldDiv) reportFieldDiv.style.display = displayStyle;
            if(typeInput) typeInput.required = (value === 'Yes');
            if(value !== 'Yes' && typeInput) typeInput.value = '';
        }
        <?php if (!empty($form_data_session['has_disability'])): ?>
        toggleDisabilityFieldsOnForm('<?php echo htmlspecialchars($form_data_session['has_disability']); ?>');
        <?php else: ?>
        toggleDisabilityFieldsOnForm('No');
        <?php endif; ?>

        function previewModalImage(input, previewElementId) {
            const preview = document.getElementById(previewElementId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            } else { preview.src = '#'; preview.style.display = 'none'; }
        }

        const passwordUpdateModalEl = document.getElementById('passwordUpdateModal');
        const passwordUpdateModalInstance = new bootstrap.Modal(passwordUpdateModalEl);
        function openPasswordModal(teacherId, teacherName, teacherPhoto) {
            document.getElementById('password_update_teacher_id').value = teacherId;
            document.getElementById('password_modal_teacher_name').textContent = teacherName;
            document.getElementById('password_modal_teacher_photo').src = teacherPhoto || `https://ui-avatars.com/api/?name=${encodeURIComponent(teacherName)}&size=50&background=random`;
            document.getElementById('passwordUpdateForm').reset(); // Reset password fields
            passwordUpdateModalInstance.show();
        }
        
        document.getElementById('passwordUpdateForm')?.addEventListener('submit', function(event) {
            const newP = document.getElementById('new_password_update').value;
            const confP = document.getElementById('confirm_new_password_update').value;
            if (newP !== confP) { alert("Passwords don't match!"); event.preventDefault(); }
            else if (newP.length < 8) { alert("Password min 8 chars."); event.preventDefault(); }
        });

        function confirmDeleteDialog(teacherId, teacherName) {
            if (confirm(`Delete teacher: ${teacherName} (ID: ${teacherId})? This is irreversible.`)) {
                const form = document.createElement('form');
                form.method = 'POST'; form.action = 'teachers_management.php';
                form.innerHTML = `<input type="hidden" name="action" value="delete_teacher"><input type="hidden" name="teacher_id" value="${teacherId}">`;
                document.body.appendChild(form); form.submit();
            }
        }

        <?php if ($auto_open_add_modal && !empty($form_data_session)): ?>
        document.addEventListener('DOMContentLoaded', () => prepareAddModal());
        <?php elseif ($auto_open_edit_modal_id): // For edit, repopulation happens in prepareEditModal using form_data_session ?>
        document.addEventListener('DOMContentLoaded', () => prepareEditModal(<?php echo $auto_open_edit_modal_id; ?>));
        <?php endif; ?>

        teacherFormModalEl.addEventListener('hidden.bs.modal', function () {
            teacherForm.reset();
            const photoPreview = document.getElementById('photo_preview_modal');
            if(photoPreview) { photoPreview.style.display = 'none'; photoPreview.src = '#'; }
            document.querySelectorAll('.current-file-link').forEach(el => el.innerHTML = '');
            toggleDisabilityFieldsOnForm('No');
            const firstTabEl = document.getElementById('personal-tab');
            if (firstTabEl) new bootstrap.Tab(firstTabEl).show();
        });

        document.getElementById('searchBar')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const teacherCards = document.querySelectorAll('#teachersDisplayGrid .teacher-card-item');
            let visibleCount = 0;
            teacherCards.forEach(card => {
                const searchPayload = card.dataset.searchPayload || "";
                card.style.display = searchPayload.includes(searchTerm) ? '' : 'none';
                if (card.style.display !== 'none') visibleCount++;
            });
            document.getElementById('noTeachersFoundMessage').style.display = visibleCount === 0 && searchTerm !== '' ? 'block' : 'none';
        });

document.addEventListener('DOMContentLoaded', function() {
    const uniqueSubjects = new Set();
    document.querySelectorAll('#teachersDisplayGrid .teacher-card .badge').forEach(badge => {
        if (badge.textContent.trim() !== 'N/A') {
            uniqueSubjects.add(badge.textContent.trim());
        }
    });
    document.getElementById('subjectsOfferedStat').textContent = uniqueSubjects.size;

    const uniqueRoles = new Set();
    document.querySelectorAll('#teachersDisplayGrid .teacher-card-item').forEach(card => {
        const role = card.querySelector('.teacher-info span:last-child')?.textContent.trim();
        if (role) uniqueRoles.add(role);
    });
    document.getElementById('activeRolesStat').textContent = uniqueRoles.size;
});

window.prepareEditModal = prepareEditModal;
window.openPasswordModal = openPasswordModal;
    </script>
</body>
</html>