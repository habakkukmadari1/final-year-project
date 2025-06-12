<?php
// head_teacher_teacher_details.php
require_once 'db_config.php';
require_once 'auth.php';

check_login('head_teacher'); // Access for Head Teacher
$loggedInUserId = $_SESSION['user_id']; // For audit if any minor edits are done here

// --- Helper Function for Current Academic Year (same as before) ---
function get_current_academic_year_info_ht_td($conn) {
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1"); // Fallback
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    return null;
}

$current_academic_year_info_ht = get_current_academic_year_info_ht_td($conn);
$current_academic_year_id_ht = $current_academic_year_info_ht['id'] ?? null;
$current_academic_year_name_ht = $current_academic_year_info_ht['year_name'] ?? 'N/A';

// Get all academic years for the filter dropdown
$all_academic_years_ht_td = [];
if ($conn) {
    $year_res_ht_td = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC");
    if ($year_res_ht_td) { while($row_ht_td = $year_res_ht_td->fetch_assoc()) { $all_academic_years_ht_td[] = $row_ht_td; } }
}


// --- AJAX ACTION HANDLING (Only for fetching assigned papers for HT view) ---
$requested_action = $_REQUEST['action'] ?? null;
if ($requested_action === 'get_assigned_papers_ht_view' && $conn) {
    header('Content-Type: application/json');
    $ajax_response = ['success' => false, 'message' => 'Invalid action or DB error.'];
    $teacher_id_fetch = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
    $academic_year_id_fetch = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);

    if (!$teacher_id_fetch || !$academic_year_id_fetch) {
        $ajax_response['message'] = 'Teacher ID and Academic Year ID are required.';
    } else {
        $assigned_papers = [];
        $sql = "SELECT tcsp.id as assignment_id, ay.year_name, c.class_name, s.subject_name, sp.paper_name, sp.paper_code
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
            while($row = $result->fetch_assoc()){ $assigned_papers[] = $row; }
            $stmt->close();
            $ajax_response = ['success' => true, 'assigned_papers' => $assigned_papers];
        } else {
            $ajax_response['message'] = 'Error fetching assigned papers: '. $conn->error;
            error_log("HT Teacher Details - Error fetching assigned papers: " . $conn->error);
        }
    }
    echo json_encode($ajax_response);
    exit;
}


// --- REGULAR PAGE LOAD LOGIC ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message_ht_mt'] = "Invalid teacher ID."; // Use the manage_teachers session key
    header("Location: head_teacher_manage_teachers.php");
    exit();
}
$teacher_id_page_ht = (int)$_GET['id'];
$teacher_ht = null;
$teacher_creator_name = 'N/A';
$teacher_updater_name = 'N/A';

