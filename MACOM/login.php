<?php
// login.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_config.php';
// --- DEBUGGING DB CONNECTION ---
if (!$conn) {
    error_log("DB_CONFIG_ERROR: \$conn object is null or false. Connection likely failed in db_config.php.");
    die("Critical Database Connection Error in db_config.php. Check PHP error log. (Code: DBCONN_FAIL)");
}
if ($conn->connect_error) {
    error_log("DB_CONNECT_ERROR: Connection failed: " . $conn->connect_error);
    die("Database Connection Error: " . htmlspecialchars($conn->connect_error) . ". Check credentials in db_config.php. (Code: DBCONN_ERR)");
}
// echo "DB Connection seems OK.<br>"; // Remove after testing
// --- END DEBUGGING ---
require_once 'auth.php';

$login_error_message = $_SESSION['login_error_message'] ?? null;
$login_success_message = $_SESSION['login_success_message'] ?? null;
if ($login_error_message) unset($_SESSION['login_error_message']);
if ($login_success_message) unset($_SESSION['login_success_message']);

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $dashboard_map = [
        'head_teacher' => 'head_teacher_dashboard.php',
        'secretary' => 'secretary_dashboard.php',
        'director_of_studies' => 'director_dashboard.php',
        'IT' => 'IT_dashboard.php',
        'student' => 'student_dashboard.php',
        'teacher' => 'teachers/teacher_dashboard.php',
    ];
    $redirect_url = $dashboard_map[$_SESSION['role']] ?? 'login.php';
    if (basename($_SERVER['PHP_SELF']) !== $redirect_url || $redirect_url !== 'login.php') {
        header("Location: " . $redirect_url);
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_input = trim($_POST['username']); // Use a different variable name to avoid confusion with table columns
    $password_input = trim($_POST['password']);
    $user_found_but_password_incorrect = false;
    $account_found_in_any_table = false; // New flag

    if (empty($username_input) || empty($password_input)) {
        $login_error_message = "Username/Email and password are required.";
    } else {
        $login_successful = false;

        // Attempt 1: Check 'users' table
        // CAREFULLY CHECK THESE COLUMN NAMES AGAINST YOUR ACTUAL `users` TABLE
        $sql_users = "SELECT username, password, role, profile_pic FROM users WHERE username = ?";
        $stmt_user = $conn->prepare($sql_users);

        if ($stmt_user) {
            $stmt_user->bind_param("s", $username_input);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();

            if ($result_user->num_rows > 0) {
                $account_found_in_any_table = true; // Account exists in users table
                $user = $result_user->fetch_assoc();
                if (password_verify($password_input, $user['password'])) {
                    $_SESSION['user_id'] = $user['username']; // Consider using a numerical ID if 'users' table has one
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['username'] ?? $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? null;
                    $login_successful = true;
                } else {
                    $user_found_but_password_incorrect = true;
                }
            }
            $stmt_user->close();
        } else {
            // This is the critical error point for U1
            error_log("Login Error (users table prepare) - SQL: $sql_users - Error: " . $conn->error);
            $login_error_message = "System error during login. Please contact support. (Code: U1)";
        }

        // Attempt 2: Check 'teachers' table
        if (!$login_successful && filter_var($username_input, FILTER_VALIDATE_EMAIL)) {
            $sql_teachers = "SELECT id, full_name, email, password, role, photo FROM teachers WHERE email = ?";
            $stmt_teacher = $conn->prepare($sql_teachers);
            if ($stmt_teacher) {
                $stmt_teacher->bind_param("s", $username_input);
                $stmt_teacher->execute();
                $result_teacher = $stmt_teacher->get_result();

                if ($result_teacher->num_rows > 0) {
                    $account_found_in_any_table = true; // Account exists in teachers table
                    $teacher = $result_teacher->fetch_assoc();
                    if (password_verify($password_input, $teacher['password'])) {
                        $_SESSION['user_id'] = $teacher['id'];
                        $_SESSION['username'] = $teacher['email'];
                        $_SESSION['full_name'] = $teacher['full_name'];
                        $_SESSION['role'] = $teacher['role'] ?: 'teacher';
                        $_SESSION['profile_pic'] = $teacher['photo'] ?? null;
                        $login_successful = true;
                    } else {
                        $user_found_but_password_incorrect = true;
                    }
                }
                $stmt_teacher->close();
            } else {
                error_log("Login Error (teachers table prepare) - SQL: $sql_teachers - Error: " . $conn->error);
                if (!$login_error_message) $login_error_message = "System error during login. Please contact support. (Code: T1)";
            }
        }
        
        // Student Login (Placeholder)
        /* ... */

        // Process Login Result
        if ($login_successful && isset($_SESSION['role'])) {
            $_SESSION['login_success_message'] = "Login successful! Welcome back, " . htmlspecialchars($_SESSION['full_name']) . ".";
            $dashboard_map = [
                'head_teacher' => 'head_teacher_dashboard.php',
                'secretary' => 'secretary_dashboard.php',
                'director_of_studies' => 'Dos/director_dashboard.php',
                'IT' => 'IT_dashboard.php',
                'student' => 'student_dashboard.php',
                'teacher' => 'teachers/teacher_dashboard.php',
            ];
            $redirect_url = $dashboard_map[$_SESSION['role']] ?? 'login.php?error=unknown_role';
            if (isset($_SESSION['login_error_message'])) unset($_SESSION['login_error_message']);
            header("Location: " . $redirect_url);
            exit();
        } else {
            if (!$login_error_message) { // If no system error occurred
                if ($user_found_but_password_incorrect) {
                    $login_error_message = "Incorrect password. Please try again.";
                } elseif ($account_found_in_any_table) {
                     // This case should ideally not be hit if $user_found_but_password_incorrect is true
                     // But as a fallback if an account was found but didn't match password for some reason
                    $login_error_message = "Password verification failed. Please try again.";
                }
                 else {
                    $login_error_message = "Account not found with the provided username or email.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MACOM School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb; --primary-light: #60a5fa; --primary-dark: #1d4ed8;
            --accent-color: #ef4444; --success-color: #10b981;
            --text-primary: #1e293b; --text-secondary: #64748b;
            --bg-primary: #ffffff; --bg-secondary: #f1f5f9;
            --input-border-color: #cbd5e1; /* Slate 300 */
            --input-focus-border-color: var(--primary-light);
            --input-bg-color: var(--bg-primary);
            --input-text-color: var(--text-primary);
            --input-placeholder-color: var(--text-secondary);
        }
        html[data-bs-theme="dark"] {
            --primary-color: #3b82f6; --primary-light: #2563eb; --primary-dark: #93c5fd;
            --accent-color: #f87171; --success-color: #34d399;
            --text-primary: #f1f5f9; --text-secondary: #94a3b8;
            --bg-primary: #1e293b; --bg-secondary: #0f172a;
            --input-border-color: #475569; /* Slate 600 */
            --input-focus-border-color: var(--primary-light);
            --input-bg-color: #334155; /* Slate 700 for a slightly lighter input bg in dark mode */
            --input-text-color: var(--text-primary);
            --input-placeholder-color: var(--text-secondary);
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-secondary); color: var(--text-primary);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem;
        }
        .login-container-wrapper { max-width: 1000px; width: 100%; }
        .login-container {
            display: flex; background-color: var(--bg-primary); border-radius: 1rem;
            overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
        }
        .welcome-section {
            flex: 1.2; background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white;
            padding: 3rem; display: flex; flex-direction: column; justify-content: center;
        }
        .login-section { flex: 1; padding: 3rem; display: flex; flex-direction: column; justify-content: center; }
        .school-logo { width: 70px; margin-bottom: 1.5rem; }
        .welcome-title { font-size: 2.25rem; font-weight: 700; margin-bottom: 1rem; }
        .welcome-subtitle { font-size: 1rem; opacity: 0.9; margin-bottom: 2rem; }
        .value-item { display: flex; align-items: flex-start; margin-bottom: 1.25rem; }
        .value-icon {
            background-color: rgba(255, 255, 255, 0.15); width: 36px; height: 36px;
            border-radius: 0.5rem; display: flex; align-items: center; justify-content: center;
            margin-right: 1rem; flex-shrink: 0;
        }
        .value-item h5 { font-size: 0.95rem; font-weight: 600; margin-bottom: 0.25rem; }
        .value-item p { font-size: 0.85rem; opacity: 0.85; margin-bottom: 0; line-height: 1.5; }
        .login-title { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--primary-color); }
        .login-subtitle { color: var(--text-secondary); margin-bottom: 2rem; font-size: 0.9rem; }
        
        .form-control { /* Applied to the input field itself */
            height: calc(1.5em + 1rem + 2px); 
            border-radius: 0.5rem; 
            border: 1px solid var(--input-border-color);
            padding-left: 1rem; 
            padding-right: 2.5rem; /* Space for the icon button */
            background-color: var(--input-bg-color); 
            color: var(--input-text-color); 
            flex-grow: 1;
            border-top-right-radius: 0; /* For when used with appended button */
            border-bottom-right-radius: 0;
        }
        .form-control::placeholder { color: var(--input-placeholder-color); }
        .form-control:focus {
            border-color: var(--input-focus-border-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
            z-index: 2; /* Ensure focus ring is above button */
        }
        .input-group { 
            margin-bottom: 1.25rem; 
            position: relative; /* For absolute positioning of button if not using BS input-group */
        }
        .input-group .form-control {
            margin-bottom: 0; /* Reset margin if inside input-group */
        }
        .input-group-append-custom { /* Custom styling for the button container */
            display: flex;
        }
        .btn-eye-toggle {
            height: calc(1.5em + 1rem + 2px);
            border: 1px solid var(--input-border-color);
            background-color: var(--input-bg-color);
            color: var(--text-secondary);
            border-left: 0;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border-top-right-radius: 0.5rem;
            border-bottom-right-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .btn-eye-toggle:hover {
            background-color: var(--bs-tertiary-bg); /* Slight hover effect */
        }
        .form-control:focus + .input-group-append-custom .btn-eye-toggle {
            border-color: var(--input-focus-border-color); /* Match focus border */
            z-index: 2;
        }


        .btn-login {
            background-color: var(--primary-color); color: white; height: calc(1.5em + 1rem + 2px);
            border-radius: 0.5rem; font-weight: 600; border: none;
            transition: background-color 0.2s ease-in-out, transform 0.1s ease-in-out; width: 100%;
        }
        .btn-login:hover { background-color: var(--primary-dark); transform: translateY(-1px); }
        .btn-login:active { transform: translateY(0px); }
        .alert-custom {
            padding: 0.9rem 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;
            display: flex; align-items: center; font-size: 0.9rem; animation: fadeIn 0.3s ease-in-out;
        }
        .alert-custom i { margin-right: 0.75rem; font-size: 1.1rem; }
        .alert-danger-custom { background-color: var(--accent-color); color: white; }
        .alert-success-custom { background-color: var(--success-color); color: white; }
        .login-footer { margin-top: 1.5rem; font-size: 0.85rem; text-align: center; }
        .login-footer a { color: var(--primary-color); text-decoration: none; }
        .login-footer a:hover { text-decoration: underline; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 768px) {
            .login-container { flex-direction: column; }
            .welcome-section, .login-section { padding: 2rem; flex-basis: auto; }
            .welcome-title { font-size: 1.75rem; } .login-title { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="login-container-wrapper">
        <div class="login-container">
            <div class="welcome-section">
                <img src="path/to/your/logo.png" alt="MACOM School Logo" class="school-logo"> <!-- UPDATE YOUR LOGO PATH -->
                <h1 class="welcome-title">Welcome to MACOM</h1>
                <p class="welcome-subtitle">Majorine College Mulawa School Management System</p>
                <div class="values-list">
                    <div class="value-item"><div class="value-icon"><i class="fas fa-graduation-cap"></i></div><div><h5>Academic Excellence</h5><p>Nurturing future leaders.</p></div></div>
                    <div class="value-item"><div class="value-icon"><i class="fas fa-users"></i></div><div><h5>Community Values</h5><p>Fostering strong bonds.</p></div></div>
                    <div class="value-item"><div class="value-icon"><i class="fas fa-lightbulb"></i></div><div><h5>Innovation in Learning</h5><p>Embracing technology.</p></div></div>
                </div>
            </div>
            
            <div class="login-section">
                <h2 class="login-title">Sign In</h2>
                <p class="login-subtitle">Enter your credentials to access your account.</p>
                
                <?php if (!empty($login_error_message)): ?>
                    <div class="alert-custom alert-danger-custom">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($login_error_message); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($login_success_message)): ?>
                    <div class="alert-custom alert-success-custom">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($login_success_message); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['logged_out'])): ?>
                    <div class="alert-custom alert-success-custom">
                        <i class="fas fa-check-circle"></i> You have been successfully logged out.
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized_access_attempt'): ?>
                    <div class="alert-custom alert-danger-custom">
                        <i class="fas fa-shield-alt"></i> You attempted to access an unauthorized page.
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="form-group mb-3">
                        <input type="text" class="form-control" name="username" placeholder="Username or Email" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    <div class="input-group mb-3">
                        <input type="password" class="form-control" id="passwordInput" name="password" placeholder="Password" required>
                        <button class="btn btn-eye-toggle" type="button" id="togglePasswordBtn" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <button type="submit" class="btn btn-login">Login</button>
                </form>
                
                <div class="login-footer">
                    <a href="#">Forgot password?</a>
                    <span class="mx-2 text-muted">|</span>
                    <a href="#">Contact Support</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const togglePasswordBtn = document.getElementById('togglePasswordBtn');
        const passwordInput = document.getElementById('passwordInput');

        if (togglePasswordBtn && passwordInput) {
            togglePasswordBtn.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
        }

        // Theme toggle (if you want it on the login page, otherwise remove)
        // function toggleTheme() { 
        //     const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
        //     const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        //     document.documentElement.setAttribute('data-bs-theme', newTheme);
        //     localStorage.setItem('themePreferenceMACOM', newTheme);
        // }
        // document.addEventListener('DOMContentLoaded', () => {
        //     const savedTheme = localStorage.getItem('themePreferenceMACOM');
        //     if (savedTheme) document.documentElement.setAttribute('data-bs-theme', savedTheme);
        // });

    </script>
</body>
</html>