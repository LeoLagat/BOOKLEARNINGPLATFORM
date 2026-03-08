<?php
session_start();

// 1. Credentials
$consumerKey = "aA3lXxuVwaLRjywVx3Oo68FwA9eFVrFe1Q76O2caQlUGa9z2";
$consumerSecret = "unqYsCySAeICOZlbrhDT7MYNqXAI0A6dMkLsAlfEU3vdpto7N53PDZsErloFlwHn";

// Safaricom Sandbox Details
$BusinessShortCode = "174379"; 
$Passkey = "bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919"; 

// Get data from the POST request (sent by index.php)
$phone = $_POST['phone'] ?? '';
$amount = $_POST['amount'] ?? 1;

// 2. Phone Number Sanitization
$phone = str_replace([' ', '+'], '', $phone); 
if (substr($phone, 0, 1) == '0') {
    $phone = '254' . substr($phone, 1);
} elseif (substr($phone, 0, 1) == '7' || substr($phone, 0, 1) == '1') {
    $phone = '254' . $phone;
}

// 3. Get Access Token
$credentials = base64_encode($consumerKey . ":" . $consumerSecret);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $credentials"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if ($response === false) {
    // curl failure – bail out with message and close handle
    $err = curl_error($ch);
    curl_close($ch);
    die("Failed to obtain access token: $err");
}
$tokenData = json_decode($response);
if (!$tokenData || !isset($tokenData->access_token)) {
    curl_close($ch);
    die("Unexpected token response: " . htmlentities($response));
}
$access_token = $tokenData->access_token;
curl_close($ch);

// 4. Generate Password & Timestamp
date_default_timezone_set('Africa/Nairobi');
$Timestamp = date('YmdHis');
$Password = base64_encode($BusinessShortCode . $Passkey . $Timestamp);

// 5. verify callback is reachable (helps diagnose timeout issues)
$callbackUrl = "https://triseptate-unproperly-crew.ngrok-free.dev/Books-Elearning-platform/backend/api/callback.php";
$check = curl_init($callbackUrl);
curl_setopt($check, CURLOPT_NOBODY, true);
curl_setopt($check, CURLOPT_TIMEOUT, 5);
curl_setopt($check, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($check);
$http = curl_getinfo($check, CURLINFO_HTTP_CODE);
$error = curl_error($check);
curl_close($check);
// treat as failure only when HTTP status is not 200 or curl_exec failed entirely
if ($result === false || $http !== 200) {
    // log full diagnostics
    file_put_contents(__DIR__ . '/stk-response.log', date('c') . " CALLBACK CHECK failed (http=$http, err=\"$error\")\n", FILE_APPEND);
    die("Callback URL unreachable (HTTP $http). Error: $error. Please ensure the ngrok tunnel is active and the URL is correct.");
}

// 5. STK Push Request
$stkpush_url = "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $stkpush_url);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $access_token"
]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

$data = [
    "BusinessShortCode" => $BusinessShortCode,
    "Password"          => $Password,
    "Timestamp"         => $Timestamp,
    "TransactionType"   => "CustomerPayBillOnline",
    "Amount"            => (int)$amount,
    "PhoneNumber"       => $phone,
    "PartyA"            => $phone,
    "PartyB"            => $BusinessShortCode,
    // make sure callback address matches your project directory (case‑sensitive on remote hosts)
    "CallBackURL"       => "https://triseptate-unproperly-crew.ngrok-free.dev/Books-Elearning-platform/backend/api/callback.php",
    "AccountReference"  => "BookPlatform",
    "TransactionDesc"   => "Membership Upgrade"
];
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($curl);
// log raw STK response for debugging (ensure writable path or adjust as needed)
file_put_contents(__DIR__ . '/stk-response.log', date('c') . " RESPONSE: " . $response . "\n", FILE_APPEND);

if ($response === false) {
    $res_data = (object)[
        'errorMessage' => 'Curl error: ' . curl_error($curl)
    ];
} else {
    $res_data = json_decode($response);
    if ($res_data === null) {
        // invalid JSON from Safaricom
        $res_data = (object)[
            'errorMessage' => 'Invalid response from M-Pesa: ' . htmlentities($response)
        ];
    }
}
curl_close($curl);

// 6. User Feedback
if (isset($res_data->ResponseCode) && $res_data->ResponseCode == "0") {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Sent</title>
        <style>
            body { 
                font-family: "Segoe UI", Arial, sans-serif; 
                margin: 0; 
                background: linear-gradient(120deg, AliceBlue, LavenderBlush); 
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .success-card { 
                background: White; 
                padding: 40px; 
                border-radius: 20px; 
                box-shadow: 0 20px 40px rgba(0,0,0,0.1); 
                width: 100%;
                max-width: 450px;
                text-align: center;
                animation: fadeIn 0.5s ease-out;
            }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
            
            .icon-box {
                width: 80px;
                height: 80px;
                background: MediumSeaGreen;
                color: White;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 40px;
                margin: 0 auto 20px;
                box-shadow: 0 10px 20px rgba(16, 185, 129, 0.2);
            }
            h2 { color: MidnightBlue; margin: 0 0 15px 0; }
            p { color: SlateGray; line-height: 1.6; margin-bottom: 25px; font-size: 1.05rem; }
            .highlight { color: MediumSeaGreen; font-weight: bold; }
            
            .btn-home {
                display: inline-block;
                background: MediumSeaGreen;
                color: White;
                padding: 14px 30px;
                border-radius: 10px;
                text-decoration: none;
                font-weight: bold;
                transition: 0.3s;
                width: 100%;
                box-sizing: border-box;
            }
            .btn-home:hover {
                background: SeaGreen;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(5, 150, 105, 0.3);
            }
        </style>
    </head>
    <body>
        <div class="success-card">
            <div class="icon-box">✓</div>
            <h2>Push Sent!</h2>
            <p>
                A payment request for <span class="highlight">KES <?php echo number_format($amount, 2); ?></span> 
                has been sent to <span class="highlight"><?php echo $phone; ?></span>.
            </p>
            <p>Please enter your <b>M-Pesa PIN</b> on your phone to complete the transaction.</p>
            <a href="../../frontend/index.php" class="btn-home">Return to Home</a>
        </div>
    </body>
    </html>
    <?php
} else {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <style>
            body { font-family: "Segoe UI", sans-serif; background: WhiteSmoke; height: 100vh; display: flex; align-items: center; justify-content: center; }
            .error-card { background: White; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; max-width: 400px; }
            .error-icon { background: Crimson; color: White; width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 20px; }
            .btn-retry { display: block; background: SlateGray; color: White; padding: 12px; border-radius: 10px; text-decoration: none; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-icon">✕</div>
            <h2 style="color: DarkRed;">Payment Failed</h2>
            <p style="color: SlateGray;"><?php echo $res_data->errorMessage ?? "We couldn't reach M-Pesa. Please try again."; ?></p>
            <a href="javascript:history.back()" class="btn-retry">Try Again</a>
        </div>
    </body>
    </html>
    <?php
}
?>