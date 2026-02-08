<!DOCTYPE html>
<html>
<head>
    <title>Browser Label Debugger</title>
    <style>
        body {
            background: #1a1f2e;
            color: #fff;
            font-family: monospace;
            padding: 20px;
        }
        .result {
            background: #000;
            padding: 15px;
            margin: 10px 0;
            border: 1px solid #8ab4f8;
            border-radius: 5px;
        }
        .error { color: #ff6b6b; }
        .success { color: #51cf66; }
        .warning { color: #ffd43b; }
    </style>
</head>
<body>
    <h1>🔍 Browser Label Inspector</h1>
    <p>Open the edit page, then copy and paste this script into your browser console (F12)</p>
    
    <div class="result">
        <h3>Console Script:</h3>
        <pre>// Label Inspector Script
const labels = document.querySelectorAll('label.form-label');
console.log(`Found ${labels.length} labels`);

labels.forEach((label, i) => {
    const computed = window.getComputedStyle(label);
    const rect = label.getBoundingClientRect();
    const forAttr = label.getAttribute('for');
    
    console.log(`\nLabel ${i+1} (for="${forAttr}"):`);
    console.log(`  Text: "${label.textContent.trim()}"`);
    console.log(`  Visible: ${rect.width > 0 && rect.height > 0}`);
    console.log(`  Size: ${rect.width}x${rect.height}`);
    console.log(`  Position: ${rect.x}, ${rect.y}`);
    console.log(`  Color: ${computed.color}`);
    console.log(`  Font: ${computed.fontSize} / ${computed.fontWeight}`);
    console.log(`  Display: ${computed.display}`);
    console.log(`  Visibility: ${computed.visibility}`);
    console.log(`  Opacity: ${computed.opacity}`);
    console.log(`  Height: ${computed.height}`);
    console.log(`  Line-height: ${computed.lineHeight}`);
    
    // Highlight the label
    label.style.border = '3px solid red';
    label.style.background = 'yellow';
    label.style.color = 'black';
});

console.log('\n✅ All labels should now have RED borders and YELLOW backgrounds');
console.log('If you don\'t see them, screenshot the page and send it');</pre>
    </div>
    
    <p>After running the script:</p>
    <ol>
        <li>It will add RED borders and YELLOW backgrounds to all labels</li>
        <li>If you still don't see them, there's a rendering bug</li>
        <li>Check the console output for the label details</li>
        <li>Take a screenshot showing the page after running the script</li>
    </ol>
</body>
</html>
