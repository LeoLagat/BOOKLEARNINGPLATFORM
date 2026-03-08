<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "config.php";
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

            $_SESSION["fullname"] = $user["fullname"];
            $_SESSION["membership"] = $user["membership"];
            $_SESSION["phone"] = $user["phone"];
            $_SESSION["payment_status"] = $user["payment_status"];

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
