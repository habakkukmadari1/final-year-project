<?php
// teachers/teacher_my_students.php
require_once '../db_config.php';
require_once '../auth.php';

check_login('teacher');

$teacher_id = $_SESSION['user_id'] ?? null;
$teacher_full_name = $_SESSION['full_name'] ?? 'Teacher';

// --- AJAX ACTION HANDLING ---
// (Keep the entire AJAX switch block from the previous response - no changes needed there)
$requested_action = $_REQUEST['action'] ?? null;
if ($requested_action && $conn && $teacher_id) {
    header('Content-Type: application/json');
    $ajax_response = ['success' => false, 'message' => 'Invalid action, database error, or teacher not identified.'];

    switch ($requested_action) {
        case 'get_teacher_assignments':
            $academic_year_id_fetch = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);
            if (!$academic_year_id_fetch) { $ajax_response['message'] = 'Academic Year ID is required.'; break; }
            $assignments = [];
            $sql = "SELECT tcsp.id as assignment_id, ay.year_name, c.class_name, c.id as class_id, s.subject_name, s.id as subject_id, sp.paper_name, sp.id as paper_id, sp.paper_code
                    FROM teacher_class_subject_papers tcsp
                    JOIN academic_years ay ON tcsp.academic_year_id = ay.id JOIN classes c ON tcsp.class_id = c.id
                    JOIN subjects s ON tcsp.subject_id = s.id JOIN subject_papers sp ON tcsp.subject_paper_id = sp.id
                    WHERE tcsp.teacher_id = ? AND tcsp.academic_year_id = ? ORDER BY c.class_name, s.subject_name, sp.paper_name";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $teacher_id, $academic_year_id_fetch); $stmt->execute();
                $result = $stmt->get_result(); while ($row = $result->fetch_assoc()) { $assignments[] = $row; }
                $stmt->close(); $ajax_response = ['success' => true, 'assignments' => $assignments];
            } else { $ajax_response['message'] = 'Error fetching assignments: ' . $conn->error; error_log("TMS - Error fetching assignments: " . $conn->error); }
            break;

        case 'get_students_for_teacher_assignment':
            $class_id_stud = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
            $subject_id_stud = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
            $academic_year_id_stud = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT);
            if (!$class_id_stud || !$subject_id_stud || !$academic_year_id_stud) { $ajax_response['message'] = 'Class ID, Subject ID, and Academic Year ID are required.'; break; }
            $students_list = [];
            $sql_students = "SELECT DISTINCT s.id, s.full_name, s.admission_number, s.sex, s.photo FROM students s
                JOIN student_enrollments se ON s.id = se.student_id
                WHERE se.class_id = ? AND se.academic_year_id = ? AND se.status IN ('enrolled', 'promoted', 'repeated')
                AND (EXISTS (SELECT 1 FROM class_subjects cs JOIN subjects subj_comp ON cs.subject_id = subj_comp.id WHERE cs.class_id = ? AND cs.subject_id = ? AND subj_comp.is_optional = 0)
                    OR EXISTS (SELECT 1 FROM student_optional_subjects sos WHERE sos.student_id = s.id AND sos.subject_id = ? AND sos.academic_year_id = ?))
                ORDER BY s.full_name ASC";
            $stmt_stud = $conn->prepare($sql_students);
            if ($stmt_stud) {
                $stmt_stud->bind_param("iiiiii", $class_id_stud, $academic_year_id_stud, $class_id_stud, $subject_id_stud, $subject_id_stud, $academic_year_id_stud);
                $stmt_stud->execute(); $result_stud = $stmt_stud->get_result();
                while ($row_stud = $result_stud->fetch_assoc()) { $students_list[] = $row_stud; }
                $stmt_stud->close(); $ajax_response = ['success' => true, 'students' => $students_list];
            } else { $ajax_response['message'] = 'DB query error for fetching students: ' . $conn->error; error_log("TMS - Error fetching students: " . $conn->error); }
            break;

        case 'add_student_comment_by_teacher':
            $student_id_comment = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $assignment_id_comment = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
            $comment_text = trim(filter_input(INPUT_POST, 'comment_text', FILTER_SANITIZE_STRING));
            $context_class_id = null; $context_subject_id = null; $context_academic_year_id = null; // Initialize
            
            if (!$student_id_comment || !$assignment_id_comment || empty($comment_text)) { $ajax_response['message'] = 'Student, assignment context, and comment text are required.'; break; }
            
            $context_stmt = $conn->prepare("SELECT class_id, subject_id, academic_year_id FROM teacher_class_subject_papers WHERE id = ? AND teacher_id = ?");
            if ($context_stmt) {
                $context_stmt->bind_param("ii", $assignment_id_comment, $teacher_id); $context_stmt->execute();
                $context_res = $context_stmt->get_result();
                if ($context_data = $context_res->fetch_assoc()) {
                    $context_class_id = $context_data['class_id']; $context_subject_id = $context_data['subject_id']; $context_academic_year_id = $context_data['academic_year_id'];
                } $context_stmt->close();
            } else { error_log("TMS - Comment context prepare error: ".$conn->error); }

            if (!$context_class_id) { $ajax_response['message'] = 'Could not verify assignment context.'; break; }

            $stmt_insert_comment = $conn->prepare("INSERT INTO student_comments (student_id, comment_by_user_id, comment_by_role, teacher_assignment_id, class_id, subject_id, academic_year_id, comment_text) VALUES (?, ?, 'teacher', ?, ?, ?, ?, ?)");
            if ($stmt_insert_comment) {
                $stmt_insert_comment->bind_param("iiiiiss", $student_id_comment, $teacher_id, $assignment_id_comment, $context_class_id, $context_subject_id, $context_academic_year_id, $comment_text);
                if ($stmt_insert_comment->execute()) { $ajax_response = ['success' => true, 'message' => 'Comment added successfully.']; }
                else { $ajax_response['message'] = 'Failed to add comment: ' . $stmt_insert_comment->error; error_log("TMS - Error adding comment: " . $stmt_insert_comment->error); }
                $stmt_insert_comment->close();
            } else { $ajax_response['message'] = 'DB query error for adding comment: ' . $conn->error; }
            break;
        default: $ajax_response['message'] = 'Unknown AJAX action requested.'; break;
    }
    echo json_encode($ajax_response);
    exit;
}

