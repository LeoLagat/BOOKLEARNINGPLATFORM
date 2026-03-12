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

// Prevent accidental duplicate STK pushes for the same phone/amount in a short period.
$cooldownSeconds = 120;
if (
    isset($_SESSION['last_stk_phone'], $_SESSION['last_stk_amount'], $_SESSION['last_stk_time']) &&
    $_SESSION['last_stk_phone'] === $phone &&
    (int)$_SESSION['last_stk_amount'] === (int)$amount
) {
    $elapsed = time() - (int)$_SESSION['last_stk_time'];
    if ($elapsed < $cooldownSeconds) {
        $wait = $cooldownSeconds - $elapsed;
        die("A payment prompt was sent recently. Please wait {$wait} seconds, then click Sync My Payment Status before retrying.");
    }
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

// 5. Resolve callback URL.
// Priority: explicit environment variable, otherwise infer from the active request host.
$configuredCallbackUrl = getenv('MPESA_CALLBACK_URL');
if ($configuredCallbackUrl !== false && trim($configuredCallbackUrl) !== '') {
    $callbackUrl = trim($configuredCallbackUrl);
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $callbackPath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/callback.php';

    if ($host !== '' && preg_match('/^(localhost|127\\.0\\.0\\.1)(:\\d+)?$/i', $host)) {
        // Dev fallback: if ngrok is running locally, use its first HTTPS tunnel.
        $tunnelUrl = '';
        $ngrokApi = @file_get_contents('http://127.0.0.1:4040/api/tunnels');
        if ($ngrokApi !== false) {
            $ngrokData = json_decode($ngrokApi, true);
            if (isset($ngrokData['tunnels']) && is_array($ngrokData['tunnels'])) {
                foreach ($ngrokData['tunnels'] as $tunnel) {
                    if (!empty($tunnel['public_url']) && stripos($tunnel['public_url'], 'https://') === 0) {
                        $tunnelUrl = rtrim($tunnel['public_url'], '/');
                        break;
                    }
                }
            }
        }

        if ($tunnelUrl !== '') {
            $callbackUrl = $tunnelUrl . $callbackPath;
        } else {
            die("Localhost callback detected and no ngrok HTTPS tunnel found. Start ngrok or set MPESA_CALLBACK_URL.");
        }
    } else {
        $callbackUrl = $host !== '' ? "$scheme://$host$callbackPath" : '';
    }
}

if ($callbackUrl === '') {
    die("Unable to determine callback URL. Set MPESA_CALLBACK_URL to your public callback endpoint.");
}

// verify callback is reachable (helps diagnose timeout issues)
$check = curl_init($callbackUrl);
curl_setopt($check, CURLOPT_TIMEOUT, 8);
curl_setopt($check, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($check);
$http = curl_getinfo($check, CURLINFO_HTTP_CODE);
$error = curl_error($check);
curl_close($check);
// treat as failure when request cannot be completed or callback responds 4xx/5xx
if ($result === false || $http >= 400 || $http === 0) {
    // log full diagnostics
    file_put_contents(__DIR__ . '/stk-response.log', date('c') . " CALLBACK CHECK failed (url=$callbackUrl, http=$http, err=\"$error\")\n", FILE_APPEND);
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
    // use one resolved callback URL source for both validation and STK request
    "CallBackURL"       => $callbackUrl,
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
    $_SESSION['last_stk_phone'] = $phone;
    $_SESSION['last_stk_amount'] = (int)$amount;
    $_SESSION['last_stk_time'] = time();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Waiting for Payment Confirmation</title>
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
                background: RoyalBlue;
                color: White;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 40px;
                margin: 0 auto 20px;
                box-shadow: 0 10px 20px rgba(65, 105, 225, 0.22);
            }
            h2 { color: MidnightBlue; margin: 0 0 15px 0; }
            p { color: SlateGray; line-height: 1.6; margin-bottom: 25px; font-size: 1.05rem; }
            .highlight { color: MediumSeaGreen; font-weight: bold; }

            .spinner {
                width: 48px;
                height: 48px;
                border: 5px solid AliceBlue;
                border-top: 5px solid RoyalBlue;
                border-radius: 50%;
                margin: 0 auto 18px;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .status-pill {
                display: inline-block;
                background: AliceBlue;
                color: RoyalBlue;
                border: 1px solid LightBlue;
                border-radius: 999px;
                padding: 8px 14px;
                font-weight: 600;
                margin-bottom: 18px;
            }
            
            .btn-home {
                display: inline-block;
                background: SlateGray;
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
                background: DimGray;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(55, 65, 81, 0.25);
            }
        </style>
    </head>
    <body>
        <div class="success-card">
            <div class="icon-box">⌛</div>
            <h2>STK Push Sent</h2>
            <div class="spinner" aria-hidden="true"></div>
            <div id="paymentState" class="status-pill">Waiting for M-Pesa confirmation...</div>
            <p>
                A payment request for <span class="highlight">KES <?php echo number_format($amount, 2); ?></span> 
                has been sent to <span class="highlight"><?php echo $phone; ?></span>.
            </p>
            <p id="paymentHint">Please enter your <b>M-Pesa PIN</b> on your phone. We are checking confirmation automatically.</p>
            <a href="../../frontend/index.php" class="btn-home">Back to Dashboard</a>
        </div>

        <script>
            const statusEl = document.getElementById('paymentState');
            const hintEl = document.getElementById('paymentHint');
            let checks = 0;
            const maxChecks = 40; // ~2 minutes at 3s interval

            async function pollPaymentStatus() {
                checks++;
                try {
                    const res = await fetch('payment_status.php?phone=<?php echo urlencode($phone); ?>', { cache: 'no-store' });
                    const data = await res.json();

                    if (data && data.ok && data.payment_status === 'Paid') {
                        statusEl.textContent = 'Payment confirmed. Redirecting...';
                        statusEl.style.background = 'HoneyDew';
                        statusEl.style.color = 'ForestGreen';
                        hintEl.innerHTML = 'Your payment has been verified successfully.';
                        setTimeout(() => {
                            window.location.href = '../../frontend/index.php?toast=payment_verified';
                        }, 1500);
                        return;
                    }

                    if (checks >= maxChecks) {
                        statusEl.textContent = 'Still waiting for confirmation.';
                        hintEl.innerHTML = 'If you have completed payment, click <b>Sync My Payment Status</b> on the dashboard.';
                        return;
                    }
                } catch (e) {
                    if (checks >= maxChecks) {
                        statusEl.textContent = 'Unable to verify right now.';
                        hintEl.textContent = 'Please return to dashboard and use Sync My Payment Status.';
                        return;
                    }
                }

                setTimeout(pollPaymentStatus, 3000);
            }

            setTimeout(pollPaymentStatus, 1500);
        </script>
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