<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../db_config.php';
require_once '../auth.php'; // Connects to DB, starts session

check_auth();

// Role check (keep your original logic)
if ($_SESSION['role'] !== 'head_teacher' && $_SESSION['role'] !== 'director_of_studies') {
    // For production, uncomment and use:
     $_SESSION['error_message_cm'] = "You don't have permission to access this page";
      header("Location: dashboard.php");
    // exit();
    echo "<div class='alert alert-warning p-2 m-2'><strong>Dev Mode:</strong> Role check bypassed.</div>";
}



// --- Helper & Data Fetching Functions (same as your previous complete version) ---
function get_current_academic_year_info_cm($conn) { /* ... */ 
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    return null;
}
$current_academic_year_info = null; $current_academic_year_id = null; $current_academic_year_name = 'N/A';
if ($conn) { $current_academic_year_info = get_current_academic_year_info_cm($conn); if ($current_academic_year_info) { $current_academic_year_id = $current_academic_year_info['id']; $current_academic_year_name = $current_academic_year_info['year_name']; }}
else { $_SESSION['error_message_cm'] = "Database connection failed."; }
if ($conn && !$current_academic_year_id && !isset($_SESSION['error_message_cm'])) { $_SESSION['warning_message_cm'] = "Current academic year is not set. Some functionalities might be limited."; }

// --- AJAX ACTION HANDLING (Same as your previous complete version) ---
$requested_action = $_REQUEST['action'] ?? null;
if ($requested_action) {
    // ... (Keep the entire AJAX switch block from the previous full version) ...
    // Make sure it's complete and correct
    header('Content-Type: application/json');
    $ajax_response = ['success' => false, 'message' => 'Invalid action or database connection error.'];
    if (!$conn) { echo json_encode($ajax_response); exit; }
    switch ($requested_action) {
        case 'get_class_details': 
            $class_id_ajax = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
            if (!$class_id_ajax) { $ajax_response['message'] = 'Invalid Class ID.'; break; }
            $stmt_class = $conn->prepare("SELECT c.*, t.full_name as class_teacher_name FROM classes c LEFT JOIN teachers t ON c.class_teacher_id = t.id WHERE c.id = ?");
            if ($stmt_class) {
                $stmt_class->bind_param("i", $class_id_ajax); $stmt_class->execute();
                $result_class = $stmt_class->get_result();
                if ($class_data = $result_class->fetch_assoc()) {
                    $subjects_assigned = [];
                    $stmt_subjects = $conn->prepare("SELECT s.id, s.subject_name FROM class_subjects cs JOIN subjects s ON cs.subject_id = s.id WHERE cs.class_id = ? ORDER BY s.subject_name");
                    if($stmt_subjects){
                        $stmt_subjects->bind_param("i", $class_id_ajax); $stmt_subjects->execute();
                        $res_subjects = $stmt_subjects->get_result();
                        while($row_s = $res_subjects->fetch_assoc()){ $subjects_assigned[] = $row_s; } 
                        $stmt_subjects->close();
                    }
                    $class_data['assigned_subjects'] = $subjects_assigned; 
                    $ajax_response = ['success' => true, 'class_data' => $class_data];
                } else { $ajax_response['message'] = 'Class not found.'; }
                $stmt_class->close();
            } else { $ajax_response['message'] = 'DB query error for class details: ' . $conn->error;}
            break;
        case 'get_students_in_class': 
            $class_id_ajax = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
            $academic_year_id_ajax = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);
            if (!$class_id_ajax || !$academic_year_id_ajax) { $ajax_response['message'] = 'Missing Class ID or Academic Year ID.'; break; }
            $students_in_class = [];
            $sql_students = "SELECT s.id, s.admission_number, s.full_name, s.photo, s.sex FROM students s JOIN student_enrollments se ON s.id = se.student_id WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status IN ('enrolled', 'promoted', 'repeated') ORDER BY s.full_name ASC";
            $stmt_students = $conn->prepare($sql_students);
            if($stmt_students){
                $stmt_students->bind_param("ii", $class_id_ajax, $academic_year_id_ajax); $stmt_students->execute();
                $result_students = $stmt_students->get_result();
                while($student_row = $result_students->fetch_assoc()){ $students_in_class[] = $student_row; }
                $stmt_students->close();
                $ajax_response = ['success' => true, 'students' => $students_in_class];
            } else { $ajax_response['message'] = 'Failed to prepare student list query: ' . $conn->error; }
            break;
        case 'search_student_for_class_add':
            $searchTerm_ajax = trim($conn->real_escape_string($_POST['searchTerm'] ?? ''));
            if (empty($searchTerm_ajax)) { $ajax_response['message'] = 'Search term is empty.'; break; }
            $student_data_ajax = null;
            $sql_search_student = "SELECT s.id, s.full_name, s.admission_number, s.sex, s.photo, s.current_class_id, c.class_name as current_class_name 
                                   FROM students s 
                                   LEFT JOIN classes c ON s.current_class_id = c.id
                                   WHERE s.admission_number = ? OR s.full_name LIKE ?
                                   LIMIT 1";
            $stmt_search = $conn->prepare($sql_search_student);
            if ($stmt_search) {
                $name_like = "%" . $searchTerm_ajax . "%";
                $stmt_search->bind_param("ss", $searchTerm_ajax, $name_like); $stmt_search->execute();
                $result_search = $stmt_search->get_result();
                if ($found_student = $result_search->fetch_assoc()) {
                    $student_data_ajax = $found_student;
                }
                $stmt_search->close();
            } else { $ajax_response['message'] = "DB error searching student: " . $conn->error; break; }
            if ($student_data_ajax) $ajax_response = ['success' => true, 'student' => $student_data_ajax];
            else $ajax_response['message'] = "No student found matching '" . htmlspecialchars($searchTerm_ajax) . "'.";
            break;
        case 'add_student_to_class_enrollment':
            $student_id_enroll = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $class_id_enroll = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $academic_year_id_enroll = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
            if (!$student_id_enroll || !$class_id_enroll || !$academic_year_id_enroll) { $ajax_response['message'] = 'Missing data for enrollment.'; break; }
            $conn->begin_transaction();
            try {
                $stmt_update_student = $conn->prepare("UPDATE students SET current_class_id = ? WHERE id = ?");
                if (!$stmt_update_student) throw new Exception("Prepare student update failed: ".$conn->error);
                $stmt_update_student->bind_param("ii", $class_id_enroll, $student_id_enroll);
                if (!$stmt_update_student->execute()) throw new Exception("Execute student update failed: ".$stmt_update_student->error);
                $stmt_update_student->close();
                $stmt_deactivate_old = $conn->prepare("UPDATE student_enrollments SET status = 'transferred_out' WHERE student_id = ? AND academic_year_id = ? AND class_id != ? AND status IN ('enrolled', 'promoted', 'repeated')");
                if (!$stmt_deactivate_old) throw new Exception("Prepare deactivate old enrollments: ".$conn->error);
                $stmt_deactivate_old->bind_param("iii", $student_id_enroll, $academic_year_id_enroll, $class_id_enroll);
                if (!$stmt_deactivate_old->execute()) throw new Exception("Execute deactivate old enrollments: ".$stmt_deactivate_old->error);
                $stmt_deactivate_old->close();
                $existing_enrollment_id = null;
                $stmt_check_exist = $conn->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND class_id = ? AND academic_year_id = ?");
                if ($stmt_check_exist) { $stmt_check_exist->bind_param("iii", $student_id_enroll, $class_id_enroll, $academic_year_id_enroll); $stmt_check_exist->execute(); $stmt_check_exist->bind_result($existing_enrollment_id); $stmt_check_exist->fetch(); $stmt_check_exist->close(); } 
                else throw new Exception("Prepare check existing enrollment: ".$conn->error);
                if ($existing_enrollment_id) {
                    $stmt_update_enroll = $conn->prepare("UPDATE student_enrollments SET status = 'enrolled', enrollment_date = CURDATE() WHERE id = ?");
                    if (!$stmt_update_enroll) throw new Exception("Prepare update enrollment: ".$conn->error);
                    $stmt_update_enroll->bind_param("i", $existing_enrollment_id);
                    if (!$stmt_update_enroll->execute()) throw new Exception("Execute update enrollment: ".$stmt_update_enroll->error);
                    $stmt_update_enroll->close();
                } else {
                    $stmt_insert_enroll = $conn->prepare("INSERT INTO student_enrollments (student_id, class_id, academic_year_id, enrollment_date, status) VALUES (?, ?, ?, CURDATE(), 'enrolled')");
                    if (!$stmt_insert_enroll) throw new Exception("Prepare insert enrollment: ".$conn->error);
                    $stmt_insert_enroll->bind_param("iii", $student_id_enroll, $class_id_enroll, $academic_year_id_enroll);
                    if (!$stmt_insert_enroll->execute()) throw new Exception("Execute insert enrollment: ".$stmt_insert_enroll->error);
                    $stmt_insert_enroll->close();
                }
                $conn->commit(); $ajax_response = ['success' => true, 'message' => 'Student successfully added/updated in class.'];
            } catch (Exception $e) { $conn->rollback(); $ajax_response['message'] = "Error enrolling student: " . $e->getMessage(); error_log("Add student to class enrollment error: " . $e->getMessage()); }
            break;
        default: $ajax_response['message'] = 'Unknown AJAX action requested.'; break;
    }
    echo json_encode($ajax_response); exit;
}

