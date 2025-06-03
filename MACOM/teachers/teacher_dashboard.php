<?php
// teachers/teacher_dashboard.php
require_once '../db_config.php'; 
require_once '../auth.php';     

check_login('teacher');

$teacher_id = $_SESSION['user_id'] ?? null;
$teacher_full_name = $_SESSION['full_name'] ?? 'Teacher';
// $teacher_role and $teacher_profile_pic are handled by nav&sidebar_teacher.php

function get_current_academic_year_info_teach_dash($conn) {
    if (!$conn) return null;
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    $stmt = $conn->prepare("SELECT id, year_name FROM academic_years ORDER BY start_date DESC LIMIT 1");
    if ($stmt) { $stmt->execute(); $result = $stmt->get_result(); if ($year = $result->fetch_assoc()) { $stmt->close(); return $year; } $stmt->close(); }
    return null;
}

$current_academic_year_info = get_current_academic_year_info_teach_dash($conn);
$current_academic_year_id = $current_academic_year_info['id'] ?? null;
$current_academic_year_name = $current_academic_year_info['year_name'] ?? 'N/A';

$all_academic_years = [];
if ($conn) {
    $year_res = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC");
    if ($year_res) { while($row = $year_res->fetch_assoc()) { $all_academic_years[] = $row; } }
}

$success_message_tdash = $_SESSION['success_message_tdash'] ?? null;
$error_message_tdash = $_SESSION['error_message_tdash'] ?? null;
if ($success_message_tdash) unset($_SESSION['success_message_tdash']);
if ($error_message_tdash) unset($_SESSION['error_message_tdash']);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet"> <!-- Path to global styles.css -->
    <style>
        /* Add any page-specific overrides or new styles here if needed */
        /* Most sidebar/navbar styles should come from ../style/styles.css */
        
        /* Dashboard specific styles (can be moved to styles.css too) */
        .dashboard-header {
            background: linear-gradient(135deg, var(--bs-primary) 0%, var(--bs-primary-dark, #0a58ca) 100%);
            color: white; padding: 2rem 1.5rem; border-radius: .75rem;
            margin-bottom: 2rem; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15);
        }
        .dashboard-header h1 { font-weight: 300; font-size: 2.25rem; }
        .dashboard-header .lead { font-size: 1.1rem; opacity: .9; }

        .stats-card-teacher {
            background-color: var(--bs-card-bg, var(--bs-body-bg));
            border: 1px solid var(--bs-border-color); border-left: 5px solid var(--bs-primary);
            border-radius: .5rem; padding: 1.25rem; margin-bottom: 1.5rem;
            transition: all 0.3s ease-in-out; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        }
        .stats-card-teacher:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1); }
        .stats-card-teacher .stat-icon { font-size: 2rem; color: var(--bs-primary); opacity: 0.7; }
        .stats-card-teacher .stat-value { font-size: 1.75rem; font-weight: 600; color: var(--bs-emphasis-color); }
        .stats-card-teacher .stat-label { font-size: 0.9rem; color: var(--bs-secondary-color); }
        
        .section-card {
            background-color: var(--bs-card-bg, var(--bs-body-bg));
            border: 1px solid var(--bs-border-color); border-radius: .75rem;
            margin-bottom: 2rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        }
        .section-card .card-header {
            background-color: var(--bs-tertiary-bg); font-weight: 500;
            padding: 1rem 1.25rem; border-bottom: 1px solid var(--bs-border-color);
        }
        .student-photo-thumb-modal { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; background-color: var(--bs-secondary-bg); }
    </style>
