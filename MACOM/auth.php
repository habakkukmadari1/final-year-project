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
        $_SESSION['error_message'] = "You must be logged in to access this page.";
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
            $_SESSION['error_message'] = "You are not authorized to access this page or perform this action.";
            // Redirect to their own dashboard or a general access denied page.
            // Avoid redirect loops.
            $dashboard_map = [
                'head_teacher' => 'head_teacher_dashboard.php',
                'secretary' => 'secretary_dashboard.php',
                'director_of_studies' => 'director_dashboard.php',
                'IT' => 'IT_dashboard.php',
                'student' => 'student_dashboard.php',
                'teacher' => 'teacher_dashboard.php',
            ];
            $fallback_dashboard = $dashboard_map[$_SESSION['role']] ?? 'dashboard.php'; // A generic dashboard if role not mapped

            // If the current page is already their supposed dashboard, just show message (handled by page)
            // Otherwise, redirect them.
            if (basename($_SERVER['PHP_SELF']) !== $fallback_dashboard) {
                 header("Location: " . $fallback_dashboard . "?error=unauthorized");
            } else {
                // If they are on their dashboard but trying an action they are not allowed for
                // This case is tricky. The page itself should handle displaying the error.
                // For now, this function focuses on page-level access.
            }
            // Consider exiting here if you want to strictly enforce redirection
            // exit();
        }
    }
}

/**
 * Logs out the current user by destroying the session.
 */
function logout_user() {
    // Unset all of the session variables.
    $_SESSION = array();

    // If it's desired to kill the session, also delete the session cookie.
    // Note: This will destroy the session, and not just the session data!
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Finally, destroy the session.
    session_destroy();

    // Redirect to login page. A message can be passed via GET if needed.
    header("Location: login.php?logged_out=true");
    exit();
}

/**
 * Helper function to safely get session variables.
 * @param string $key The session key.
 * @param mixed $default The default value if key is not set.
 * @return mixed The session value or default.
 */
function get_session_value($key, $default = null) {
    return $_SESSION[$key] ?? $default;
}

/**
 * Helper function to generate a more secure random password.
 * @param int $length Length of the password.
 * @return string Generated password.
 */
function generate_random_password($length = 12) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+-=[]{}|';
    $password = '';
    $characterSetLength = strlen($characters);
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, $characterSetLength - 1)];
    }
    return $password;
}

/**
 * Handles file uploads.
 * @param array $file_input The $_FILES['input_name'] array.
 * @param string $upload_subdir The subdirectory within 'uploads/' (e.g., 'teachers/photos').
 * @param array $allowed_types Allowed MIME types.
 * @param int $max_size Maximum file size in bytes.
 * @return string|false The path to the uploaded file on success, or false on failure.
 */
function handle_file_upload($file_input, $upload_subdir, $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'], $max_size = 5 * 1024 * 1024) {
    if (isset($file_input) && $file_input['error'] === UPLOAD_ERR_OK) {
        $base_upload_dir = 'uploads/';
        $target_dir = $base_upload_dir . trim($upload_subdir, '/') . '/';

        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0775, true)) {
                error_log("Failed to create upload directory: " . $target_dir);
                return false; // Failed to create directory
            }
        }

        $file_type = mime_content_type($file_input['tmp_name']);
        if (!in_array($file_type, $allowed_types)) {
            error_log("Invalid file type: " . $file_type);
            $_SESSION['form_error'] = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
            return false; // Invalid file type
        }

        if ($file_input['size'] > $max_size) {
            error_log("File too large: " . $file_input['size']);
            $_SESSION['form_error'] = "File is too large. Maximum size is " . ($max_size / 1024 / 1024) . "MB.";
            return false; // File too large
        }

        $file_extension = pathinfo($file_input['name'], PATHINFO_EXTENSION);
        $safe_filename = uniqid('file_', true) . '.' . strtolower($file_extension);
        $target_file = $target_dir . $safe_filename;

        if (move_uploaded_file($file_input['tmp_name'], $target_file)) {
            return trim($target_file, './'); // Return relative path
        } else {
            error_log("Failed to move uploaded file to: " . $target_file);
            return false; // Failed to move file
        }
    } elseif (isset($file_input) && $file_input['error'] !== UPLOAD_ERR_NO_FILE) {
        // An error occurred other than no file uploaded
        error_log("File upload error code: " . $file_input['error']);
        $_SESSION['form_error'] = "File upload error code: " . $file_input['error'];
        return false;
    }
    return null; // No file uploaded or error, but not a critical one if file is optional
}


function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

?>