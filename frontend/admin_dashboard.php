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

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

$feedback = '';
$feedbackType = '';
$adminRole = $_SESSION['admin_role'] ?? 'super_admin';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Books & E-Learning</title>
    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            margin: 0;
            background: GhostWhite;
            color: DarkSlateGray;
        }

        .container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px 40px;
            box-sizing: border-box;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 12px;
        }

        h1 {
            margin: 0;
            color: MidnightBlue;
        }

        .meta {
            color: SlateGray;
            margin: 6px 0 0;
        }

        .btn-logout {
            text-decoration: none;
            background: Crimson;
            color: White;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
        }

        .panel {
            background: White;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .panel h2 {
            margin-top: 0;
            color: SlateBlue;
        }

        .grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .grid .full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
            color: MidnightBlue;
        }

        input, textarea, select {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            border-radius: 10px;
            border: 1px solid LightGray;
            font-size: 0.98rem;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .btn-submit {
            margin-top: 10px;
            border: none;
            padding: 12px 18px;
            border-radius: 10px;
            background: SeaGreen;
            color: White;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-delete {
            border: none;
            padding: 9px 12px;
            border-radius: 9px;
            background: Crimson;
            color: White;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-restore {
            border: none;
            padding: 9px 12px;
            border-radius: 9px;
            background: RoyalBlue;
            color: White;
            font-weight: 700;
            cursor: pointer;
            margin-right: 8px;
        }

        .btn-purge {
            border: none;
            padding: 9px 12px;
            border-radius: 9px;
            background: DarkRed;
            color: White;
            font-weight: 700;
            cursor: pointer;
        }

        .subtext {
            margin: 0 0 14px;
            color: SlateGray;
        }

        .notice {
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-weight: 600;
        }

        .success {
            background: HoneyDew;
            border: 1px solid PaleGreen;
            color: DarkGreen;
        }

        .error {
            background: MistyRose;
            border: 1px solid LightCoral;
            color: FireBrick;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: White;
            border-radius: 12px;
            overflow: hidden;
        }

        th, td {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid #ececec;
        }

        th {
            background: AliceBlue;
            color: MidnightBlue;
        }

        .tier {
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 0.85rem;
            display: inline-block;
        }

        .tier-basic { background: HoneyDew; color: DarkGreen; }
        .tier-premium { background: LavenderBlush; color: DarkMagenta; }
        .tier-vip { background: FloralWhite; color: SaddleBrown; }

        .role-badge {
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 0.85rem;
            display: inline-block;
        }

        .role-super { background: HoneyDew; color: DarkGreen; }
        .role-sub { background: AliceBlue; color: MidnightBlue; }

        @media (max-width: 800px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <div>
            <h1>Admin Dashboard</h1>
            <p class="meta">Logged in as <?php echo htmlspecialchars($_SESSION['admin_fullname'] ?? 'Admin'); ?></p>
            <p class="meta">Role: <?php echo htmlspecialchars(str_replace('_', ' ', $adminRole)); ?></p>
        </div>
        <a class="btn-logout" href="../backend/api/admin_logout.php">Logout</a>
    </div>

    <div class="panel">
        <h2>Add New Book</h2>

        <?php if ($feedback !== ''): ?>
            <div class="notice <?php echo ($feedbackType === 'success') ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($feedback); ?>
            </div>
        <?php endif; ?>

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
                        <option value="Basic">Basic</option>
                        <option value="Premium">Premium</option>
                        <option value="VIP">VIP</option>
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
            <button class="btn-submit" type="submit">Upload Book</button>
        </form>
    </div>

    <div class="panel">
        <h2>Sub Admin Management</h2>
        <?php if ($adminRole === 'super_admin'): ?>
            <p class="subtext">Create sub-admin accounts for trusted staff. Passwords are stored with secure hashing.</p>
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
                <button class="btn-submit" type="submit">Create Sub Admin</button>
            </form>
        <?php else: ?>
            <p class="subtext">Sub-admin creation is limited to the super admin account.</p>
        <?php endif; ?>

        <?php if (count($adminAccounts) === 0): ?>
            <p>No admin accounts found.</p>
        <?php else: ?>
            <table style="margin-top: 18px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($adminAccounts as $adminAccount): ?>
                    <tr>
                        <td><?php echo (int) $adminAccount['id']; ?></td>
                        <td><?php echo htmlspecialchars($adminAccount['fullname']); ?></td>
                        <td><?php echo htmlspecialchars($adminAccount['email']); ?></td>
                        <td>
                            <span class="role-badge <?php echo ($adminAccount['role'] === 'super_admin') ? 'role-super' : 'role-sub'; ?>">
                                <?php echo htmlspecialchars(str_replace('_', ' ', $adminAccount['role'])); ?>
                            </span>
                        </td>
                        <td><?php echo ((int) $adminAccount['is_active'] === 1) ? 'Active' : 'Disabled'; ?></td>
                        <td><?php echo htmlspecialchars($adminAccount['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Existing Books</h2>
        <p class="subtext">Deleting now moves a book into the recycle bin so it can be restored later.</p>
        <?php if (count($books) === 0): ?>
            <p>No books found yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Tier</th>
                        <th>File</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($books as $book): ?>
                    <?php
                        $tierClass = 'tier-basic';
                        if ($book['membership_required'] === 'Premium') {
                            $tierClass = 'tier-premium';
                        } elseif ($book['membership_required'] === 'VIP') {
                            $tierClass = 'tier-vip';
                        }
                    ?>
                    <tr>
                        <td><?php echo (int) $book['id']; ?></td>
                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                        <td><span class="tier <?php echo $tierClass; ?>"><?php echo htmlspecialchars($book['membership_required']); ?></span></td>
                        <td><?php echo htmlspecialchars($book['file_path']); ?></td>
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
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Recycle Bin</h2>
        <p class="subtext">Restore archived books or permanently delete them together with their uploaded PDF file.</p>
        <?php if (count($archivedBooks) === 0): ?>
            <p>No archived books.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Tier</th>
                        <th>File</th>
                        <th>Archived By</th>
                        <th>Archived At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($archivedBooks as $book): ?>
                    <?php
                        $tierClass = 'tier-basic';
                        if ($book['membership_required'] === 'Premium') {
                            $tierClass = 'tier-premium';
                        } elseif ($book['membership_required'] === 'VIP') {
                            $tierClass = 'tier-vip';
                        }
                    ?>
                    <tr>
                        <td><?php echo (int) $book['id']; ?></td>
                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                        <td><span class="tier <?php echo $tierClass; ?>"><?php echo htmlspecialchars($book['membership_required']); ?></span></td>
                        <td><?php echo htmlspecialchars($book['file_path']); ?></td>
                        <td><?php echo htmlspecialchars($book['archived_by'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($book['archived_at']); ?></td>
                        <td>
                            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Restore this archived book?');">
                                <input type="hidden" name="restore_book" value="1">
                                <input type="hidden" name="archive_id" value="<?php echo (int) $book['id']; ?>">
                                <button class="btn-restore" type="submit">Restore</button>
                            </form>
                            <form method="POST" style="display:inline-block;" onsubmit="return confirm('Permanently delete this archived book and its PDF file?');">
                                <input type="hidden" name="purge_book" value="1">
                                <input type="hidden" name="archive_id" value="<?php echo (int) $book['id']; ?>">
                                <button class="btn-purge" type="submit">Delete Forever</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