</head>
<body>
    <?php include 'nav&sidebar_teacher.php'; ?>
    <!-- The include nav&sidebar_teacher.php opens <div class="wrapper">, <nav id="sidebar">, and <div id="content"> with top nav -->
    <!-- Page specific content starts here, inside the #content div from the include -->
        <main class="p-3 p-md-4">
            <div class="dashboard-header">
                <h1 class="display-5">Welcome, <?php echo htmlspecialchars(explode(' ', $teacher_full_name)[0]); ?>!</h1>
                <p class="lead">Manage your classes, students, and grades efficiently.</p>
                <p class="small mb-0">Current Academic Year: <strong><?php echo htmlspecialchars($current_academic_year_name); ?></strong></p>
            </div>

            <?php if ($success_message_tdash): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_tdash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error_message_tdash): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($error_message_tdash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card-teacher">
                        <div class="d-flex align-items-center"><div class="stat-icon me-3"><i class="fas fa-chalkboard"></i></div><div><div class="stat-value" id="statTotalClasses">...</div><div class="stat-label">Assigned Classes</div></div></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card-teacher">
                        <div class="d-flex align-items-center"><div class="stat-icon me-3"><i class="fas fa-book-open-reader"></i></div><div><div class="stat-value" id="statTotalSubjects">...</div><div class="stat-label">Distinct Subjects Taught</div></div></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card-teacher">
                         <div class="d-flex align-items-center"><div class="stat-icon me-3"><i class="fas fa-copy"></i></div><div><div class="stat-value" id="statTotalPapers">...</div><div class="stat-label">Assigned Papers</div></div></div>
                    </div>
                </div>
            </div>

            <div class="section-card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="mb-0 me-2"><i class="fas fa-tasks me-2 text-primary"></i>My Assigned Subject Papers</h5>
                    <div class="mt-2 mt-md-0">
                        <label for="filterTeacherAcademicYear" class="form-label visually-hidden">Academic Year</label>
                        <select id="filterTeacherAcademicYear" class="form-select form-select-sm" style="min-width: 200px;">
                            <?php if (empty($all_academic_years)): ?> <option value="">No academic years found</option>
                            <?php else: ?>
                                <?php foreach ($all_academic_years as $year): ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == $current_academic_year_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0"><div id="teacherAssignedPapersContainer" class="table-responsive"><p class="text-muted text-center p-3">Loading your assignments...</p></div></div>
            </div>

            <div class="row mt-4">
                <div class="col-md-6 mb-4"><div class="section-card h-100"><div class="card-header"><h5 class="mb-0"><i class="far fa-calendar-alt me-2 text-success"></i>My Timetable</h5></div><div class="card-body"><p class="text-muted">Feature coming soon.</p></div></div></div>
                <div class="col-md-6 mb-4"><div class="section-card h-100"><div class="card-header"><h5 class="mb-0"><i class="fas fa-bullhorn me-2 text-info"></i>Notifications</h5></div><div class="card-body"><p class="text-muted">Feature coming soon.</p></div></div></div>
            </div>
        </main>
    <!-- Closing #content and .wrapper are in nav&sidebar_teacher.php -->


    <div class="modal fade" id="teacherViewStudentsModal" tabindex="-1" aria-labelledby="teacherViewStudentsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="teacherViewStudentsModalLabel">Students List</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body"><div id="teacherStudentsListContainer"><p class="text-center">Loading students...</p></div></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Path to global script.js -->
    <script src="../javascript/script.js"></script> 
    <script>
        const teacherIdLoggedIn = <?php echo $teacher_id ? $teacher_id : 'null'; ?>;
        const ajaxTeacherDetailsUrl = '../teacher_details.php'; 

        // The main sidebar toggle and theme toggle JS should be in ../javascript/script.js
        // This page-specific script will focus on its own AJAX calls.

        $(document).ready(function () {
            // Initial load of assignments
            const initialAcademicYearId = $('#filterTeacherAcademicYear').val();
            if (teacherIdLoggedIn && initialAcademicYearId) {
                loadTeacherAssignedPapers(initialAcademicYearId);
            } else if (!teacherIdLoggedIn) {
                 $('#teacherAssignedPapersContainer').html('<div class="alert alert-danger p-2 m-2">Teacher ID error.</div>');
            } else {
                 $('#teacherAssignedPapersContainer').html('<div class="alert alert-info p-2 m-2">Select academic year.</div>');
            }

            // Handler for academic year filter change
            $('#filterTeacherAcademicYear').on('change', function() {
                if (teacherIdLoggedIn) {
                    loadTeacherAssignedPapers($(this).val());
                }
            });
        });

        function loadTeacherAssignedPapers(academicYearId) {
            // ... (keep the exact same loadTeacherAssignedPapers function from previous response)
            const container = $('#teacherAssignedPapersContainer');
            container.html('<p class="text-center p-3"><span class="spinner-border spinner-border-sm"></span> Loading...</p>');
            if (!academicYearId) {
                container.html('<p class="text-muted text-center p-3">Select academic year.</p>');
                updateStats([]); return;
            }
            $.ajax({
                url: ajaxTeacherDetailsUrl, type: 'GET',
                data: { action: 'get_assigned_papers', teacher_id: teacherIdLoggedIn, academic_year_id: academicYearId },
                dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.assigned_papers && response.assigned_papers.length > 0) {
                        const table = $('<table class="table table-sm table-hover table-striped mb-0"><thead><tr><th>Class</th><th>Subject</th><th>Paper</th><th>Code</th><th>Actions</th></tr></thead><tbody></tbody></table>');
                        response.assigned_papers.forEach(item => {
                            const row = $('<tr></tr>');
                            row.append($('<td></td>').text(item.class_name));
                            row.append($('<td></td>').text(item.subject_name));
                            row.append($('<td></td>').text(item.paper_name));
                            row.append($('<td></td>').text(item.paper_code || 'N/A'));
                            const actions = $('<td></td>');
                            const viewStudentsBtn = $('<button class="btn btn-xs btn-outline-primary me-1" title="View Students"><i class="fas fa-users"></i> <span class="d-none d-sm-inline">Students</span></button>');
                            viewStudentsBtn.on('click', function(){ 
                                openTeacherViewStudentsModal(item.class_id_for_students, item.subject_id_for_students, academicYearId, item.class_name, item.subject_name, item.paper_name);
                            });
                            actions.append(viewStudentsBtn);
                            row.append(actions);
                            table.find('tbody').append(row);
                        });
                        container.append(table);
                        updateStats(response.assigned_papers);
                    } else if (response.success) {
                        container.html('<div class="alert alert-info p-2 m-2">No assignments for this year.</div>');
                        updateStats([]);
                    } else {
                        container.html('<div class="alert alert-danger p-2 m-2">Error: ' + (response.message || 'Could not load.') + '</div>');
                        updateStats([]);
                    }
                },
                error: function(jqXHR) { 
                    console.error("AJAX error loading assigned papers:", jqXHR.responseText);
                    container.html('<div class="alert alert-danger p-2 m-2">Failed to load. AJAX URL: '+ajaxTeacherDetailsUrl+'</div>'); 
                    updateStats([]);
                }
            });
        }

        function updateStats(assignedPapers) {
            // ... (keep the exact same updateStats function from previous response)
            let classes = new Set(); let subjects = new Set();
            $('#statTotalPapers').text(assignedPapers.length);
            assignedPapers.forEach(item => { classes.add(item.class_name); subjects.add(item.subject_name); });
            $('#statTotalClasses').text(classes.size); $('#statTotalSubjects').text(subjects.size);
        }

        function openTeacherViewStudentsModal(classId, subjectId, academicYearId, className, subjectName, paperName) {
            // ... (keep the exact same openTeacherViewStudentsModal function from previous response)
            const modal = $('#teacherViewStudentsModal'); const container = $('#teacherStudentsListContainer');
            const modalLabel = $('#teacherViewStudentsModalLabel');
            modalLabel.text(`Students in ${className} taking ${subjectName} (Paper: ${paperName})`);
            container.html('<p class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Loading...</p>');
            modal.modal('show');
            $.ajax({
                url: ajaxTeacherDetailsUrl, type: 'GET',
                data: { action: 'get_students_for_subject_in_class', class_id: classId, subject_id: subjectId, academic_year_id: academicYearId },
                dataType: 'json',
                success: function(response) {
                    container.empty();
                    if (response.success && response.students && response.students.length > 0) {
                        const studentTable = $('<table class="table table-sm table-hover table-striped"><thead><tr><th>Photo</th><th>Adm No.</th><th>Full Name</th><th>Sex</th></tr></thead><tbody></tbody></table>');
                        response.students.forEach(student => {
                            let photoSrc = student.photo ? `../${student.photo}` : `https://ui-avatars.com/api/?name=${encodeURIComponent(student.full_name)}&size=35&background=random&color=fff`;
                            const studentRow = `<tr>
                                    <td><img src="${photoSrc}" class="student-photo-thumb-modal" alt="${student.full_name}"></td>
                                    <td>${student.admission_number || 'N/A'}</td>
                                    <td><a href="../student_details.php?id=${student.id}" target="_blank">${student.full_name}</a></td>
                                    <td>${student.sex || 'N/A'}</td></tr>`;
                            studentTable.find('tbody').append(studentRow);
                        });
                        container.append(studentTable);
                    } else if (response.success) { container.html('<div class="alert alert-info">No students for this assignment.</div>');
                    } else { container.html('<div class="alert alert-danger">Error: ' + (response.message || 'Could not load.') + '</div>'); }
                },
                error: function(jqXHR) {
                     console.error("AJAX error loading students:", jqXHR.responseText);
                    container.html('<div class="alert alert-danger">Failed to load students. AJAX URL: '+ajaxTeacherDetailsUrl+'</div>');
                }
            });
        }
        // ../javascript/script.js
