<?php
// logout.php
require_once 'auth.php'; // This file contains the logout_user() function and starts the session

// Call the logout function from auth.php
logout_user();

// The logout_user() function already handles session destruction and redirection to login.php.
// So, no further code is needed here.
?>