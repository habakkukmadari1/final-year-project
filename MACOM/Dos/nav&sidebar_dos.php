<?php
// nav&sidebar_dos.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$loggedInUserFullName = $_SESSION['full_name'] ?? 'User';
$loggedInUserRole = $_SESSION['role'] ?? 'guest';
$loggedInUserProfilePic = $_SESSION['profile_pic'] ?? null; // From users table

// Adjust path for profile picture if DoS images are stored differently, or use users.profile_pic
$profilePicUrl = 'https://ui-avatars.com/api/?name=' . urlencode($loggedInUserFullName) . '&background=087990&color=fff&size=35&font-size=0.4'; // DoS color
if (!empty($loggedInUserProfilePic)) {
    // Check if profile_pic is a full URL or a relative path
    if (filter_var($loggedInUserProfilePic, FILTER_VALIDATE_URL)) {
        $profilePicUrl = $loggedInUserProfilePic;
    } else {
        // Assuming profile_pic stores a path like "uploads/users/profile_pics/image.jpg"
        // and this nav file is in root or a subdir, and uploads is in root.
        $potentialPath = (basename(dirname(__FILE__)) === 'dos' ? '../' : '') . $loggedInUserProfilePic;
        if (file_exists($potentialPath)) {
            $profilePicUrl = $potentialPath;
        }
    }
}


$currentPageNav = basename($_SERVER['PHP_SELF']);

$dosMenuItems = [
    ['href' => 'director_dashboard.php', 'icon' => 'fas fa-tachometer-alt', 'text' => 'DoS Dashboard'],
    ['href' => '../academic_year_management.php', 'icon' => 'fas fa-calendar-alt', 'text' => 'Academic Years'], // Link to existing if suitable
    ['href' => '../class_management.php', 'icon' => 'fas fa-sitemap', 'text' => 'Class Structure'],      // Link to existing
    ['href' => 'dos_student_overview.php', 'icon' => 'fas fa-user-plus', 'text' => 'Student Class Assignment'],
    ['href' => '../subject_management.php', 'icon' => 'fas fa-book', 'text' => 'Subject Management'],    // Link to existing
    ['href' => 'dos_assign_teacher_subjects.php', 'icon' => 'fas fa-chalkboard-teacher', 'text' => 'Teacher Subject Assign'],
    ['href' => 'dos_exam_management.php', 'icon' => 'fas fa-file-signature', 'text' => 'Exam Management'],
    ['href' => '../student_overview.php', 'icon' => 'fas fa-users-viewfinder', 'text' => 'View Students'], // Link to existing student list
    ['href' => '../teachers_management.php', 'icon' => 'fas fa-user-tie', 'text' => 'View Teachers'], // Link to existing teacher list
    // Add more DoS specific links: Timetabling, Curriculum, Reports etc.
    ['href' => 'dos_profile.php', 'icon' => 'fas fa-user-cog', 'text' => 'My Profile'],
];

// Determine base path for URLs (if nav is in a subdirectory)
$basePath = (basename(dirname(__FILE__)) === 'dos' ? '../' : ''); // If in /dos/, prepend ../
?>
<div class="wrapper">
    <nav id="sidebar">
        <div class="sidebar-header">
            <img src="<?php echo $basePath; ?>images/macom_logo_small.png" alt="MACOM" style="height: 40px; margin-right: 10px;">
            <h3>MACOM</h3>
            <div class="logo-small">SMS</div>
        </div>

        <ul class="list-unstyled components">
            <?php foreach ($dosMenuItems as $item):
                $linkHref = $item['href'];
                // No need to prepend basePath if it's already an absolute-like path or meant for root
                // This logic assumes menu items are defined relative to where nav&sidebar_dos.php is called from,
                // or are root-relative if the calling page is in root.
                // If nav&sidebar_dos.php is in /dos/ and director_dashboard.php is in /dos/,
                // then a link like 'dos_assign_student_class.php' is fine.
                // A link like '../class_management.php' correctly goes up one level.
            ?>
            <li class="<?php echo ($currentPageNav == basename($linkHref)) ? 'active' : ''; ?>">
                <a href="<?php echo htmlspecialchars($linkHref); ?>">
                    <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item['text']); ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <ul class="list-unstyled CTAs">
            <li class="bottom-item">
                <a href="<?php echo $basePath; ?>logout.php" class="logout-link download text-danger">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <div id="content" class="flex-grow-1">
        <nav class="navbar navbar-expand-lg navbar-light">
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
                        <label class="theme-switch" for="themeToggleCheckboxNav">
                            <input type="checkbox" id="themeToggleCheckboxNav" />
                            <div class="slider round"><i class="fas fa-sun"></i><i class="fas fa-moon"></i></div>
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
                            <li><a class="dropdown-item" href="dos_profile.php"><i class="fas fa-user-cog fa-sm me-2 text-muted"></i> My Profile</a></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo $basePath; ?>logout.php"><i class="fas fa-sign-out-alt fa-sm me-2 text-muted"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>