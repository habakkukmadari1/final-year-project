<?php 
require_once 'db_config.php'; 
require_once 'auth.php';

check_login('head_teacher');
$loggedInUserId = $_SESSION['user_id']; // The HT's ID

// --- AJAX for HT approval/decline ---
$action = $_POST['action'] ?? null;
if($action == 'resolve_request' && $conn) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'An unknown error occurred.'];
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $resolution = $_POST['resolution'] ?? ''; // 'approve' or 'decline'

    if(!$request_id || !in_array($resolution, ['approve', 'decline'])) {
        $response['message'] = 'Invalid data provided.';
        echo json_encode($response); exit;
    }

    $conn->begin_transaction();
    try {
        // First, update the request status
        $new_status = ($resolution == 'approve') ? 'approved' : 'declined';
        $stmt_update_req = $conn->prepare("UPDATE system_change_requests SET status=?, resolved_by_user_id=?, resolved_at=NOW() WHERE id=? AND status='pending'");
        $stmt_update_req->bind_param("sii", $new_status, $loggedInUserId, $request_id);
        if(!$stmt_update_req->execute() || $stmt_update_req->affected_rows == 0) {
            throw new Exception("Request could not be updated. It might have been already resolved or cancelled.");
        }
        $stmt_update_req->close();

        // If approved, perform the action
        if($resolution == 'approve') {
            // Get the target year ID from the request
            $stmt_get_target = $conn->prepare("SELECT target_id FROM system_change_requests WHERE id = ?");
            $stmt_get_target->bind_param("i", $request_id);
            $stmt_get_target->execute();
            $target_id = $stmt_get_target->get_result()->fetch_assoc()['target_id'];
            $stmt_get_target->close();

            if(!$target_id) throw new Exception("Could not find the target year for the request.");

            // Set the new year
            $conn->query("UPDATE academic_years SET is_current = 0");
            $stmt_set_year = $conn->prepare("UPDATE academic_years SET is_current = 1 WHERE id = ?");
            $stmt_set_year->bind_param("i", $target_id);
            $stmt_set_year->execute();
            $stmt_set_year->close();
        }

        $conn->commit();
        $response = ['success' => true, 'message' => "Request has been successfully {$new_status}."];

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// --- REGULAR PAGE LOAD: Fetch pending requests for display ---
$pending_requests = [];
if ($conn) {
    $sql = "SELECT req.id, req.request_type, req.expires_at, u.full_name as requester_name, ay.year_name as target_year
            FROM system_change_requests req
            JOIN users u ON req.requester_user_id = u.id
            JOIN academic_years ay ON req.target_id = ay.id
            WHERE req.status = 'pending'
            ORDER BY req.created_at ASC";
    $result = $conn->query($sql);
    if($result) $pending_requests = $result->fetch_all(MYSQLI_ASSOC);
}
check_auth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Majorine Collage Mulawa</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Custom CSS -->
    <link href="style/styles.css" rel="stylesheet">
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="active">
            <div class="sidebar-header">
                <h3>MACOM</h3>
                <div class="logo-small">SMS</div>
            </div>

            <ul class="list-unstyled components">
                <li class="active">
                    <a href="#" class="dashboard-link">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="student_overview.php" class="student-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Student Overview</span>
                    </a>
                </li>
                <li>
                    <a href="#classSubmenu" data-bs-toggle="collapse" class="dropdown-toggle">
                        <i class="fas fa-chalkboard"></i>
                        <span>Classes Management</span>
                    </a>
                    <ul class="collapse list-unstyled" id="classSubmenu">
                        <li>
                            <a href="#">
                                <i class="fas fa-book"></i>
                                <span>Subject Management</span>
                            </a>
                        </li>
                    </ul>
                </li>
                <li>
                    <a href="#" class="teacher-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Teacher Management</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="announcement-link">
                        <i class="fas fa-bullhorn"></i>
                        <span>School Announcements</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="parents-link">
                        <i class="fas fa-users"></i>
                        <span>Parents Communication</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="finance-link">
                        <i class="fas fa-dollar-sign"></i>
                        <span>Finance</span>
                    </a>
                </li>
                <li class="bottom-item">
                    <a href="#" class="logout-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Page Content -->
        <div id="content">
            <!-- Top Navigation -->
            <nav class="navbar navbar-expand-lg">
                <div class="container-fluid">
                    <button type="button" id="sidebarCollapse" class="btn">
                        <i class="fas fa-bars"></i>
                    </button>

                    <div class="d-flex align-items-center">
                        <div class="datetime me-4">
                            <div id="current-time" class="time"></div>
                            <div id="current-date" class="date"></div>
                        </div>
                        
                        <div class="theme-switch-wrapper me-3">
                            <label class="theme-switch" for="checkbox">
                                <input type="checkbox" id="checkbox" />
                                <div class="slider round">
                                    <i class="fas fa-sun"></i>
                                    <i class="fas fa-moon"></i>
                                </div>
                            </label>
                        </div>

                        <div class="user-profile dropdown">
                            <a class="dropdown-toggle d-flex align-items-center text-decoration-none" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if (!empty($_SESSION['profile_pic'])): ?>
                                    <img src="<?php echo $_SESSION['profile_pic']; ?>" alt="Profile" class="profile-img rounded-circle">
                                <?php else: ?>
                                    <div class="profile-avatar" data-name="<?php echo $_SESSION['full_name']; ?>">
                                        <?php 
                                        $names = explode(' ', $_SESSION['full_name']);
                                        $initials = '';
                                        foreach ($names as $name) {
                                            $initials .= strtoupper(substr($name, 0, 1));
                                        }
                                        echo substr($initials, 0, 2);
                                        ?>
                                    </div>
                                <?php endif; ?>
                                <div class="profile-info ms-2">
                                    <div class="profile-name text-dark fw-bold"><?php echo $_SESSION['full_name']; ?></div>
                                    <div class="profile-role text-muted small"><?php echo ucfirst($_SESSION['role']); ?></div>
                                </div>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li>
                                    <div class="dropdown-header px-3 py-2">
                                        <div class="fw-bold"><?php echo $_SESSION['full_name']; ?></div>
                                        <small class="text-muted"><?php echo ucfirst($_SESSION['role']); ?></small>
                                    </div>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="container-fluid dashboard-content">
                  <!-- ** NEW HT APPROVAL PANEL ** -->
                  <?php if(!empty($pending_requests)): ?>
                <div class="card border-primary shadow-lg mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-gavel me-2"></i>Action Required: System Change Requests
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach($pending_requests as $request): ?>
                        <div class="list-group-item">
                            <div class="row align-items-center">
                                <div class="col-md-7">
                                    <strong><?php echo htmlspecialchars($request['requester_name']); ?></strong> has requested to change the current academic year to "<strong><?php echo htmlspecialchars($request['target_year']); ?></strong>".
                                    <small class="d-block text-muted">Auto-approves in: <?php echo date('d M Y H:i', strtotime($request['expires_at'])); ?></small>
                                </div>
                                <div class="col-md-5 text-md-end mt-2 mt-md-0">
                                    <button class="btn btn-success btn-sm me-2" onclick="resolveRequest(<?php echo $request['id']; ?>, 'approve')">
                                        <i class="fas fa-check"></i> Approve Now
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="resolveRequest(<?php echo $request['id']; ?>, 'decline')">
                                        <i class="fas fa-times"></i> Decline
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Overview Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon bg-primary">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <div class="stats-info">
                                <h5>Total Students</h5>
                                <h3>1,234</h3>
                                <p><span class="text-success"><i class="fas fa-arrow-up"></i> 5.27%</span> vs last month</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon bg-success">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <div class="stats-info">
                                <h5>Total Teachers</h5>
                                <h3>85</h3>
                                <p><span class="text-success"><i class="fas fa-arrow-up"></i> 2.15%</span> vs last month</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon bg-warning">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <div class="stats-info">
                                <h5>Revenue</h5>
                                <h3>$52,489</h3>
                                <p><span class="text-danger"><i class="fas fa-arrow-down"></i> 1.35%</span> vs last month</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-icon bg-info">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="stats-info">
                                <h5>Attendance Rate</h5>
                                <h3>95.8%</h3>
                                <p><span class="text-success"><i class="fas fa-arrow-up"></i> 0.82%</span> vs last month</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Student Performance Overview</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="performanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Attendance Distribution</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="attendanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activities and Announcements -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Recent Activities</h5>
                            </div>
                            <div class="card-body">
                                <div class="activity-item">
                                    <div class="activity-icon bg-primary">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h6>New Student Registration</h6>
                                        <p>John Smith has been registered to Class 10A</p>
                                        <small class="text-muted">2 hours ago</small>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon bg-success">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h6>Exam Results Published</h6>
                                        <p>Mid-term examination results have been published</p>
                                        <small class="text-muted">5 hours ago</small>
                                    </div>
                                </div>
                                <div class="activity-item">
                                    <div class="activity-icon bg-warning">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h6>Parent-Teacher Meeting</h6>
                                        <p>Schedule updated for next week's PTA meeting</p>
                                        <small class="text-muted">1 day ago</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Latest Announcements</h5>
                            </div>
                            <div class="card-body">
                                <div class="announcement-item">
                                    <h6>Annual Sports Day</h6>
                                    <p>Annual Sports Day will be held on March 15th. All students must participate.</p>
                                    <small class="text-muted">Posted by Admin - 1 day ago</small>
                                </div>
                                <div class="announcement-item">
                                    <h6>Holiday Notice</h6>
                                    <p>School will remain closed on March 8th due to local elections.</p>
                                    <small class="text-muted">Posted by Admin - 2 days ago</small>
                                </div>
                                <div class="announcement-item">
                                    <h6>Science Exhibition</h6>
                                    <p>Inter-school Science Exhibition registration is now open.</p>
                                    <small class="text-muted">Posted by Science Department - 3 days ago</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript -->
   <script src="javascript/script.js">
      function resolveRequest(requestId, resolution) {
            const actionText = resolution === 'approve' ? 'APPROVE' : 'DECLINE';
            if (confirm(`Are you sure you want to ${actionText} this request? This action is immediate.`)) {
                $.ajax({
                    url: 'head_teacher_dashboard.php',
                    type: 'POST',
                    data: { action: 'resolve_request', request_id: requestId, resolution: resolution },
                    dataType: 'json',
                    success: function(response) {
                        alert(response.message);
                        if (response.success) {
                            window.location.reload();
                        }
                    },
                    error: function() {
                        alert('An error occurred while processing the request.');
                    }
                });
            }
        }
   </script>
</body>
</html>"