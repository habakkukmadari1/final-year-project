<?php
// director_dashboard.php
require_once '../db_config.php';
require_once '../auth.php';

check_login('director_of_studies');

$dos_full_name = $_SESSION['full_name'] ?? 'Director of Studies';
$user_id_dos = $_SESSION['user_id']; // For audit trails

// --- Fetch Summary Data ---
$stats = [
    'total_classes' => 0,
    'total_teachers' => 0,
    'total_students' => 0,
    'total_subjects' => 0,
];

if ($conn) {
    $result_classes = $conn->query("SELECT COUNT(id) as count FROM classes");
    if ($result_classes) $stats['total_classes'] = $result_classes->fetch_assoc()['count'];

    $result_teachers = $conn->query("SELECT COUNT(id) as count FROM teachers"); // Simplified for now
    if ($result_teachers) $stats['total_teachers'] = $result_teachers->fetch_assoc()['count'];

    // More accurate student count for current academic year
    $current_year_id_for_query = null;
    $year_q_temp = $conn->query("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
    if ($year_q_temp && $year_q_temp->num_rows > 0) {
        $current_year_id_for_query = $year_q_temp->fetch_assoc()['id'];
    } else {
        $year_q_temp = $conn->query("SELECT id FROM academic_years ORDER BY start_date DESC LIMIT 1");
        if ($year_q_temp) $current_year_id_for_query = $year_q_temp->fetch_assoc()['id'];
    }

    if ($current_year_id_for_query) {
        $stmt_students = $conn->prepare("SELECT COUNT(DISTINCT se.student_id) as count FROM student_enrollments se WHERE se.academic_year_id = ? AND se.status IN ('enrolled', 'promoted', 'repeated')");
        if ($stmt_students) {
            $stmt_students->bind_param("i", $current_year_id_for_query);
            $stmt_students->execute();
            $result_students = $stmt_students->get_result();
            if ($result_students) $stats['total_students'] = $result_students->fetch_assoc()['count'];
            $stmt_students->close();
        }
    } else {
        // Fallback if no academic year is current/exists
        $result_students_all = $conn->query("SELECT COUNT(id) as count FROM students WHERE enrollment_status = 'active'");
        if ($result_students_all) $stats['total_students'] = $result_students_all->fetch_assoc()['count'];
    }


    $result_subjects = $conn->query("SELECT COUNT(id) as count FROM subjects");
    if ($result_subjects) $stats['total_subjects'] = $result_subjects->fetch_assoc()['count'];
}

$success_message_dos = $_SESSION['success_message_dos'] ?? null;
$error_message_dos = $_SESSION['error_message_dos'] ?? null;
if ($success_message_dos) unset($_SESSION['success_message_dos']);
if ($error_message_dos) unset($_SESSION['error_message_dos']);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DoS Dashboard - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- Chart.js CDN -->
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, var(--bs-info) 0%, var(--bs-info-dark, #087990) 100%);
            color: white; padding: 2rem 1.5rem; border-radius: .75rem;
            margin-bottom: 2rem; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15);
        }
        .dashboard-header h1 { font-weight: 300; font-size: 2.25rem; }
        .stats-card-dos {
            background-color: var(--bs-card-bg, var(--bs-body-bg));
            border: 1px solid var(--bs-border-color); border-left: 5px solid var(--bs-info);
            border-radius: .5rem; padding: 1.25rem; margin-bottom: 1.5rem;
            transition: all 0.3s ease-in-out; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        }
        .stats-card-dos:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1); }
        .stats-card-dos .stat-icon { font-size: 2rem; color: var(--bs-info); opacity: 0.7; }
        .stats-card-dos .stat-value { font-size: 1.75rem; font-weight: 600; color: var(--bs-emphasis-color); }
        .stats-card-dos .stat-label { font-size: 0.9rem; color: var(--bs-secondary-color); }
        .quick-link-card { text-align: center; padding: 1.5rem; }
        .quick-link-card i { font-size: 2.5rem; margin-bottom: 0.75rem; color: var(--bs-info); }
        .quick-link-card h5 { font-size: 1.1rem; }
        .chart-container {
            position: relative; height: 350px; width: 100%;
            background-color: var(--bs-card-bg, var(--bs-body-bg));
            padding: 1rem; border-radius: .5rem;
            border: 1px solid var(--bs-border-color);
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        }
        .notifications-panel .list-group-item { border-left-width: 3px; }
        .notifications-panel .list-group-item-primary { border-left-color: var(--bs-primary); }
        .notifications-panel .list-group-item-warning { border-left-color: var(--bs-warning); }
        .notifications-panel .list-group-item-danger { border-left-color: var(--bs-danger); }
    </style>
