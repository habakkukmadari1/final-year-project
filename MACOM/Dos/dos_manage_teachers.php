<?php
// dos_manage_teachers.php
require_once '../db_config.php';
require_once '../auth.php';

check_login('director_of_studies');
$loggedInUserId = $_SESSION['user_id']; // For audit if DoS can edit anything minor

// --- Fetch Data for Display (No Add/Edit/Delete POST logic for DoS on this page) ---
$teachers_display_list_dos = [];
if ($conn) {
    // DoS sees all teachers, similar to HT
    $sql_fetch_dos = "SELECT t.id, t.full_name, t.email, t.phone_number, t.photo, t.subject_specialization, t.role,
                           u_creator.username as creator_username, u_updater.username as updater_username,
                           t.created_at, t.updated_at
                      FROM teachers t
                      LEFT JOIN users u_creator ON t.created_by = u_creator.id
                      LEFT JOIN users u_updater ON t.updated_by = u_updater.id
                      ORDER BY t.full_name ASC";
    $teachers_result_dos = $conn->query($sql_fetch_dos);
    if ($teachers_result_dos) {
        while ($row_dos = $teachers_result_dos->fetch_assoc()) {
            $teachers_display_list_dos[] = $row_dos;
        }
    } else {
        $_SESSION['error_message_dos_mt'] = "DB Error fetching teachers list: " . $conn->error;
    }
} else {
    $_SESSION['error_message_dos_mt'] = "Database connection error.";
}

