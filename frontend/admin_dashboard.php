<?php
session_start();
require_once "../backend/api/config.php";

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

function ensureAdminProfileColumns(mysqli $conn): bool {
    $profileColumns = [
        "ALTER TABLE admin_users ADD COLUMN profile_photo VARCHAR(255) NULL DEFAULT NULL AFTER role",
        "ALTER TABLE admin_users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER created_at",
        "ALTER TABLE admin_users ADD COLUMN last_login_ip VARCHAR(45) NULL DEFAULT NULL AFTER last_login_at",
        "ALTER TABLE admin_users ADD COLUMN last_login_user_agent VARCHAR(255) NULL DEFAULT NULL AFTER last_login_ip"
    ];

    foreach ($profileColumns as $sqlAlter) {
        if (!$conn->query($sqlAlter)) {
            $error = $conn->error;
            if (stripos($error, 'Duplicate column name') === false) {
                return false;
            }
        }
    }

    return true;
}

function formatDateDisplay(?string $value): string {
    if (!$value) {
        return 'N/A';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('F j, Y g:i A', $timestamp);
}

function ensureArchivedBooksTableExists(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS archived_books (
        id INT(11) NOT NULL AUTO_INCREMENT,
        original_book_id INT(11) DEFAULT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        membership_required ENUM('Basic','Premium','VIP') DEFAULT 'Basic',
        file_path VARCHAR(255) NOT NULL,
        archived_by VARCHAR(100) DEFAULT NULL,
        archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    return (bool) $conn->query($sql);
}

function deleteUploadedPdf(string $filePath): bool {
    $uploadsDir = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');

    if ($uploadsDir === false || strpos($filePath, 'uploads/') !== 0) {
        return true;
    }

    $absoluteFile = $uploadsDir . DIRECTORY_SEPARATOR . basename(substr($filePath, strlen('uploads/')));

    if (!is_file($absoluteFile)) {
        return true;
    }

    return unlink($absoluteFile);
}

ensureArchivedBooksTableExists($conn);
ensureAdminUsersTableExists($conn);
ensureAdminProfileColumns($conn);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

$feedback = '';
$feedbackType = '';
$adminRole = $_SESSION['admin_role'] ?? 'super_admin';
$adminId = (int) ($_SESSION['admin_id'] ?? 0);

$adminProfile = null;
if ($adminId > 0) {
    $adminStmt = $conn->prepare("SELECT id, fullname, email, role, profile_photo, last_login_at, last_login_ip, last_login_user_agent, created_at FROM admin_users WHERE id = ? LIMIT 1");
    if ($adminStmt) {
        $adminStmt->bind_param("i", $adminId);
        $adminStmt->execute();
        $adminResult = $adminStmt->get_result();
        $adminProfile = $adminResult ? $adminResult->fetch_assoc() : null;
    }
}

if (!$adminProfile) {
    session_unset();
    session_destroy();
    header("Location: admin_login.php?error=invalid_credentials");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_profile'])) {
    $fullname = trim($_POST['admin_fullname'] ?? '');
    $email = trim($_POST['admin_email'] ?? '');

    if ($fullname === '' || $email === '') {
        $feedback = 'Admin full name and email are required.';
        $feedbackType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $feedback = 'Please provide a valid admin email address.';
        $feedbackType = 'error';
    } else {
        $dupeStmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ? AND id <> ? LIMIT 1");
        if (!$dupeStmt) {
            $feedback = 'Server error checking admin email.';
            $feedbackType = 'error';
        } else {
            $dupeStmt->bind_param("si", $email, $adminId);
            $dupeStmt->execute();
            $dupeResult = $dupeStmt->get_result();

            if ($dupeResult && $dupeResult->fetch_assoc()) {
                $feedback = 'That email is already in use by another admin.';
                $feedbackType = 'error';
            } else {
                $newPhotoPath = $adminProfile['profile_photo'] ?? null;

                if (isset($_FILES['admin_profile_photo']) && is_uploaded_file($_FILES['admin_profile_photo']['tmp_name'])) {
                    $tmpFile = $_FILES['admin_profile_photo']['tmp_name'];
                    $info = @getimagesize($tmpFile);
                    $extension = strtolower(pathinfo($_FILES['admin_profile_photo']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                    if (!$info || !in_array($extension, $allowed, true)) {
                        $feedback = 'Profile photo must be a valid JPG, PNG, or WEBP image.';
                        $feedbackType = 'error';
                    } else {
                        $profileDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'admin_profiles';
                        if (!is_dir($profileDir)) {
                            mkdir($profileDir, 0777, true);
                        }

                        $fileName = 'admin_' . $adminId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $extension;
                        $targetPath = $profileDir . DIRECTORY_SEPARATOR . $fileName;
                        $relativePath = 'uploads/admin_profiles/' . $fileName;

                        if (move_uploaded_file($tmpFile, $targetPath)) {
                            if (!empty($adminProfile['profile_photo']) && strpos($adminProfile['profile_photo'], 'uploads/admin_profiles/') === 0) {
                                $oldPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $adminProfile['profile_photo']);
                                if (is_file($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }
                            $newPhotoPath = $relativePath;
                        } else {
                            $feedback = 'Failed to upload profile photo.';
                            $feedbackType = 'error';
                        }
                    }
                }

                if ($feedbackType !== 'error') {
                    $updateStmt = $conn->prepare("UPDATE admin_users SET fullname = ?, email = ?, profile_photo = ? WHERE id = ?");
                    if (!$updateStmt) {
                        $feedback = 'Server error updating admin profile.';
                        $feedbackType = 'error';
                    } else {
                        $updateStmt->bind_param("sssi", $fullname, $email, $newPhotoPath, $adminId);
                        if ($updateStmt->execute()) {
                            $_SESSION['admin_fullname'] = $fullname;
                            $_SESSION['admin_email'] = $email;
                            $_SESSION['admin_profile_photo'] = $newPhotoPath;
                            $feedback = 'Admin profile updated successfully.';
                            $feedbackType = 'success';
                        } else {
                            $feedback = 'Could not update profile at this time.';
                            $feedbackType = 'error';
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_admin_password'])) {
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
        $pwStmt = $conn->prepare("SELECT password FROM admin_users WHERE id = ? LIMIT 1");
        if (!$pwStmt) {
            $feedback = 'Server error verifying password.';
            $feedbackType = 'error';
        } else {
            $pwStmt->bind_param("i", $adminId);
            $pwStmt->execute();
            $pwResult = $pwStmt->get_result();
            $pwRow = $pwResult ? $pwResult->fetch_assoc() : null;

            if (!$pwRow || !password_verify($currentPassword, $pwRow['password'])) {
                $feedback = 'Current password is incorrect.';
                $feedbackType = 'error';
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updatePasswordStmt = $conn->prepare("UPDATE admin_users SET password = ? WHERE id = ?");
                if (!$updatePasswordStmt) {
                    $feedback = 'Server error updating password.';
                    $feedbackType = 'error';
                } else {
                    $updatePasswordStmt->bind_param("si", $passwordHash, $adminId);
                    if ($updatePasswordStmt->execute()) {
                        $feedback = 'Admin password updated successfully.';
                        $feedbackType = 'success';
                    } else {
                        $feedback = 'Could not update admin password.';
                        $feedbackType = 'error';
                    }
                }
            }
        }
    }
}

if ($adminId > 0) {
    $adminRefreshStmt = $conn->prepare("SELECT id, fullname, email, role, profile_photo, last_login_at, last_login_ip, last_login_user_agent, created_at FROM admin_users WHERE id = ? LIMIT 1");
    if ($adminRefreshStmt) {
        $adminRefreshStmt->bind_param("i", $adminId);
        $adminRefreshStmt->execute();
        $adminRefreshResult = $adminRefreshStmt->get_result();
        $latestAdmin = $adminRefreshResult ? $adminRefreshResult->fetch_assoc() : null;
        if ($latestAdmin) {
            $adminProfile = $latestAdmin;
            $adminRole = $adminProfile['role'] ?? $adminRole;
            $_SESSION['admin_role'] = $adminRole;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sub_admin'])) {
    if ($adminRole !== 'super_admin') {
        $feedback = 'Only the super admin can create sub-admin accounts.';
        $feedbackType = 'error';
    } else {
        $fullname = trim($_POST['sub_admin_fullname'] ?? '');
        $email = trim($_POST['sub_admin_email'] ?? '');
        $password = $_POST['sub_admin_password'] ?? '';

        if ($fullname === '' || $email === '' || $password === '') {
            $feedback = 'Sub-admin name, email, and password are required.';
            $feedbackType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $feedback = 'Please enter a valid sub-admin email address.';
            $feedbackType = 'error';
        } elseif (strlen($password) < 4) {
            $feedback = 'Sub-admin password must be at least 4 characters.';
            $feedbackType = 'error';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ? LIMIT 1");

            if (!$checkStmt) {
                $feedback = 'Server error checking existing admin accounts.';
                $feedbackType = 'error';
            } else {
                $checkStmt->bind_param("s", $email);
                $checkStmt->execute();
                $existingAdmin = $checkStmt->get_result();

                if ($existingAdmin && $existingAdmin->fetch_assoc()) {
                    $feedback = 'That admin email already exists.';
                    $feedbackType = 'error';
                } else {
                    $insertStmt = $conn->prepare("INSERT INTO admin_users (fullname, email, password, role, is_active) VALUES (?, ?, ?, 'sub_admin', 1)");

                    if (!$insertStmt) {
                        $feedback = 'Server error creating the sub-admin account.';
                        $feedbackType = 'error';
                    } else {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $insertStmt->bind_param("sss", $fullname, $email, $passwordHash);

                        if ($insertStmt->execute()) {
                            $feedback = 'Sub-admin account created successfully.';
                            $feedbackType = 'success';
                        } else {
                            $feedback = 'Failed to create the sub-admin account.';
                            $feedbackType = 'error';
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_book'])) {
    $bookId = (int) ($_POST['book_id'] ?? 0);

    if ($bookId <= 0) {
        $feedback = 'Invalid book selected for archive.';
        $feedbackType = 'error';
    } else {
        $selectStmt = $conn->prepare("SELECT id, title, description, membership_required, file_path FROM books WHERE id = ? LIMIT 1");

        if (!$selectStmt) {
            $feedback = 'Server error preparing archive request.';
            $feedbackType = 'error';
        } else {
            $selectStmt->bind_param("i", $bookId);
            $selectStmt->execute();
            $bookResult = $selectStmt->get_result();
            $bookRow = $bookResult ? $bookResult->fetch_assoc() : null;

            if (!$bookRow) {
                $feedback = 'Book record not found.';
                $feedbackType = 'error';
            } else {
                $archiveStmt = $conn->prepare("INSERT INTO archived_books (original_book_id, title, description, membership_required, file_path, archived_by) VALUES (?, ?, ?, ?, ?, ?)");

                if (!$archiveStmt) {
                    $feedback = 'Server error archiving the book.';
                    $feedbackType = 'error';
                } else {
                    $archivedBy = $_SESSION['admin_email'] ?? ($_SESSION['admin_fullname'] ?? 'admin');
                    $archiveStmt->bind_param(
                        "isssss",
                        $bookRow['id'],
                        $bookRow['title'],
                        $bookRow['description'],
                        $bookRow['membership_required'],
                        $bookRow['file_path'],
                        $archivedBy
                    );

                    if (!$archiveStmt->execute()) {
                        $feedback = 'Failed to move the book into the recycle bin.';
                        $feedbackType = 'error';
                    } else {
                        $deleteStmt = $conn->prepare("DELETE FROM books WHERE id = ?");

                        if (!$deleteStmt) {
                            $feedback = 'Book archived, but active record cleanup failed.';
                            $feedbackType = 'error';
                        } else {
                            $deleteStmt->bind_param("i", $bookId);

                            if (!$deleteStmt->execute()) {
                                $feedback = 'Book archived, but active record cleanup failed.';
                                $feedbackType = 'error';
                            } else {
                                $feedback = 'Book moved to recycle bin. File kept for possible restore.';
                                $feedbackType = 'success';
                            }
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_book'])) {
    $archiveId = (int) ($_POST['archive_id'] ?? 0);

    if ($archiveId <= 0) {
        $feedback = 'Invalid archived book selected for restore.';
        $feedbackType = 'error';
    } else {
        $selectArchiveStmt = $conn->prepare("SELECT id, title, description, membership_required, file_path FROM archived_books WHERE id = ? LIMIT 1");

        if (!$selectArchiveStmt) {
            $feedback = 'Server error preparing restore request.';
            $feedbackType = 'error';
        } else {
            $selectArchiveStmt->bind_param("i", $archiveId);
            $selectArchiveStmt->execute();
            $archiveResult = $selectArchiveStmt->get_result();
            $archiveRow = $archiveResult ? $archiveResult->fetch_assoc() : null;

            if (!$archiveRow) {
                $feedback = 'Archived book not found.';
                $feedbackType = 'error';
            } else {
                $insertBookStmt = $conn->prepare("INSERT INTO books (title, description, membership_required, file_path) VALUES (?, ?, ?, ?)");

                if (!$insertBookStmt) {
                    $feedback = 'Server error restoring the book.';
                    $feedbackType = 'error';
                } else {
                    $insertBookStmt->bind_param("ssss", $archiveRow['title'], $archiveRow['description'], $archiveRow['membership_required'], $archiveRow['file_path']);

                    if (!$insertBookStmt->execute()) {
                        $feedback = 'Failed to restore the book.';
                        $feedbackType = 'error';
                    } else {
                        $deleteArchiveStmt = $conn->prepare("DELETE FROM archived_books WHERE id = ?");

                        if ($deleteArchiveStmt) {
                            $deleteArchiveStmt->bind_param("i", $archiveId);
                            $deleteArchiveStmt->execute();
                        }

                        $feedback = 'Book restored from recycle bin.';
                        $feedbackType = 'success';
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge_book'])) {
    $archiveId = (int) ($_POST['archive_id'] ?? 0);

    if ($archiveId <= 0) {
        $feedback = 'Invalid archived book selected for permanent deletion.';
        $feedbackType = 'error';
    } else {
        $selectArchiveStmt = $conn->prepare("SELECT id, file_path FROM archived_books WHERE id = ? LIMIT 1");

        if (!$selectArchiveStmt) {
            $feedback = 'Server error preparing permanent delete request.';
            $feedbackType = 'error';
        } else {
            $selectArchiveStmt->bind_param("i", $archiveId);
            $selectArchiveStmt->execute();
            $archiveResult = $selectArchiveStmt->get_result();
            $archiveRow = $archiveResult ? $archiveResult->fetch_assoc() : null;

            if (!$archiveRow) {
                $feedback = 'Archived book not found.';
                $feedbackType = 'error';
            } else {
                $deleteArchiveStmt = $conn->prepare("DELETE FROM archived_books WHERE id = ?");

                if (!$deleteArchiveStmt) {
                    $feedback = 'Server error removing archived record.';
                    $feedbackType = 'error';
                } else {
                    $deleteArchiveStmt->bind_param("i", $archiveId);

                    if (!$deleteArchiveStmt->execute()) {
                        $feedback = 'Failed to delete archived record.';
                        $feedbackType = 'error';
                    } else {
                        if (deleteUploadedPdf($archiveRow['file_path'])) {
                            $feedback = 'Archived book permanently deleted and file removed.';
                            $feedbackType = 'success';
                        } else {
                            $feedback = 'Archived record deleted, but file removal from disk failed.';
                            $feedbackType = 'error';
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_book'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $membershipRequired = $_POST['membership_required'] ?? 'Basic';
    $allowedMemberships = ['Basic', 'Premium', 'VIP'];

    if ($title === '' || $description === '') {
        $feedback = 'Title and description are required.';
        $feedbackType = 'error';
    } elseif (!in_array($membershipRequired, $allowedMemberships, true)) {
        $feedback = 'Invalid membership tier selected.';
        $feedbackType = 'error';
    } elseif (!isset($_FILES['book_file']) || $_FILES['book_file']['error'] !== UPLOAD_ERR_OK) {
        $feedback = 'Please upload a valid PDF file.';
        $feedbackType = 'error';
    } else {
        $fileInfo = $_FILES['book_file'];
        $originalName = $fileInfo['name'];
        $tmpName = $fileInfo['tmp_name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            $feedback = 'Only PDF files are allowed.';
            $feedbackType = 'error';
        } else {
            $uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $newFileName = $safeBase . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $targetPath = $uploadsDir . DIRECTORY_SEPARATOR . $newFileName;

            if (!move_uploaded_file($tmpName, $targetPath)) {
                $feedback = 'Failed to save uploaded file.';
                $feedbackType = 'error';
            } else {
                $dbPath = 'uploads/' . $newFileName;
                $stmt = $conn->prepare("INSERT INTO books (title, description, membership_required, file_path) VALUES (?, ?, ?, ?)");

                if ($stmt) {
                    $stmt->bind_param("ssss", $title, $description, $membershipRequired, $dbPath);

                    if ($stmt->execute()) {
                        $feedback = 'Book uploaded successfully.';
                        $feedbackType = 'success';
                    } else {
                        @unlink($targetPath);
                        $feedback = 'Database error while saving book.';
                        $feedbackType = 'error';
                    }
                } else {
                    @unlink($targetPath);
                    $feedback = 'Server error preparing database query.';
                    $feedbackType = 'error';
                }
            }
        }
    }
}

$books = [];
$result = $conn->query("SELECT id, title, membership_required, file_path FROM books ORDER BY id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
}

$archivedBooks = [];
$archivedResult = $conn->query("SELECT id, title, membership_required, file_path, archived_by, archived_at FROM archived_books ORDER BY archived_at DESC, id DESC");
if ($archivedResult) {
    while ($row = $archivedResult->fetch_assoc()) {
        $archivedBooks[] = $row;
    }
}

$adminAccounts = [];
$adminResult = $conn->query("SELECT id, fullname, email, role, is_active, created_at FROM admin_users ORDER BY FIELD(role, 'super_admin', 'sub_admin'), id ASC");
if ($adminResult) {
    while ($row = $adminResult->fetch_assoc()) {
        $adminAccounts[] = $row;
    }
}

// Subscription board data
$subStats = ['Basic' => 0, 'Premium' => 0, 'VIP' => 0];
$subPaid   = ['Basic' => 0, 'Premium' => 0, 'VIP' => 0];
$subPending = ['Basic' => 0, 'Premium' => 0, 'VIP' => 0];
$subscribedUsers = [];

$subResult = $conn->query(
    "SELECT id, fullname, email, phone, membership, payment_status, created_at
     FROM users
     ORDER BY membership, fullname ASC"
);
if ($subResult) {
    while ($row = $subResult->fetch_assoc()) {
        $tier = $row['membership'] ?? 'Basic';
        if (!isset($subStats[$tier])) {
            $subStats[$tier] = 0;
            $subPaid[$tier]  = 0;
            $subPending[$tier] = 0;
        }
        $subStats[$tier]++;
        if (strtolower($row['payment_status']) === 'paid') {
            $subPaid[$tier]++;
        } else {
            $subPending[$tier]++;
        }
        $subscribedUsers[] = $row;
    }
}
$totalUsers   = array_sum($subStats);
$totalPaid    = array_sum($subPaid);
$totalPending = array_sum($subPending);

// Revenue calculation — prices match the frontend pricing page
$tierPrices = ['Basic' => 0, 'Premium' => 1000, 'VIP' => 2000];
$totalRevenue         = 0;
$totalPendingRevenue  = 0;
$revenueByTier        = ['Basic' => 0, 'Premium' => 0, 'VIP' => 0];
$pendingRevenueByTier = ['Basic' => 0, 'Premium' => 0, 'VIP' => 0];
foreach (['Basic', 'Premium', 'VIP'] as $t) {
    $price = $tierPrices[$t];
    $revenueByTier[$t]        = ($subPaid[$t] ?? 0)    * $price;
    $pendingRevenueByTier[$t] = ($subPending[$t] ?? 0) * $price;
    $totalRevenue        += $revenueByTier[$t];
    $totalPendingRevenue += $pendingRevenueByTier[$t];
}

$totalBooks    = count($books);
$totalArchived = count($archivedBooks);
$totalAdmins   = count($adminAccounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Books &amp; E-Learning</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            margin: 0;
            background: GhostWhite;
            color: DarkSlateGray;
            scroll-behavior: smooth;
        }

        /* ── NAV BAR ─────────────────────────────────── */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: MidnightBlue;
            color: White;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            height: 58px;
            box-shadow: 0 2px 10px Navy;
            flex-wrap: wrap;
            gap: 8px;
        }
        .navbar .brand {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            white-space: nowrap;
            color: White;
            text-decoration: none;
        }
        .navbar .nav-links {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .navbar .nav-links a {
            color: LightSteelBlue;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: background 0.2s;
        }
        .navbar .nav-links a:hover { background: RoyalBlue; color: White; }
        .navbar .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            white-space: nowrap;
        }
        .navbar .admin-name {
            font-size: 0.88rem;
            font-weight: 600;
            color: LightSteelBlue;
        }
        .btn-logout {
            text-decoration: none;
            background: Crimson;
            color: White;
            padding: 7px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
        }
        .btn-logout:hover { background: DarkRed; }

        /* ── LAYOUT ──────────────────────────────────── */
        .container {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        /* ── SECTION HEADERS ─────────────────────────── */
        .section-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }
        .section-head h2 {
            margin: 0;
            font-size: 1.25rem;
            color: MidnightBlue;
        }
        .section-head .section-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        .icon-blue   { background: AliceBlue;     color: RoyalBlue; }
        .icon-green  { background: HoneyDew;      color: SeaGreen; }
        .icon-purple { background: LavenderBlush; color: DarkMagenta; }
        .icon-amber  { background: LightYellow;   color: DarkGoldenRod; }
        .icon-red    { background: MistyRose;     color: Crimson; }
        .icon-slate  { background: WhiteSmoke;    color: SlateBlue; }

        /* ── PANEL ───────────────────────────────────── */
        .panel {
            background: White;
            border-radius: 18px;
            padding: 26px 28px;
            box-shadow: 0 4px 18px Gainsboro;
            margin-bottom: 28px;
        }

        /* ── KPI GRID ────────────────────────────────── */
        .kpi-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            margin-bottom: 28px;
        }
        .kpi-card {
            border-radius: 16px;
            padding: 20px 16px 16px;
            text-align: center;
        }
        .kpi-card .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }
        .kpi-card .kpi-label {
            font-size: 0.8rem;
            font-weight: 700;
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.75;
        }
        .kpi-card .kpi-sub {
            font-size: 0.75rem;
            margin-top: 5px;
            opacity: 0.65;
        }
        .kpi-blue    { background: AliceBlue;     color: DarkBlue; }
        .kpi-green   { background: HoneyDew;      color: DarkGreen; }
        .kpi-purple  { background: LavenderBlush; color: DarkMagenta; }
        .kpi-amber   { background: LightYellow;   color: DarkGoldenRod; }
        .kpi-teal    { background: MintCream;     color: Teal; }
        .kpi-brown   { background: FloralWhite;   color: SaddleBrown; }

        /* ── REVENUE BARS ────────────────────────────── */
        .rev-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-bottom: 20px;
        }
        .rev-card {
            border-radius: 14px;
            padding: 18px;
            border-left: 5px solid;
        }
        .rev-card.rev-total   { background: LavenderBlush; border-color: DarkMagenta; }
        .rev-card.rev-basic   { background: HoneyDew;      border-color: SeaGreen; }
        .rev-card.rev-premium { background: AliceBlue;     border-color: RoyalBlue; }
        .rev-card.rev-vip     { background: FloralWhite;   border-color: SaddleBrown; }
        .rev-card.rev-pending { background: LightYellow;   border-color: DarkGoldenRod; }
        .rev-card .rev-amount {
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0 0 4px;
        }
        .rev-card .rev-name {
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            opacity: 0.75;
        }
        .rev-card .rev-detail {
            font-size: 0.78rem;
            margin-top: 5px;
            opacity: 0.65;
        }

        /* ── SUB TIER CARDS ──────────────────────────── */
        .tier-cards {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            margin-bottom: 20px;
        }
        .tier-card {
            border-radius: 14px;
            padding: 16px;
            text-align: center;
        }
        .tier-card .tc-count {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }
        .tier-card .tc-label {
            font-size: 0.82rem;
            font-weight: 600;
            margin-top: 5px;
            opacity: 0.8;
        }
        .tier-card .tc-detail {
            font-size: 0.75rem;
            margin-top: 5px;
            opacity: 0.6;
        }
        .tc-basic   { background: HoneyDew;      color: DarkGreen; }
        .tc-premium { background: AliceBlue;     color: DarkBlue; }
        .tc-vip     { background: FloralWhite;   color: SaddleBrown; }
        .tc-paid    { background: MintCream;     color: SeaGreen; }
        .tc-pending { background: LightYellow;   color: DarkGoldenRod; }

        /* ── TABLES ──────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }
        th, td {
            text-align: left;
            padding: 11px 13px;
            border-bottom: 1px solid Gainsboro;
            font-size: 0.9rem;
        }
        th {
            background: AliceBlue;
            color: MidnightBlue;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: Snow; }

        /* ── TIER / STATUS BADGES ────────────────────── */
        .tier {
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.8rem;
            display: inline-block;
        }
        .tier-basic   { background: HoneyDew;      color: DarkGreen; }
        .tier-premium { background: LavenderBlush; color: DarkMagenta; }
        .tier-vip     { background: FloralWhite;   color: SaddleBrown; }

        .badge {
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.8rem;
            display: inline-block;
        }
        .badge-paid    { background: MintCream;  color: SeaGreen; }
        .badge-pending { background: LightYellow; color: DarkGoldenRod; }
        .badge-super   { background: HoneyDew;    color: DarkGreen; }
        .badge-sub     { background: AliceBlue;   color: MidnightBlue; }
        .badge-active  { background: MintCream;   color: SeaGreen; }
        .badge-inactive{ background: MistyRose;   color: FireBrick; }

        /* ── FORMS ───────────────────────────────────── */
        .grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .grid .full { grid-column: 1 / -1; }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 700;
            color: MidnightBlue;
            font-size: 0.88rem;
        }
        input, textarea, select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid LightGray;
            font-size: 0.95rem;
            background: White;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: CornflowerBlue;
        }
        textarea { min-height: 100px; resize: vertical; }

        .btn-submit {
            margin-top: 10px;
            border: none;
            padding: 11px 20px;
            border-radius: 10px;
            background: SeaGreen;
            color: White;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .btn-submit:hover { background: DarkGreen; }
        .btn-delete {
            border: none;
            padding: 7px 12px;
            border-radius: 8px;
            background: Crimson;
            color: White;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.82rem;
        }
        .btn-restore {
            border: none;
            padding: 7px 12px;
            border-radius: 8px;
            background: RoyalBlue;
            color: White;
            font-weight: 700;
            cursor: pointer;
            margin-right: 6px;
            font-size: 0.82rem;
        }
        .btn-purge {
            border: none;
            padding: 7px 12px;
            border-radius: 8px;
            background: DarkRed;
            color: White;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.82rem;
        }

        /* ── NOTICE / FEEDBACK ───────────────────────── */
        .notice {
            border-radius: 10px;
            padding: 11px 14px;
            margin-bottom: 18px;
            font-weight: 600;
            font-size: 0.92rem;
        }
        .success { background: HoneyDew;  border: 1px solid PaleGreen; color: DarkGreen; }
        .error   { background: MistyRose; border: 1px solid LightCoral; color: FireBrick; }

        /* ── PROFILE ─────────────────────────────────── */
        .profile-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .profile-card {
            border: 1px solid Lavender;
            border-radius: 14px;
            padding: 18px;
            background: GhostWhite;
        }
        .admin-avatar {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid LightSteelBlue;
            background: AliceBlue;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: MidnightBlue;
            font-weight: 800;
            font-size: 1.5rem;
        }
        .meta {
            color: SlateGray;
            font-size: 0.85rem;
            margin: 5px 0;
        }
        .divider { height: 1px; background: Gainsboro; margin: 20px 0; }
        .subtext { color: SlateGray; font-size: 0.9rem; margin: 0 0 16px; }

        /* ── RESPONSIVE ──────────────────────────────── */
        @media (max-width: 860px) {
            .profile-grid, .grid { grid-template-columns: 1fr; }
            .grid .full { grid-column: 1; }
        }
        @media (max-width: 640px) {
            .navbar { height: auto; padding: 10px 16px; }
            .navbar .nav-links { display: none; }
            .container { padding: 20px 14px 50px; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════
     NAV BAR
════════════════════════════════════════════════════ -->
<nav class="navbar">
    <a class="brand" href="#overview">&#128218; Admin Dashboard</a>
    <div class="nav-links">
        <a href="#overview">Overview</a>
        <a href="#revenue">Revenue</a>
        <a href="#subscribers">Subscribers</a>
        <a href="#books">Books</a>
        <a href="#recycle">Recycle Bin</a>
        <a href="#admins">Admins</a>
        <a href="#profile">My Profile</a>
    </div>
    <div class="admin-info">
        <span class="admin-name">
            <?php echo htmlspecialchars($adminProfile['fullname'] ?? ($_SESSION['admin_fullname'] ?? 'Admin')); ?>
            &nbsp;&bull;&nbsp;
            <?php echo htmlspecialchars(str_replace('_', ' ', ucwords($adminRole))); ?>
        </span>
        <a class="btn-logout" href="../backend/api/admin_logout.php">Logout</a>
    </div>
</nav>

<div class="container">

    <?php if ($feedback !== ''): ?>
    <div class="notice <?php echo ($feedbackType === 'success') ? 'success' : 'error'; ?>">
        <?php echo htmlspecialchars($feedback); ?>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════
         SECTION 1 — OVERVIEW KPI CARDS
    ════════════════════════════════════════════ -->
    <div id="overview" class="kpi-grid">
        <div class="kpi-card kpi-purple">
            <div class="kpi-value"><?php echo $totalUsers; ?></div>
            <div class="kpi-label">Total Users</div>
            <div class="kpi-sub"><?php echo $totalPaid; ?> paid &bull; <?php echo $totalPending; ?> pending</div>
        </div>
        <div class="kpi-card kpi-green">
            <div class="kpi-value"><?php echo $totalPaid; ?></div>
            <div class="kpi-label">Active Subscribers</div>
            <div class="kpi-sub">Payment confirmed</div>
        </div>
        <div class="kpi-card kpi-teal">
            <div class="kpi-value">KES <?php echo number_format($totalRevenue); ?></div>
            <div class="kpi-label">Total Revenue</div>
            <div class="kpi-sub">Confirmed payments</div>
        </div>
        <div class="kpi-card kpi-amber">
            <div class="kpi-value">KES <?php echo number_format($totalPendingRevenue); ?></div>
            <div class="kpi-label">Pending Revenue</div>
            <div class="kpi-sub">Awaiting payment</div>
        </div>
        <div class="kpi-card kpi-blue">
            <div class="kpi-value"><?php echo $totalBooks; ?></div>
            <div class="kpi-label">Active Books</div>
            <div class="kpi-sub"><?php echo $totalArchived; ?> archived</div>
        </div>
        <div class="kpi-card kpi-brown">
            <div class="kpi-value"><?php echo $totalAdmins; ?></div>
            <div class="kpi-label">Admin Accounts</div>
            <div class="kpi-sub">All roles</div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         SECTION 2 — REVENUE BREAKDOWN
    ════════════════════════════════════════════ -->
    <div id="revenue" class="panel">
        <div class="section-head">
            <div class="section-icon icon-green">&#128176;</div>
            <h2>Revenue Breakdown</h2>
        </div>
        <p class="subtext">Based on confirmed M-Pesa payments. Pricing: Basic (Free) &bull; Premium KES 1,000/mo &bull; VIP KES 2,000/mo</p>

        <div class="rev-grid">
            <div class="rev-card rev-total">
                <div class="rev-amount">KES <?php echo number_format($totalRevenue); ?></div>
                <div class="rev-name">Total Collected Revenue</div>
                <div class="rev-detail"><?php echo $totalPaid; ?> paid subscribers across all tiers</div>
            </div>
            <div class="rev-card rev-basic">
                <div class="rev-amount">KES <?php echo number_format($revenueByTier['Basic']); ?></div>
                <div class="rev-name">Basic Tier</div>
                <div class="rev-detail"><?php echo $subPaid['Basic']; ?> paid &bull; Free plan &bull; KES 0/mo</div>
            </div>
            <div class="rev-card rev-premium">
                <div class="rev-amount">KES <?php echo number_format($revenueByTier['Premium']); ?></div>
                <div class="rev-name">Premium Tier</div>
                <div class="rev-detail"><?php echo $subPaid['Premium']; ?> paid &bull; KES 1,000/mo</div>
            </div>
            <div class="rev-card rev-vip">
                <div class="rev-amount">KES <?php echo number_format($revenueByTier['VIP']); ?></div>
                <div class="rev-name">VIP Tier</div>
                <div class="rev-detail"><?php echo $subPaid['VIP']; ?> paid &bull; KES 2,000/mo</div>
            </div>
            <div class="rev-card rev-pending">
                <div class="rev-amount">KES <?php echo number_format($totalPendingRevenue); ?></div>
                <div class="rev-name">Pending Revenue</div>
                <div class="rev-detail"><?php echo $totalPending; ?> users with outstanding payments</div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         SECTION 3 — SUBSCRIBERS
    ════════════════════════════════════════════ -->
    <div id="subscribers" class="panel">
        <div class="section-head">
            <div class="section-icon icon-purple">&#128101;</div>
            <h2>Subscribers</h2>
        </div>
        <p class="subtext">All registered users and their subscription status.</p>

        <div class="tier-cards">
            <?php foreach (['basic' => 'Basic', 'premium' => 'Premium', 'vip' => 'VIP'] as $cls => $tier): ?>
            <div class="tier-card tc-<?php echo $cls; ?>">
                <div class="tc-count"><?php echo $subStats[$tier] ?? 0; ?></div>
                <div class="tc-label"><?php echo $tier; ?> Members</div>
                <div class="tc-detail"><?php echo $subPaid[$tier] ?? 0; ?> paid &bull; <?php echo $subPending[$tier] ?? 0; ?> pending</div>
            </div>
            <?php endforeach; ?>
            <div class="tier-card tc-paid">
                <div class="tc-count"><?php echo $totalPaid; ?></div>
                <div class="tc-label">Paid</div>
                <div class="tc-detail">Active subscriptions</div>
            </div>
            <div class="tier-card tc-pending">
                <div class="tc-count"><?php echo $totalPending; ?></div>
                <div class="tc-label">Pending</div>
                <div class="tc-detail">Awaiting payment</div>
            </div>
        </div>

        <?php if (empty($subscribedUsers)): ?>
        <p class="subtext">No registered users yet.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Subscription</th>
                    <th>Status</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscribedUsers as $i => $u): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($u['fullname']); ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></td>
                    <td><span class="tier tier-<?php echo strtolower($u['membership']); ?>"><?php echo htmlspecialchars($u['membership']); ?></span></td>
                    <td><span class="badge <?php echo strtolower($u['payment_status']) === 'paid' ? 'badge-paid' : 'badge-pending'; ?>"><?php echo htmlspecialchars($u['payment_status']); ?></span></td>
                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($u['created_at']))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════
         SECTION 4 — BOOK LIBRARY
    ════════════════════════════════════════════ -->
    <div id="books" class="panel">
        <div class="section-head">
            <div class="section-icon icon-blue">&#128218;</div>
            <h2>Book Library</h2>
        </div>

        <p class="subtext" style="font-weight:700; color:MidnightBlue; font-size:1rem;">Add New Book</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="create_book" value="1">
            <div class="grid">
                <div>
                    <label for="title">Book Title</label>
                    <input id="title" type="text" name="title" required>
                </div>
                <div>
                    <label for="membership_required">Access Tier</label>
                    <select id="membership_required" name="membership_required" required>
                        <option value="Basic">Basic (Free)</option>
                        <option value="Premium">Premium — KES 1,000/mo</option>
                        <option value="VIP">VIP — KES 2,000/mo</option>
                    </select>
                </div>
                <div class="full">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" required></textarea>
                </div>
                <div class="full">
                    <label for="book_file">Upload PDF</label>
                    <input id="book_file" type="file" name="book_file" accept="application/pdf" required>
                </div>
            </div>
            <button class="btn-submit" type="submit">&#128228; Upload Book</button>
        </form>

        <div class="divider"></div>
        <p class="subtext" style="font-weight:700; color:MidnightBlue; font-size:1rem;">Existing Books (<?php echo $totalBooks; ?>)</p>
        <p class="subtext">Deleting moves a book to the Recycle Bin — it can be restored later.</p>

        <?php if (empty($books)): ?>
        <p class="subtext">No books uploaded yet.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Title</th>
                    <th>Tier</th>
                    <th>File</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($books as $i => $book): ?>
                <?php
                    $tc = 'tier-basic';
                    if ($book['membership_required'] === 'Premium') $tc = 'tier-premium';
                    elseif ($book['membership_required'] === 'VIP')  $tc = 'tier-vip';
                ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($book['title']); ?></td>
                    <td><span class="tier <?php echo $tc; ?>"><?php echo htmlspecialchars($book['membership_required']); ?></span></td>
                    <td style="font-size:0.82rem; color:SlateGray;"><?php echo htmlspecialchars($book['file_path']); ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Move this book to the recycle bin?');">
                            <input type="hidden" name="delete_book" value="1">
                            <input type="hidden" name="book_id" value="<?php echo (int) $book['id']; ?>">
                            <button class="btn-delete" type="submit">Move to Bin</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════
         SECTION 5 — RECYCLE BIN
    ════════════════════════════════════════════ -->
    <div id="recycle" class="panel">
        <div class="section-head">
            <div class="section-icon icon-red">&#128465;</div>
            <h2>Recycle Bin</h2>
        </div>
        <p class="subtext">Restore archived books or permanently delete them (removes the PDF file too).</p>

        <?php if (empty($archivedBooks)): ?>
        <p class="subtext">Recycle bin is empty.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Title</th>
                    <th>Tier</th>
                    <th>Archived By</th>
                    <th>Archived At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($archivedBooks as $i => $book): ?>
                <?php
                    $tc = 'tier-basic';
                    if ($book['membership_required'] === 'Premium') $tc = 'tier-premium';
                    elseif ($book['membership_required'] === 'VIP')  $tc = 'tier-vip';
                ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($book['title']); ?></td>
                    <td><span class="tier <?php echo $tc; ?>"><?php echo htmlspecialchars($book['membership_required']); ?></span></td>
                    <td><?php echo htmlspecialchars($book['archived_by'] ?? '—'); ?></td>
                    <td style="font-size:0.82rem;"><?php echo htmlspecialchars($book['archived_at']); ?></td>
                    <td>
                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Restore this book?');">
                            <input type="hidden" name="restore_book" value="1">
                            <input type="hidden" name="archive_id" value="<?php echo (int) $book['id']; ?>">
                            <button class="btn-restore" type="submit">Restore</button>
                        </form>
                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Permanently delete this book and its PDF?');">
                            <input type="hidden" name="purge_book" value="1">
                            <input type="hidden" name="archive_id" value="<?php echo (int) $book['id']; ?>">
                            <button class="btn-purge" type="submit">Delete Forever</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════
         SECTION 6 — ADMIN MANAGEMENT
    ════════════════════════════════════════════ -->
    <div id="admins" class="panel">
        <div class="section-head">
            <div class="section-icon icon-slate">&#128272;</div>
            <h2>Admin Management</h2>
        </div>

        <?php if ($adminRole === 'super_admin'): ?>
        <p class="subtext" style="font-weight:700; color:MidnightBlue; font-size:1rem;">Create Sub-Admin Account</p>
        <p class="subtext">Create accounts for trusted staff. Passwords are securely hashed.</p>
        <form method="POST">
            <input type="hidden" name="create_sub_admin" value="1">
            <div class="grid">
                <div>
                    <label for="sub_admin_fullname">Full Name</label>
                    <input id="sub_admin_fullname" type="text" name="sub_admin_fullname" required>
                </div>
                <div>
                    <label for="sub_admin_email">Email</label>
                    <input id="sub_admin_email" type="email" name="sub_admin_email" required>
                </div>
                <div class="full">
                    <label for="sub_admin_password">Password</label>
                    <input id="sub_admin_password" type="password" name="sub_admin_password" required>
                </div>
            </div>
            <button class="btn-submit" type="submit">&#10010; Create Sub Admin</button>
        </form>
        <div class="divider"></div>
        <?php else: ?>
        <p class="subtext">Sub-admin creation is restricted to the super admin account.</p>
        <?php endif; ?>

        <p class="subtext" style="font-weight:700; color:MidnightBlue; font-size:1rem;">All Admin Accounts (<?php echo $totalAdmins; ?>)</p>
        <?php if (empty($adminAccounts)): ?>
        <p class="subtext">No admin accounts found.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($adminAccounts as $i => $acc): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($acc['fullname']); ?></td>
                    <td><?php echo htmlspecialchars($acc['email']); ?></td>
                    <td><span class="badge <?php echo ($acc['role'] === 'super_admin') ? 'badge-super' : 'badge-sub'; ?>"><?php echo htmlspecialchars(str_replace('_', ' ', ucwords($acc['role']))); ?></span></td>
                    <td><span class="badge <?php echo $acc['is_active'] ? 'badge-active' : 'badge-inactive'; ?>"><?php echo $acc['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                    <td style="font-size:0.82rem;"><?php echo htmlspecialchars(date('M j, Y', strtotime($acc['created_at']))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════
         SECTION 7 — MY PROFILE
    ════════════════════════════════════════════ -->
    <div id="profile" class="panel">
        <div class="section-head">
            <div class="section-icon icon-blue">&#128100;</div>
            <h2>My Admin Profile</h2>
        </div>

        <div class="profile-grid">
            <!-- Account Info -->
            <div class="profile-card">
                <?php if (!empty($adminProfile['profile_photo'])): ?>
                    <img class="admin-avatar" src="../<?php echo htmlspecialchars($adminProfile['profile_photo']); ?>" alt="Admin photo">
                <?php else: ?>
                    <div class="admin-avatar"><?php echo htmlspecialchars(strtoupper(substr($adminProfile['fullname'], 0, 1))); ?></div>
                <?php endif; ?>
                <p style="font-weight:700; margin:10px 0 4px; color:MidnightBlue;"><?php echo htmlspecialchars($adminProfile['fullname']); ?></p>
                <p class="meta"><?php echo htmlspecialchars($adminProfile['email']); ?></p>
                <div class="divider" style="margin:12px 0;"></div>
                <p class="meta"><strong>Role:</strong> <?php echo htmlspecialchars(str_replace('_', ' ', ucwords($adminRole))); ?></p>
                <p class="meta"><strong>Last login:</strong> <?php echo htmlspecialchars(formatDateDisplay($adminProfile['last_login_at'] ?? null)); ?></p>
                <p class="meta"><strong>Last IP:</strong> <?php echo htmlspecialchars($adminProfile['last_login_ip'] ?? 'N/A'); ?></p>
                <p class="meta"><strong>Session started:</strong> <?php echo htmlspecialchars(formatDateDisplay($_SESSION['admin_login_time'] ?? null)); ?></p>
                <p class="meta"><strong>Account created:</strong> <?php echo htmlspecialchars(formatDateDisplay($adminProfile['created_at'] ?? null)); ?></p>
            </div>

            <!-- Edit Forms -->
            <div class="profile-card">
                <p style="font-weight:700; margin:0 0 12px; color:MidnightBlue;">Edit Profile</p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_admin_profile" value="1">
                    <label for="admin_fullname">Full Name</label>
                    <input id="admin_fullname" type="text" name="admin_fullname" value="<?php echo htmlspecialchars($adminProfile['fullname']); ?>" required>
                    <label for="admin_email" style="margin-top:10px;">Email</label>
                    <input id="admin_email" type="email" name="admin_email" value="<?php echo htmlspecialchars($adminProfile['email']); ?>" required>
                    <label for="admin_profile_photo" style="margin-top:10px;">Profile Photo (JPG, PNG, WEBP)</label>
                    <input id="admin_profile_photo" type="file" name="admin_profile_photo" accept="image/png,image/jpeg,image/webp">
                    <button class="btn-submit" type="submit">Save Profile</button>
                </form>

                <div class="divider"></div>
                <p style="font-weight:700; margin:0 0 12px; color:MidnightBlue;">Change Password</p>
                <form method="POST">
                    <input type="hidden" name="change_admin_password" value="1">
                    <label for="current_password">Current Password</label>
                    <input id="current_password" type="password" name="current_password" required>
                    <label for="new_password" style="margin-top:10px;">New Password</label>
                    <input id="new_password" type="password" name="new_password" minlength="6" required>
                    <label for="confirm_password" style="margin-top:10px;">Confirm New Password</label>
                    <input id="confirm_password" type="password" name="confirm_password" minlength="6" required>
                    <button class="btn-submit" type="submit">Change Password</button>
                </form>
            </div>
        </div>
    </div>

</div><!-- /.container -->
</body>
</html>
