<?php
include "config.php";

$data = file_get_contents('php://input');
// Log the raw response for debugging
file_put_contents("mpesa_callback.txt", $data . PHP_EOL, FILE_APPEND);

$response = json_decode($data);

// 1. Check if the transaction was successful (ResultCode 0)
if(isset($response->Body->stkCallback->ResultCode) && $response->Body->stkCallback->ResultCode == 0){
    
    $metadata = $response->Body->stkCallback->CallbackMetadata->Item;
    $receipt = "";
    $phone = "";

    foreach($metadata as $item){
        if($item->Name == "MpesaReceiptNumber") { $receipt = $item->Value; }
        if($item->Name == "PhoneNumber") { $phone = $item->Value; }
    }

    // 2. Format phone variants (e.g., 2547... and 07...)
    $phone_variant = "0" . substr($phone, 3); 

    // 3. SECURE UPDATE: Using prepared statements instead of raw variables
    $stmt = $conn->prepare("UPDATE users SET payment_status='Paid', mpesa_receipt=? WHERE phone=? OR phone=?");
    $stmt->bind_param("sss", $receipt, $phone, $phone_variant);
    
    if ($stmt->execute()) {
        file_put_contents("mpesa_callback.txt", "Update Success for Receipt: $receipt\n", FILE_APPEND);
    } else {
        file_put_contents("mpesa_callback.txt", "Update Failed: " . $conn->error . "\n", FILE_APPEND);
    }

    $stmt->close();
} else {
    // Log failed transactions (ResultCode != 0)
    file_put_contents("mpesa_callback.txt", "Transaction failed or cancelled by user.\n", FILE_APPEND);
}
?>