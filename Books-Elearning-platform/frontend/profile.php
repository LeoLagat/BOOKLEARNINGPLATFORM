<?php
session_start();

if (!isset($_SESSION['fullname'])) {
    header('Location: login_view.php');
    exit();
}

include "../backend/api/config.php";

function ensureUserProfileColumns(mysqli $conn): void {
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_user_agent VARCHAR(255) NULL DEFAULT NULL");
}

function normalizePhone(string $rawPhone): string {
    $phone = str_replace([' ', '+'], '', $rawPhone);
    if ($phone === '') {
        return '';
    }

    if (strpos($phone, '254') === 0) {
        return $phone;
    }

    if (strpos($phone, '0') === 0) {
        return '254' . substr($phone, 1);
    }

    return '254' . ltrim($phone, '0');
}

function fetchCurrentUser(mysqli $conn): ?array {
    $sessionPhone = $_SESSION['phone'] ?? '';
    $sessionEmail = $_SESSION['email'] ?? '';

    if ($sessionPhone !== '') {
        $phone254 = normalizePhone($sessionPhone);
        $phone0 = (strpos($phone254, '254') === 0) ? '0' . substr($phone254, 3) : $sessionPhone;

        $stmt = $conn->prepare('SELECT id, fullname, email, phone, membership, payment_status, created_at, profile_photo, last_login_at, last_login_ip, last_login_user_agent FROM users WHERE phone = ? OR phone = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ss', $phone254, $phone0);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($row) {
                return $row;
            }
        }
    }

    if ($sessionEmail !== '') {
        $stmt = $conn->prepare('SELECT id, fullname, email, phone, membership, payment_status, created_at, profile_photo, last_login_at, last_login_ip, last_login_user_agent FROM users WHERE email = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $sessionEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($row) {
                return $row;
            }
        }
    }

    return null;
}

function ensureUserBookTablesExist(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS user_bookmarks (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        book_id INT(11) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_user_bookmark (user_id, book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS user_book_activity (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        book_id INT(11) NOT NULL,
        action VARCHAR(20) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

ensureUserBookTablesExist($conn);
ensureUserProfileColumns($conn);

$user = fetchCurrentUser($conn);

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login_view.php');
    exit();
}

$feedback = '';
$feedbackType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phoneInput = trim($_POST['phone'] ?? '');

        if ($fullname === '' || $email === '' || $phoneInput === '') {
            $feedback = 'All profile fields are required.';
            $feedbackType = 'error';
        } else {
            $phone254 = normalizePhone($phoneInput);
            $phone0 = '0' . substr($phone254, 3);

            $dupe = $conn->prepare('SELECT id FROM users WHERE (email = ? OR phone = ? OR phone = ?) AND id <> ? LIMIT 1');
            if ($dupe) {
                $dupe->bind_param('sssi', $email, $phone254, $phone0, $user['id']);
                $dupe->execute();
                $dupeResult = $dupe->get_result();
                if ($dupeResult && $dupeResult->num_rows > 0) {
                    $feedback = 'Email or phone is already used by another account.';
                    $feedbackType = 'error';
                } else {
                    $nextPhoto = $user['profile_photo'] ?? null;
                    if (isset($_FILES['profile_photo']) && is_uploaded_file($_FILES['profile_photo']['tmp_name'])) {
                        $tmpPath = $_FILES['profile_photo']['tmp_name'];
                        $imageInfo = @getimagesize($tmpPath);
                        $extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];

                        if (!$imageInfo || !in_array($extension, $allowedExt, true)) {
                            $feedback = 'Profile image must be a valid JPG, PNG, or WEBP file.';
                            $feedbackType = 'error';
                        } else {
                            $photoDir = dirname(__DIR__) . '/uploads/profile_photos';
                            if (!is_dir($photoDir)) {
                                mkdir($photoDir, 0775, true);
                            }

                            $fileName = 'user_' . (int) $user['id'] . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
                            $targetPath = $photoDir . '/' . $fileName;
                            $relativePath = 'uploads/profile_photos/' . $fileName;

                            if (move_uploaded_file($tmpPath, $targetPath)) {
                                if (!empty($user['profile_photo']) && strpos($user['profile_photo'], 'uploads/profile_photos/') === 0) {
                                    $oldPath = dirname(__DIR__) . '/' . $user['profile_photo'];
                                    if (is_file($oldPath)) {
                                        @unlink($oldPath);
                                    }
                                }
                                $nextPhoto = $relativePath;
                            } else {
                                $feedback = 'Failed to upload profile photo. Try again.';
                                $feedbackType = 'error';
                            }
                        }
                    }

                    if ($feedbackType === 'error' && $feedback !== '') {
                        // Stop update when upload validation fails.
                    } else {
                        $update = $conn->prepare('UPDATE users SET fullname = ?, email = ?, phone = ?, profile_photo = ? WHERE id = ?');
                    if ($update) {
                        $update->bind_param('ssssi', $fullname, $email, $phone254, $nextPhoto, $user['id']);
                        if ($update->execute()) {
                            $_SESSION['fullname'] = $fullname;
                            $_SESSION['email'] = $email;
                            $_SESSION['phone'] = $phone254;
                            $_SESSION['profile_photo'] = $nextPhoto;
                            $feedback = 'Profile updated successfully.';
                            $feedbackType = 'success';
                            $user = fetchCurrentUser($conn);
                        } else {
                            $feedback = 'Could not update profile right now.';
                            $feedbackType = 'error';
                        }
                    }
                    }
                }
            }
        }
    }

    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword === '' || strlen($newPassword) < 6) {
            $feedback = 'New password must be at least 6 characters.';
            $feedbackType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $feedback = 'New password and confirmation do not match.';
            $feedbackType = 'error';
        } else {
            $pwCheck = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            if ($pwCheck) {
                $pwCheck->bind_param('i', $user['id']);
                $pwCheck->execute();
                $pwResult = $pwCheck->get_result();
                $pwRow = $pwResult ? $pwResult->fetch_assoc() : null;

                if (!$pwRow || !password_verify($currentPassword, $pwRow['password'])) {
                    $feedback = 'Current password is incorrect.';
                    $feedbackType = 'error';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pwUpdate = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    if ($pwUpdate) {
                        $pwUpdate->bind_param('si', $hash, $user['id']);
                        if ($pwUpdate->execute()) {
                            $feedback = 'Password changed successfully.';
                            $feedbackType = 'success';
                        } else {
                            $feedback = 'Could not change password right now.';
                            $feedbackType = 'error';
                        }
                    }
                }
            }
        }
    }
}

