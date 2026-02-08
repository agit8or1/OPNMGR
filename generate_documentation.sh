#!/bin/bash

# OPNsense Manager Documentation Generator
# Converts markdown documentation to professional PDF

set -e

echo "=== OPNsense Manager Documentation Generator ==="
echo "Generating professional PDF documentation..."

# Check if pandoc is installed
if ! command -v pandoc &> /dev/null; then
    echo "Installing pandoc for PDF generation..."
    pkg install -y pandoc
fi

# Check if texlive is available for PDF generation
if ! command -v pdflatex &> /dev/null; then
    echo "Installing LaTeX for high-quality PDF output..."
    pkg install -y texlive-base texlive-latex-recommended texlive-fonts-recommended
fi

# Create output directory
mkdir -p /var/www/opnsense/documentation

# Combine all documentation files
cat > /var/www/opnsense/documentation/Complete_Documentation.md << 'EOF'
---
title: "OPNsense Manager"
subtitle: "Comprehensive Management Platform for OPNsense Firewalls"
author: "OPNsense Manager Development Team"
date: "September 2025"
version: "2.0"
geometry: margin=1in
fontsize: 11pt
documentclass: article
header-includes:
  - \usepackage{fancyhdr}
  - \usepackage{graphicx}
  - \usepackage{xcolor}
  - \usepackage{tikz}
  - \pagestyle{fancy}
  - \fancyhead[L]{OPNsense Manager}
  - \fancyhead[R]{Version 2.0}
  - \fancyfoot[C]{\thepage}
  - \definecolor{primary}{RGB}{0,102,204}
  - \definecolor{secondary}{RGB}{108,117,125}
  - \definecolor{success}{RGB}{40,167,69}
  - \definecolor{danger}{RGB}{220,53,69}
---

\newpage
\tableofcontents
\newpage

EOF

# Append the main documentation
cat /var/www/opnsense/OPNsense_Manager_Documentation.md >> /var/www/opnsense/documentation/Complete_Documentation.md

# Add a page break and the flowchart
cat >> /var/www/opnsense/documentation/Complete_Documentation.md << 'EOF'

\newpage

# Appendix A: Menu Layout Flowchart

EOF

# Append the flowchart documentation
cat /var/www/opnsense/Menu_Layout_Flowchart.md >> /var/www/opnsense/documentation/Complete_Documentation.md

# Generate PDF with pandoc
echo "Generating PDF..."
cd /var/www/opnsense/documentation

pandoc Complete_Documentation.md \
    -o "OPNsense_Manager_Complete_Documentation.pdf" \
    --pdf-engine=pdflatex \
    --template=default \
    --toc \
    --toc-depth=3 \
    --number-sections \
    --highlight-style=github \
    --variable geometry:margin=1in \
    --variable fontsize=11pt \
    --variable documentclass=article \
    --variable colorlinks=true \
    --variable linkcolor=primary \
    --variable urlcolor=primary \
    --variable toccolor=primary \
    2>/dev/null || {
        echo "LaTeX not available, generating simpler PDF..."
        pandoc Complete_Documentation.md \
            -o "OPNsense_Manager_Complete_Documentation.pdf" \
            --pdf-engine=wkhtmltopdf \
            --toc \
            --toc-depth=3 \
            --css=/var/www/opnsense/documentation/pdf-style.css \
            2>/dev/null || {
                echo "Creating HTML version as fallback..."
                pandoc Complete_Documentation.md \
                    -o "OPNsense_Manager_Complete_Documentation.html" \
                    --standalone \
                    --toc \
                    --toc-depth=3 \
                    --css=/var/www/opnsense/documentation/pdf-style.css
            }
    }

# Create CSS for better HTML styling
cat > /var/www/opnsense/documentation/pdf-style.css << 'EOF'
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    line-height: 1.6;
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    color: #333;
}

h1, h2, h3, h4, h5, h6 {
    color: #0066cc;
    margin-top: 2em;
    margin-bottom: 1em;
}

h1 {
    border-bottom: 3px solid #0066cc;
    padding-bottom: 0.5em;
}

