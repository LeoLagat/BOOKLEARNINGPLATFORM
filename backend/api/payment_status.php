<?php
session_start();
header('Content-Type: application/json');

include "config.php";

$rawPhone = $_GET['phone'] ?? ($_SESSION['phone'] ?? '');
$phone = str_replace([' ', '+'], '', $rawPhone);

if ($phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Phone is required']);
    exit();
}

if (substr($phone, 0, 1) === '0') {
    $phone254 = '254' . substr($phone, 1);
} elseif (substr($phone, 0, 3) === '254') {
    $phone254 = $phone;
} else {
    $phone254 = '254' . ltrim($phone, '0');
}

$phone0 = '0' . substr($phone254, 3);

$stmt = $conn->prepare("SELECT payment_status, membership FROM users WHERE phone = ? OR phone = ? LIMIT 1");
$stmt->bind_param("ss", $phone254, $phone0);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if (isset($_SESSION['phone']) && ($_SESSION['phone'] === $phone254 || $_SESSION['phone'] === $phone0)) {
        $_SESSION['payment_status'] = $row['payment_status'];
        $_SESSION['membership'] = $row['membership'];
    }

    echo json_encode([
        'ok' => true,
        'payment_status' => $row['payment_status'],
        'membership' => $row['membership']
    ]);
} else {
    echo json_encode([
        'ok' => false,
        'message' => 'User not found'
    ]);
}

$stmt->close();
