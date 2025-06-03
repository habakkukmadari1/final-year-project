<?php
// auth.php
require_once 'db_config.php';
// Ensure session is started. This might already be handled if db_config.php is included first.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Checks if a user is logged in. If not, redirects to the login page.
 * Can also check for a specific role or an array of allowed roles.
 *
 * @param string|array|null $required_role The role(s) required to access the page.
 *                                         Null means any logged-in user can access.
 */
function check_login($required_role = null) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        $_SESSION['login_error_message'] = "You must be logged in to access this page."; // Use a specific session key for login errors
        header("Location: login.php");
        exit();
    }

    if ($required_role !== null) {
        $user_role = $_SESSION['role'];
        $is_authorized = false;

        if (is_array($required_role)) {
            if (in_array($user_role, $required_role)) {
                $is_authorized = true;
            }
        } else {
            if ($user_role === $required_role) {
                $is_authorized = true;
            }
        }

        if (!$is_authorized) {
            // Log out the user because they are trying to access something they shouldn't
            // and their current session might be for a different role.
            // Or, just redirect with an error message without logging out if you prefer.
            // For stricter security, logging out is safer.

            // Option 1: Just redirect with error (user stays logged in with their current role)
            $_SESSION['login_error_message'] = "You are not authorized to access this page or perform this action.";
             // Determine their correct dashboard to avoid a redirect loop if they are already on it.
            $dashboard_map = [
                'head_teacher' => 'head_teacher_dashboard.php',
                'secretary' => 'secretary_dashboard.php',
                'director_of_studies' => 'director_dashboard.php',
                'IT' => 'IT_dashboard.php',
                'student' => 'student_dashboard.php', // Assuming students have a dashboard
                'teacher' => 'teacher_dashboard.php',
            ];
            $current_user_dashboard = $dashboard_map[$_SESSION['role']] ?? 'login.php'; // Fallback to login if role unknown

            if (basename($_SERVER['PHP_SELF']) !== $current_user_dashboard) {
                header("Location: " . $current_user_dashboard . "?error=unauthorized_access_attempt");
            }
            // If they are already on their dashboard and the error message is set, the page should display it.
            exit(); // Stop further execution

            // Option 2: Log them out and redirect (more secure if a compromised account tries to access restricted areas)
            /*
            logout_user_with_message("You are not authorized for that page. You have been logged out.");
            exit();
            */
        }
    }
}

/**
 * Logs out the current user by destroying the session.
 * Optionally sets a message to be displayed on the login page.
 * @param string|null $message Message to display on login page.
 */
function logout_user_with_message($message = null) {
    if ($message) {
        $_SESSION['login_success_message'] = $message; // Or a specific message key like 'logout_message'
    }
    // Unset all of the session variables.
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: login.php"); // Redirect after destroying session
    exit();
}


// Keep other functions like logout_user, get_session_value, generate_random_password, handle_file_upload, check_auth
// The original logout_user function:
function logout_user() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: login.php?logged_out=true");
    exit();
}

function get_session_value($key, $default = null) {
    return $_SESSION[$key] ?? $default;
}

function generate_random_password($length = 12) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+-=[]{}|';
    $password = '';
    $characterSetLength = strlen($characters);
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, $characterSetLength - 1)];
    }
    return $password;
}

function handle_file_upload($file_input, $upload_subdir, $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'], $max_size = 5 * 1024 * 1024) {
    if (isset($file_input) && $file_input['error'] === UPLOAD_ERR_OK) {
        $base_upload_dir = 'uploads/';
        $target_dir = $base_upload_dir . trim($upload_subdir, '/') . '/';

        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0775, true)) {
                error_log("Failed to create upload directory: " . $target_dir);
                return false; 
            }
        }

        $file_type = mime_content_type($file_input['tmp_name']);
        if (!in_array($file_type, $allowed_types)) {
            error_log("Invalid file type: " . $file_type . " for file " . $file_input['name']);
            $_SESSION['form_error'] = "Invalid file type for " . htmlspecialchars($file_input['name']) . ". Allowed types: " . implode(', ', $allowed_types);
            return false; 
        }

        if ($file_input['size'] > $max_size) {
            error_log("File too large: " . $file_input['size'] . " for file " . $file_input['name']);
            $_SESSION['form_error'] = "File " . htmlspecialchars($file_input['name']) . " is too large. Maximum size is " . ($max_size / 1024 / 1024) . "MB.";
            return false; 
        }

        $file_extension = pathinfo($file_input['name'], PATHINFO_EXTENSION);
        $safe_filename = uniqid(basename($file_input['name'], ".".$file_extension) . '_', true) . '.' . strtolower($file_extension); // More descriptive unique name
        $target_file = $target_dir . $safe_filename;

        if (move_uploaded_file($file_input['tmp_name'], $target_file)) {
            return trim($target_file, './'); 
        } else {
            error_log("Failed to move uploaded file " . $file_input['name'] . " to: " . $target_file);
            $_SESSION['form_error'] = "Could not save file " . htmlspecialchars($file_input['name']) . ".";
            return false; 
        }
    } elseif (isset($file_input) && $file_input['error'] !== UPLOAD_ERR_NO_FILE) {
        error_log("File upload error code: " . $file_input['error'] . " for file " . ($file_input['name'] ?? 'unknown'));
        $_SESSION['form_error'] = "File upload error for " . htmlspecialchars($file_input['name'] ?? 'N/A') . ". Code: " . $file_input['error'];
        return false;
    }
    return null; 
}

function check_auth() { // This is a simpler version, ensure it's what you need.
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['login_error_message'] = "Please log in."; // Add message
        header('Location: login.php');
        exit();
    }
}
?>