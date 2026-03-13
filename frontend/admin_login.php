<?php
session_start();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: admin_dashboard.php");
    exit();
}

$error = $_GET['error'] ?? '';
$toast = $_GET['toast'] ?? '';
$errorMessage = '';

if ($error === 'missing_fields') {
    $errorMessage = 'Please enter both email and password.';
} elseif ($error === 'invalid_credentials') {
    $errorMessage = 'Invalid admin credentials.';
} elseif ($error === 'admin_table_setup_failed') {
    $errorMessage = 'Admin setup failed. Confirm database permissions for creating tables.';
} elseif ($error === 'server_error') {
    $errorMessage = 'Server error. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Books & E-Learning</title>
    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, AliceBlue, HoneyDew);
            color: DarkSlateGray;
        }

        .login-card {
            background: White;
            width: 100%;
            max-width: 420px;
            padding: 36px;
            border-radius: 18px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.12);
            box-sizing: border-box;
        }

        h1 {
            margin: 0 0 8px;
            color: MidnightBlue;
            text-align: center;
        }

        .subtitle {
            text-align: center;
            color: SlateGray;
            margin-bottom: 26px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: MidnightBlue;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid LightGray;
            border-radius: 10px;
            margin-bottom: 18px;
            box-sizing: border-box;
        }

        input:focus {
            outline: none;
            border-color: SlateBlue;
            box-shadow: 0 0 0 3px rgba(106, 90, 205, 0.2);
        }

        button {
            width: 100%;
            border: none;
            padding: 13px;
            border-radius: 10px;
            background: SlateBlue;
            color: White;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: RoyalBlue;
        }

        .alert {
            background: MistyRose;
            color: FireBrick;
            border: 1px solid LightCoral;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        .success {
            background: HoneyDew;
            color: DarkGreen;
            border: 1px solid PaleGreen;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        .links {
            margin-top: 18px;
            text-align: center;
            font-size: 0.95rem;
        }

        .links a {
            color: SlateBlue;
            font-weight: 600;
            text-decoration: none;
        }

        .links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="login-card">
    <h1>Admin Portal</h1>
    <p class="subtitle">Sign in to manage books and access tiers</p>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($toast === 'logged_out'): ?>
        <div class="success">You have been logged out successfully.</div>
    <?php endif; ?>

    <form action="../backend/api/admin_login.php" method="POST">
        <label for="email">Admin Email</label>
        <input id="email" type="email" name="email" placeholder="admin@example.com" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" placeholder="********" required>

        <button type="submit">Login as Admin</button>
    </form>

    <div class="links">
        <a href="index.php">Back to main platform</a>
    </div>
</div>

</body>
</html>
