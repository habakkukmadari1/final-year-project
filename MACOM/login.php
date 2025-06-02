<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db_config.php';
include 'auth.php';

// Process login if form submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Debug: Check if form data is received
    error_log("Login attempt - Username: $username, Password: $password");

    // Check in the users table first
    $user_query = $conn->prepare("SELECT * FROM users WHERE username = ?");
    if (!$user_query) {
        error_log("Prepare failed: " . $conn->error);
        $login_error = "Database error. Please try again later.";
    } else {
        $user_query->bind_param("s", $username);
        $executed = $user_query->execute();
        
        if (!$executed) {
            error_log("Execute failed: " . $user_query->error);
            $login_error = "Database error. Please try again later.";
        } else {
            $user_result = $user_query->get_result();
            
            if ($user_result->num_rows > 0) {
                $user = $user_result->fetch_assoc();
                
                // Debug: Check what user data was retrieved
                error_log("User found: " . print_r($user, true));
                
                // Check hashed password
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['username']; // Using username as ID since no ID column
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['username']; // Using username as name
                    $_SESSION['role'] = $user['role'];
                    
                    // Debug: Check session variables
                    error_log("Session set: " . print_r($_SESSION, true));
                    
                    // Success message
                    $_SESSION['success'] = "Login successful! Welcome back, " . $user['username'];
                    
                    // Redirect based on role
                    $dashboard_map = [
                        'head_teacher' => 'head_teacher_dashboard.php',
                        'secretary' => 'secretary_dashboard.php',
                        'director_of_studies' => 'director_dashboard.php',
                        'IT' => 'IT_dashboard.php',
                        'student' => 'student_dashboard.php'
                    ];
                    
                    $redirect = $dashboard_map[$user['role']] ?? 'dashboard.php';
                    header("Location: $redirect");
                    exit();
                } else {
                    $login_error = "Invalid password. Please try again.";
                    error_log("Password mismatch for user: $username");
                }
            } else {
                $login_error = "No account found with that username.";
                error_log("No user found with username: $username");
            }
        }
    }
}

// If user is already logged in, redirect them
if (isset($_SESSION['user_id'])) {
    check_login();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MACOM School Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-light: #60a5fa;
            --primary-dark: #1d4ed8;
            --accent-color: #ef4444;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --bg-primary: #ffffff;
            --bg-secondary: #f1f5f9;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            height: 100vh;
            display: flex;
            align-items: center;
        }

        .login-container {
            display: flex;
            max-width: 1200px;
            margin: 0 auto;
            background-color: var(--bg-primary);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .welcome-section {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-section {
            flex: 1;
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .school-logo {
            width: 80px;
            margin-bottom: 20px;
        }

        .welcome-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .welcome-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 30px;
        }

        .value-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .value-icon {
            background-color: rgba(255, 255, 255, 0.2);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }

        .login-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .login-subtitle {
            color: var(--text-secondary);
            margin-bottom: 30px;
        }
        /* Success message styling */
.success-message {
    color: white;
    background-color: #10b981;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
    text-align: center;
    animation: fadeIn 0.3s ease-in-out;
    display: flex;
    align-items: center;
    justify-content: center;
}

.success-message i {
    margin-right: 10px;
    font-size: 1.2rem;
}



        .form-control {
            height: 50px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding-left: 15px;
            margin-bottom: 20px;
        }

        .form-control:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-login {
            background-color: var(--primary-color);
            color: white;
            height: 50px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            transition: all 0.3s;
        }

        .btn-login:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Enhanced error message styling */
        .error-message {
            color: white;
            background-color: var(--accent-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            text-align: center;
            animation: fadeIn 0.3s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .error-message i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 992px) {
            .login-container {
                flex-direction: column;
                max-width: 500px;
            }
            
            .welcome-section, .login-section {
                padding: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="welcome-section">
            <img src="https://via.placeholder.com/80x80" alt="School Logo" class="school-logo">
            <h1 class="welcome-title">Welcome to MACOM</h1>
            <p class="welcome-subtitle">Majorine College Mulawa School Management System</p>
            
            <div class="values-list">
                <div class="value-item">
                    <div class="value-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div>
                        <h5>Academic Excellence</h5>
                        <p>Nurturing future leaders through quality education</p>
                    </div>
                </div>
                
                <div class="value-item">
                    <div class="value-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <div>
                        <h5>Community Values</h5>
                        <p>Building strong relationships between students, staff and parents</p>
                    </div>
                </div>
                
                <div class="value-item">
                    <div class="value-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <div>
                        <h5>Innovation</h5>
                        <p>Embracing technology for better learning experiences</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="login-section">
            <h2 class="login-title">Login to Your Account</h2>
            <p class="login-subtitle">Enter your credentials to access the system</p>
            
            <?php if (!empty($login_error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($login_error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); 
                    unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <input type="text" class="form-control" name="username" placeholder="Username" required>
                </div>
                <div class="form-group">
                    <input type="password" class="form-control" name="password" placeholder="Password" required>
                </div>
                <button type="submit" class="btn btn-login btn-block">Login</button>
            </form>
            
            <div class="text-center mt-4">
                <a href="#" class="text-decoration-none">Forgot password?</a>
                <span class="mx-2">|</span>
                <a href="#" class="text-decoration-none">Contact Support</a>
            </div>
        </div>
    </div>


    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>