<?php
session_start();
include "config.php"; 

if(!isset($_SESSION['fullname'])){ 
    header("Location: ../../frontend/login_view.php");
    exit();
}

$user_membership = $_SESSION['membership'] ?? "Basic";
$payment_status = $_SESSION['payment_status'] ?? ($user_membership === "Basic" ? "Paid" : "Pending"); 

$levels = ['Basic' => 0, 'Premium' => 1, 'VIP' => 2];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Library - Books & E-Learning</title>
    <style>
        body { 
            font-family: "Segoe UI", Arial, sans-serif; 
            margin: 0; 
            /* Clean, professional background */
            background: GhostWhite; 
            color: DarkSlateGray; 
            min-height: 100vh;
        }
        .container { max-width: 1000px; margin: 40px auto; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        
        h1 { color: MidnightBlue; margin: 0; }
        .subtitle { color: SlateGray; margin-top: 5px; font-size: 1.1rem; }
        
        /* Buttons & Links */
        .btn-back { 
            text-decoration: none; 
            color: RoyalBlue; 
            font-weight: bold; 
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: 0.2s;
        }
        .btn-back:hover { color: MidnightBlue; }

        .btn-read { 
            background: SeaGreen; 
            color: White; 
            padding: 12px 20px; 
            text-decoration: none; 
            border-radius: 8px; 
            display: inline-block;
            font-weight: bold;
            transition: 0.3s;
            text-align: center;
            width: 100%;
            box-sizing: border-box;
            border: none;
        }
        .btn-read:hover { background: DarkGreen; transform: translateY(-2px); }

        /* Book Grid Layout */
        .book-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px; margin-top: 30px; }
        
        .book-card { 
            background: White; 
            padding: 25px; 
            border-radius: 16px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
            border: 1px solid LightGray;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: 0.3s;
        }
        .book-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        
        .book-card h3 { margin: 0 0 10px 0; color: Indigo; font-size: 1.25rem; }
        .book-card p { font-size: 0.95rem; color: DimGray; line-height: 1.5; margin-bottom: 20px; flex-grow: 1; }
        
        /* Alert Box */
        .alert-card {
            background: White;
            border-left: 5px solid Crimson;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            text-align: center;
            max-width: 500px;
            margin: 50px auto;
        }
        .alert-card h3 { color: Crimson; margin-top: 0; font-size: 1.5rem; }
        .alert-card p { color: DarkSlateGray; font-size: 1.1rem; }
        
        .btn-pay {
            background: SlateBlue; 
            color: White; 
            padding: 12px 25px; 
            border-radius: 8px; 
            text-decoration: none; 
            font-weight: bold; 
            display: inline-block; 
            margin-top: 15px;
        }
    </style>
</head>
<body>

<div class="container">
    <div style="margin-bottom: 20px;">
        <a href="../../frontend/index.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <?php
    if ($user_membership !== "Basic" && $payment_status !== "Paid") {
        echo "<div class='alert-card'>";
        echo "<h3>Payment Required</h3>";
        echo "<p>Please complete your M-Pesa payment to unlock your <b>$user_membership</b> content.</p>";
        echo "<a href='../../frontend/index.php' class='btn-pay'>Go to Dashboard to Sync Payment</a>";
        echo "</div></div></body></html>";
        exit();
    }
    ?>

    <div class="header">
        <div>
            <h1>Library Access: <?php echo htmlspecialchars($user_membership); ?> Tier</h1>
            <p class="subtitle">Welcome, <?php echo htmlspecialchars($_SESSION['fullname']); ?>! Explore your resources.</p>
        </div>
    </div>

    <?php
    $sql = "SELECT * FROM books";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        echo "<div class='book-grid'>";
        while ($row = $result->fetch_assoc()) {
            $required = $row['membership_required'];
            
            if (isset($levels[$required]) && $levels[$user_membership] >= $levels[$required]) {
                echo "<div class='book-card'>";
                echo "<div>";
                echo "<h3>" . htmlspecialchars($row["title"]) . "</h3>";
                echo "<p>" . htmlspecialchars($row["description"]) . "</p>";
                echo "</div>";
                echo "<a href='../../" . htmlspecialchars($row["file_path"]) . "' target='_blank' class='btn-read'>Read Now</a>";
                echo "</div>";
            }
        }
        echo "</div>";
    } else {
        echo "<div class='alert-card' style='border-left-color: DarkOrange;'>";
        echo "<h3 style='color: DarkOrange;'>No Books Available</h3>";
        echo "<p>We couldn't find any books for your level yet. Please check back soon.</p>";
        echo "</div>";
    }
    ?>
</div>

</body>
</html>