// --- POST NON-AJAX FORM HANDLING (Same as your previous complete version) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") { 
    // ... (Keep the entire POST handling block from the previous full version for add_edit_class_action, assign_class_teacher_to_existing, assign_subjects_to_class_action)
    // Ensure it uses $conn and $_SESSION['error_message_cm'], $_SESSION['success_message_cm']
    if (isset($_POST['add_edit_class_action'])) { 
        $class_id_form = filter_input(INPUT_POST, 'class_id_form', FILTER_VALIDATE_INT);
        $class_name_form = trim($conn->real_escape_string($_POST['class_name_form']));
        $class_level_form = $conn->real_escape_string($_POST['class_level_form']);
        $class_teacher_id_form = !empty($_POST['class_teacher_id_form']) ? (int)$_POST['class_teacher_id_form'] : null;
        $errors_form = [];
        if (empty($class_name_form)) $errors_form[] = "Class Name is required.";
        if (empty($class_level_form)) $errors_form[] = "Class Level is required.";
        if (!in_array($class_level_form, ['O-Level', 'A-Level'])) $errors_form[] = "Invalid Class Level.";
        
        $stmt_check_class = null;
        if ($class_id_form) { $stmt_check_class = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND id != ?"); if($stmt_check_class) $stmt_check_class->bind_param("si", $class_name_form, $class_id_form); }
        else { $stmt_check_class = $conn->prepare("SELECT id FROM classes WHERE class_name = ?"); if($stmt_check_class) $stmt_check_class->bind_param("s", $class_name_form); }
        $can_proceed = true;
        if ($stmt_check_class) {
            $stmt_check_class->execute(); $stmt_check_class->store_result();
            if ($stmt_check_class->num_rows > 0) { $_SESSION['error_message_cm'] = "A class with name '".htmlspecialchars($class_name_form)."' already exists."; $can_proceed = false; }
            $stmt_check_class->close();
        } else { $_SESSION['error_message_cm'] = "DB error checking class name."; $can_proceed = false; }

        if ($can_proceed) {
            if ($class_id_form) {
                $stmt_update = $conn->prepare("UPDATE classes SET class_name = ?, class_level = ?, class_teacher_id = ? WHERE id = ?");
                if ($stmt_update) { $stmt_update->bind_param("ssii", $class_name_form, $class_level_form, $class_teacher_id_form, $class_id_form);
                    if ($stmt_update->execute()) $_SESSION['success_message_cm'] = "Class '".htmlspecialchars($class_name_form)."' updated.";
                    else $_SESSION['error_message_cm'] = "Error updating: ".$stmt_update->error; $stmt_update->close();
                } else $_SESSION['error_message_cm'] = "DB error prep update: ".$conn->error;
            } else {
                $stmt_insert = $conn->prepare("INSERT INTO classes (class_name, class_level, class_teacher_id) VALUES (?, ?, ?)");
                 if ($stmt_insert) { $stmt_insert->bind_param("ssi", $class_name_form, $class_level_form, $class_teacher_id_form);
                    if ($stmt_insert->execute()) $_SESSION['success_message_cm'] = "Class '".htmlspecialchars($class_name_form)."' added.";
                    else $_SESSION['error_message_cm'] = "Error adding: ".$stmt_insert->error; $stmt_insert->close();
                } else $_SESSION['error_message_cm'] = "DB error prep insert: ".$conn->error;
            }
        } elseif (empty($errors_form) && isset($_SESSION['error_message_cm'])) { /* Keep session error if already set */ }
        else { $_SESSION['error_message_cm'] = "Validation Errors:<br>" . implode("<br>", $errors_form); }
        header("Location: dos_class_management.php"); exit();
    }
    elseif (isset($_POST['assign_class_teacher_to_existing'])) { 
        $class_id_assign_teacher = (int)$_POST['class_id_for_teacher_assign'];
        $teacher_id_assign_teacher = (int)$_POST['teacher_id_assign'];
        $stmt_assign = $conn->prepare("UPDATE classes SET class_teacher_id = ? WHERE id = ?");
        if ($stmt_assign) { $stmt_assign->bind_param("ii", $teacher_id_assign_teacher, $class_id_assign_teacher);
            if ($stmt_assign->execute()) $_SESSION['success_message_cm'] = "Class teacher assigned.";
            else $_SESSION['error_message_cm'] = "Error assigning teacher: " . $stmt_assign->error;
            $stmt_assign->close();
        } else $_SESSION['error_message_cm'] = "DB error prep teacher assign: " . $conn->error;
        header("Location: dos_class_management.php"); exit();
    }
    elseif (isset($_POST['assign_subjects_to_class_action'])) { 
        $class_id_assign_subjects = (int)$_POST['class_id_for_subject_assign'];
        $subject_ids_form = $_POST['subject_ids_assign'] ?? [];
        $subject_ids_clean = array_filter(array_map('intval', $subject_ids_form));
        if ($class_id_assign_subjects > 0) {
            $conn->begin_transaction();
            try {
                $stmt_del = $conn->prepare("DELETE FROM class_subjects WHERE class_id = ?");
                if (!$stmt_del) throw new Exception("Prep del failed: " . $conn->error);
                $stmt_del->bind_param("i", $class_id_assign_subjects);
                if (!$stmt_del->execute()) throw new Exception("Exec del failed: " . $stmt_del->error); $stmt_del->close();
                if (!empty($subject_ids_clean)) {
                    $stmt_ins = $conn->prepare("INSERT INTO class_subjects (class_id, subject_id) VALUES (?, ?)");
                    if (!$stmt_ins) throw new Exception("Prep ins failed: " . $conn->error);
                    foreach ($subject_ids_clean as $subject_id_single) {
                        $stmt_ins->bind_param("ii", $class_id_assign_subjects, $subject_id_single);
                        if (!$stmt_ins->execute()) throw new Exception("Exec ins for subj $subject_id_single: " . $stmt_ins->error);
                    } $stmt_ins->close();
                }
                $conn->commit(); $_SESSION['success_message_cm'] = "Subjects assigned.";
            } catch (Exception $e) { $conn->rollback(); $_SESSION['error_message_cm'] = "Error assigning subjects: " . $e->getMessage(); }
        } else $_SESSION['error_message_cm'] = "Invalid class for assigning subjects.";
        header("Location: dos_class_management.php"); exit();
    }
}