h2 {
    border-bottom: 1px solid #ccc;
    padding-bottom: 0.3em;
}

pre {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 0.375rem;
    padding: 1rem;
    overflow-x: auto;
    font-family: 'Courier New', monospace;
}

code {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 0.25rem;
    padding: 0.125rem 0.25rem;
    font-family: 'Courier New', monospace;
    font-size: 0.9em;
}

blockquote {
    border-left: 4px solid #0066cc;
    margin: 1em 0;
    padding-left: 1em;
    color: #666;
}

table {
    border-collapse: collapse;
    width: 100%;
    margin: 1em 0;
}

th, td {
    border: 1px solid #ddd;
    padding: 0.5em;
    text-align: left;
}

th {
    background-color: #f8f9fa;
    font-weight: bold;
}

.toc {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 0.375rem;
    padding: 1rem;
    margin: 2rem 0;
}

.toc ul {
    list-style-type: none;
    padding-left: 1em;
}

.toc a {
    text-decoration: none;
    color: #0066cc;
}

.toc a:hover {
    text-decoration: underline;
}

@media print {
    body {
        font-size: 12pt;
    }
    
    h1 {
        page-break-before: always;
    }
    
    pre, code {
        page-break-inside: avoid;
    }
}
EOF

# Create a simple HTML version if PDF generation fails
if [ ! -f "OPNsense_Manager_Complete_Documentation.pdf" ]; then
    echo "Creating enhanced HTML documentation..."
    
    cat > /var/www/opnsense/documentation/OPNsense_Manager_Complete_Documentation.html << 'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPNsense Manager - Complete Documentation</title>
    <link rel="stylesheet" href="pdf-style.css">
    <style>
        .cover {
            text-align: center;
            padding: 4rem 2rem;
            background: linear-gradient(135deg, #0066cc, #004499);
            color: white;
            margin-bottom: 2rem;
        }
        .cover h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            border: none;
            color: white;
        }
        .cover h2 {
            font-size: 1.5rem;
            font-weight: normal;
            border: none;
            color: #e6f3ff;
        }
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #0066cc;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            z-index: 1000;
        }
        @media print {
            .print-button { display: none; }
        }
    </style>
</head>
<body>
    <button class="print-button" onclick="window.print()">Print/Save as PDF</button>
    
    <div class="cover">
        <h1>OPNsense Manager</h1>
        <h2>Comprehensive Management Platform for OPNsense Firewalls</h2>
        <p><strong>Version 2.0</strong> | September 2025 | Production Ready</p>
    </div>
EOF

    # Convert markdown to HTML and append
    pandoc Complete_Documentation.md -t html >> /var/www/opnsense/documentation/OPNsense_Manager_Complete_Documentation.html
    
    cat >> /var/www/opnsense/documentation/OPNsense_Manager_Complete_Documentation.html << 'EOF'
</body>
</html>
EOF
fi

# Set proper permissions
chown -R www-data:www-data /var/www/opnsense/documentation
chmod 644 /var/www/opnsense/documentation/*

echo ""
echo "✅ Documentation generated successfully!"
echo ""
echo "Generated files:"
ls -la /var/www/opnsense/documentation/

if [ -f "/var/www/opnsense/documentation/OPNsense_Manager_Complete_Documentation.pdf" ]; then
    echo ""
    echo "🎉 PDF documentation available at:"
    echo "   /var/www/opnsense/documentation/OPNsense_Manager_Complete_Documentation.pdf"
fi

if [ -f "/var/www/opnsense/documentation/OPNsense_Manager_Complete_Documentation.html" ]; then
    echo ""
    echo "🌐 HTML documentation available at:"
    echo "   http://localhost/documentation/OPNsense_Manager_Complete_Documentation.html"
fi

echo ""
echo "📋 Source files:"
echo "   - Main Documentation: /var/www/opnsense/OPNsense_Manager_Documentation.md"
echo "   - Menu Flowchart: /var/www/opnsense/Menu_Layout_Flowchart.md"
echo "   - Combined Source: /var/www/opnsense/documentation/Complete_Documentation.md"
echo ""
echo "Done!"