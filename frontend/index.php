<?php
session_start();

// --- 1. SECURITY LOCK ---
if (!isset($_SESSION["fullname"]) && !isset($_GET['action']) && $_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: login_view.php");
    exit();
}

include "../backend/api/config.php";

// Registration & POST Logic
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- REFRESH SESSION LOGIC ---
    if (isset($_POST['refresh_status'])) {
        $phone = $_SESSION['phone'];
        $stmt = $conn->prepare("SELECT payment_status, membership FROM users WHERE phone = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            $_SESSION['payment_status'] = $user['payment_status'];
            $_SESSION['membership'] = $user['membership'];
            header("Location: index.php");
            exit();
        }
    }

    // --- CASE A: UPGRADE/DOWNGRADE for logged-in user ---
    if (isset($_SESSION['phone']) && isset($_POST['membership']) && !isset($_POST['fullname'])) {
        $membership = $_POST['membership'];
        $phone = $_SESSION['phone']; 
        
        // (removed guard -- upgrades always trigger STK push)
        
        if ($membership === "Basic") {
            $status = "Paid"; 
            $stmt = $conn->prepare("UPDATE users SET membership = ?, payment_status = ? WHERE phone = ?");
            $stmt->bind_param("sss", $membership, $status, $phone);
               if ($stmt->execute()) {
                $_SESSION['membership'] = $membership;
                $_SESSION['payment_status'] = $status;
                header("Location: index.php?toast=basic_switched");
                exit();
            }
         
        } else {
            $status = "Pending"; 
            $stmt = $conn->prepare("UPDATE users SET membership = ?, payment_status = ? WHERE phone = ?");
            $stmt->bind_param("sss", $membership, $status, $phone);
            
            if ($stmt->execute()) {
                $_SESSION['membership'] = $membership;
                $_SESSION['payment_status'] = $status;
                $amount = ($membership === "Premium") ? 1 : 20; 

                // Store pending STK data in session to process on page load smoothly
                $_SESSION['pending_stk'] = ['phone' => $phone, 'amount' => $amount];
                header("Location: index.php");
                exit();
            }
        }
    } 
    // --- CASE B: NEW Registration ---
    else if (isset($_POST['fullname'])) {
        $fullname = $_POST["fullname"];
        $email = $_POST["email"];
        $phone = $_POST["phone"];
        $password = password_hash($_POST["password"], PASSWORD_DEFAULT);
        $membership = $_POST["membership"];
        $status = ($membership === "Basic") ? "Paid" : "Pending";
        
        // check for existing user (email or phone) to prevent duplicate key error
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
        $chk->bind_param("ss", $email, $phone);
        $chk->execute();
        $chkResult = $chk->get_result();
        if ($chkResult && $chkResult->num_rows > 0) {
            // user already exists, redirect back with toast
            header("Location: index.php?toast=duplicate");
            exit();
        }
        
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, phone, password, membership, payment_status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $fullname, $email, $phone, $password, $membership, $status);
        
        if ($stmt->execute()) {
            $_SESSION["fullname"] = $fullname;
            $_SESSION["membership"] = $membership;
            $_SESSION["phone"] = $phone;
            $_SESSION["payment_status"] = $status;

            if ($membership === "Basic") {
                header("Location: index.php?toast=account_created");
            } else {
                $amount = ($membership === 'Premium') ? 1 : 20;
                // Store pending STK data in session to process on page load smoothly
                $_SESSION['pending_stk'] = ['phone' => $phone, 'amount' => $amount];
                header("Location: index.php");
            }
            exit();
        }
    }
} // End of POST Check
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Books & E-Learning Platform</title>
    <style>
    :root {
        --surface: White;
        --surface-soft: GhostWhite;
        --brand: SlateBlue;
        --brand-strong: MidnightBlue;
        --accent: RoyalBlue;
        --success: MediumSeaGreen;
        --warning: Orange;
        --danger: Crimson;
        --text: DarkSlateGray;
        --muted: SlateGray;
        --shadow-soft: 0 10px 24px rgba(15, 23, 42, 0.08);
        --shadow-strong: 0 20px 40px rgba(15, 23, 42, 0.12);
    }

    body { 
        font-family: "Segoe UI", Arial, sans-serif; 
        margin: 0; 
        background: radial-gradient(circle at top left, AliceBlue 0%, GhostWhite 45%, LavenderBlush 100%);
        color: var(--text); 
        scroll-behavior: smooth; 
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    .container { max-width: 1120px; margin: auto; padding: 20px; flex: 1; width: 100%; box-sizing: border-box; }
    h1 { font-size: clamp(2rem, 4vw, 3rem); text-align: center; color: var(--brand-strong); margin-top: 8px; }
    h2 { 
        margin-top: 30px; 
        color: var(--accent); 
        border-left: 5px solid var(--accent); 
        padding-left: 15px; 
    }

    nav {
        display: flex;
        gap: 12px;
        margin: 20px 0 30px;
        flex-wrap: wrap;
        justify-content: center;
        position: sticky;
        top: 10px;
        z-index: 50;
        padding: 12px;
        border-radius: 16px;
        background: rgba(255,255,255,0.72);
        backdrop-filter: blur(10px);
    }
    nav button {
        background: var(--surface);
        border: 1px solid Gainsboro;
        padding: 12px 22px; 
        border-radius: 12px;
        cursor: pointer; 
        font-weight: 600; 
        box-shadow: var(--shadow-soft);
        transition: 0.25s ease;
    }
    nav button:hover { 
        background: var(--brand); 
        color: White; 
        transform: translateY(-2px); 
    }
    nav button.nav-active {
        background: var(--brand-strong);
        color: White;
        border-color: var(--brand-strong);
    }

    section { display: none; animation: fadeIn 0.5s ease; }
    section.active { 
        display: block; 
        background: var(--surface);
        padding: 30px; 
        border-radius: 20px; 
        box-shadow: var(--shadow-strong);
    }
    @keyframes fadeIn { 
        from { opacity: 0; transform: translateY(10px); } 
        to { opacity: 1; transform: translateY(0); } 
    }

    .book-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px; }
    .book-card { 
        background: var(--surface-soft);
        padding: 20px; 
        border-radius: 16px; 
        box-shadow: var(--shadow-soft);
        transition: 0.3s; 
        border: 1px solid AliceBlue; 
    }
    .book-card:hover { transform: translateY(-5px); border-color: SlateBlue; }
    .book-card h3 { margin-top: 0; color: SlateBlue; font-size: 1.2rem; }
    .book-card p { font-size: 0.9rem; color: Gray; }
    .book-card button { 
        margin-top: 10px; 
        width: 100%; 
        padding: 10px; 
        border: none; 
        background: SlateBlue; 
        color: White; 
        border-radius: 10px; 
        cursor: pointer; 
        font-weight: 600; 
    }

    .quiz-item { 
        background: GhostWhite; 
        padding: 15px; 
        border-radius: 12px; 
        margin-bottom: 15px; 
        border-left: 4px solid LightGrey; 
    }
    .quiz-item p { font-weight: 600; margin-bottom: 10px; }
    .quiz-item label { display: block; padding: 5px 0; cursor: pointer; transition: 0.2s; }
    .quiz-item label:hover { color: SlateBlue; }
    #quiz-score { font-size: 22px; font-weight: bold; text-align: center; margin-top: 20px; }

    input[type="text"], input[type="email"], input[type="password"], select {
        padding: 12px; 
        border-radius: 10px; 
        border: 1px solid LightGray; 
        margin-bottom: 15px; 
        width: 100%; 
        box-sizing: border-box;
    }
    .membership-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 25px; margin-top: 20px; }
    .membership-card { 
        background: var(--surface-soft);
        padding: 25px; 
        border-radius: 18px; 
        text-align: center; 
        border: 1px solid LightGrey; 
        transition: 0.3s; 
    }
    .membership-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-soft); }
    .popular { 
        border: 2.5px solid var(--brand);
        transform: scale(1.05); 
        background: var(--surface);
    }
    
    footer {
        text-align: center; 
        padding: 40px 20px; 
        background-color: GhostWhite; 
        margin-top: 50px; 
        border-top: 1px solid LightGrey;
    }
  
    /* Utility Classes */
    .text-success { color: MediumSeaGreen; }
    .text-primary { color: SlateBlue; }
    .text-warning { color: Orange; }
    .text-danger { color: Crimson; }
    .text-muted { color: Gray; }
    .font-bold { font-weight: bold; }
    .text-center { text-align: center; }
    
    .btn-success { background: var(--success); color: White; padding: 12px 25px; border: none; border-radius: 10px; cursor: pointer; font-weight: bold; transition: 0.2s ease; }
    .btn-primary { background: var(--brand); color: White; padding: 10px 20px; border: none; border-radius: 10px; cursor: pointer; font-weight: bold; transition: 0.2s ease; }
    .btn-danger { background: var(--danger); color: White; padding: 15px 40px; border: none; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 16px; transition: 0.2s ease; }
    .btn-warning { background: var(--warning); color: White; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; transition: 0.2s ease; }
    .btn-blue { background: var(--accent); color: White; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.2s ease; }
    .btn-full { width: 100%; padding: 15px; }
    .btn-block { width: 100%; display: block; box-sizing: border-box; }
    .btn-success:hover, .btn-primary:hover, .btn-danger:hover, .btn-warning:hover, .btn-blue:hover { transform: translateY(-1px); filter: brightness(0.95); }
    .btn-success:focus-visible, .btn-primary:focus-visible, .btn-danger:focus-visible, .btn-warning:focus-visible, .btn-blue:focus-visible, nav button:focus-visible {
        outline: 3px solid rgba(65, 105, 225, 0.35);
        outline-offset: 2px;
    }
    .btn-success:disabled, .btn-primary:disabled, .btn-danger:disabled, .btn-warning:disabled, .btn-blue:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    .list-none { list-style: none; padding: 0; }
    .preview-text { line-height: 1.6; color: DarkSlateGray; font-size: 1.1rem; }
    .status-box { background: var(--surface-soft); padding: 18px; border-radius: 14px; margin: 15px 0; border: 1px solid LightSteelBlue; max-width: 620px; box-shadow: var(--shadow-soft); }
    .status-text { margin: 0 0 12px 0; font-family: sans-serif; }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: 0.95rem;
        border: 1px solid transparent;
    }
    .status-paid { background: HoneyDew; color: ForestGreen; border-color: LightGreen; }
    .status-pending { background: FloralWhite; color: Sienna; border-color: Gold; }
    .status-helper { margin: 0 0 10px; color: var(--muted); font-size: 0.95rem; }
    .info-box { background: AliceBlue; padding: 18px; border-radius: 12px; color: MidnightBlue; border: 1px solid LightBlue; max-width: 720px; }
    .hidden { display: none !important; }

    /* Loader */
    .loader {
        border: 4px solid Ivory;
        border-top: 4px solid DeepSkyBlue;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        animation: spin 1s linear infinite;
        display: inline-block;
        vertical-align: middle;
        margin-left: 10px;
    }
    
    .loader-large {
        width: 50px;
        height: 50px;
        border-width: 5px;
        margin: 20px auto;
        display: block;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    #loader-container { display: none; align-items: center; justify-content: center; margin-top: 10px; }
    .loader-text { margin-left: 10px; color: DeepSkyBlue; font-weight: bold; }

    /* Toast Notification */
    .toast {
        visibility: hidden;
        min-width: 250px;
        background-color: MidnightBlue;
        color: White;
        text-align: center;
        border-radius: 8px;
        padding: 16px;
        position: fixed;
        z-index: 1000;
        left: 50%;
        bottom: 30px;
        font-size: 17px;
        transform: translateX(-50%);
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }
    .toast.show {
        visibility: visible;
        animation: fadein 0.5s, fadeout 0.5s 2.5s;
    }
    @keyframes fadein { from {bottom: 0; opacity: 0;} to {bottom: 30px; opacity: 1;} }
    @keyframes fadeout { from {bottom: 30px; opacity: 1;} to {bottom: 0; opacity: 0;} }

    /* Modal */
    .modal {
        display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);
    }
    .modal.show-modal { display: flex; align-items: center; justify-content: center; }
    .modal-content {
        background-color: White; padding: 30px; border-radius: 12px; text-align: center; width: 90%; max-width: 400px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }
    .modal-actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }

    @media (max-width: 820px) {
        .container { padding: 14px; }
        section.active { padding: 22px; }
        nav { position: static; padding: 8px; }
        nav button { flex: 1 1 calc(50% - 10px); padding: 11px 12px; }
        .popular { transform: none; }
        .modal-actions { flex-direction: column; }
        .btn-danger { padding: 12px 20px; }
    }