$success_message_dos = $_SESSION['success_message_dos_mt'] ?? null;
$error_message_dos = $_SESSION['error_message_dos_mt'] ?? null;
if ($success_message_dos) unset($_SESSION['success_message_dos_mt']);
if ($error_message_dos) unset($_SESSION['error_message_dos_mt']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Teachers (DoS) - MACOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet">
    <style>
        /* Re-use styles from HT version, or define DoS specific ones if needed */
        .wrapper { display: flex; min-height: 100vh; }
        .dashboard-content { padding: 1.5rem; background-color: var(--bs-body-bg); border-radius: .5rem; box-shadow: 0 .125rem .25rem rgba(0,0,0,.075); }
        .teacher-card .card-header { background-color: var(--bs-info); color: white; } /* DoS color */
        .teacher-card .card-footer { background-color: var(--bs-tertiary-bg); }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar_dos.php'; // Assumes DoS specific nav/sidebar ?>

        <div class="container-fluid dashboard-content">
            <?php if ($success_message_dos): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message_dos); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            <?php if ($error_message_dos): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo nl2br(htmlspecialchars($error_message_dos)); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">View Teachers <small class="text-muted fs-6 fw-normal">(Director of Studies)</small></h2>
                <!-- No "Add Teacher" button for DoS -->
                 <a href="dos_assign_teacher_subjects.php" class="btn btn-info">
                    <i class="fas fa-user-cog me-2"></i>Manage Teacher Assignments
                </a>
            </div>

            <!-- Stats Cards (can be different for DoS) -->
             <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card shadow-sm">
                        <div class="stats-icon bg-info"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="stats-info"><h5>Total Teachers</h5><h3><?php echo count($teachers_display_list_dos); ?></h3></div>
                    </div>
                </div>
                <div class="col-md-4">
                     <div class="stats-card shadow-sm">
                        <div class="stats-icon bg-success"><i class="fas fa-user-check"></i></div>
                        <div class="stats-info"><h5>Assigned Teachers</h5><h3 id="assignedTeachersStatDos">0</h3></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card shadow-sm">
                        <div class="stats-icon bg-warning"><i class="fas fa-user-clock"></i></div>
                        <div class="stats-info"><h5>Teachers Awaiting Assignment</h5><h3 id="unassignedTeachersStatDos">0</h3></div>
                    </div>
                </div>
            </div>


            <!-- Search and Filter -->
            <div class="card mb-4 shadow-sm">
                <div class="card-body">
                     <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchBarDos" class="form-control global-search" placeholder="Search teachers by name, email, subject...">
                    </div>
                </div>
            </div>

            <!-- Teachers Grid (DoS View) -->
            <div class="row gy-4" id="teachersDisplayGridDos">
                <?php if (!empty($teachers_display_list_dos)): ?>
                    <?php foreach ($teachers_display_list_dos as $teacher_item): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 teacher-card-item-dos" data-search-payload-dos="<?php echo strtolower(htmlspecialchars($teacher_item['full_name'] . ' ' . $teacher_item['email'] . ' ' . ($teacher_item['subject_specialization'] ?? ''))); ?>">
                        <div class="card h-100 teacher-card shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fs-6 text-truncate" title="<?php echo htmlspecialchars($teacher_item['full_name']); ?>"><?php echo htmlspecialchars($teacher_item['full_name']); ?></h5>
                                <span class="badge bg-light text-dark small"><?php echo htmlspecialchars($teacher_item['subject_specialization'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="card-body text-center">
                                 <img src="<?php echo htmlspecialchars(!empty($teacher_item['photo']) && file_exists($teacher_item['photo']) ? $teacher_item['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($teacher_item['full_name']).'&background=random&color=fff&size=100'); ?>"
                                     class="rounded-circle border" style="width: 100px; height: 100px; object-fit: cover;"
                                     alt="<?php echo htmlspecialchars($teacher_item['full_name']); ?>">
                                <div class="mt-2 teacher-info text-start small">
                                    <div class="mb-1"><i class="fas fa-envelope fa-fw me-2 text-muted"></i><span class="text-truncate" title="<?php echo htmlspecialchars($teacher_item['email']); ?>"><?php echo htmlspecialchars($teacher_item['email']); ?></span></div>
                                    <div class="mb-1"><i class="fas fa-phone fa-fw me-2 text-muted"></i><span><?php echo htmlspecialchars($teacher_item['phone_number'] ?: 'N/A'); ?></span></div>
                                    <div class="mb-1"><i class="fas fa-user-tag fa-fw me-2 text-muted"></i><span>Role: <?php echo htmlspecialchars(ucfirst($teacher_item['role'])); ?></span></div>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-around align-items-center py-2">
                                <!-- DoS views details on a different page (dos_teacher_details.php) -->
                                <a href="dos_teacher_details.php?id=<?php echo $teacher_item['id']; ?>" class="btn btn-sm btn-outline-info flex-fill me-1" title="View Details & Assign">
                                    <i class="fas fa-eye"></i> <span class="d-none d-md-inline">View & Assign</span>
                                </a>
                                <!-- No Edit/Delete/Pwd change for DoS on this overview page -->
                                <button class="btn btn-sm btn-outline-secondary flex-fill ms-1" onclick="requestTeacherUpdate(<?php echo $teacher_item['id']; ?>)" title="Request update from HT">
                                    <i class="fas fa-flag"></i> <span class="d-none d-md-inline">Req. Update</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                     <div class="col-12"><div class="alert alert-info text-center">No teachers found in the system. The Head Teacher needs to add them.</div></div>
                <?php endif; ?>
                <div class="col-12" id="noTeachersFoundMessageDos" style="display: none;">
                    <div class="alert alert-warning text-center">No teachers match your search criteria.</div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // DoS specific JS
        document.getElementById('searchBarDos')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const teacherCards = document.querySelectorAll('#teachersDisplayGridDos .teacher-card-item-dos');
            let visibleCount = 0;
            teacherCards.forEach(card => {
                const searchPayload = card.dataset.searchPayloadDos || ""; // Use DoS specific data attribute
                card.style.display = searchPayload.includes(searchTerm) ? '' : 'none';
                if (card.style.display !== 'none') visibleCount++;
            });
            document.getElementById('noTeachersFoundMessageDos').style.display = visibleCount === 0 && searchTerm !== '' ? 'block' : 'none';
        });

        function requestTeacherUpdate(teacherId) {
            // This would ideally trigger a notification to the HT or log a request.
            // For now, just an alert.
            alert(`A request to review/update details for teacher ID ${teacherId} would be sent to the Head Teacher.`);
            // Future: AJAX call to create a notification/task for HT.
        }

        // Placeholder for DoS specific stats - this would need AJAX to fetch real assignment data
        document.addEventListener('DOMContentLoaded', function() {
            // Simulating counts, replace with actual data fetching
            // e.g., count teachers with at least one assignment in teacher_class_subject_papers for current year
            const totalTeachersDos = <?php echo count($teachers_display_list_dos); ?>;
            // const assignedTeachers = ... (complex query needed)
            // document.getElementById('assignedTeachersStatDos').textContent = assignedTeachers;
            // document.getElementById('unassignedTeachersStatDos').textContent = totalTeachersDos - assignedTeachers;
            document.getElementById('assignedTeachersStatDos').textContent = 'N/A'; // Placeholder
            document.getElementById('unassignedTeachersStatDos').textContent = 'N/A'; // Placeholder
        });
    </script>
</body>
</html>