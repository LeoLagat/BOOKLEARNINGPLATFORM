<?php
session_start();
require_once "config.php";

mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_SESSION['fullname'])) {
    header("Location: ../../frontend/login_view.php");
    exit();
}

function ensureUserBookTablesExist(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS user_book_activity (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        book_id INT(11) NOT NULL,
        action VARCHAR(20) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_activity_user (user_id),
        KEY idx_user_activity_book (book_id),
        KEY idx_user_activity_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function resolveCurrentUser(mysqli $conn): ?array {
    $rawPhone = $_SESSION['phone'] ?? '';
    $phone = str_replace([' ', '+'], '', $rawPhone);

    if ($phone === '') {
        return null;
    }

    if (substr($phone, 0, 1) === '0') {
        $phone254 = '254' . substr($phone, 1);
    } elseif (substr($phone, 0, 3) === '254') {
        $phone254 = $phone;
    } else {
        $phone254 = '254' . ltrim($phone, '0');
    }

    $phone0 = '0' . substr($phone254, 3);

    $stmt = $conn->prepare("SELECT id, membership, payment_status FROM users WHERE phone = ? OR phone = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ss", $phone254, $phone0);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

ensureUserBookTablesExist($conn);

$bookId = (int) ($_GET['id'] ?? 0);
if ($bookId <= 0) {
    header("Location: books.php?toast=book_not_found");
    exit();
}

$user = resolveCurrentUser($conn);
if (!$user) {
    header("Location: books.php?toast=activity_error");
    exit();
}

$userId = (int) $user['id'];
$userMembership = $user['membership'] ?? 'Basic';
$paymentStatus = $user['payment_status'] ?? ($userMembership === 'Basic' ? 'Paid' : 'Pending');

if ($userMembership !== 'Basic' && $paymentStatus !== 'Paid') {
    header("Location: books.php?toast=book_forbidden");
    exit();
}

$stmt = $conn->prepare("SELECT id, membership_required, file_path FROM books WHERE id = ? LIMIT 1");
if (!$stmt) {
    header("Location: books.php?toast=activity_error");
    exit();
}

$stmt->bind_param("i", $bookId);
$stmt->execute();
$result = $stmt->get_result();
$book = $result ? $result->fetch_assoc() : null;

if (!$book) {
    header("Location: books.php?toast=book_not_found");
    exit();
}

$levels = ['Basic' => 0, 'Premium' => 1, 'VIP' => 2];
$required = $book['membership_required'] ?? 'Basic';

if (!isset($levels[$required]) || $levels[$userMembership] < $levels[$required]) {
    header("Location: books.php?toast=book_forbidden");
    exit();
}

$logStmt = $conn->prepare("INSERT INTO user_book_activity (user_id, book_id, action) VALUES (?, ?, 'view')");
if ($logStmt) {
    $logStmt->bind_param("ii", $userId, $bookId);
    $logStmt->execute();
}

$filePath = $book['file_path'];
$relativePath = '../../' . ltrim($filePath, '/');

header("Location: " . $relativePath);
exit();