if ($conn) {
    $stmt_ht = $conn->prepare("SELECT t.*, u_creator.username as creator, u_updater.username as updater
                           FROM teachers t
                           LEFT JOIN users u_creator ON t.created_by = u_creator.id
                           LEFT JOIN users u_updater ON t.updated_by = u_updater.id
                           WHERE t.id = ?");
    if (!$stmt_ht) {
        error_log("SQL prepare error HT teacher_details: " . $conn->error);
        $_SESSION['error_message_ht_td'] = "Database error fetching teacher details.";
    } else {
        $stmt_ht->bind_param("i", $teacher_id_page_ht);
        $stmt_ht->execute();
        $result_ht = $stmt_ht->get_result();
        $teacher_ht = $result_ht->fetch_assoc();
        if ($teacher_ht) {
            $teacher_creator_name = $teacher_ht['creator'] ?: 'System/Unknown';
            $teacher_updater_name = $teacher_ht['updater'] ?: 'N/A';
        }
        $stmt_ht->close();
    }
}

if (!$teacher_ht) {
    $_SESSION['error_message_ht_mt'] = "Teacher not found.";
    header("Location: head_teacher_manage_teachers.php");
    exit();
}

$default_teacher_photo_url_ht = 'https://ui-avatars.com/api/?name=' . urlencode($teacher_ht['full_name']) . '&background=random&color=fff&size=150';
$teacher_photo_path_page_ht = (!empty($teacher_ht['photo']) && file_exists($teacher_ht['photo'])) ? htmlspecialchars($teacher_ht['photo']) : $default_teacher_photo_url_ht;


$success_message_ht_td = $_SESSION['success_message_ht_td'] ?? null;
$error_message_ht_td = $_SESSION['error_message_ht_td'] ?? null;
if ($success_message_ht_td) unset($_SESSION['success_message_ht_td']);
if ($error_message_ht_td) unset($_SESSION['error_message_ht_td']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($teacher_ht['full_name']); ?> - Teacher Details (HT) | MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="style/styles.css" rel="stylesheet"> <!-- Ensure correct path -->
    <style>
        /* Using styles from your previous student_details.php for consistency */
        :root { --primary-accent: #0d6efd; /* HT's primary color */ }
        .profile-container { background-color: var(--bs-tertiary-bg); border-radius: 0.75rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid var(--bs-border-color); }
        .profile-pic { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary-accent); box-shadow: 0 4px 10px rgba(0,0,0,0.1); background-color: var(--bs-secondary-bg); }
        .details-panel .nav-tabs .nav-link.active { color: var(--primary-accent); border-bottom-color: var(--primary-accent); }
        .details-panel .tab-content { border-left: 5px solid var(--primary-accent); }
        .details-section-card .card-header { color: var(--primary-accent); }
        .audit-info { font-size: 0.75rem; color: #6c757d; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar.php'; // Or nav_sidebar_headteacher.php ?>
        <div class="flex-grow-1 p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0 h3"><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Teacher Details <small class="text-muted fw-normal">(HT View)</small></h2>
                <div><button class="btn btn-sm btn-outline-secondary me-2" onclick="window.location.href='head_teacher_manage_teachers.php'"><i class="fas fa-arrow-left me-1"></i> Back to List</button></div>
            </div>

            <?php if ($success_message_ht_td): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_ht_td); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error_message_ht_td): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo nl2br(htmlspecialchars($error_message_ht_td)); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <div class="row mb-4">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="profile-container p-3 p-md-4 h-100">
                        <div class="d-flex flex-column align-items-center text-center">
                            <img src="<?php echo $teacher_photo_path_page_ht; ?>" alt="Teacher Photo" class="profile-pic mb-3">
                            <h3 class="h5 mb-1 fw-bold"><?php echo htmlspecialchars($teacher_ht['full_name']); ?></h3>
                            <p class="text-secondary mb-1 small"><i class="fas fa-user-tie opacity-75 me-1"></i> Role: <?php echo htmlspecialchars(ucfirst($teacher_ht['role'])); ?></p>
                            <p class="text-secondary mb-2 small"><i class="fas fa-book-reader opacity-75 me-1"></i> Specialization: <?php echo htmlspecialchars($teacher_ht['subject_specialization'] ?: 'N/A'); ?></p>
                            <hr class="w-75 my-3">
                            <div class="info-item w-100 text-start"><span class="info-label small">Email:</span> <span class="info-value small text-truncate" title="<?php echo htmlspecialchars($teacher_ht['email']); ?>"><?php echo htmlspecialchars($teacher_ht['email']); ?></span></div>
                            <div class="info-item w-100 text-start"><span class="info-label small">Phone:</span> <span class="info-value small"><?php echo htmlspecialchars($teacher_ht['phone_number']); ?></span></div>
                            <div class="info-item w-100 text-start"><span class="info-label small">Joined:</span> <span class="info-value small"><?php echo htmlspecialchars($teacher_ht['join_date'] ? date('d M, Y', strtotime($teacher_ht['join_date'])) : 'N/A'); ?></span></div>
                            <hr class="w-75 my-2">
                            <div class="audit-info text-start w-100">
                                Created: <?php echo date('d M Y H:i', strtotime($teacher_ht['created_at'])); ?> by <?php echo htmlspecialchars($teacher_creator_name); ?><br>
                                Last Updated: <?php echo date('d M Y H:i', strtotime($teacher_ht['updated_at'])); ?> by <?php echo htmlspecialchars($teacher_updater_name); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="details-panel">
                        <ul class="nav nav-tabs" id="teacherInfoTabsHt" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active" id="t-personal-ht-tab" data-bs-toggle="tab" data-bs-target="#t-personal-ht-pane" type="button"><i class="fas fa-user-circle me-1"></i>Personal & Professional</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link" id="t-documents-ht-tab" data-bs-toggle="tab" data-bs-target="#t-documents-ht-pane" type="button"><i class="fas fa-folder-open me-1"></i>Documents</button></li>
                        </ul>
                        <div class="tab-content" id="teacherInfoTabsContentHt">
                            <!-- Content identical to your teacher_details.php for these tabs -->
                            <div class="tab-pane fade show active" id="t-personal-ht-pane" role="tabpanel" aria-labelledby="t-personal-ht-tab" tabindex="0">
                                <h5 class="section-title">Basic Information</h5>
                                <div class="info-item"><span class="info-label">Full Name:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['full_name']); ?></span></div>
                                <div class="info-item"><span class="info-label">Date of Birth:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['date_of_birth'] ? date('d M Y', strtotime($teacher_ht['date_of_birth'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Email:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['email']); ?></span></div>
                                <div class="info-item"><span class="info-label">Phone Number:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['phone_number']); ?></span></div>
                                <div class="info-item"><span class="info-label">Address:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['address'] ?: 'N/A'); ?></span></div>
                                <h5 class="section-title mt-4">Professional Details</h5>
                                <div class="info-item"><span class="info-label">NIN Number:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['nin_number'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Subject Specialization:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['subject_specialization'] ?: 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Joining Date:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['join_date'] ? date('d M Y', strtotime($teacher_ht['join_date'])) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Salary (UGX):</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['salary'] ? number_format($teacher_ht['salary'], 2) : 'N/A'); ?></span></div>
                                <div class="info-item"><span class="info-label">Role:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($teacher_ht['role'])); ?></span></div>
                                <h5 class="section-title mt-4">Disability Information</h5>
                                <div class="info-item"><span class="info-label">Has Disability:</span> <span class="info-value"><?php echo htmlspecialchars(ucfirst($teacher_ht['has_disability'])); ?></span></div>
                                <?php if ($teacher_ht['has_disability'] === 'Yes'): ?>
                                    <div class="info-item"><span class="info-label">Disability Type:</span> <span class="info-value"><?php echo htmlspecialchars($teacher_ht['disability_type'] ?: 'Not specified'); ?></span></div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="t-documents-ht-pane" role="tabpanel" aria-labelledby="t-documents-ht-tab" tabindex="0">
                                <h5 class="section-title">Uploaded Documents</h5>
                                <?php
                                $doc_fields_ht = [
                                    'photo' => 'Photo', 'disability_medical_report_path' => 'Disability Medical Report',
                                    'application_form_path' => 'Application Form', 'national_id_path' => 'National ID',
                                    'academic_transcripts_path' => 'Academic Transcripts', 'other_documents_path' => 'Other Documents'
                                ];
                                $has_docs_ht = false;
                                foreach ($doc_fields_ht as $field_key => $label) {
                                    if (!empty($teacher_ht[$field_key]) && file_exists($teacher_ht[$field_key])) {
                                        $has_docs_ht = true;
                                        echo '<div class="info-item"><span class="info-label">' . htmlspecialchars($label) . ':</span><span class="info-value"><a href="' . htmlspecialchars($teacher_ht[$field_key]) . '" target="_blank" class="file-link"><i class="fas fa-external-link-alt fa-xs me-1"></i>View Document</a></span></div>';
                                    }
                                }
                                if (!$has_docs_ht) echo '<p class="text-muted">No documents uploaded.</p>';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
                <button class="btn btn-primary action-btn" onclick="redirectToEditTeacherHt(<?php echo $teacher_id_page_ht; ?>)">
                    <i class="fas fa-edit me-1"></i> Edit Teacher Profile
                </button>
                <button class="btn btn-warning action-btn text-dark" onclick="openPasswordModalPageHt(<?php echo $teacher_id_page_ht; ?>, '<?php echo htmlspecialchars(addslashes($teacher_ht['full_name'])); ?>', '<?php echo $teacher_photo_path_page_ht; ?>')">
                    <i class="fas fa-key me-1"></i> Change Password
                </button>
                 <a href="head_teacher_manage_teachers.php" class="btn btn-danger action-btn">
                    <i class="fas fa-trash me-1"></i> Delete Teacher Profile
                 </a>
                 <!-- The delete button above ideally should call confirmDeleteDialogHt(<?php echo $teacher_id_page_ht; ?>, '...')
                      and the form submission for delete should happen via JS for better UX, or it can just be a link
                      to head_teacher_manage_teachers.php which has delete functionality on the list.
                      For simplicity now, it just links back. A better UX would be direct delete with confirmation.
                 -->
            </div>

            <div class="details-section-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-tasks me-2"></i>Assigned Subject Papers (Read-Only)</span>
                    <!-- No "New Assignment" button for HT here -->
                </div>
                <div class="card-body p-3 p-md-4">
                    <div class="mb-3">
                        <label for="filterAcademicYearAssignmentsHt" class="form-label">Filter by Academic Year:</label>
                        <select id="filterAcademicYearAssignmentsHt" class="form-select form-select-sm" style="max-width: 300px;">
                           <?php if (empty($all_academic_years_ht_td)): ?> <option value="">No academic years found</option>
                            <?php else: foreach ($all_academic_years_ht_td as $year_ht): ?>
                                <option value="<?php echo $year_ht['id']; ?>" <?php echo ($year_ht['id'] == $current_academic_year_id_ht) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year_ht['year_name']); ?>
                                </option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <div id="assignedPapersListContainerHt"><p class="text-muted">Select an academic year to view assignments.</p></div>
                </div>
            </div>
        </div> 
    </div> 

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="javascript/script.js"></script> <!-- Ensure correct path -->
    <script>
        const teacherIdPageHt = <?php echo $teacher_id_page_ht; ?>;
        const ajaxUrlHtTd = '<?php echo $_SERVER["PHP_SELF"]; ?>';
        const currentAcademicYearIdHtPage = <?php echo $current_academic_year_id_ht ? $current_academic_year_id_ht : 'null'; ?>;

        $(document).ready(function() {
            if(currentAcademicYearIdHtPage) { loadAssignedPapersHt(currentAcademicYearIdHtPage); }
            $('#filterAcademicYearAssignmentsHt').on('change', function(){ loadAssignedPapersHt($(this).val()); });
        });

        function loadAssignedPapersHt(academicYearId) {
            const container = $('#assignedPapersListContainerHt');
            container.html('<p class="text-center"><span class="spinner-border spinner-border-sm"></span> Loading assignments...</p>');
            if (!academicYearId) { container.html('<p class="text-muted">Please select an academic year.</p>'); return; }

            $.ajax({
                url: ajaxUrlHtTd, type: 'GET',
                data: { action: 'get_assigned_papers_ht_view', teacher_id: teacherIdPageHt, academic_year_id: academicYearId },
                dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.assigned_papers.length > 0) {
                        const table = $('<table class="table table-sm table-hover table-striped"><thead><tr><th>Class</th><th>Subject</th><th>Paper</th><th>Code</th></tr></thead><tbody></tbody></table>');
                        response.assigned_papers.forEach(item => {
                            const row = $('<tr></tr>');
                            row.append($('<td></td>').text(item.class_name));
                            row.append($('<td></td>').text(item.subject_name));
                            row.append($('<td></td>').text(item.paper_name));
                            row.append($('<td></td>').text(item.paper_code || 'N/A'));
                            // No actions for HT on assignments view
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
        
        function redirectToEditTeacherHt(teacherId) {
            // Redirects to the main management page with a GET param to trigger edit modal
            window.location.href = `head_teacher_manage_teachers.php?edit_attempt=${teacherId}`;
        }
        function openPasswordModalPageHt(teacherId, teacherName, teacherPhoto) {
            // This will redirect to the main teacher management page, which has the password modal logic
            // The main page JS (head_teacher_manage_teachers.php) will need to check for GET params to auto-open this.
            // For now, we make it simpler: the modal is on head_teacher_manage_teachers.php
            // So, this function on the details page would effectively just note that password changes are done there.
            // To make it more direct, you could replicate the password modal here, or have a global modal.
            // For now, HT must go back to the list page to use the 'Pwd' button.
            // OR, we make this button also redirect with a special param.
            window.location.href = `head_teacher_manage_teachers.php?change_password_for=${teacherId}`;
            // The head_teacher_manage_teachers.php JS would then need to check for `change_password_for`
            // and call its `openPasswordModalHt` function. This is more complex.
            // Simpler: just tell user where to do it, or use the existing btn on the list page.
            // We will rely on the user going back to head_teacher_manage_teachers.php and using the 'Pwd' button there.
            // This `openPasswordModalPageHt` could just be an alert:
            alert(`Password for ${teacherName} can be changed from the main Teacher Management list using the 'Pwd' button.`);
        }
    </script>
</body>
</html>