</style>
</head>
<body>

<div id="custom-toast" class="toast hidden" role="status" aria-live="polite"></div>

<div id="confirm-modal" class="modal">
    <div class="modal-content">
        <h3 class="text-primary">Confirm Upgrade</h3>
        <p id="confirm-text" class="text-muted"></p>
        <div class="modal-actions">
            <button onclick="confirmUpgrade()" class="btn-success">Yes, Upgrade</button>
            <button onclick="closeConfirmModal()" class="btn-danger">Cancel</button>
        </div>
    </div>
</div>

<?php if(isset($_SESSION['pending_stk'])): ?>
<div class="modal show-modal">
    <div class="modal-content">
        <h3 class="text-primary">Connecting to M-Pesa...</h3>
        <div class="loader loader-large"></div>
        <p class="text-muted font-bold">Please check your phone for the PIN prompt.</p>
        <form id='stkForm' action='../backend/api/stkpush.php' method='POST' class='hidden'>
            <input type='hidden' name='phone' value='<?php echo $_SESSION['pending_stk']['phone']; ?>'>
            <input type='hidden' name='amount' value='<?php echo $_SESSION['pending_stk']['amount']; ?>'>
        </form>
    </div>
</div>
<script>
    setTimeout(() => { document.getElementById('stkForm').submit(); }, 1500);
