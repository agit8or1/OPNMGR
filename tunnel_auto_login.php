<?php
/**
 * Auto-Login for Tunnel Proxy
 * Handles OPNsense's dynamic CSRF tokens and logs in automatically
 */
require_once __DIR__ . '/inc/bootstrap.php';

// Get session ID from URL
$session_id = (int)($_GET['session'] ?? 0);

if (!$session_id) {
    die('Missing session ID');
}

// Get session from database
$stmt = db()->prepare("
    SELECT s.*, f.hostname, f.api_key as username, f.api_secret as password
    FROM ssh_access_sessions s
    JOIN firewalls f ON s.firewall_id = f.id
    WHERE s.id = ?
");
$stmt->execute([$session_id]);
$session = $stmt->fetch();

if (!$session || $session['status'] !== 'active') {
    die('Invalid or inactive session');
}

$tunnel_port = $session['tunnel_port'];
$username = $session['username']; // Should be 'root'
$password = $session['password']; // Root password

?>
<!DOCTYPE html>
<html>
<head>
    <title>Logging in...</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #1a1a1a;
            color: #fff;
        }
        .login-box {
            text-align: center;
            padding: 40px;
            background: #2a2a2a;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        .spinner {
            border: 4px solid #333;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        #status {
            margin-top: 20px;
            color: #888;
        }
        .error {
            color: #ff4444;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Logging in to <?php echo htmlspecialchars($session['hostname']); ?></h2>
        <div class="spinner"></div>
        <div id="status">Fetching login form...</div>
    </div>

    <script>
        const sessionId = <?php echo $session_id; ?>;
        const tunnelPort = <?php echo $tunnel_port; ?>;
        const username = <?php echo json_encode($username); ?>;
        const password = <?php echo json_encode($password); ?>;
        
        async function autoLogin() {
            try {
                // Step 1: Fetch the login page to get CSRF token
                updateStatus('Fetching login form...');
                const loginPageUrl = `/tunnel_proxy.php?session=${sessionId}&path=index.php&fresh=1`;
                const loginPageResponse = await fetch(loginPageUrl);
                const loginPageHtml = await loginPageResponse.text();
                
                // Step 2: Parse the HTML to extract CSRF token
                updateStatus('Extracting CSRF token...');
                const parser = new DOMParser();
                const doc = parser.parseFromString(loginPageHtml, 'text/html');
                
                // Find the hidden CSRF field (it has a random name)
                const form = doc.querySelector('form#iform');
                if (!form) {
                    throw new Error('Login form not found');
                }
                
                const hiddenInput = form.querySelector('input[type="hidden"]');
                if (!hiddenInput) {
                    throw new Error('CSRF token not found');
                }
                
                const csrfName = hiddenInput.getAttribute('name');
                const csrfValue = hiddenInput.getAttribute('value');
                
                console.log('CSRF Token:', csrfName, '=', csrfValue);
                
                // Step 3: Submit login form with CSRF token
                updateStatus('Submitting login...');
                const formData = new FormData();
                formData.append('usernamefld', username);
                formData.append('passwordfld', password);
                formData.append(csrfName, csrfValue);
                formData.append('login', 'Login');
                
                const loginResponse = await fetch(`/tunnel_proxy.php?session=${sessionId}&path=index.php`, {
                    method: 'POST',
                    body: formData,
                    redirect: 'manual' // Don't auto-follow redirects
                });
                
                // Step 4: Check if login was successful
                const responseText = await loginResponse.text();
                
                // If we get redirected or see the dashboard, login was successful
                if (loginResponse.headers.get('location') || responseText.includes('/ui/core/dashboard')) {
                    updateStatus('Login successful! Redirecting...');
                    setTimeout(() => {
                        window.location.href = `/tunnel_proxy.php?session=${sessionId}&path=/ui/core/dashboard`;
                    }, 500);
                } else if (responseText.includes('class="page-login"')) {
                    // Still on login page = login failed
                    throw new Error('Login failed - invalid credentials or CSRF mismatch');
                } else {
                    // Assume success if not on login page
                    updateStatus('Login successful! Redirecting...');
                    setTimeout(() => {
                        window.location.href = `/tunnel_proxy.php?session=${sessionId}&path=/ui/core/dashboard`;
                    }, 500);
                }
                
            } catch (error) {
                console.error('Auto-login error:', error);
                document.getElementById('status').className = 'error';
                updateStatus('Error: ' + error.message);
            }
        }
        
        function updateStatus(message) {
            document.getElementById('status').textContent = message;
        }
        
        // Start auto-login
        autoLogin();
    </script>
</body>
</html>