function get_current_academic_year_info_tms($conn) {
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    return null;
}
$current_academic_year_info_page = get_current_academic_year_info_tms($conn);
$current_academic_year_id_page = $current_academic_year_info_page['id'] ?? null;
$all_academic_years_page = [];
if ($conn) {
    $year_res_page = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC");
    if ($year_res_page) { while($row_page = $year_res_page->fetch_assoc()) { $all_academic_years_page[] = $row_page; } }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light"> <!-- Ensure default theme is set -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Students & Assignments - MACOM Teacher Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Path to general styles.css, assuming this file is in teachers/ and styles.css is in root/style/ -->
    <link href="../style/styles.css" rel="stylesheet">
    <style>
        /*
         Ensure the sidebar, top-navbar, and content-area CSS rules from the previous
         `teacher_dashboard.php`'s embedded style (or your main `../style/styles.css`)
         are loaded and correct.
        */

        /* Page-specific styles for teacher_my_students.php */
        .page-header-tms { /* Renamed to avoid conflict if you had .page-header elsewhere */
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }
        .assignments-list .list-group-item {
            cursor: pointer;
            transition: background-color 0.2s ease-in-out, border-left-color 0.2s ease-in-out;
            border-left: 3px solid transparent;
            padding: 0.75rem 1.25rem;
        }
        .assignments-list .list-group-item:hover {
            background-color: var(--bs-primary-bg-subtle);
            color: var(--bs-primary-text-emphasis);
        }
        .assignments-list .list-group-item.active {
            background-color: var(--bs-primary-bg-subtle);
            border-left-color: var(--bs-primary);
            color: var(--bs-primary-text-emphasis);
            font-weight: 500;
        }
        .student-list-card .card-header {
            background-color: var(--bs-primary);
            color: white;
            padding: 0.75rem 1.25rem;
        }
        .student-photo-list {
            width: 40px; height: 40px; object-fit: cover; border-radius: 50%;
            border: 1px solid var(--bs-border-color); /* Softer border */
        }
        .action-icon-btn { /* For buttons acting as icons */
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
            line-height: 1;
            border-radius: 0.25rem;
        }
        .comment-modal-student-info img {
            width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px;
            border: 2px solid var(--bs-border-color);
        }
        .card-title-icon {
            color: var(--bs-primary); /* Match primary theme color */
        }
        .list-group-flush .list-group-item:last-child {
            border-bottom-width: 0; /* Remove double border if card has one */
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar_teacher.php'; ?>
        <!-- The <main class="main-content-custom p-3 p-md-4"> tag is now opened by nav&sidebar_teacher.php -->
        <!-- Content for THIS PAGE (teacher_my_students.php) starts here -->

            <div class="page-header-tms d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                <h2 class="mb-2 mb-md-0 h3"><i class="fas fa-users me-2 card-title-icon"></i>My Students & Assignments</h2>
                <div class="ms-md-auto"> <!-- Push to right on medium and up -->
                    <label for="filterMyStudentsAcademicYear" class="form-label visually-hidden">Academic Year</label>
                    <select id="filterMyStudentsAcademicYear" class="form-select form-select-sm" style="min-width: 220px;">
                        <?php if (empty($all_academic_years_page)): ?>
                            <option value="">No academic years found</option>
                        <?php else: ?>
                            <?php foreach ($all_academic_years_page as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_academic_year_id_page) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="row g-lg-4 g-md-3 g-2">
                <div class="col-lg-5 col-xl-4 mb-3 mb-lg-0">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0 card-title"><i class="fas fa-clipboard-list me-2 card-title-icon"></i>My Teaching Assignments</h5>
                        </div>
                        <div id="teacherAssignmentsList" class="list-group list-group-flush" style="max-height: calc(100vh - 250px); overflow-y: auto;">
                            <p class="text-muted p-3 text-center">Loading assignments...</p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 col-xl-8">
                    <div class="card shadow-sm student-list-card h-100">
                        <div class="card-header">
                            <h5 class="mb-0 card-title" id="studentsListHeader">
                                <i class="fas fa-user-graduate me-2"></i>Students
                            </h5>
                        </div>
                        <div class="card-body p-0">
                             <div id="studentsForAssignmentContainer" class="table-responsive" style="max-height: calc(100vh - 280px); overflow-y: auto;">
                                <p class="text-muted p-3 text-center" id="studentsPlaceholder">Select an assignment to view students.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <!-- The closing </main> tag is now part of nav&sidebar_teacher.php -->
    </div> <!-- .wrapper -->

    <div class="modal fade" id="addCommentModal" tabindex="-1" aria-labelledby="addCommentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCommentModalLabel">Add Comment for Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addCommentForm">
                    <div class="modal-body">
                        <input type="hidden" id="comment_student_id" name="student_id">
                        <input type="hidden" id="comment_assignment_id" name="assignment_id"> 
                        <div class="mb-3 comment-modal-student-info d-flex align-items-center">
                            <img id="comment_student_photo" src="#" alt="Student" class="border">
                            <div>
                                <strong id="comment_student_name">Student Name</strong><br>
                                <small class="text-muted" id="comment_context_info">Assignment Context</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="comment_text" class="form-label">Comment <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="comment_text" name="comment_text" rows="4" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Submit Comment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const teacherIdPageTMS = <?php echo $teacher_id ? $teacher_id : 'null'; ?>;
        const ajaxUrlTMS = '<?php echo $_SERVER["PHP_SELF"]; ?>'; 
        let currentSelectedAssignmentId = null; 

        // Theme toggle and icon update (ensure these are defined, typically in nav&sidebar or a global script)
        function toggleTheme() { 
            const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('themePreferenceMACOM', newTheme);
            updateThemeIconNavTMS(); 
        }
        function updateThemeIconNavTMS() {
            const themeToggleBtnNav = document.getElementById('themeToggleBtnNav');
            const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
            const themeIconNav = themeToggleBtnNav ? themeToggleBtnNav.querySelector('i') : null;
            if (themeIconNav) {
                themeIconNav.className = currentTheme === 'dark' ? 'fas fa-sun text-warning' : 'fas fa-moon text-primary';
            }
        }

        $(document).ready(function() {
            const savedTheme = localStorage.getItem('themePreferenceMACOM');
            if (savedTheme) { document.documentElement.setAttribute('data-bs-theme', savedTheme); }
            else { document.documentElement.setAttribute('data-bs-theme', window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); }
            updateThemeIconNavTMS();

            // Sidebar JS - This should ideally be in a global JS file loaded by nav&sidebar_teacher.php
            const sidebar = $('#sidebar');
            const sidebarCollapseOuter = $('#sidebarCollapseOuter');
            const sidebarCollapseInner = $('#sidebarCollapseInner');
            let overlay = $('.sidebar-overlay');

            function effectiveToggleSidebar() {
                if (sidebar.length) {
                    if ($(window).width() < 992) { 
                        sidebar.toggleClass('active');
                        toggleOverlayLocal(sidebar.hasClass('active'));
                    } else { 
                        sidebar.toggleClass('collapsed');
                        localStorage.setItem('sidebarState', sidebar.hasClass('collapsed') ? 'collapsed' : 'expanded');
                    }
                }
            }
            
            if (!overlay.length && $(window).width() < 992) {
                overlay = $('<div class="sidebar-overlay"></div>').appendTo('body');
                overlay.on('click', effectiveToggleSidebar); 
            } else if (overlay.length && $(window).width() >= 992) {
                overlay.off('click', effectiveToggleSidebar).remove();
                overlay = $();
            }

            function toggleOverlayLocal(show) { if (overlay.length) overlay.toggleClass('active', show); }
            if (sidebarCollapseOuter.length) sidebarCollapseOuter.on('click', effectiveToggleSidebar);
            if (sidebarCollapseInner.length) sidebarCollapseInner.on('click', effectiveToggleSidebar);
            
            function applyInitialSidebarStateLocal() {
                if ($(window).width() >= 992) {
                    const storedSidebarState = localStorage.getItem('sidebarState');
                    if (sidebar.length) {
                        if (storedSidebarState === 'collapsed') sidebar.addClass('collapsed');
                        else sidebar.removeClass('collapsed');
                    }
                    if (overlay.length) overlay.removeClass('active');
                } else {
                    if (sidebar.length) sidebar.removeClass('collapsed');
                }
            }
            applyInitialSidebarStateLocal();
            $(window).on('resize', applyInitialSidebarStateLocal);
            
            const currentDateNav = $('#currentDateNav');
            const currentTimeNav = $('#currentTimeNav');
            function updateDateTimeNavLocal() {
                const now = new Date();
                if(currentDateNav.length) currentDateNav.text(now.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }));
                if(currentTimeNav.length) currentTimeNav.text(now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }));
            }
            if(currentDateNav.length || currentTimeNav.length) { updateDateTimeNavLocal(); setInterval(updateDateTimeNavLocal, 30000); }
            const themeToggleBtnNav = $('#themeToggleBtnNav');
            if(themeToggleBtnNav.length) themeToggleBtnNav.on('click', toggleTheme);
            // End of Sidebar/Nav JS

            const initialAcademicYearIdPage = $('#filterMyStudentsAcademicYear').val();
            if (teacherIdPageTMS && initialAcademicYearIdPage) {
                loadTeacherAssignments(initialAcademicYearIdPage);
            } else {
                $('#teacherAssignmentsList').html('<p class="text-muted p-3 text-center">Select an academic year to view assignments.</p>');
                $('#studentsForAssignmentContainer').html('<p class="text-muted p-3 text-center" id="studentsPlaceholder">Select an assignment to view students.</p>');
            }

            $('#filterMyStudentsAcademicYear').on('change', function() {
                if (teacherIdPageTMS) {
                    loadTeacherAssignments($(this).val());
                    $('#studentsForAssignmentContainer').html('<p class="text-muted p-3 text-center" id="studentsPlaceholder">Select an assignment to view students.</p>');
                    $('#studentsListHeader').html('<i class="fas fa-user-graduate me-2"></i>Students');
                    currentSelectedAssignmentId = null;
                }
            });

            $('#addCommentForm').on('submit', function(e){
                e.preventDefault();
                const form = $(this); const btn = form.find('button[type="submit"]');
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Submitting...');
                $.ajax({
                    url: ajaxUrlTMS, type: 'POST', data: form.serialize() + '&action=add_student_comment_by_teacher', dataType: 'json',
                    success: function(response){
                        if(response.success){ alert(response.message || 'Comment added.'); $('#addCommentModal').modal('hide'); form[0].reset(); } 
                        else { alert('Error: ' + (response.message || 'Failed.')); }
                    },
                    error: function(){ alert('AJAX error.'); },
                    complete: function(){ btn.prop('disabled', false).html('<i class="fas fa-paper-plane me-1"></i>Submit Comment'); }
                });
            });
        });

        function loadTeacherAssignments(academicYearId) {
            const container = $('#teacherAssignmentsList');
            container.html('<p class="text-muted p-3 text-center"><span class="spinner-border spinner-border-sm"></span> Loading...</p>');
            if (!academicYearId) { container.html('<p class="text-muted p-3 text-center">Select academic year.</p>'); return; }

            $.ajax({
                url: ajaxUrlTMS, type: 'GET', data: { action: 'get_teacher_assignments', academic_year_id: academicYearId }, dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.assignments && response.assignments.length > 0) {
                        response.assignments.forEach(item => {
                            const assignmentItem = $(`<a href="#" class="list-group-item list-group-item-action" data-assignment-id="${item.assignment_id}" data-class-id="${item.class_id}" data-subject-id="${item.subject_id}" data-academic-year-id="${academicYearId}"><div class="d-flex w-100 justify-content-between"><h6 class="mb-1">${item.class_name} - ${item.subject_name}</h6><small class="text-body-secondary">${item.paper_code || 'N/A'}</small></div><p class="mb-1 small text-body-secondary">${item.paper_name}</p></a>`);
                            assignmentItem.on('click', function(e){ e.preventDefault(); container.find('.list-group-item.active').removeClass('active'); $(this).addClass('active'); currentSelectedAssignmentId = item.assignment_id; loadStudentsForAssignment(item.class_id, item.subject_id, academicYearId, item.class_name, item.subject_name, item.paper_name); });
                            container.append(assignmentItem);
                        });
                    } else if (response.success) { container.html('<p class="text-muted p-3 text-center">No assignments for this year.</p>');
                    } else { container.html('<p class="text-danger p-3 text-center">Error: ' + (response.message || 'Could not load.') + '</p>'); }
                },
                error: function() { container.html('<p class="text-danger p-3 text-center">Failed to load assignments.</p>'); }
            });
        }

        function loadStudentsForAssignment(classId, subjectId, academicYearId, className, subjectName, paperName) {
            const container = $('#studentsForAssignmentContainer'); const header = $('#studentsListHeader');
            header.html(`<i class="fas fa-user-graduate me-2"></i>Students: ${className} - ${subjectName} (${paperName})`);
            container.html('<p class="text-muted p-3 text-center"><span class="spinner-border spinner-border-sm"></span> Loading...</p>');
            $.ajax({
                url: ajaxUrlTMS, type: 'GET', data: { action: 'get_students_for_teacher_assignment', class_id: classId, subject_id: subjectId, academic_year_id: academicYearId }, dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.students && response.students.length > 0) {
                        const table = $('<table class="table table-hover table-sm mb-0"><thead><tr><th>Photo</th><th>Adm No.</th><th>Name</th><th>Sex</th><th>Actions</th></tr></thead><tbody></tbody></table>');
                        response.students.forEach(student => {
                            let photoSrc = student.photo ? `../${student.photo}` : `https://ui-avatars.com/api/?name=${encodeURIComponent(student.full_name)}&size=40&background=random&color=fff`;
                            const row = $('<tr></tr>');
                            row.append(`<td><img src="${photoSrc}" class="student-photo-list" alt="${student.full_name}"></td>`);
                            row.append($('<td></td>').text(student.admission_number || 'N/A'));
                            row.append($('<td></td>').html(`<a href="../student_details.php?id=${student.id}" target="_blank">${student.full_name}</a>`));
                            row.append($('<td></td>').text(student.sex || 'N/A'));
                            const actionsCell = $('<td></td>');
                            const commentBtn = $('<button class="btn btn-xs btn-outline-secondary action-icon-btn" title="Add Comment"><i class="fas fa-comment-dots"></i></button>').on('click', function() { openAddCommentModal(student.id, student.full_name, photoSrc, className, subjectName, paperName, currentSelectedAssignmentId); });
                            actionsCell.append(commentBtn);
                            row.append(actionsCell); table.find('tbody').append(row);
                        });
                        container.append(table);
                    } else if (response.success) { container.html('<p class="text-muted p-3 text-center">No students for selection.</p>');
                    } else { container.html('<p class="text-danger p-3 text-center">Error: ' + (response.message || 'Could not load.') + '</p>'); }
                },
                error: function() { container.html('<p class="text-danger p-3 text-center">Failed to load students.</p>');}
            });
        }

        function openAddCommentModal(studentId, studentName, studentPhoto, className, subjectName, paperName, assignmentId) {
            $('#comment_student_id').val(studentId); $('#comment_assignment_id').val(assignmentId); 
            $('#comment_student_photo').attr('src', studentPhoto); $('#comment_student_name').text(studentName);
            $('#comment_context_info').text(`Class: ${className}, Subject: ${subjectName} (${paperName})`);
            $('#comment_text').val(''); $('#addCommentModal').modal('show');
        }
    </script>
</body>
</html>