<?php
// director_dashboard.php
require_once '../db_config.php'; // Assuming DoS dashboard is in a subdirectory like /dos/
require_once '../auth.php';     // Or adjust path if in root

check_login('director_of_studies'); // Restrict access to DoS

$dos_full_name = $_SESSION['full_name'] ?? 'Director of Studies';

// Fetch any summary data needed for the DoS dashboard (e.g., total students, classes, upcoming exams)
// For now, let's keep it simple.

$success_message_dos = $_SESSION['success_message_dos'] ?? null;
$error_message_dos = $_SESSION['error_message_dos'] ?? null;
if ($success_message_dos) unset($_SESSION['success_message_dos']);
if ($error_message_dos) unset($_SESSION['error_message_dos']);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light"> <!-- Default to light, JS will handle preference -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DoS Dashboard - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet"> <!-- Adjust path if needed -->
    <style>
        /* Styles similar to teacher_dashboard for consistency */
        .dashboard-header {
            background: linear-gradient(135deg, var(--bs-info) 0%, var(--bs-info-dark, #087990) 100%); /* Different color for DoS */
            color: white; padding: 2rem 1.5rem; border-radius: .75rem;
            margin-bottom: 2rem; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15);
        }
        .dashboard-header h1 { font-weight: 300; font-size: 2.25rem; }
        .stats-card-dos { /* Similar to stats-card-teacher but can be distinct */
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
    </style>
</head>
<body>
    <?php include 'nav&sidebar_dos.php'; // We'll create this next ?>

    <main class="p-3 p-md-4">
        <div class="dashboard-header">
            <h1 class="display-5">Welcome, <?php echo htmlspecialchars(explode(' ', $dos_full_name)[0]); ?>!</h1>
            <p class="lead">Oversee and manage academic activities and performance.</p>
        </div>

        <?php if ($success_message_dos): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($success_message_dos); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error_message_dos): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($error_message_dos); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <!-- Summary Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-school"></i></div>
                        <div><div class="stat-value" id="statTotalClassesDos">--</div><div class="stat-label">Managed Classes</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div><div class="stat-value" id="statTotalTeachersDos">--</div><div class="stat-label">Active Teachers</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-user-graduate"></i></div>
                        <div><div class="stat-value" id="statTotalStudentsDos">--</div><div class="stat-label">Enrolled Students</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card-dos">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon me-3"><i class="fas fa-book"></i></div>
                        <div><div class="stat-value" id="statTotalSubjectsDos">--</div><div class="stat-label">Subjects Offered</div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="row">
            <div class="col-md-4 col-lg-3 mb-3">
                <a href="dos_assign_student_class.php" class="text-decoration-none">
                    <div class="card quick-link-card h-100 shadow-sm">
                        <i class="fas fa-user-plus"></i><h5>Assign Students to Class</h5>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-lg-3 mb-3">
                <a href="dos_assign_teacher_subjects.php" class="text-decoration-none">
                     <div class="card quick-link-card h-100 shadow-sm">
                        <i class="fas fa-chalkboard-teacher"></i><h5>Assign Teachers to Subjects</h5>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-lg-3 mb-3">
                <a href="dos_exam_management.php" class="text-decoration-none">
                     <div class="card quick-link-card h-100 shadow-sm">
                        <i class="fas fa-file-alt"></i><h5>Manage Examinations</h5>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-lg-3 mb-3">
                <a href="../class_management.php" class="text-decoration-none"> <!-- Link to existing class management -->
                     <div class="card quick-link-card h-100 shadow-sm">
                        <i class="fas fa-sitemap"></i><h5>View Class Structure</h5>
                    </div>
                </a>
            </div>
            <!-- Add more quick links as needed -->
        </div>
        
        <!-- Placeholder for more content like recent activities, charts, etc. -->

    </main>
    <?php // The closing #content and .wrapper divs are handled by nav&sidebar_dos.php ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../javascript/script.js"></script> <!-- Adjust path if needed -->
    <script>
        // Placeholder for DoS Dashboard specific JS
        // Example: Fetch and display summary stats
        $(document).ready(function() {
            // fetchDosSummaryData();
            // Simulate fetching data
            $('#statTotalClassesDos').text('<?php echo count($fixed_classes_display_data["O-Level"] ?? []) + count($fixed_classes_display_data["A-Level"] ?? []); ?>'); // Example
            $('#statTotalTeachersDos').text('<?php echo count($all_teachers_list ?? []); ?>'); // Example
            $('#statTotalStudentsDos').text('<?php echo $total_students ?? "--"; ?>'); // Example, if you fetch total students
            $('#statTotalSubjectsDos').text('<?php echo count($all_subjects_list ?? []); ?>'); // Example
        });

        // function fetchDosSummaryData() {
        //     $.ajax({
        //         url: 'dos_ajax_handler.php?action=get_summary_stats', // You'll need to create this
        //         type: 'GET',
        //         dataType: 'json',
        //         success: function(data) {
        //             if (data.success) {
        //                 $('#statTotalClassesDos').text(data.stats.total_classes || '--');
        //                 $('#statTotalTeachersDos').text(data.stats.total_teachers || '--');
        //                 $('#statTotalStudentsDos').text(data.stats.total_students || '--');
        //                 $('#statTotalSubjectsDos').text(data.stats.total_subjects || '--');
        //             }
        //         },
        //         error: function() { console.error("Failed to load DoS summary stats."); }
        //     });
        // }
    </script>
</body>
</html>