$bookmarkCount = 0;
$activityCount = 0;

$bookmarkStmt = $conn->prepare('SELECT COUNT(*) AS total FROM user_bookmarks WHERE user_id = ?');
if ($bookmarkStmt) {
    $bookmarkStmt->bind_param('i', $user['id']);
    $bookmarkStmt->execute();
    $bookmarkResult = $bookmarkStmt->get_result();
    $bookmarkRow = $bookmarkResult ? $bookmarkResult->fetch_assoc() : null;
    $bookmarkCount = $bookmarkRow ? (int) $bookmarkRow['total'] : 0;
}

$activityStmt = $conn->prepare('SELECT COUNT(*) AS total FROM user_book_activity WHERE user_id = ?');
if ($activityStmt) {
    $activityStmt->bind_param('i', $user['id']);
    $activityStmt->execute();
    $activityResult = $activityStmt->get_result();
    $activityRow = $activityResult ? $activityResult->fetch_assoc() : null;
    $activityCount = $activityRow ? (int) $activityRow['total'] : 0;
}

$joined = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'N/A';
$lastLogin = !empty($user['last_login_at']) ? date('F j, Y g:i A', strtotime($user['last_login_at'])) : 'No login history yet';
$sessionStarted = !empty($_SESSION['login_time']) ? date('F j, Y g:i A', strtotime($_SESSION['login_time'])) : 'Current session';
$photoUrl = !empty($user['profile_photo']) ? '../' . ltrim($user['profile_photo'], '/') : '';
$lastLoginIp = !empty($user['last_login_ip']) ? $user['last_login_ip'] : 'Unknown';
$lastLoginAgent = !empty($user['last_login_user_agent']) ? $user['last_login_user_agent'] : 'Unknown device';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Books E-Learning</title>
    <style>
        :root {
            --bg: #f2f6ff;
            --card: #ffffff;
            --primary: #243b6b;
            --secondary: #415fa7;
            --success: #1f8f5f;
            --error: #b82f45;
            --text: #1f2a3d;
            --muted: #5f6e89;
            --line: #dbe3f4;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: radial-gradient(circle at top right, #e9f1ff 0%, var(--bg) 42%, #fdfdff 100%);
            color: var(--text);
            min-height: 100vh;
        }

        .container {
            max-width: 980px;
            margin: 0 auto;
            padding: 24px 18px 40px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .topbar h1 {
            margin: 0;
            color: var(--primary);
        }

        .links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            color: #fff;
            background: var(--secondary);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
        }

        .btn.danger {
            background: #c43f4e;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 14px 24px rgba(36, 59, 107, 0.08);
            padding: 20px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 8px;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .avatar {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #d4e0fa;
            background: #eef3ff;
        }

        .avatar-fallback {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            font-weight: 700;
            background: #e8efff;
            color: var(--secondary);
            border: 3px solid #d4e0fa;
        }

        .meta {
            margin-top: 10px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fbfdff;
            padding: 12px;
            line-height: 1.6;
            color: #33415f;
        }

        input[type="file"] {
            border: 1px dashed #9fb3dd;
            background: #f7faff;
            padding: 10px;
            border-radius: 10px;
        }

        .stat {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            background: #fbfdff;
        }

        .stat .value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary);
        }

        h2 {
            margin-top: 0;
            color: var(--secondary);
        }

        form {
            display: grid;
            gap: 10px;
        }

        label {
            font-weight: 600;
            color: var(--primary);
        }

        input {
            border: 1px solid #c9d4ec;
            border-radius: 10px;
            padding: 10px 12px;
            width: 100%;
            box-sizing: border-box;
        }

        .status {
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            font-weight: 600;
        }

        .status.success {
            background: #eaf9f1;
            color: var(--success);
            border: 1px solid #bde7ce;
        }

        .status.error {
            background: #fff0f3;
            color: var(--error);
            border: 1px solid #f1c1cb;
        }

        .muted {
            color: var(--muted);
            margin-top: 0;
        }

        @media (min-width: 860px) {
            .grid {
                grid-template-columns: 1fr 1fr;
            }
            .card.wide {
                grid-column: 1 / -1;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1>My Profile</h1>
        <div class="links">
            <a href="index.php" class="btn">Back to Dashboard</a>
            <a href="../backend/api/logout.php" class="btn danger">Logout</a>
        </div>
    </div>

    <?php if ($feedback !== ''): ?>
        <div class="status <?php echo $feedbackType === 'success' ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($feedback); ?>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="card wide">
            <div class="profile-header">
                <?php if ($photoUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Profile Photo" class="avatar">
                <?php else: ?>
                    <div class="avatar-fallback"><?php echo htmlspecialchars(strtoupper(substr($user['fullname'], 0, 1))); ?></div>
                <?php endif; ?>
                <div>
                    <h2 style="margin:0;">Account Overview</h2>
                    <p class="muted" style="margin:6px 0 0;">Keep your contact details updated so payment and account updates reach you correctly.</p>
                </div>
            </div>
            <div class="stats">
                <div class="stat"><div class="value"><?php echo htmlspecialchars($user['membership']); ?></div><div>Current Plan</div></div>
                <div class="stat"><div class="value"><?php echo htmlspecialchars($user['payment_status']); ?></div><div>Payment Status</div></div>
                <div class="stat"><div class="value"><?php echo $bookmarkCount; ?></div><div>Bookmarks</div></div>
                <div class="stat"><div class="value"><?php echo $activityCount; ?></div><div>Reading Activities</div></div>
                <div class="stat"><div class="value"><?php echo htmlspecialchars($joined); ?></div><div>Member Since</div></div>
            </div>
            <div class="meta">
                <div><strong>Last Login:</strong> <?php echo htmlspecialchars($lastLogin); ?></div>
                <div><strong>Login IP:</strong> <?php echo htmlspecialchars($lastLoginIp); ?></div>
                <div><strong>Device:</strong> <?php echo htmlspecialchars($lastLoginAgent); ?></div>
                <div><strong>Session Started:</strong> <?php echo htmlspecialchars($sessionStarted); ?></div>
            </div>
        </div>

        <div class="card">
            <h2>Edit Profile</h2>
            <form method="POST" action="" enctype="multipart/form-data">
                <label for="fullname">Full Name</label>
                <input id="fullname" type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname']); ?>" required>

                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>

                <label for="phone">Phone</label>
                <input id="phone" type="text" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>

                <label for="profile_photo">Profile Photo (JPG, PNG, WEBP)</label>
                <input id="profile_photo" type="file" name="profile_photo" accept="image/png,image/jpeg,image/webp">

                <button type="submit" name="update_profile" class="btn">Save Profile Changes</button>
            </form>
        </div>

        <div class="card">
            <h2>Change Password</h2>
            <form method="POST" action="">
                <label for="current_password">Current Password</label>
                <input id="current_password" type="password" name="current_password" required>

                <label for="new_password">New Password</label>
                <input id="new_password" type="password" name="new_password" minlength="6" required>

                <label for="confirm_password">Confirm New Password</label>
                <input id="confirm_password" type="password" name="confirm_password" minlength="6" required>

                <button type="submit" name="change_password" class="btn">Update Password</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