$(document).ready(function () {
    // Sidebar Toggle Functionality
    const sidebar = $('#sidebar');
    const content = $('#content'); // The main content area next to the sidebar

    $('#sidebarCollapse').on('click', function () {
        sidebar.toggleClass('active');
        // If your #content div also needs an 'active' class to adjust its margin/padding:
        if (content.length) { // Check if #content element exists
            content.toggleClass('active'); 
        }
    });

    // Persist sidebar state (optional) - from Head Teacher's template
    // This part assumes your sidebar uses 'active' for collapsed state.
    // If it's the other way (active = expanded), adjust logic.
    // Modern approach uses 'collapsed' class usually.
    // For the provided HT dashboard HTML, 'active' class means sidebar is HIDDEN (margin-left: -250px).
    // So, if 'sidebarToggled' is 'true', we want it hidden (add 'active').
    // if (localStorage.getItem('sidebarToggled') === 'true') {
    //     $('#sidebar').addClass('active');
    //     if (content.length) content.addClass('active');
    // }
    // $('#sidebarCollapse').on('click', function () {
    //     // $('#sidebar').toggleClass('active'); // This is already there
    //     // $('#content').toggleClass('active'); // This is already there
    //     localStorage.setItem('sidebarToggled', $('#sidebar').hasClass('active'));
    // });


    // Theme Toggle Functionality
    const themeToggleCheckbox = document.getElementById('themeToggleCheckboxNav') || document.getElementById('themeToggleCheckbox'); // Check for both IDs

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        if (themeToggleCheckbox) {
            themeToggleCheckbox.checked = theme === 'dark';
            // Update slider icons if they exist
            const slider = themeToggleCheckbox.nextElementSibling; 
            if (slider && slider.classList.contains('slider')) {
                const sunIcon = slider.querySelector('.fa-sun');
                const moonIcon = slider.querySelector('.fa-moon');
                if (sunIcon && moonIcon) {
                    sunIcon.style.opacity = theme === 'dark' ? 1 : 0;
                    moonIcon.style.opacity = theme === 'dark' ? 0 : 1;
                }
            }
        }
    }

    const preferredTheme = localStorage.getItem('themePreferenceMACOM') || 
                         (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    applyTheme(preferredTheme);

    if (themeToggleCheckbox) {
        themeToggleCheckbox.addEventListener('change', function () {
            const newTheme = this.checked ? 'dark' : 'light';
            applyTheme(newTheme);
            localStorage.setItem('themePreferenceMACOM', newTheme);
        });
    }

    // Date and Time Display in Top Nav
    function updateDateTime() {
        const now = new Date();
        const dateEl = document.getElementById('current-date');
        const timeEl = document.getElementById('current-time');

        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }
        if (timeEl) {
            timeEl.textContent = now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        }
    }

    if (document.getElementById('current-date') || document.getElementById('current-time')) {
        updateDateTime();
        setInterval(updateDateTime, 30000); // Update every 30 seconds is enough for date/time display
    }
});
    </script>
</body>
</html>