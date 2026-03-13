<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "config.php";

function ensureUserProfileColumns(mysqli $conn): void {
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_user_agent VARCHAR(255) NULL DEFAULT NULL");
}

ensureUserProfileColumns($conn);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Database error: " . $conn->error);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            $loginIp = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
            $loginUa = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $lastLoginStmt = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ?, last_login_user_agent = ? WHERE id = ?");
            if ($lastLoginStmt) {
                $lastLoginStmt->bind_param("ssi", $loginIp, $loginUa, $user['id']);
                $lastLoginStmt->execute();
            }

            $_SESSION["user_id"] = (int) $user["id"];
            $_SESSION["fullname"] = $user["fullname"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["membership"] = $user["membership"];
            $_SESSION["phone"] = $user["phone"];
            $_SESSION["payment_status"] = $user["payment_status"];
            $_SESSION["profile_photo"] = $user["profile_photo"] ?? null;
            $_SESSION["login_time"] = date("Y-m-d H:i:s");

            header("Location: ../../frontend/index.php");
            exit();

        } else {
            echo "Wrong password!";
        }

    } else {
        echo "User not found!";
    }
}
?>
