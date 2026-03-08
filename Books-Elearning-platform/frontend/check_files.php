<?php
// Define the expected files based on your library
$expected_files = [
    'healthy_living.pdf',
    'african_recipes.pdf',
    'public_speaking.pdf',
    'mindset_mastery.pdf',
    'python_basics.pdf',
    'financial_freedom.pdf',
    'real_estate_101.pdf',
    'digital_marketing.pdf'
];

$upload_dir = 'uploads/';

// Logic to count status for the Summary UX
$found_count = 0;
$missing_count = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health: File Integrity</title>
    <style>
        body { 
            font-family: "Segoe UI", Arial, sans-serif; 
            padding: 40px; 
            background: GhostWhite; 
            color: DarkSlateGray;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        .card { 
            background: White; 
            padding: 30px; 
            border-radius: 15px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
            border: 1px solid LightGray;
        }
        h2 { color: MidnightBlue; margin-top: 0; }
        
        /* Summary Bar UX */
        .summary-bar {
            display: flex;
            gap: 15px;
            margin: 20px 0;
        }
        .stat-pill {
            flex: 1;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            font-weight: bold;
        }
        .stat-found { background: Honeydew; color: ForestGreen; border: 1px solid LightGreen; }
        .stat-missing { background: MistyRose; color: FireBrick; border: 1px solid LightCoral; }

        /* List Items */
        .status-list { margin-top: 20px; }
        .status-item { 
            padding: 12px 15px; 
            margin: 8px 0; 
            border-radius: 8px; 
            font-size: 0.95rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid Transparent;
        }
        .item-found { 
            background: White; 
            color: DarkSlateGray; 
            border-color: MintCream;
            border-left: 4px solid MediumSeaGreen;
        }
        .item-missing { 
            background: White; 
            color: FireBrick; 
            border-color: SeaShell;
            border-left: 4px solid Crimson;
        }
        .file-size { color: SlateGray; font-size: 0.85rem; }

        /* Professional Button */
        .btn-scan {
            background: SlateBlue;
            color: White;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            width: 100%;
            margin-top: 20px;
        }
        .btn-scan:hover {
            background: MidnightBlue;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <h2>Library File Integrity Check</h2>
        <p style="color: SlateGray;">Scanning: <strong><?php echo $upload_dir; ?></strong></p>
        
        <?php
        $results_html = "";
        if (!is_dir($upload_dir)) {
            echo "<div class='stat-pill stat-missing'>CRITICAL: Directory '$upload_dir' not found.</div>";
        } else {
            foreach ($expected_files as $file) {
                $path = $upload_dir . $file;
                if (file_exists($path)) {
                    $found_count++;
                    $size = round(filesize($path) / 1024, 2);
                    $results_html .= "<div class='status-item item-found'>
                                        <span>✅ $file</span>
                                        <span class='file-size'>$size KB</span>
                                      </div>";
                } else {
                    $missing_count++;
                    $results_html .= "<div class='status-item item-missing'>
                                        <span>❌ $file</span>
                                        <span style='font-weight: bold;'>Missing</span>
                                      </div>";
                }
            }
        }
        ?>

        <div class="summary-bar">
            <div class="stat-pill stat-found">
                <small>AVAILABLE</small><br><?php echo $found_count; ?> Files
            </div>
            <div class="stat-pill stat-missing">
                <small>ACTION REQUIRED</small><br><?php echo $missing_count; ?> Missing
            </div>
        </div>

        <div class="status-list">
            <?php echo $results_html; ?>
        </div>
        
        <button class="btn-scan" onclick="window.location.reload()">Refresh Integrity Check</button>
    </div>
</div>

</body>
</html>