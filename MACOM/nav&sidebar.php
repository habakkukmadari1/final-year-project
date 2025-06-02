<?php 
// Start the session at the very beginning of the file


// Check if user is logged in (optional - you might want to handle this differently)
if (!isset($_SESSION['user_id'])) {
    // Redirect to login page or handle unauthorized access
    // header('Location: login.php');
    // exit();
}
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
    <!-- Sidebar -->
    <nav id="sidebar" class="active">
        <div class="sidebar-header">
            <h3>MACOM</h3>
            <div class="logo-small">MCM</div>
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
                <a href="logout.php" class="logout-link">
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
        <script src="javascript/script.js"></script>
</body>
</html>"
