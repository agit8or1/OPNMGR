<?php
// Simple documentation download page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset            <div class="file-item">
                <a href="download.php?file=generate_documentation.sh">
                    🛠️ PDF Generation Script
                </a>
                <div class="file-size">
                    <?php echo round(filesize('downloads/generate_documentation.sh') / 1024, 1); ?> KB
                </div>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPNsense Manager - Documentation Downloads</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 40px;
            background: #f8f9fa;
        }
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { 
            color: #2E4B99; 
            border-bottom: 3px solid #2E4B99;
            padding-bottom: 10px;
        }
        .file-list { 
            margin: 20px 0; 
        }
        .file-item { 
            background: #f8f9fa;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #2E4B99;
        }
        .file-item a { 
            color: #2E4B99; 
            text-decoration: none; 
            font-weight: bold;
            font-size: 16px;
        }
        .file-item a:hover { 
            text-decoration: underline; 
        }
        .file-size { 
            color: #666; 
            font-size: 14px;
            margin-top: 5px;
        }
        .file-desc { 
            color: #555; 
            margin-top: 8px;
            line-height: 1.4;
        }
        .highlight { 
            background: #e8f4f8;
            border-left-color: #17a2b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 OPNsense Manager Documentation</h1>
        <p>Download the complete documentation package for the OPNsense Manager platform.</p>
        
        <div class="file-list">
            <div class="file-item highlight">
                <a href="download.php?file=OPNsense_Manager_Complete_Documentation.pdf">
                    📄 Complete Documentation (PDF)
                </a>
                <div class="file-size">
                    <?php echo round(filesize('downloads/OPNsense_Manager_Complete_Documentation.pdf') / 1024, 1); ?> KB
                    | 26 pages
                </div>
                <div class="file-desc">
                    Professional PDF with complete project documentation, menu flowcharts, 
                    and system architecture. Ready for presentations and business use.
                </div>
            </div>
            
                        <div class="file-item">
                <a href="download.php?file=OPNsense_Manager_Complete_Documentation.pdf">
                    � Complete Documentation (PDF)
                </a>
                <div class="file-size">
                    <?php echo round(filesize('downloads/OPNsense_Manager_Complete_Documentation.pdf') / 1024, 1); ?> KB
                </div>
            
            <div class="file-item">
                <a href="download.php?file=Menu_Layout_Flowchart.md">
                    🗺️ Menu Layout & Flowcharts (Markdown)
                </a>
                <div class="file-size">
                    <?php echo round(filesize('downloads/Menu_Layout_Flowchart.md') / 1024, 1); ?> KB
                </div>
                <div class="file-desc">
                    Detailed navigation structure, data flow diagrams, and security 
                    architecture with ASCII art flowcharts.
                </div>
            </div>
            
            <div class="file-item">
                <a href="downloads/generate_documentation.sh" download>
                    ⚙️ PDF Generator Script
                </a>
                <div class="file-size">
                    <?php echo round(filesize('downloads/generate_documentation.sh') / 1024, 1); ?> KB
                </div>
                <div class="file-desc">
                    Bash script to regenerate the professional PDF documentation 
                    using pandoc and custom styling.
                </div>
            </div>
            
            <div class="file-item">
                <a href="downloads/documentation_style.css" download>
                    🎨 Professional CSS Styling
                </a>
                <div class="file-size">
                    <?php echo round(filesize('downloads/documentation_style.css') / 1024, 1); ?> KB
                </div>
                <div class="file-desc">
                    Custom CSS with corporate blue theme, print optimization, 
                    and professional typography.
                </div>
            </div>
        </div>
        
        <div style="margin-top: 40px; padding: 20px; background: #e8f4f8; border-radius: 5px;">
            <h3 style="color: #2E4B99; margin-top: 0;">📦 Complete Package Contents</h3>
            <ul style="line-height: 1.6;">
                <li><strong>Executive Summary</strong> - Project overview and value propositions</li>
                <li><strong>System Architecture</strong> - Technical diagrams and network topology</li>
                <li><strong>Security Features</strong> - Comprehensive security documentation</li>
                <li><strong>Installation Guide</strong> - One-command deployment instructions</li>
                <li><strong>Technical Specs</strong> - APIs, database schema, performance metrics</li>
                <li><strong>Use Cases</strong> - MSP, enterprise, and multi-site scenarios</li>
                <li><strong>Menu Flowcharts</strong> - Complete navigation and data flow diagrams</li>
            </ul>
        </div>
        
        <div style="margin-top: 30px; text-align: center; color: #666;">
            Generated on <?php echo date('F j, Y \a\t g:i A'); ?> | 
            OPNsense Manager v2.0
        </div>
    </div>
</body>
</html>