</script>
<?php unset($_SESSION['pending_stk']); endif; ?>

<div class="container">
    <h1>Books & E-Learning Platform</h1>

    <nav>
        <button class="nav-btn" data-target="home" onclick="showSection('home')">Home</button>
        <button class="nav-btn" data-target="books" onclick="showSection('books')">Browse Books</button>
        <button class="nav-btn" data-target="membership" onclick="showSection('membership')">Membership</button>
        <button class="nav-btn" data-target="quiz" onclick="showSection('quiz')">Take a Quiz</button>
        <button class="nav-btn" data-target="dashboard" onclick="showSection('dashboard')">My Dashboard</button>
    </nav>

    <section id="home" class="<?php echo isset($_GET['action']) ? '' : 'active'; ?>">
        <h2>Welcome to Your Future</h2>
        <p>Unlock premium knowledge through our curated library.</p>
        <div class="book-grid">
            <div class="book-card"><h3>Python for Everyone</h3><p>Tech</p><button onclick="showSection('books')">View</button></div>
            <div class="book-card"><h3>Financial Intelligence</h3><p>Finance</p><button onclick="showSection('books')">View</button></div>
        </div>
    </section>

    <section id="books">
        <h2>Explore Our Library</h2>
        <input type="text" placeholder="Search by title or category..." oninput="filterBooks(this.value)">
        
        <div class="book-grid" id="book-list">
            <div class="book-card"><h3>Healthy Living</h3><p>Lifestyle</p><button onclick="openPreview('Healthy Living')">Preview</button></div>
            <div class="book-card"><h3>Financial Freedom</h3><p>Finance</p><button onclick="openPreview('Financial Freedom')">Preview</button></div>
            <div class="book-card"><h3>Python for Everyone</h3><p>Tech</p><button onclick="openPreview('Python for Everyone')">Preview</button></div>
            <div class="book-card"><h3>African Recipes</h3><p>Food</p><button onclick="openPreview('African Recipes')">Preview</button></div>
            <div class="book-card"><h3>Public Speaking</h3><p>Career</p><button onclick="openPreview('Public Speaking')">Preview</button></div>
            <div class="book-card"><h3>Digital Marketing</h3><p>Business</p><button onclick="openPreview('Digital Marketing')">Preview</button></div>
            <div class="book-card"><h3>Mindset Mastery</h3><p>Personal Dev</p><button onclick="openPreview('Mindset Mastery')">Preview</button></div>
            <div class="book-card"><h3>Real Estate 101</h3><p>Investing</p><button onclick="openPreview('Real Estate 101')">Preview</button></div>
        </div>
    </section>

    <section id="preview">
        <h2 id="preview-title"></h2>
        <p id="preview-content" class="preview-text"></p>
        <button id="preview-btn" class="btn-success">Get Full Access</button>
    </section>

    <section id="quiz">
        <h2>Test Your Knowledge</h2>
        <div id="quiz-container">
            <div class="quiz-item"><p>1. Which of these is a macronutrient?</p>
                <label><input type="radio" name="q1" value="1"> Protein</label>
                <label><input type="radio" name="q1" value="0"> Vitamin C</label>
            </div>
            <div class="quiz-item"><p>2. What is the rule of 72 used for in finance?</p>
                <label><input type="radio" name="q2" value="1"> Estimating doubling time</label>
                <label><input type="radio" name="q2" value="0"> Calculating taxes</label>
            </div>
            <div class="quiz-item"><p>3. In Python, which keyword is used to create a function?</p>
                <label><input type="radio" name="q3" value="1"> def</label>
                <label><input type="radio" name="q3" value="0"> function</label>
            </div>
            <div class="quiz-item"><p>4. Which HTML tag is used for the largest heading?</p>
                <label><input type="radio" name="q4" value="1"> &lt;h1&gt;</label>
                <label><input type="radio" name="q4" value="0"> &lt;heading&gt;</label>
            </div>
            <div class="quiz-item"><p>5. In M-Pesa STK Push, what action is required from the user to complete payment?</p>
                <label><input type="radio" name="q5" value="1"> Enter M-Pesa PIN on phone prompt</label>
                <label><input type="radio" name="q5" value="0"> Refresh browser only</label>
            </div>
            <div class="quiz-item"><p>6. Which of these improves account security?</p>
                <label><input type="radio" name="q6" value="1"> Using a strong unique password</label>
                <label><input type="radio" name="q6" value="0"> Sharing passwords with friends</label>
            </div>
            <div class="quiz-item"><p>7. What does SEO stand for?</p>
                <label><input type="radio" name="q7" value="1"> Search Engine Optimization</label>
                <label><input type="radio" name="q7" value="0"> Secure Email Operations</label>
            </div>
            <div class="quiz-item"><p>8. Which practice helps in personal productivity?</p>
                <label><input type="radio" name="q8" value="1"> Setting clear daily priorities</label>
                <label><input type="radio" name="q8" value="0"> Multitasking everything at once</label>
            </div>
            <button onclick="checkQuiz()" class="btn-primary btn-full">Submit My Answers</button>
            <p id="quiz-score"></p>
        </div>
    </section>

    <section id="membership" class="<?php echo (isset($_GET['action']) && $_GET['action'] == 'signup') ? 'active' : ''; ?>">
        <h2>Choose Your Membership Plan</h2>
        <div class="membership-grid">
            <div class="membership-card">
                <h3>Basic Plan</h3>
                <p class="price font-bold">Free</p>
                <ul class="list-none"><li>Access to 5 Free Books</li><li>Standard Support</li></ul>
                <button onclick="showRegistration('Basic', 0)" class="btn-primary btn-full">Get Started for Free</button>
            </div>
            <div class="membership-card popular">
                <h3 class="text-primary">Premium Plan</h3>
                <p class="price font-bold">KES 1000/mo</p>
                <ul class="list-none"><li>Access All Books</li><li>Certificates</li></ul>
                <button onclick="showRegistration('Premium', 1000)" class="btn-primary btn-full">Select Premium</button>
            </div>
            <div class="membership-card">
                <h3>VIP Plan</h3>
                <p class="price font-bold">KES 2000/mo</p>
                <ul class="list-none"><li>1-on-1 Mentorship</li><li>Exclusive Webinars</li></ul>
                <button onclick="showRegistration('VIP', 2000)" class="btn-primary btn-full">Select VIP</button>
            </div>
        </div>
    </section>

    <section id="registrationSection">
        <h2>Create Your Account</h2>
        <form method="POST" action="">
            <input type="hidden" name="register" value="1">
            <input type="text" name="fullname" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="text" name="phone" placeholder="Phone Number" required>
            <input type="password" name="password" placeholder="Create Password" required>
            <p><strong>Selected Plan:</strong> <span id="selectedPlanText" class="text-primary font-bold">Please select a plan below</span></p>
            <input type="hidden" id="membershipInput" name="membership" required>
            <button type="submit" class="btn-primary btn-full">
                Register & Pay
            </button>
        </form>
    </section>

    <section id="dashboard">
        <?php if (isset($_SESSION['payment_status']) && $_SESSION['payment_status'] == 'Paid'): ?>
            <p class="text-success font-bold text-center">✅ Payment Verified! You now have access.</p>
        <?php endif; ?>
        
        <h2>Your Dashboard</h2>
        <?php if(isset($_SESSION["fullname"])): ?>
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION["fullname"]); ?>!</h1>
            
            <div class="status-box">
                <p class="status-text">
                    <strong>Current Status:</strong> 
                    <span class="status-badge <?php echo ($_SESSION['payment_status'] == 'Paid') ? 'status-paid' : 'status-pending'; ?> font-bold">
                        <?php echo $_SESSION['membership']; ?> (<?php echo $_SESSION['payment_status']; ?>)
                    </span>
                </p>
                <p class="status-helper">Use sync after completing payment on your phone to refresh account access instantly.</p>
                
                <form method="POST" onsubmit="return showSyncLoader()">
                    <button type="submit" id="sync-btn" name="refresh_status" class="btn-blue btn-block">
                        🔄 Sync My Payment Status
                    </button>
                    
                    <div id="loader-container">
                        <div class="loader"></div>
                        <span class="loader-text">Verifying payment...</span>
                    </div>
                </form>
            </div>
            
            <div class="info-box">
                <?php if($_SESSION['payment_status'] == 'Paid'): ?>
                    <p class="font-bold">✅ Your account is active. Enjoy your library!</p>
                    <button onclick="window.location.href='../backend/api/books.php'" class="btn-primary">Go to Library</button>
                <?php else: ?>
                    <p>⚠️ Your payment is still <b>Pending</b>. Please complete the M-Pesa transaction to access books.</p>
                    <button onclick="showSection('membership')" class="btn-warning">Retry Payment (after Sync)</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<footer>
    <?php if(isset($_SESSION["fullname"])): ?>
        <h3 class="text-primary">Done Learning?</h3>
        <p class="text-muted" style="margin-bottom: 20px;">Securely close your session below.</p>
        <a href="../backend/api/logout.php">
            <button class="btn-danger">
                Log Out & Close
            </button>
        </a>
    <?php else: ?>
        <p>Already have an account? <a href="login_view.php" class="text-primary font-bold" style="text-decoration:none;">Log in here</a></p>
    <?php endif; ?>
