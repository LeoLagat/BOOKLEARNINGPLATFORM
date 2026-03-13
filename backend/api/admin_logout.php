<?php
session_start();

unset($_SESSION['admin_logged_in']);
unset($_SESSION['admin_id']);
unset($_SESSION['admin_fullname']);
unset($_SESSION['admin_email']);
unset($_SESSION['admin_role']);
unset($_SESSION['admin_profile_photo']);
unset($_SESSION['admin_login_time']);

header("Location: ../../frontend/admin_login.php?toast=logged_out");
exit();
