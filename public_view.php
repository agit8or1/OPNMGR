<?php
// Public screenshot viewer - no authentication required
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Page Screenshots</title>
    <style>
        body { 
            background: #1a1f2e; 
            color: #fff; 
            font-family: Arial, sans-serif;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 { color: #8ab4f8; }
        img { 
            max-width: 100%; 
            border: 3px solid #8ab4f8;
            margin: 20px 0;
            display: block;
        }
        .section {
            margin: 40px 0;
            padding: 20px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
        }
        pre {
            color: #8ab4f8;
            background: #000;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <h1>🔍 Edit Page Label Investigation</h1>
    
    <div class="section">
        <h2>Raw Screenshot (Automated Browser)</h2>
        <p>This is what an automated Chrome browser sees when loading the edit page:</p>
        <?php
        $img1 = '/var/www/opnsense/public_screenshots/edit_page_screenshot.png';
        if (file_exists($img1)) {
            $data1 = base64_encode(file_get_contents($img1));
            echo '<img src="data:image/png;base64,' . $data1 . '" alt="Edit page raw">';
        } else {
            echo '<p style="color: red;">Image not found</p>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>Annotated Screenshot</h2>
        <p>Same screenshot with red boxes showing where the 4 labels are located:</p>
        <?php
        $img2 = '/var/www/opnsense/public_screenshots/screenshot_annotated.png';
        if (file_exists($img2)) {
            $data2 = base64_encode(file_get_contents($img2));
            echo '<img src="data:image/png;base64,' . $data2 . '" alt="Annotated">';
        } else {
            echo '<p style="color: red;">Image not found</p>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>Selenium Inspection Report</h2>
        <pre>Found 4 labels with class 'form-label'

Label 1 (for='hostname'): 'Firewall Hostname *'
  Display: block, Visibility: visible, Opacity: 1
  Color: rgb(255, 255, 255), Font: 16px/500
  Height: 24px, Margin-bottom: 8px

Label 2 (for='customer_group'): 'Customer Group'
  Display: block, Visibility: visible, Opacity: 1
  Color: rgb(255, 255, 255), Font: 16px/500
  Height: 24px, Margin-bottom: 8px

Label 3 (for='notes'): 'Notes'
  Display: block, Visibility: visible, Opacity: 1
  Color: rgb(255, 255, 255), Font: 16px/500
  Height: 24px, Margin-bottom: 8px

Label 4 (for='tags'): 'Tags'
  Display: block, Visibility: visible, Opacity: 1
  Color: rgb(255, 255, 255), Font: 16px/500
  Height: 24px, Margin-bottom: 8px</pre>
    </div>
</body>
</html>