</footer>

<script>
    // --- Toast Logic ---
    function showToast(message) {
        const toast = document.getElementById("custom-toast");
        toast.innerText = message;
        toast.classList.remove("hidden");
        toast.classList.add("show");
        setTimeout(function(){ 
            toast.classList.remove("show"); 
            toast.classList.add("hidden"); 
        }, 3000);
    }

    // Handle URL Toast Messages from PHP Redirects
    window.onload = function() {
        const urlParams = new URLSearchParams(window.location.search);
        const activeSection = document.querySelector('section.active');
        setActiveNav(activeSection ? activeSection.id : 'home');
        if(urlParams.has('toast')) {
            const toastType = urlParams.get('toast');
            if(toastType === 'basic_switched') showToast('Switched to Basic Plan! No charge applied.');
            if(toastType === 'account_created') showToast('Account created! Welcome to Basic.');
            if(toastType === 'duplicate') showToast('Email or phone already registered.');
            if(toastType === 'mustpay') showToast('Please complete your pending payment before changing plans.');
            if(toastType === 'payment_verified') showToast('Payment confirmed. Your account is now active.');
            // Clean URL after showing
            window.history.replaceState(null, null, window.location.pathname);
        }
    }

    // --- UI Navigation ---
    function setActiveNav(id) {
        document.querySelectorAll('nav .nav-btn').forEach(btn => {
            btn.classList.toggle('nav-active', btn.dataset.target === id);
        });
    }

    function showSection(id) {
        document.querySelectorAll('section').forEach(s => s.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        setActiveNav(id);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    const previews = {
        "Healthy Living": "Comprehensive guide to nutrition, sleep cycles, and mental clarity.",
        "Financial Freedom": "Step-by-step methods to escape debt and build passive income.",
        "Python for Everyone": "From 'Hello World' to building your first web application with Python.",
        "African Recipes": "A collection of 50+ traditional and modern dishes from across the continent.",
        "Public Speaking": "Master the art of persuasion and overcome stage fright forever.",
        "Digital Marketing": "Learn SEO, PPC, and Social Media strategies used by top agencies.",
        "Mindset Mastery": "Psychological techniques to build resilience and focus.",
        "Real Estate 101": "How to evaluate properties and secure your first investment."
    };

    function openPreview(book) {
        document.getElementById('preview-title').innerText = book + " — Preview";
        document.getElementById('preview-content').innerText = previews[book] || "Preview coming soon...";
        
        const isLoggedIn = <?php echo isset($_SESSION['fullname']) ? 'true' : 'false'; ?>;
        const isPaid = <?php echo (isset($_SESSION['payment_status']) && $_SESSION['payment_status'] == 'Paid') ? 'true' : 'false'; ?>;
        
        let actionButton = document.getElementById('preview-btn');
        // Reset classes
        actionButton.className = '';
        
        if (isLoggedIn && isPaid) {
            actionButton.innerText = "View Full Book";
            actionButton.classList.add('btn-success');
            actionButton.onclick = function() { window.location.href = '../backend/api/books.php'; };
        } else if (isLoggedIn && !isPaid) {
            actionButton.innerText = "Payment Pending - Complete Now";
            actionButton.classList.add('btn-warning');
            actionButton.onclick = function() { showSection('membership'); };
        } else {
            actionButton.innerText = "Get Full Access";
            actionButton.classList.add('btn-primary');
            actionButton.onclick = function() { showSection('membership'); };
        }
        
        showSection('preview');
    }

    function filterBooks(query) {
        const q = query.toLowerCase();
        document.querySelectorAll('.book-card').forEach(card => {
            card.style.display = card.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    // --- Upgrade Logic via Custom Modal ---
    let pendingUpgradePlan = null;
    
    function showRegistration(plan, amount) {
        const isLoggedIn = <?php echo isset($_SESSION['fullname']) ? 'true' : 'false'; ?>;
        const isPaid = <?php echo (isset($_SESSION['payment_status']) && $_SESSION['payment_status'] == 'Paid') ? 'true' : 'false'; ?>;
        
        if (isLoggedIn) {
            const currentPlan = "<?php echo $_SESSION['membership'] ?? ''; ?>";
            if (plan === currentPlan && isPaid) {
                showToast("You are already subscribed to the " + plan + " plan.");
                return;
            }

            if (plan === currentPlan && !isPaid) {
                showToast("Your " + plan + " payment is pending. Sending a new M-Pesa prompt...");
            }

            // Show custom confirm dialog
            pendingUpgradePlan = plan;
            document.getElementById('confirm-text').innerText = (plan === currentPlan && !isPaid)
                ? "Your " + plan + " payment is pending. Do you want to retry M-Pesa payment for KES " + amount + "?"
                : "You are already logged in. Do you want to upgrade to " + plan + " for KES " + amount + "?";
            document.getElementById('confirm-modal').classList.add('show-modal');
        } else {
            document.getElementById("membershipInput").value = plan;
            document.getElementById("selectedPlanText").innerText = plan + " - KES " + amount;
            showSection('registrationSection');
        }
    }

    function confirmUpgrade() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php';
        const planInput = document.createElement('input');
        planInput.type = 'hidden';
        planInput.name = 'membership';
        planInput.value = pendingUpgradePlan;
        form.appendChild(planInput);
        document.body.appendChild(form);
        form.submit();
    }

    function closeConfirmModal() {
        document.getElementById('confirm-modal').classList.remove('show-modal');
    }

    // --- Quiz Logic ---
    function checkQuiz() {
        let score = 0;
        const total = 8;
        for(let i=1; i<=total; i++) {
            let selected = document.querySelector(`input[name="q${i}"]:checked`);
            if(selected && selected.value === "1") score++;
        }
        const scoreDiv = document.getElementById('quiz-score');
        const percent = Math.round((score / total) * 100);
        let level = "Keep practicing!";
        if (percent >= 85) level = "Excellent work!";
        else if (percent >= 60) level = "Good effort!";

        scoreDiv.innerText = `You scored ${score} out of ${total} (${percent}%). ${level}`;
        scoreDiv.style.color = percent >= 60 ? "MediumSeaGreen" : "Crimson";
    }

    // --- Loading Spinner Logic ---
    function showSyncLoader() {
        const syncBtn = document.getElementById('sync-btn');
        syncBtn.disabled = true;
        syncBtn.textContent = 'Checking payment...';
        document.getElementById('loader-container').style.display = 'block';
        return true;
    }
</script>

</body>
</html>