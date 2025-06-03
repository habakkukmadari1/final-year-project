<?php
// teachers/nav&sidebar_teacher.php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // Should already be started by db_config.php from the including page
}

// These session variables are expected to be set by the login process
$loggedInUserFullName = $_SESSION['full_name'] ?? 'User';
$loggedInUserRole = $_SESSION['role'] ?? 'guest';
$loggedInUserProfilePic = $_SESSION['profile_pic'] ?? null;

// Construct profile picture URL
$profilePicUrl = 'https://ui-avatars.com/api/?name=' . urlencode($loggedInUserFullName) . '&background=random&color=fff&size=35&font-size=0.4';
if (!empty($loggedInUserProfilePic)) {
    // Assuming profile_pic stores a path like "uploads/teachers/photos/image.jpg"
    // and this nav file is in "teachers/", and uploads is in root.
    $potentialPath = '../' . $loggedInUserProfilePic;
    if (file_exists($potentialPath)) {
        $profilePicUrl = $potentialPath;
    }
}

$currentPageNav = basename($_SERVER['PHP_SELF']);

// Define Teacher's Menu Items
$teacherMenuItems = [
    ['href' => 'teacher_dashboard.php', 'icon' => 'fas fa-tachometer-alt', 'text' => 'Dashboard'],
    ['href' => 'teacher_my_students.php', 'icon' => 'fas fa-users', 'text' => 'My Students'],
    ['href' => 'teacher_grades_management.php', 'icon' => 'fas fa-marker', 'text' => 'Grades Management'],
    ['href' => 'teacher_timetable.php', 'icon' => 'fas fa-calendar-alt', 'text' => 'My Timetable'],
    ['href' => 'teacher_assignments.php', 'icon' => 'fas fa-tasks', 'text' => 'Assignments/Tests'],
    ['href' => 'teacher_notifications.php', 'icon' => 'fas fa-bell', 'text' => 'Notifications'],
    ['href' => 'teacher_profile.php', 'icon' => 'fas fa-user-circle', 'text' => 'My Profile'],
];
?>
<div class="wrapper"> <!-- This wrapper should be in the main page template -->
    <!-- Sidebar -->
    <nav id="sidebar"> <!-- Removed 'active' class, JS in script.js will handle it -->
        <div class="sidebar-header">
            <!-- Make sure this path is correct if your logo is in root/images/ -->
            <img src="../images/macom_logo_small.png" alt="MACOM" style="height: 40px; margin-right: 10px;">
            <h3>MACOM</h3>
            <div class="logo-small">SMS</div>
        </div>

        <ul class="list-unstyled components">
            <?php foreach ($teacherMenuItems as $item): ?>
            <li class="<?php echo ($currentPageNav == $item['href']) ? 'active' : ''; ?>">
                <a href="<?php echo htmlspecialchars($item['href']); ?>">
                    <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item['text']); ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <ul class="list-unstyled CTAs">
            <li class="bottom-item">
                <!-- Path to logout.php in the root directory -->
                <a href="../logout.php" class="logout-link download text-danger">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Page Content Holder -->
    <div id="content" class="flex-grow-1">
        <nav class="navbar navbar-expand-lg navbar-light bg-light"> <!-- Using Bootstrap's bg-light for default -->
            <div class="container-fluid">
                <button type="button" id="sidebarCollapse" class="btn btn-outline-secondary">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="ms-auto d-flex align-items-center">
                    <div class="datetime me-3 d-none d-md-block">
                        <small id="current-date" class="text-muted"></small>
                        <small id="current-time" class="text-muted"></small>
                    </div>
                    
                    <div class="theme-switch-wrapper me-2">
                        <label class="theme-switch" for="themeToggleCheckboxNav"> <!-- Unique ID for this checkbox -->
                            <input type="checkbox" id="themeToggleCheckboxNav" />
                            <div class="slider round">
                                <i class="fas fa-sun"></i><i class="fas fa-moon"></i>
                            </div>
                        </label>
                    </div>

                    <div class="dropdown">
                        <a class="dropdown-toggle d-flex align-items-center text-decoration-none" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profilePicUrl); ?>" alt="Profile" class="profile-img rounded-circle">
                            <div class="profile-info ms-2 d-none d-md-block">
                                <div class="profile-name fw-bold"><?php echo htmlspecialchars($loggedInUserFullName); ?></div>
                                <div class="profile-role text-muted small"><?php echo htmlspecialchars(ucfirst($loggedInUserRole)); ?></div>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li><div class="dropdown-header px-3 py-2"><div class="fw-bold"><?php echo htmlspecialchars($loggedInUserFullName); ?></div><small class="text-muted"><?php echo htmlspecialchars(ucfirst($loggedInUserRole)); ?></small></div></li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item" href="teacher_profile.php"><i class="fas fa-user-cog fa-sm me-2 text-muted"></i> My Profile</a></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt fa-sm me-2 text-muted"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>
        <!-- The ACTUAL page content (from teacher_dashboard.php, etc.) will be inserted AFTER this nav bar and before the closing div of #content -->