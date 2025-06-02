<?php
// Localhost Database Configuration with MySQLi
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'macom_db';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set timezone
date_default_timezone_set('Africa/Kampala');

// Start session if not already started.
// It's good practice to have sessions started early if your app relies on them.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>