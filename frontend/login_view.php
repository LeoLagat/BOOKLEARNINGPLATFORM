<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Books & E-Learning</title>
    <style>
        body { 
            font-family: "Segoe UI", Arial, sans-serif; 
            margin: 0; 
            background: linear-gradient(120deg, AliceBlue, LavenderBlush); 
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: DarkSlateGray;
        }
        .login-card { 
            background: White; 
            padding: 40px; 
            border-radius: 20px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1); 
            width: 100%;
            max-width: 400px;
            box-sizing: border-box;
        }
        h1 { color: MidnightBlue; text-align: center; margin-bottom: 10px; }
        p.subtitle { text-align: center; color: SlateGray; margin-bottom: 30px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: MidnightBlue; }
        input {
            padding: 12px; 
            border-radius: 10px; 
            border: 1px solid LightGray; 
            margin-bottom: 20px; 
            width: 100%; 
            box-sizing: border-box;
            font-size: 1rem;
        }
        input:focus {
            outline: none;
            border-color: SlateBlue;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }
        button { 
            width: 100%; 
            padding: 15px; 
            border: none; 
            border-radius: 10px; 
            background: SlateBlue; 
            color: White; 
            font-weight: bold; 
            font-size: 1rem;
            cursor: pointer; 
            transition: 0.3s;
        }
        button:hover { 
            background: DodgerBlue; 
            transform: translateY(-2px);
        }
        .footer-links { text-align: center; margin-top: 20px; font-size: 0.9rem; }
        .footer-links a { color: SlateBlue; text-decoration: none; font-weight: bold; }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="login-card">
    <h1>Books &amp; E-Learning Platform</h1>
    <p class="subtitle">Log in to access your library</p>
    
    <form action="../backend/api/login.php" method="POST">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="email@example.com" required>
        
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" required>
        
        <button type="submit">Login to My Account</button>
    </form>

    <div class="footer-links">
        <p>Don't have an account? <a href="index.php?action=signup">Sign Up Here</a></p>
        <p>Admin? <a href="admin_login.php">Open Admin Portal</a></p>
    </div>
</div>

</body>
</html>