<?php
session_start(); // 1. Find the current session

// 2. Remove all session variables
session_unset(); 

// 3. Destroy the session entirely
session_destroy(); 

// 4. Redirect the user back to the login page
header("Location: ../../frontend/login_view.php"); 
exit();
?>