</head>
<body>
    <?php include 'nav&sidebar_dos.php'; // Assuming this is in the same directory or adjust path ?>

    <main class="p-3 p-md-4">
        <div class="dashboard-header">
            <h1 class="display-5">Welcome, <?php echo htmlspecialchars(explode(' ', $dos_full_name)[0]); ?>!</h1>
            <p class="lead">Oversee and manage academic activities and performance.</p>
        </div>

        <?php if ($success_message_dos): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_dos); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error_message_dos): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($error_message_dos); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-school"></i></div>
                        <div><div class="stat-value" id="statTotalClassesDos"><?php echo $stats['total_classes']; ?></div><div class="stat-label">Managed Classes</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div><div class="stat-value" id="statTotalTeachersDos"><?php echo $stats['total_teachers']; ?></div><div class="stat-label">Active Teachers</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-user-graduate"></i></div>
                        <div><div class="stat-value" id="statTotalStudentsDos"><?php echo $stats['total_students']; ?></div><div class="stat-label">Enrolled Students</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-book"></i></div>
                        <div><div class="stat-value" id="statTotalSubjectsDos"><?php echo $stats['total_subjects']; ?></div><div class="stat-label">Subjects Offered</div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row mb-4">
            <div class="col-md-7">
                <div class="chart-container">
                    <canvas id="studentPerformanceChart"></canvas>
                </div>
                <small class="text-muted text-center mt-1">Overall Student Performance (Sample)</small>
            </div>
            <div class="col-md-5">
                <div class="chart-container">
                    <canvas id="subjectDistributionChart"></canvas>
                </div>
                 <small class="text-muted text-center mt-1">Subject Enrollment Distribution (Sample)</small>
            </div>
        </div>
        
        <!-- Quick Links & Notifications -->
        <div class="row">
            <div class="col-lg-8">
                <h5 class="mb-3"><i class="fas fa-rocket me-2"></i>Quick Actions</h5>
                <div class="row">
                     <div class="col-md-6 col-lg-4 mb-3">
                        <a href="dos_assign_student_class.php" class="text-decoration-none">
                            <div class="card quick-link-card h-100 shadow-sm"><i class="fas fa-user-plus"></i><h6>Assign Students to Class</h6></div>
                        </a>
                    </div>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <a href="dos_assign_teacher_subjects.php" class="text-decoration-none">
                             <div class="card quick-link-card h-100 shadow-sm"><i class="fas fa-chalkboard-teacher"></i><h6>Assign Teachers to Subjects</h6></div>
                        </a>
                    </div>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <a href="dos_exam_management.php" class="text-decoration-none">
                             <div class="card quick-link-card h-100 shadow-sm"><i class="fas fa-file-alt"></i><h6>Manage Examinations</h6></div>
                        </a>
                    </div>
                     <div class="col-md-6 col-lg-4 mb-3">
                        <a href="../class_management.php" class="text-decoration-none">
                             <div class="card quick-link-card h-100 shadow-sm"><i class="fas fa-sitemap"></i><h6>View Class Structure</h6></div>
                        </a>
                    </div>
                     <div class="col-md-6 col-lg-4 mb-3">
                        <a href="dos_view_all_assignments.php" class="text-decoration-none"> <!-- New Page we might create -->
                             <div class="card quick-link-card h-100 shadow-sm"><i class="fas fa-tasks"></i><h6>All Teacher Assignments</h6></div>
                        </a>
                    </div>
                     <div class="col-md-6 col-lg-4 mb-3">
                        <a href="dos_reports.php" class="text-decoration-none"> <!-- New Page -->
                             <div class="card quick-link-card h-100 shadow-sm"><i class="fas fa-chart-pie"></i><h6>Academic Reports</h6></div>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                 <h5 class="mb-3"><i class="fas fa-bell me-2"></i>Notifications & Alerts</h5>
                 <div class="card shadow-sm notifications-panel">
                    <div class="list-group list-group-flush">
                        <a href="#" class="list-group-item list-group-item-action list-group-item-warning">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Upcoming: Mid-Term Exams</h6><small>3 days ago</small>
                            </div>
                            <p class="mb-1 small">Schedule to be finalized by end of week.</p>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action list-group-item-primary">
                             <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">S3 Maths Paper Unassigned</h6><small>1 day ago</small>
                            </div>
                            <p class="mb-1 small">Teacher needed for S3 Mathematics Paper 1.</p>
                        </a>
                         <a href="#" class="list-group-item list-group-item-action">
                            <p class="mb-0 text-center text-muted small py-2">View all notifications</p>
                        </a>
                        <!-- Add more notifications dynamically here -->
                    </div>
                 </div>
            </div>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../javascript/script.js"></script>
    <script>
        $(document).ready(function() {
            // Sample Data for Charts (Replace with AJAX calls to fetch real data)
            const studentPerformanceData = {
                labels: ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'],
                datasets: [{
                    label: 'Average Score % (Term 1)',
                    data: [65, 72, 68, 75, 70, 78],
                    backgroundColor: 'rgba(23, 162, 184, 0.6)', // bs-info with opacity
                    borderColor: 'rgba(23, 162, 184, 1)',
                    borderWidth: 1
                }]
            };
            const subjectDistributionData = {
                labels: ['Mathematics', 'English', 'Physics', 'Chemistry', 'Biology', 'History', 'Geography'],
                datasets: [{
                    label: 'Students Enrolled',
                    data: [250, 280, 180, 170, 190, 200, 210],
                    backgroundColor: [
                        'rgba(0, 123, 255, 0.7)', 'rgba(220, 53, 69, 0.7)',
                        'rgba(255, 193, 7, 0.7)', 'rgba(40, 167, 69, 0.7)',
                        'rgba(23, 162, 184, 0.7)', 'rgba(108, 117, 125, 0.7)',
                        'rgba(253, 126, 20, 0.7)'
                    ],
                }]
            };

            // Student Performance Chart
            const perfCtx = document.getElementById('studentPerformanceChart')?.getContext('2d');
            if (perfCtx) {
                new Chart(perfCtx, {
                    type: 'bar', data: studentPerformanceData,
                    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
                });
            }

            // Subject Distribution Chart
            const subjCtx = document.getElementById('subjectDistributionChart')?.getContext('2d');
            if (subjCtx) {
                new Chart(subjCtx, {
                    type: 'doughnut', data: subjectDistributionData,
                    options: { responsive: true, maintainAspectRatio: false }
                });
            }

            // AJAX calls to fetch real data for charts would go here
            // fetchPerformanceData();
            // fetchSubjectDistributionData();
        });

        // function fetchPerformanceData() { /* AJAX call */ }
        // function fetchSubjectDistributionData() { /* AJAX call */ }
    </script>
</body>
</html>