// --- Delete Class (GET request - ensure this is secured, e.g., with a confirmation token if needed) ---
if (isset($_GET['delete_class_id_action'])) { // Added _action to differentiate
    $class_id_delete = filter_input(INPUT_GET, 'class_id_to_delete', FILTER_VALIDATE_INT);
    if ($class_id_delete && $conn) { // Ensure $conn is valid
        // Add checks here: e.g., are students enrolled? If so, prevent deletion or require re-assignment.
        // For now, direct deletion:
        $conn->begin_transaction();
        try {
            // Delete from class_subjects first due to potential foreign key constraints
            $stmt_del_cs = $conn->prepare("DELETE FROM class_subjects WHERE class_id = ?");
            if (!$stmt_del_cs) throw new Exception("Prepare delete class_subjects failed: " . $conn->error);
            $stmt_del_cs->bind_param("i", $class_id_delete);
            if (!$stmt_del_cs->execute()) throw new Exception("Execute delete class_subjects failed: " . $stmt_del_cs->error);
            $stmt_del_cs->close();

            // Delete from student_enrollments (or update student's current_class_id to null, or re-assign)
            // For now, let's just delete enrollments for simplicity. In a real system, you'd need a strategy.
            $stmt_del_enroll = $conn->prepare("DELETE FROM student_enrollments WHERE class_id = ?");
            if(!$stmt_del_enroll) throw new Exception ("Prep delete enrollments failed: " . $conn->error);
            $stmt_del_enroll->bind_param("i", $class_id_delete);
            if(!$stmt_del_enroll->execute()) throw new Exception("Exec delete enrollments failed: " . $stmt_del_enroll->error);
            $stmt_del_enroll->close();

            // Update students who have this as current_class_id
            $stmt_update_students = $conn->prepare("UPDATE students SET current_class_id = NULL WHERE current_class_id = ?");
            if(!$stmt_update_students) throw new Exception ("Prep update students failed: " . $conn->error);
            $stmt_update_students->bind_param("i", $class_id_delete);
            if(!$stmt_update_students->execute()) throw new Exception("Exec update students failed: " . $stmt_update_students->error);
            $stmt_update_students->close();


            // Then delete the class itself
            $stmt_del_class = $conn->prepare("DELETE FROM classes WHERE id = ?");
            if (!$stmt_del_class) throw new Exception("Prepare delete class failed: " . $conn->error);
            $stmt_del_class->bind_param("i", $class_id_delete);
            if (!$stmt_del_class->execute()) throw new Exception("Execute delete class failed: " . $stmt_del_class->error . ". It might be in use by other records (e.g., teachers if not handled).");
            $stmt_del_class->close();
            
            $conn->commit();
            $_SESSION['success_message_cm'] = "Class and related assignments deleted successfully.";

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message_cm'] = "Error deleting class: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message_cm'] = "Invalid class ID for deletion or DB connection error.";
    }
    header("Location: dos_class_management.php");
    exit();
}


// --- Fetch Data for Page Display ---
$actual_classes_db = [];
if ($conn) {
    $query_actual_classes = "SELECT c.id as class_db_id, c.class_name, c.class_level, c.class_teacher_id, t.full_name as class_teacher_name, t.photo as class_teacher_photo 
                             FROM classes c 
                             LEFT JOIN teachers t ON c.class_teacher_id = t.id 
                             ORDER BY c.class_level, c.class_name";
    $result_actual = $conn->query($query_actual_classes);
    if ($result_actual) {
        while ($row_ac = $result_actual->fetch_assoc()) {
            $student_count = 0;
            if ($current_academic_year_id && $row_ac['class_db_id']) {
                $stmt_count_ac = $conn->prepare("SELECT COUNT(DISTINCT se.student_id) as count FROM student_enrollments se WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status IN ('enrolled','promoted','repeated')");
                if($stmt_count_ac){ $stmt_count_ac->bind_param("ii", $row_ac['class_db_id'], $current_academic_year_id); $stmt_count_ac->execute(); $res_count_ac = $stmt_count_ac->get_result(); if($res_count_ac){ $count_row_ac = $res_count_ac->fetch_assoc(); $student_count = $count_row_ac['count'] ?? 0; } $stmt_count_ac->close(); }
                else { error_log("Student count query failed: " . $conn->error); }
            }
            $row_ac['student_count'] = $student_count;
            $actual_classes_db[$row_ac['class_name']] = $row_ac;
        }
        $result_actual->free();
    } else { $_SESSION['error_message_cm'] = ($_SESSION['error_message_cm'] ?? '') . "<br>Error fetching actual class data: " . $conn->error; }
}
$class_assigned_subjects_db = [];
if ($conn) {
    $subjects_query_assigned = "SELECT c.class_name, s.id as subject_id, s.subject_name, s.subject_code FROM class_subjects cs JOIN subjects s ON cs.subject_id = s.id JOIN classes c ON cs.class_id = c.id"; // Join with classes to get class_name
    $subjects_result_assigned = $conn->query($subjects_query_assigned);
    if ($subjects_result_assigned) { while ($row_as = $subjects_result_assigned->fetch_assoc()) { $class_assigned_subjects_db[$row_as['class_name']][] = $row_as; } $subjects_result_assigned->free(); }
    else { $_SESSION['error_message_cm'] = ($_SESSION['error_message_cm'] ?? '') . "<br>Error fetching assigned subjects details: " . $conn->error; }
}
$all_teachers_list = [];
if ($conn) {
    $teachers_q = "SELECT id, full_name, photo, subject_specialization FROM teachers ORDER BY full_name";
    $teachers_r = $conn->query($teachers_q);
    if ($teachers_r) { while ($row_t = $teachers_r->fetch_assoc()) $all_teachers_list[] = $row_t; $teachers_r->free(); }
    else { $_SESSION['error_message_cm'] = ($_SESSION['error_message_cm'] ?? '') . "<br>Error fetching teachers: " . $conn->error; }
}
$all_subjects_list = [];
if ($conn) {
    $subjects_l_q = "SELECT id, subject_name, subject_code, is_optional, level_offered FROM subjects ORDER BY subject_name";
    $subjects_l_r = $conn->query($subjects_l_q);
    if ($subjects_l_r) { while ($row_sl = $subjects_l_r->fetch_assoc()) $all_subjects_list[] = $row_sl; $subjects_l_r->free(); }
    else { $_SESSION['error_message_cm'] = ($_SESSION['error_message_cm'] ?? '') . "<br>Error fetching all subjects: " . $conn->error; }
}

