<?php
session_start();
include "config.php"; 

mysqli_report(MYSQLI_REPORT_OFF);

function ensureUserBookTablesExist(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS user_bookmarks (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        book_id INT(11) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_user_bookmark (user_id, book_id),
        KEY idx_user_bookmarks_user (user_id),
        KEY idx_user_bookmarks_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS user_book_activity (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        book_id INT(11) NOT NULL,
        action VARCHAR(20) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_activity_user (user_id),
        KEY idx_user_activity_book (book_id),
        KEY idx_user_activity_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function resolveCurrentUser(mysqli $conn): ?array {
    $rawPhone = $_SESSION['phone'] ?? '';
    $phone = str_replace([' ', '+'], '', $rawPhone);

    if ($phone === '') {
        return null;
    }

    if (substr($phone, 0, 1) === '0') {
        $phone254 = '254' . substr($phone, 1);
    } elseif (substr($phone, 0, 3) === '254') {
        $phone254 = $phone;
    } else {
        $phone254 = '254' . ltrim($phone, '0');
    }

    $phone0 = '0' . substr($phone254, 3);

    $stmt = $conn->prepare("SELECT id, fullname, membership, payment_status FROM users WHERE phone = ? OR phone = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ss", $phone254, $phone0);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function formatRelativeTime(string $datetime): string {
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return date('Y-m-d H:i', $timestamp);
}

ensureUserBookTablesExist($conn);

if(!isset($_SESSION['fullname'])){ 
    header("Location: ../../frontend/login_view.php");
    exit();
}

$user = resolveCurrentUser($conn);
$userId = isset($user['id']) ? (int) $user['id'] : 0;

$user_membership = $user['membership'] ?? ($_SESSION['membership'] ?? "Basic");
$payment_status = $user['payment_status'] ?? ($_SESSION['payment_status'] ?? ($user_membership === "Basic" ? "Paid" : "Pending")); 

$levels = ['Basic' => 0, 'Premium' => 1, 'VIP' => 2];

$bookmarksByBookId = [];
$bookmarkBooks = [];
$activityRows = [];
$allowedActivityFilters = ['all', 'view', 'bookmark_add', 'bookmark_remove'];
$activityFilter = $_GET['activity_filter'] ?? 'all';
if (!in_array($activityFilter, $allowedActivityFilters, true)) {
    $activityFilter = 'all';
}

if ($userId > 0) {
    $bookmarkStmt = $conn->prepare("SELECT b.id, b.title, b.membership_required, ub.created_at AS bookmarked_at
        FROM user_bookmarks ub
        INNER JOIN books b ON b.id = ub.book_id
        WHERE ub.user_id = ?
        ORDER BY ub.created_at DESC");

    if ($bookmarkStmt) {
        $bookmarkStmt->bind_param("i", $userId);
        $bookmarkStmt->execute();
        $bookmarkResult = $bookmarkStmt->get_result();

        while ($bookmarkResult && $row = $bookmarkResult->fetch_assoc()) {
            $bookmarksByBookId[(int) $row['id']] = true;
            $bookmarkBooks[] = $row;
        }
    }

    $activitySql = "SELECT b.title, uba.action, uba.created_at
        FROM user_book_activity uba
        INNER JOIN books b ON b.id = uba.book_id
        WHERE uba.user_id = ?";

    if ($activityFilter !== 'all') {
        $activitySql .= " AND uba.action = ?";
    }

    $activitySql .= " ORDER BY uba.created_at DESC LIMIT 10";

    $activityStmt = $conn->prepare($activitySql);

    if ($activityStmt) {
        if ($activityFilter === 'all') {
            $activityStmt->bind_param("i", $userId);
        } else {
            $activityStmt->bind_param("is", $userId, $activityFilter);
        }
        $activityStmt->execute();
        $activityResult = $activityStmt->get_result();

        while ($activityResult && $row = $activityResult->fetch_assoc()) {
            $activityRows[] = $row;
        }
    }
}

$toast = $_GET['toast'] ?? '';
$toastMap = [
    'bookmark_added' => 'Book added to your bookmarks.',
    'bookmark_removed' => 'Book removed from your bookmarks.',
    'bookmark_error' => 'Could not update bookmark. Please try again.',
    'book_not_found' => 'Book not found.',
    'book_forbidden' => 'You do not have access to that book.',
    'activity_error' => 'Could not open the book right now. Please try again.'
];
$toastMessage = $toastMap[$toast] ?? '';
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

        .btn-bookmark {
            background: White;
            color: SlateBlue;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid SlateBlue;
            font-weight: bold;
            width: 100%;
            box-sizing: border-box;
            margin-top: 10px;
            cursor: pointer;
        }
        .btn-bookmark.active {
            background: SlateBlue;
            color: White;
        }

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

        .info-panels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
            gap: 20px;
            margin-top: 28px;
        }

        .panel {
            background: White;
            padding: 18px;
            border-radius: 12px;
            border: 1px solid LightGray;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .panel h3 {
            margin-top: 0;
            color: MidnightBlue;

        .activity-filter {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .activity-filter select {
            width: auto;
            min-width: 220px;
            padding: 9px 11px;
        }

        .btn-small {
            border: none;
            background: RoyalBlue;
            color: White;
            padding: 9px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        }

        .panel ul {
            margin: 0;
            padding-left: 18px;
            color: DimGray;
        }

        .panel li {
            margin-bottom: 8px;
        }

        .toast {
            background: AliceBlue;
            border-left: 4px solid RoyalBlue;
            color: MidnightBlue;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-weight: 600;
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

    <?php if ($toastMessage !== ''): ?>
        <div class="toast"><?php echo htmlspecialchars($toastMessage); ?></div>
    <?php endif; ?>

    <div class="info-panels">
        <div class="panel">
            <h3>Your Bookmarks</h3>
            <?php if (count($bookmarkBooks) === 0): ?>
                <p>You have no bookmarks yet.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($bookmarkBooks as $bookmark): ?>
                        <li>
                            <?php echo htmlspecialchars($bookmark['title']); ?>
                            (<?php echo htmlspecialchars($bookmark['membership_required']); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="panel">
            <h3>Recent Activity</h3>
            <form class="activity-filter" method="GET">
                <label for="activity_filter">Filter:</label>
                <select id="activity_filter" name="activity_filter">
                    <option value="all" <?php echo ($activityFilter === 'all') ? 'selected' : ''; ?>>All Actions</option>
                    <option value="view" <?php echo ($activityFilter === 'view') ? 'selected' : ''; ?>>Book Views</option>
                    <option value="bookmark_add" <?php echo ($activityFilter === 'bookmark_add') ? 'selected' : ''; ?>>Bookmarks Added</option>
                    <option value="bookmark_remove" <?php echo ($activityFilter === 'bookmark_remove') ? 'selected' : ''; ?>>Bookmarks Removed</option>
                </select>
                <button class="btn-small" type="submit">Apply</button>
            </form>
            <?php if (count($activityRows) === 0): ?>
                <p>No activity yet. Open a book to start your reading history.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($activityRows as $activity): ?>
                        <li>
                            <?php echo htmlspecialchars($activity['title']); ?> -
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['action']))); ?>
                            (<?php echo htmlspecialchars(formatRelativeTime($activity['created_at'])); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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
                $bookId = (int) $row['id'];
                $isBookmarked = isset($bookmarksByBookId[$bookId]);
                echo "<div class='book-card'>";
                echo "<div>";
                echo "<h3>" . htmlspecialchars($row["title"]) . "</h3>";
                echo "<p>" . htmlspecialchars($row["description"]) . "</p>";
                echo "</div>";
                echo "<a href='read_book.php?id=" . $bookId . "' class='btn-read'>Read Now</a>";
                echo "<form method='POST' action='bookmark.php'>";
                echo "<input type='hidden' name='book_id' value='" . $bookId . "'>";
                echo "<button type='submit' class='btn-bookmark " . ($isBookmarked ? "active" : "") . "'>" . ($isBookmarked ? "Remove Bookmark" : "Add Bookmark") . "</button>";
                echo "</form>";
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