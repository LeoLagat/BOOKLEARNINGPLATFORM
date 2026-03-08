<?php
session_start();
include "config.php";

$fullname   = $_POST['fullname'];
$email      = $_POST['email'];
$phone      = $_POST['phone'];
$password   = password_hash($_POST['password'], PASSWORD_DEFAULT);
$membership = $_POST['membership'];

// Determine status: If 'Basic', mark as 'Paid' immediately. Otherwise 'Pending'.
$status = ($membership == "Basic") ? "Paid" : "pending";

$stmt = $conn->prepare("INSERT INTO users (fullname, email, phone, password, membership, payment_status) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssss", $fullname, $email, $phone, $password, $membership, $status);

if($stmt->execute()){
    if($membership == "Basic") {
        // Log them in automatically and go to books
        $_SESSION["fullname"] = $fullname;
        $_SESSION["membership"] = $membership;
        echo "<script>alert('Free Account Created! Accessing Books...'); window.location.href='../frontend/index.php';</script>";
    } else {
        // Trigger M-Pesa STK Push for Premium/VIP (Keep your existing M-Pesa CURL code here)
        // ... [Your M-Pesa Code from previous steps] ...
        echo "Please check your phone for the M-Pesa prompt to activate $membership.";
    }
} else {
    echo "Error: Registration failed.";
}