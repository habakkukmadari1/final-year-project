<?php
// dev_user_manager.php
// WARNING: This page contains hardcoded credentials and should be
// strictly protected or used only in a local development environment.
// DO NOT DEPLOY TO A PUBLIC PRODUCTION SERVER WITHOUT ADDITIONAL SECURITY.

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Hardcoded Developer Credentials ---
define('DEV_USERNAME', 'haba'); // CHANGE THIS
define('DEV_PASSWORD', '858799haba'); // CHANGE THIS

$is_dev_logged_in = false;
if (isset($_SESSION['dev_logged_in']) && $_SESSION['dev_logged_in'] === true) {
    $is_dev_logged_in = true;
}

$login_error = '';
$success_message = '';
$error_message = '';

// Handle Developer Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dev_login'])) {
    if (isset($_POST['dev_username']) && isset($_POST['dev_password'])) {
        if ($_POST['dev_username'] === DEV_USERNAME && password_verify($_POST['dev_password'], password_hash(DEV_PASSWORD, PASSWORD_DEFAULT))) { // Verify hashed
            $_SESSION['dev_logged_in'] = true;
            $is_dev_logged_in = true;
            header("Location: dev_user_manager.php"); // Redirect to clear POST
            exit;
        } else {
            $login_error = "Invalid developer credentials.";
        }
    }
}

// Handle Developer Logout
if (isset($_GET['dev_logout'])) {
    unset($_SESSION['dev_logged_in']);
    $is_dev_logged_in = false;
    header("Location: dev_user_manager.php");
    exit;
}


// --- User Management Logic (Only if dev is logged in) ---
if ($is_dev_logged_in) {
    require_once 'db_config.php'; // Establishes $conn

    if (!$conn) {
        $error_message = "Database connection failed. Check db_config.php.";
    } else {
        // Handle Add User
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
            $username = trim($_POST['username']);
            $email = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
            $password = trim($_POST['password']);
            $role = trim($_POST['role']);
            $phone = trim($_POST['phone'] ?? null); // Optional
            // profile_pic can be handled later or via a different interface

            $errors_add = [];
            if (empty($username)) $errors_add[] = "Username is required.";
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors_add[] = "Valid email is required.";
            if (empty($password) || strlen($password) < 8) $errors_add[] = "Password must be at least 8 characters.";
            if (empty($role)) $errors_add[] = "Role is required.";

            // Check if username or email already exists
            if (empty($errors_add)) {
                $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt_check->bind_param("ss", $username, $email);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $errors_add[] = "Username or Email already exists.";
                }
                $stmt_check->close();
            }

            if (empty($errors_add)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt_insert = $conn->prepare("INSERT INTO users (username, email, password, role, phone) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->bind_param("sssss", $username, $email, $hashed_password, $role, $phone);
                if ($stmt_insert->execute()) {
                    $success_message = "User '$username' added successfully with role '$role'.";
                } else {
                    $error_message = "Error adding user: " . $stmt_insert->error;
                }
                $stmt_insert->close();
            } else {
                $error_message = "Validation errors:<br>" . implode("<br>", $errors_add);
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
    <title>Developer User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 800px; margin-top: 30px; }
        .card { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center mb-4">
            <h2><i class="fas fa-user-shield"></i> Developer User Management</h2>
            <p class="text-muted">For adding core system administrators (HT, DoS, Secretary, etc.)</p>
        </div>

        <?php if (!$is_dev_logged_in): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Developer Login</h5>
                </div>
                <div class="card-body">
                    <?php if ($login_error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($login_error); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="dev_user_manager.php">
                        <input type="hidden" name="dev_login" value="1">
                        <div class="mb-3">
                            <label for="dev_username" class="form-label">Dev Username</label>
                            <input type="text" class="form-control" id="dev_username" name="dev_username" required>
                        </div>
                        <div class="mb-3">
                            <label for="dev_password" class="form-label">Dev Password</label>
                            <input type="password" class="form-control" id="dev_password" name="dev_password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login as Developer</button>
                    </form>
                </div>
            </div>
        <?php else: // Developer IS logged in ?>
            <div class_="my-3">
                 <a href="dev_user_manager.php?dev_logout=1" class="btn btn-sm btn-outline-secondary float-end"><i class="fas fa-sign-out-alt"></i> Dev Logout</a>
                 <div class="clearfix"></div>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                 <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo nl2br(htmlspecialchars($error_message)); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($conn): // Only show form if DB connection is okay ?>
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-user-plus"></i> Add New System User</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="dev_user_manager.php">
                        <input type="hidden" name="add_user" value="1">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required minlength="8">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword"><i class="fas fa-eye"></i></button>
                                </div>
                                <small class="form-text text-muted">Min. 8 characters.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="">Select Role...</option>
                                    <option value="head_teacher" <?php echo (($_POST['role'] ?? '') == 'head_teacher') ? 'selected' : ''; ?>>Head Teacher</option>
                                    <option value="director_of_studies" <?php echo (($_POST['role'] ?? '') == 'director_of_studies') ? 'selected' : ''; ?>>Director of Studies</option>
                                    <option value="secretary" <?php echo (($_POST['role'] ?? '') == 'secretary') ? 'selected' : ''; ?>>Secretary</option>
                                    <option value="IT" <?php echo (($_POST['role'] ?? '') == 'IT') ? 'selected' : ''; ?>>IT Admin</option>
                                    <option value="bursar" <?php echo (($_POST['role'] ?? '') == 'bursar') ? 'selected' : ''; ?>>Bursar</option>
                                    <!-- Add other core system roles as needed -->
                                </select>
                            </div>
                             <div class="col-md-6">
                                <label for="phone" class="form-label">Phone (Optional)</label>
                                <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success mt-3 w-100"><i class="fas fa-plus-circle"></i> Add User</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-users-cog"></i> Existing System Users</h5>
                </div>
                <div class="card-body p-0">
                    <?php
                    $stmt_users = $conn->query("SELECT id, username, email, role, phone, created_at FROM users ORDER BY role, username");
                    if ($stmt_users && $stmt_users->num_rows > 0):
                    ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead>
                                <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Phone</th><th>Created</th></tr>
                            </thead>
                            <tbody>
                            <?php while($user = $stmt_users->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone'] ?: 'N/A'); ?></td>
                                    <td><?php echo date('d M Y, H:i', strtotime($user['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <p class="text-muted p-3 text-center">No system users found in the 'users' table.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: // DB Connection failed ?>
                <div class="alert alert-danger">Database connection is not available. Cannot manage users.</div>
            <?php endif; // End $conn check ?>

        <?php endif; // End $is_dev_logged_in check ?>

        <footer class="text-center mt-4 mb-3">
            <small class="text-muted">© <?php echo date("Y"); ?> MACOM SMS - Developer Tool</small>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });
        }
    </script>
</body>
</html>