$fixed_classes_display_data = [];
for ($i = 1; $i <= 6; $i++) {
    $loop_class_name_main = "S$i";
    $class_level_loop_main = ($i <= 4) ? 'O-Level' : 'A-Level';
    $db_data_main = $actual_classes_db[$loop_class_name_main] ?? null;
    $assigned_subjects_list_main = [];
    if ($db_data_main && isset($class_assigned_subjects_db[$loop_class_name_main])) {
        $assigned_subjects_list_main = $class_assigned_subjects_db[$loop_class_name_main];
    }
    $fixed_classes_display_data[$class_level_loop_main][] = [
        'card_class_name' => $loop_class_name_main,
        'card_class_level' => $class_level_loop_main,
        'db_data' => $db_data_main,
        'assigned_subjects_list' => $assigned_subjects_list_main
    ];
}

$success_message_cm = $_SESSION['success_message_cm'] ?? null; $error_message_cm = $_SESSION['error_message_cm'] ?? null; $warning_message_cm = $_SESSION['warning_message_cm'] ?? null;
if ($success_message_cm) unset($_SESSION['success_message_cm']); if ($error_message_cm) unset($_SESSION['error_message_cm']); if ($warning_message_cm) unset($_SESSION['warning_message_cm']);

if ($conn) { $conn->close(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Management - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet">
    <style>
        body { background-color: var(--bs-light-bg-subtle); }
        .class-card { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .5rem; margin-bottom: 1.5rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075); display: flex; flex-direction: column; }
        .class-header-custom { padding: 0.75rem 1rem; background-color: var(--bs-tertiary-bg); border-bottom: 1px solid var(--bs-border-color); color: var(--bs-emphasis-color); }
        .class-header-custom .class-title { font-size: 1.05rem; font-weight: 600; }
        .class-header-custom .class-level-badge { font-size: 0.7rem; padding: .25em .5em; }
        .class-header-custom .class-actions { display: flex; gap: 0.3rem; justify-content: flex-end; } /* Ensure flex for alignment */
        .class-header-custom .class-actions .btn { padding: 0.2rem 0.45rem; font-size: 0.75rem; }
        .class-body-info { padding: 0.75rem 1rem; font-size: 0.875rem; flex-grow: 1; } /* flex-grow to push footer down */
        .class-teacher-info { display: flex; align-items: center; min-height: 45px; margin-bottom: .5rem;}
        .teacher-photo { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; margin-right: 8px; background-color: var(--bs-secondary-bg);}
        .teacher-name { font-weight: 500; }
        .no-teacher { color: var(--bs-secondary-color); font-style: italic;}
        .class-assigned-subjects h6 { font-size: 0.8rem; color: var(--bs-secondary-color); margin-bottom: .25rem;}
        .subjects-scroll-container { max-height: 70px; overflow-y: auto; margin-bottom: 5px; padding-right: 5px;} /* Scroll for many subjects */
        .subject-badge { margin-right: 4px; margin-bottom: 4px; padding: .3em .6em; font-size: .75em; background-color: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); border: 1px solid var(--bs-primary-border-subtle); }
        .no-subjects { color: var(--bs-secondary-color); font-style: italic; }
        .view-more-subjects { font-size: 0.75rem; cursor: pointer; color: var(--bs-link-color); }
        .section-title { font-size: 1.3rem; font-weight: 600; margin: 20px 0 15px; padding-bottom: 8px; border-bottom: 2px solid var(--bs-primary); color: var(--bs-emphasis-color); }
        .modal-header { background-color: var(--bs-primary); color: white; }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .student-photo-thumb-modal { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; background-color: var(--bs-secondary-bg); }
        .list-group-item.teacher-selectable-item:hover { background-color: var(--bs-primary-bg-subtle); }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar_dos.php'; ?>
        <div  class="flex-grow-1 p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0 h3"><i class="fas fa-chalkboard me-2 text-primary"></i>Class Management</h2>
                <div>
                    <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addClassModal" onclick="prepareAddClassModal()">
                        <i class="fas fa-plus me-1"></i> Add New Class
                    </button>
                    <a href="subject_management.php" class="btn btn-outline-secondary">
                        <i class="fas fa-book me-1"></i> Manage All Subjects
                    </a>
                </div>
            </div>

            <?php if ($success_message_cm): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_cm); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error_message_cm): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo nl2br(htmlspecialchars($error_message_cm)); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($warning_message_cm): ?><div class="alert alert-warning alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($warning_message_cm); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <?php foreach (['O-Level', 'A-Level'] as $level_group): ?>
                <h3 class="section-title"><?php echo $level_group; ?> Classes</h3>
                <div class="row g-3">
                    <?php if (!empty($fixed_classes_display_data[$level_group])): ?>
                        <?php foreach ($fixed_classes_display_data[$level_group] as $class_card_item): ?>
                            <?php
                                $card_class_name = $class_card_item['card_class_name'];
                                $db_class_data = $class_card_item['db_data'];
                                $class_db_id = $db_class_data['class_db_id'] ?? null;
                                $assigned_subjects_for_card = $class_card_item['assigned_subjects_list'];
                                $student_count_for_card = $db_class_data['student_count'] ?? 0;
                            ?>
                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <div class="class-card h-100">
                                    <div class="class-header-custom">
                                        <div class="d-flex align-items-center mb-1">
                                            <h4 class="class-title mb-0 me-auto"><?php echo htmlspecialchars($card_class_name); ?></h4>
                                            <span class="badge <?php echo $level_group === 'O-Level' ? 'text-bg-primary' : 'text-bg-success'; ?> class-level-badge">
                                                <?php echo htmlspecialchars($level_group); ?>
                                            </span>
                                        </div>
                                        <div class="class-actions">
                                            <button class="btn btn-sm btn-outline-secondary" title="Assign Teacher" data-bs-toggle="modal" data-bs-target="#assignTeacherModal" data-class-id="<?php echo $class_db_id; ?>" data-class-name="<?php echo htmlspecialchars($card_class_name); ?>" <?php echo !$class_db_id ? 'disabled' : ''; ?>><i class="fas fa-user-tie"></i></button>
                                            <button class="btn btn-sm btn-outline-secondary" title="Assign Subjects" data-bs-toggle="modal" data-bs-target="#assignSubjectsModal" data-class-id="<?php echo $class_db_id; ?>" data-class-name="<?php echo htmlspecialchars($card_class_name); ?>" <?php echo !$class_db_id ? 'disabled' : ''; ?>><i class="fas fa-book-open"></i></button>
                                            <button class="btn btn-sm btn-outline-info view-students-btn" title="View Students" data-class-id="<?php echo $class_db_id; ?>" data-class-name="<?php echo htmlspecialchars($card_class_name); ?>" data-class-teacher="<?php echo htmlspecialchars($db_class_data['class_teacher_name'] ?? 'N/A'); ?>" <?php echo (!$class_db_id || !$current_academic_year_id) ? 'disabled' : ''; ?>><i class="fas fa-users"></i></button>
                                        </div>
                                    </div>
                                    <div class="class-body-info">
                                        <div class="class-teacher-info">
                                            <?php if ($db_class_data && !empty($db_class_data['class_teacher_name'])): ?>
                                                <img src="<?php echo htmlspecialchars($db_class_data['class_teacher_photo'] ?: 'https://ui-avatars.com/api/?name='.urlencode($db_class_data['class_teacher_name']).'&size=30&background=random&color=fff'); ?>" class="teacher-photo" alt="Teacher">
                                                <span class="teacher-name"><?php echo htmlspecialchars($db_class_data['class_teacher_name']); ?></span>
                                            <?php else: ?>
                                                <i class="fas fa-user-tie text-muted me-2 opacity-50"></i><span class="no-teacher">Not Assigned</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="class-assigned-subjects">
                                            <h6>Subjects (<span class="subject-count-<?php echo $card_class_name; ?>"><?php echo count($assigned_subjects_for_card); ?></span>):</h6>
                                            <div class="subjects-scroll-container" id="subjects-list-<?php echo htmlspecialchars($card_class_name); ?>">
                                                <?php if (!empty($assigned_subjects_for_card)): ?>
                                                    <?php foreach ($assigned_subjects_for_card as $idx => $subject_item): ?>
                                                        <span class="badge subject-badge <?php if ($idx >= 3) echo 'subject-extra d-none'; ?>"><?php echo htmlspecialchars($subject_item['subject_name']); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?> <div class="no-subjects small">None Assigned</div> <?php endif; ?>
                                            </div>
                                            <?php if (count($assigned_subjects_for_card) > 3): ?>
                                                <a href="#!" class="view-more-subjects" onclick="toggleMoreSubjects('<?php echo htmlspecialchars($card_class_name); ?>', this)">Show more...</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-transparent text-center py-2 border-top">
                                        <small class="text-muted me-2">Students: <?php echo $class_db_id && $current_academic_year_id ? $student_count_for_card : 'N/A'; ?></small> |
                                        <?php if ($class_db_id): ?>
                                            <button class="btn btn-sm btn-link text-decoration-none p-0 ms-1" onclick="editClassModal(<?php echo $class_db_id; ?>)">Edit</button>
                                            <a href="?delete_class_id_action=1&class_id_to_delete=<?php echo $class_db_id; ?>" class="btn btn-sm btn-link text-danger text-decoration-none p-0 ms-1" onclick="return confirm('Delete class <?php echo htmlspecialchars($card_class_name); ?>? This is irreversible and will remove related student enrollments and subject assignments for this class.')"><i class="fas fa-trash-alt fa-xs"></i></a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-link text-decoration-none p-0 ms-1" data-bs-toggle="modal" data-bs-target="#addClassModal" onclick="prefillAddClassModal('<?php echo htmlspecialchars($card_class_name); ?>', '<?php echo htmlspecialchars($level_group); ?>')">Create Class</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                     <?php else: ?>
                        <div class="col-12"><p class="text-muted">No predefined <?php echo $level_group; ?> slots in the current setup. Use "Add New Class" to create specific classes like 'S1 Arts'.</p></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div> <!-- End #content -->
    </div> <!-- End .wrapper -->

    <!-- All Modals (Add/Edit Class, Assign Teacher, Assign Subjects, View Students, Add Student to Class) -->
    <!-- Keep all modal HTML from the previous full version here -->
    <!-- Add/Edit Class Modal -->
    <div class="modal fade" id="addClassModal" tabindex="-1" aria-labelledby="addClassModalLabel" aria-hidden="true">
        <div class="modal-dialog"> <div class="modal-content">
            <form method="POST" action="dos_class_management.php">
                <input type="hidden" name="add_edit_class_action" value="1">
                <input type="hidden" id="form_class_id_edit" name="class_id_form">
                <div class="modal-header"><h5 class="modal-title" id="addClassModalLabel">Add New Class</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label for="add_class_name_form" class="form-label">Class Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="add_class_name_form" name="class_name_form" required></div>
                    <div class="mb-3"><label for="add_class_level_form" class="form-label">Class Level <span class="text-danger">*</span></label><select class="form-select" id="add_class_level_form" name="class_level_form" required><option value="">Select Level...</option><option value="O-Level">O-Level</option><option value="A-Level">A-Level</option></select></div>
                    <div class="mb-3"><label for="add_class_teacher_id_form" class="form-label">Class Teacher (Optional)</label><select class="form-select" id="add_class_teacher_id_form" name="class_teacher_id_form"><option value="">Select Teacher...</option><?php if(!empty($all_teachers_list)) foreach ($all_teachers_list as $teacher): ?><option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Class</button></div>
            </form>
        </div></div>
    </div>

    <!-- Assign Teacher Modal -->
    <div class="modal fade" id="assignTeacherModal" tabindex="-1" aria-labelledby="assignTeacherModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <form method="POST" action="dos_class_management.php">
                <input type="hidden" name="assign_class_teacher_to_existing" value="1">
                <input type="hidden" id="assign_teacher_class_id_hidden" name="class_id_for_teacher_assign">
                <div class="modal-header"><h5 class="modal-title" id="assignTeacherModalLabel">Assign Teacher to <span id="assignTeacherModalClassName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="text" id="teacherSearchInput" class="form-control mb-3" placeholder="Search teachers by name or subject...">
                    <div class="list-group" id="assignTeacherModalTeachersList" style="max-height: 300px; overflow-y: auto;">
                        <?php if (!empty($all_teachers_list)): foreach ($all_teachers_list as $teacher): ?>
                        <label class="list-group-item list-group-item-action teacher-selectable-item" data-teacher-name="<?php echo strtolower(htmlspecialchars($teacher['full_name'])); ?>" data-teacher-subject="<?php echo strtolower(htmlspecialchars($teacher['subject_specialization'] ?? '')); ?>">
                            <input class="form-check-input me-1" type="radio" name="teacher_id_assign" value="<?php echo $teacher['id']; ?>" id="teacher_radio_<?php echo $teacher['id']; ?>">
                            <img src="<?php echo htmlspecialchars($teacher['photo'] ?: 'https://ui-avatars.com/api/?name='.urlencode($teacher['full_name']).'&size=30&background=random&color=fff'); ?>" class="teacher-photo rounded-circle me-2" style="width:30px; height:30px;" alt="Teacher">
                            <?php echo htmlspecialchars($teacher['full_name']); ?> <small class="text-muted ms-2">(<?php echo htmlspecialchars($teacher['subject_specialization'] ?? 'General'); ?>)</small>
                        </label>
                        <?php endforeach; else: ?><p class="text-muted p-3">No teachers available.</p><?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Assign Selected Teacher</button></div>
            </form>
        </div></div>
    </div>

    <!-- Assign Subjects Modal -->
    <div class="modal fade" id="assignSubjectsModal" tabindex="-1" aria-labelledby="assignSubjectsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <form method="POST" action="dos_class_management.php">
                <input type="hidden" name="assign_subjects_to_class_action" value="1">
                <input type="hidden" id="assign_subject_class_id_hidden" name="class_id_for_subject_assign">
                <div class="modal-header"><h5 class="modal-title" id="assignSubjectsModalLabel">Assign Subjects to <span id="assignSubjectModalClassName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="text" id="subjectSearchInput" class="form-control mb-3" placeholder="Search subjects...">
                    <div id="assignSubjectsModalCheckboxes" class="row gx-3 gy-2" style="max-height: 400px; overflow-y: auto;"><div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Loading...</div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-check-circle me-1"></i>Assign Selected</button></div>
            </form>
        </div></div>
    </div>

    <!-- View Students Modal -->
    <div class="modal fade" id="viewStudentsModal" tabindex="-1" aria-labelledby="viewStudentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="viewStudentsModalLabel">Students in <span id="viewStudentsModalClassName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                    <div class="me-3 mb-2 mb-md-0">Teacher: <strong id="viewStudentsModalClassTeacher">N/A</strong> | Year: <strong><?php echo htmlspecialchars($current_academic_year_name); ?></strong> | Total: <strong id="viewStudentsModalCount">0</strong></div>
                    <div class="d-flex align-items-center">
                        <button class="btn btn-sm btn-success me-2" id="openAddStudentToClassModalBtn" disabled><i class="fas fa-user-plus me-1"></i> Add Student</button>
                        <input type="text" id="viewStudentsModalSearch" class="form-control form-control-sm" style="width: auto;" placeholder="Search current...">
                    </div>
                </div>
                <div class="table-responsive"><table class="table table-sm table-hover table-striped" id="viewStudentsModalTable"><thead><tr><th>Photo</th><th>Adm No.</th><th>Full Name</th><th>Sex</th></tr></thead><tbody id="viewStudentsModalTableBody"></tbody></table><div id="viewStudentsModalNoStudentsMsg" class="alert alert-info text-center" style="display:none;"></div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div></div>
    </div>

    <!-- Add Student to Class Modal -->
    <div class="modal fade" id="addStudentToClassModal" tabindex="-1" aria-labelledby="addStudentToClassModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="addStudentToClassModalLabel">Add Student to <span id="addStudentTargetClassName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="addStudentTargetClassIdHidden">
                <div class="row mb-3"><div class="col-md-8"><label for="searchStudentToAddInput" class="form-label">Search by Adm No. or Full Name:</label><input type="text" class="form-control" id="searchStudentToAddInput" placeholder="Enter Admission No. or Name"></div><div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100" id="searchStudentBtn"><i class="fas fa-search me-1"></i> Search</button></div></div>
                <div id="studentSearchResultContainer" class="mt-3" style="display:none;"><h6 class="mb-3">Student Found:</h6><div class="card"><div class="card-body"><div class="d-flex align-items-center"><img src="" id="foundStudentPhoto" class="student-photo-thumb-modal me-3" alt="Student"><div><h5 class="mb-0" id="foundStudentName"></h5><p class="mb-0 text-muted small">Adm No: <span id="foundStudentAdmNo"></span> | Sex: <span id="foundStudentSex"></span></p><p class="mb-0 text-muted small">Current Class: <span id="foundStudentCurrentClass"></span></p></div></div><hr><p id="studentAlreadyInClassWarning" class="text-warning small" style="display:none;"><i class="fas fa-exclamation-triangle me-1"></i> Student already in this class for current year.</p><p id="studentDifferentClassWarning" class="text-info small" style="display:none;"><i class="fas fa-info-circle me-1"></i> Student currently in <span id="foundStudentCurrentClassForWarning"></span>. Adding updates class.</p><button class="btn btn-success w-100 mt-3" id="confirmAddStudentToClassBtn" disabled><i class="fas fa-plus-circle me-1"></i> Add <span id="confirmStudentName"></span> to this Class</button><input type="hidden" id="foundStudentIdHidden"></div></div></div>
                <div id="studentSearchNotFound" class="alert alert-warning mt-3" style="display:none;"></div><div id="studentSearchError" class="alert alert-danger mt-3" style="display:none;"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button></div>
        </div></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const currentAcademicYearIdJS = <?php echo $current_academic_year_id ? $current_academic_year_id : 'null'; ?>;
        const allSubjectsJS = <?php echo json_encode($all_subjects_list); ?>;
        const addClassModalInstance = new bootstrap.Modal(document.getElementById('addClassModal'));
        const assignTeacherModalInstance = new bootstrap.Modal(document.getElementById('assignTeacherModal'));
        const assignSubjectsModalInstance = new bootstrap.Modal(document.getElementById('assignSubjectsModal'));
        const viewStudentsModalInstance = new bootstrap.Modal(document.getElementById('viewStudentsModal'));
        const addStudentToClassModalInstance = new bootstrap.Modal(document.getElementById('addStudentToClassModal'));
        let currentClassIdForAddingStudent = null; // Store DB ID of class for student add modal

        function setThemePreference(theme) { document.documentElement.setAttribute('data-bs-theme', theme); localStorage.setItem('themePreferenceMACOM', theme); const themeIcon = document.querySelector('#themeToggleBtn i'); if (themeIcon) themeIcon.className = theme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';}
        function toggleTheme() { const currentTheme = document.documentElement.getAttribute('data-bs-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); const newTheme = currentTheme === 'dark' ? 'light' : 'dark'; setThemePreference(newTheme); }
        document.addEventListener('DOMContentLoaded', function() { const savedTheme = localStorage.getItem('themePreferenceMACOM'); if (savedTheme) setThemePreference(savedTheme); else setThemePreference(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');});
        
        function prepareAddClassModal() {
            document.getElementById('addClassModalLabel').textContent = 'Add New Class';
            document.querySelector('#addClassModal form').reset();
            document.getElementById('form_class_id_edit').value = '';
        }
        function prefillAddClassModal(className, classLevel) {
            prepareAddClassModal(); // Reset first
            document.getElementById('addClassModalLabel').textContent = `Create Class: ${className}`;
            document.getElementById('add_class_name_form').value = className;
            document.getElementById('add_class_level_form').value = classLevel;
        }
        function editClassModal(classDbId) {
            const classDataAll = <?php echo json_encode($actual_classes_db); ?>;
            let targetClassData = null;
            for (const name in classDataAll) { if (classDataAll[name] && classDataAll[name].class_db_id == classDbId) { targetClassData = classDataAll[name]; break; } }
            if (targetClassData) {
                document.getElementById('addClassModalLabel').textContent = `Edit Class: ${targetClassData.class_name}`;
                document.querySelector('#addClassModal form').reset();
                document.getElementById('form_class_id_edit').value = targetClassData.class_db_id;
                document.getElementById('add_class_name_form').value = targetClassData.class_name;
                document.getElementById('add_class_level_form').value = targetClassData.class_level;
                document.getElementById('add_class_teacher_id_form').value = targetClassData.class_teacher_id || '';
                addClassModalInstance.show();
            } else alert('Could not find class details for editing.');
        }
        
        document.getElementById('assignTeacherModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget; const classId = button.getAttribute('data-class-id'); const className = button.getAttribute('data-class-name');
            if (!classId || classId === 'null' || classId === '') { event.preventDefault(); alert('Class must be formally created first to assign a teacher.'); return; }
            document.getElementById('assignTeacherModalClassName').textContent = className;
            document.getElementById('assign_teacher_class_id_hidden').value = classId;
            assignTeacherModalEl.querySelectorAll('input[type="radio"]').forEach(radio => radio.checked = false);
            const currentTeacherData = <?php echo json_encode($actual_classes_db); ?>;
            if(currentTeacherData[className] && currentTeacherData[className].class_teacher_id) {
                const radioToSelect = assignTeacherModalEl.querySelector(`input[value="${currentTeacherData[className].class_teacher_id}"]`);
                if(radioToSelect) radioToSelect.checked = true;
            }
            document.getElementById('teacherSearchInput').value = ''; filterTeacherList('');
        });
        document.getElementById('teacherSearchInput')?.addEventListener('input', function() { filterTeacherList(this.value.toLowerCase()); });
        function filterTeacherList(searchTerm) { document.querySelectorAll('.teacher-selectable-item').forEach(item => { const name = item.dataset.teacherName || ""; const subject = item.dataset.teacherSubject || ""; item.style.display = (name.includes(searchTerm) || subject.includes(searchTerm)) ? '' : 'none'; });}

        document.getElementById('assignSubjectsModal').addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget; const classId = button.getAttribute('data-class-id'); const className = button.getAttribute('data-class-name');
            if (!classId || classId === 'null' || classId === '') { event.preventDefault(); alert('Class must be formally created first to assign subjects.'); return; }
            document.getElementById('assignSubjectModalClassName').textContent = className;
            document.getElementById('assign_subject_class_id_hidden').value = classId;
            const checkboxesContainer = document.getElementById('assignSubjectsModalCheckboxes');
            checkboxesContainer.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Loading...</div>';
            
            const classDataAll = <?php echo json_encode($actual_classes_db); ?>; // Get all actual classes
            let assignedSubjectsForThisClassIds = [];
            if (classDataAll[className] && classDataAll[className].class_db_id == classId) {
                // Fetch specific assigned subjects for THIS classId (more reliable)
                 $.ajax({
                    url: 'dos_class_management.php?action=get_class_details&class_id=' + classId, // AJAX to get fresh assigned subjects
                    type: 'GET', dataType: 'json',
                    success: function(data) {
                        if (data.success && data.class_data && data.class_data.assigned_subjects) {
                            assignedSubjectsForThisClassIds = data.class_data.assigned_subjects.map(s => s.id.toString());
                        }
                        populateSubjectCheckboxes(assignedSubjectsForThisClassIds);
                    }, error: function() { populateSubjectCheckboxes([]); checkboxesContainer.innerHTML = '<div class="alert alert-danger">Error fetching current subjects.</div>';}
                });
            } else { populateSubjectCheckboxes([]); } // Fallback or if class_name doesn't match a DB entry with that ID
            
            function populateSubjectCheckboxes(assignedIds) {
                checkboxesContainer.innerHTML = ''; 
                if (allSubjectsJS.length > 0) {
                    allSubjectsJS.forEach(subject => {
                        const isChecked = assignedIds.includes(subject.id.toString()) ? 'checked' : '';
                        const subjectType = subject.is_optional == 1 ? 'Optional' : 'Compulsory';
                        const level = subject.level_offered && subject.level_offered !== 'Both' ? `(${subject.level_offered})` : '';
                        checkboxesContainer.insertAdjacentHTML('beforeend', `<div class="col-md-6 subject-checkbox-item" data-subject-name="${subject.subject_name.toLowerCase()}"><div class="form-check"><input class="form-check-input" type="checkbox" name="subject_ids_assign[]" value="${subject.id}" id="modal_subj_assign_${subject.id}" ${isChecked}><label class="form-check-label small" for="modal_subj_assign_${subject.id}">${subject.subject_name} ${level} <span class="text-muted">(${subjectType})</span></label></div></div>`);
                    });
                } else checkboxesContainer.innerHTML = '<div class="alert alert-info">No subjects defined.</div>';
                document.getElementById('subjectSearchInput').value = ''; filterSubjectList('');
            }
        });
        document.getElementById('subjectSearchInput')?.addEventListener('input', function() { filterSubjectList(this.value.toLowerCase()); });
        function filterSubjectList(searchTerm) { document.querySelectorAll('.subject-checkbox-item').forEach(item => { const name = item.dataset.subjectName || ""; item.style.display = name.includes(searchTerm) ? '' : 'none'; });}

        document.querySelectorAll('.view-students-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const classId = this.dataset.classId; const className = this.dataset.className; const classTeacher = this.dataset.classTeacher;
                if (!classId || classId === 'null' || classId === '') { alert("This class slot isn't formal. Create the class first."); $('#openAddStudentToClassModalBtn').prop('disabled', true); return; }
                if (!currentAcademicYearIdJS) { alert('Current academic year is not set.'); $('#openAddStudentToClassModalBtn').prop('disabled', true); return; }
                currentClassIdForAddingStudent = classId; $('#openAddStudentToClassModalBtn').prop('disabled', false);
                
                $('#viewStudentsModalClassName').text(className); $('#viewStudentsModalClassTeacher').text(classTeacher || 'N/A');
                const tableBody = $('#viewStudentsModalTableBody'); const noStudentsMsg = $('#viewStudentsModalNoStudentsMsg'); const studentCountEl = $('#viewStudentsModalCount');
                tableBody.html('<tr><td colspan="4" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div> Loading...</td></tr>');
                noStudentsMsg.hide(); studentCountEl.text('0'); $('#viewStudentsModalSearch').val('');
                
                $.ajax({
                    url: 'dos_class_management.php', type: 'GET', data: { action: 'get_students_in_class', class_id: classId, academic_year_id: currentAcademicYearIdJS }, dataType: 'json',
                    success: function(response) {
                        tableBody.empty();
                        if (response.success && response.students && response.students.length > 0) {
                            studentCountEl.text(response.students.length);
                            response.students.forEach(student => {
                                let photo = student.photo || `https://ui-avatars.com/api/?name=${encodeURIComponent(student.full_name)}&size=35&background=random&color=fff`;
                                tableBody.append(`<tr data-searchable-name="${(student.full_name || '').toLowerCase()}" data-searchable-adm="${(student.admission_number || '').toLowerCase()}"><td><img src="${photo}" class="student-photo-thumb-modal" alt="${student.full_name || ''}"></td><td>${student.admission_number || 'N/A'}</td><td>${student.full_name || 'N/A'}</td><td>${student.sex || 'N/A'}</td></tr>`);
                            });
                        } else noStudentsMsg.text(response.message || 'No students enrolled in this class for the current academic year.').show();
                    },
                    error: function() { tableBody.empty(); noStudentsMsg.text('Failed to load students.').show(); }
                });
                viewStudentsModalInstance.show();
            });
        });
        $('#viewStudentsModalSearch').on('input', function() {
            const searchTerm = $(this).val().toLowerCase().trim(); let visibleCount = 0;
            $('#viewStudentsModalTableBody tr').each(function() {
                const name = $(this).data('searchableName') || ""; const admNo = $(this).data('searchableAdm') || "";
                const isVisible = name.includes(searchTerm) || admNo.includes(searchTerm); $(this).toggle(isVisible); if(isVisible) visibleCount++;
            });
            const noMsgEl = $('#viewStudentsModalNoStudentsMsg');
            if (visibleCount === 0 && searchTerm !== '' && $('#viewStudentsModalTableBody tr').length > 0) { noMsgEl.text('No students match search.').show(); }
            else if (visibleCount === 0 && $('#viewStudentsModalTableBody tr').length === 0 &&searchTerm === '' && !noMsgEl.text().includes('enrolled')) { noMsgEl.text('No students enrolled...').show(); }
            else if (visibleCount > 0 || ($('#viewStudentsModalTableBody tr').length === 0 && searchTerm === '')) { noMsgEl.hide(); } // Hide if results or if initially empty and no search
        });
        
        $('#openAddStudentToClassModalBtn').click(function() {
            if (!currentClassIdForAddingStudent) { alert('Target class not identified.'); return; }
            $('#addStudentTargetClassIdHidden').val(currentClassIdForAddingStudent);
            $('#addStudentTargetClassName').text($('#viewStudentsModalClassName').text());
            $('#searchStudentToAddInput').val('');
            $('#studentSearchResultContainer, #studentSearchNotFound, #studentSearchError, #studentAlreadyInClassWarning, #studentDifferentClassWarning').hide();
            $('#confirmAddStudentToClassBtn').prop('disabled', true);
            addStudentToClassModalInstance.show();
        });
        $('#searchStudentBtn').click(function() {
            const searchTerm = $('#searchStudentToAddInput').val().trim(); if (searchTerm === '') { alert('Please enter search term.'); return; }
            $('#studentSearchResultContainer, #studentSearchNotFound, #studentSearchError, #studentAlreadyInClassWarning, #studentDifferentClassWarning').hide();
            const btn = $(this); btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Searching...');
            $.ajax({
                url: 'dos_class_management.php', type: 'POST', data: { action: 'search_student_for_class_add', searchTerm: searchTerm }, dataType: 'json',
                success: function(response) {
                    if (response.success && response.student) {
                        const student = response.student; const targetClassId = $('#addStudentTargetClassIdHidden').val();
                        $('#foundStudentPhoto').attr('src', student.photo ? student.photo : `https://ui-avatars.com/api/?name=${encodeURIComponent(student.full_name)}&size=35&background=random&color=fff`);
                        $('#foundStudentName').text(student.full_name); $('#confirmStudentName').text(student.full_name.split(' ')[0]);
                        $('#foundStudentAdmNo').text(student.admission_number || 'N/A'); $('#foundStudentSex').text(student.sex || 'N/A');
                        $('#foundStudentCurrentClass').text(student.current_class_name || 'Not Assigned');
                        $('#foundStudentIdHidden').val(student.id);
                        $('#studentSearchResultContainer').show(); $('#confirmAddStudentToClassBtn').prop('disabled', false);
                        if (student.current_class_id == targetClassId) { $('#studentAlreadyInClassWarning').show(); /* May still allow adding to update enrollment status */ }
                        if (student.current_class_id && student.current_class_id != targetClassId) { $('#foundStudentCurrentClassForWarning').text(student.current_class_name || 'another class'); $('#studentDifferentClassWarning').show(); }
                    } else { $('#studentSearchNotFound').text(response.message || 'No student found.').show(); }
                },
                error: function() { $('#studentSearchError').text('Search error. Try again.').show(); },
                complete: function() { btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> Search'); }
            });
        });
        $('#confirmAddStudentToClassBtn').click(function() {
            const studentIdToAdd = $('#foundStudentIdHidden').val(); const targetClassId = $('#addStudentTargetClassIdHidden').val();
            const targetClassName = $('#addStudentTargetClassName').text(); const btn = $(this);
            if (!studentIdToAdd || !targetClassId || !currentAcademicYearIdJS) { alert('Missing data.'); return; }
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Adding...');
            $.ajax({
                url: 'dos_class_management.php', type: 'POST', data: { action: 'add_student_to_class_enrollment', student_id: studentIdToAdd, class_id: targetClassId, academic_year_id: currentAcademicYearIdJS }, dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert(response.message || 'Student added successfully!'); addStudentToClassModalInstance.hide();
                        const viewStudentsClassTeacher = $('#viewStudentsModalClassTeacher').text();
                        openViewStudentsModal(targetClassId, targetClassName, viewStudentsClassTeacher);
                    } else { alert('Error: ' + (response.message || 'Could not add student.')); }
                },
                error: function() { alert('An error occurred. Please try again.'); },
                complete: function() { btn.prop('disabled', false).html(`<i class="fas fa-plus-circle me-1"></i> Add ${$('#confirmStudentName').text()} to Class`); }
            });
        });

        function toggleMoreSubjects(className, element) {
            const container = document.getElementById(`subjects-list-${className}`);
            const extraSubjects = container.querySelectorAll('.subject-extra');
            const isShowingMore = element.textContent.includes('less');

            extraSubjects.forEach(sub => {
                sub.classList.toggle('d-none', isShowingMore);
            });
            element.textContent = isShowingMore ? 'Show more...' : 'Show less';
        }
    </script>
</body>
</html>