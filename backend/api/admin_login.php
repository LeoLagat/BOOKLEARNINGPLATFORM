<?php
session_start();
require_once "config.php";

mysqli_report(MYSQLI_REPORT_OFF);

function ensureAdminUsersTableExists(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS admin_users (
        id INT(11) NOT NULL AUTO_INCREMENT,
        fullname VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('super_admin','sub_admin') NOT NULL DEFAULT 'sub_admin',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($sql)) {
        return false;
    }

    $columnResult = $conn->query("SHOW COLUMNS FROM admin_users LIKE 'role'");
    if ($columnResult && $columnResult->num_rows === 0) {
        if (!$conn->query("ALTER TABLE admin_users ADD COLUMN role ENUM('super_admin','sub_admin') NOT NULL DEFAULT 'sub_admin' AFTER password")) {
            return false;
        }
    }

    return true;
}

function ensureAdminsLegacyTableExists(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS admins (
        id INT(11) NOT NULL AUTO_INCREMENT,
        fullname VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    return (bool) $conn->query($sql);
}

function ensureDefaultAdminUser(mysqli $conn, string $tableName): bool {
    $checkSql = "SELECT id FROM {$tableName} WHERE email = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);

    if (!$checkStmt) {
        return false;
    }

    $defaultEmail = 'admin@gmail.com';
    $checkStmt->bind_param("s", $defaultEmail);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result && $result->fetch_assoc()) {
        if ($tableName === 'admin_users') {
            $updateStmt = $conn->prepare("UPDATE admin_users SET role = 'super_admin' WHERE email = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("s", $defaultEmail);
                $updateStmt->execute();
            }
        }
        return true;
    }

    if ($tableName === 'admin_users') {
        $insertSql = "INSERT INTO admin_users (fullname, email, password, role, is_active) VALUES (?, ?, ?, 'super_admin', 1)";
    } else {
        $insertSql = "INSERT INTO {$tableName} (fullname, email, password, is_active) VALUES (?, ?, ?, 1)";
    }
    $insertStmt = $conn->prepare($insertSql);

    if (!$insertStmt) {
        return false;
    }

    $defaultName = 'Platform Admin';
    $defaultPasswordHash = password_hash('1234', PASSWORD_DEFAULT);
    $insertStmt->bind_param("sss", $defaultName, $defaultEmail, $defaultPasswordHash);

    return $insertStmt->execute();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../frontend/admin_login.php");
    exit();
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    header("Location: ../../frontend/admin_login.php?error=missing_fields");
    exit();
}

$stmt = $conn->prepare("SELECT id, fullname, email, password, role FROM admin_users WHERE email = ? AND is_active = 1 LIMIT 1");

if (!$stmt && ensureAdminUsersTableExists($conn)) {
    ensureDefaultAdminUser($conn, 'admin_users');
    $stmt = $conn->prepare("SELECT id, fullname, email, password, role FROM admin_users WHERE email = ? AND is_active = 1 LIMIT 1");
}

if (!$stmt && ensureAdminsLegacyTableExists($conn)) {
    ensureDefaultAdminUser($conn, 'admins');
    $stmt = $conn->prepare("SELECT id, fullname, email, password FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
}

if ($stmt) {
    ensureDefaultAdminUser($conn, 'admin_users');
}

if (!$stmt) {
    header("Location: ../../frontend/admin_login.php?error=admin_table_setup_failed");
    exit();
}

$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($admin = $result->fetch_assoc()) {
    if (password_verify($password, $admin['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_fullname'] = $admin['fullname'];
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_role'] = $admin['role'] ?? 'super_admin';

        header("Location: ../../frontend/admin_dashboard.php");
        exit();
    }
}

header("Location: ../../frontend/admin_login.php?error=invalid_credentials");
exit();
