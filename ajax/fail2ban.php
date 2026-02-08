<?php
require_once __DIR__ . '/../inc/auth.php';
requireLogin();
header('Content-Type: application/json');

 = trim(['jail'] ?? '');
if ( === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no jail']);
    exit;
}

 = '/usr/local/sbin/opnmgr-fail2ban-wrapper';
 = trim(shell_exec('command -v fail2ban-client || true'));
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'fail2ban-client not available']);
    exit;
}

// prefer wrapper if present
 = is_executable();

 = ['REQUEST_METHOD'] ?? 'GET';
if ( === 'POST') {
     = ['act'] ?? '';
     = trim(['ip'] ?? '');

    if ( === 'ban') {
            echo json_encode(['ok' => false, 'error' => 'invalid ip']);
            exit;
        }
        if () {
             = sprintf('sudo %s %s %s %s', escapeshellcmd(), 'ban', escapeshellarg(), escapeshellarg());
        } else {
             = sprintf('%s set %s banip %s', escapeshellcmd(), escapeshellarg(), escapeshellarg());
             = 'sudo ' . ;
        }
         = shell_exec( . ' 2>&1');
        echo json_encode(['ok' => true, 'cmd' => , 'out' => substr(, 0, 2000)]);
        exit;
    } elseif ( === 'unban') {
            echo json_encode(['ok' => false, 'error' => 'invalid ip']);
            exit;
        }
        if () {
             = sprintf('sudo %s %s %s %s', escapeshellcmd(), 'unban', escapeshellarg(), escapeshellarg());
        } else {
             = sprintf('%s set %s unbanip %s', escapeshellcmd(), escapeshellarg(), escapeshellarg());
             = 'sudo ' . ;
        }
         = shell_exec( . ' 2>&1');
        echo json_encode(['ok' => true, 'cmd' => , 'out' => substr(, 0, 2000)]);
        exit;
    } elseif ( === 'reload') {
        if () {
             = sprintf('sudo %s %s', escapeshellcmd(), 'reload');
        } else {
             = escapeshellcmd() . ' reload';
             = 'sudo ' . ;
        }
         = shell_exec( . ' 2>&1');
        echo json_encode(['ok' => true, 'cmd' => , 'out' => substr(, 0, 2000)]);
        exit;
    }
}

// Default: status
if () {
     = sprintf('sudo %s %s %s', escapeshellcmd(), 'status', escapeshellarg());
} else {
     = escapeshellcmd() . ' status ' . escapeshellarg();
     = 'sudo ' . ;
}
 = shell_exec( . ' 2>&1');

// parse IPs
 = [];
if (preg_match('/Banned IP list:\s*(.*)$/mi', , )) {
     = array_map('trim', array_filter(array_map('trim', preg_split('/[,
]/', [1]))));
}
if (empty() && preg_match('/Currently banned:\s*(.*)$/mi', , )) {
     = array_map('trim', array_filter(array_map('trim', preg_split('/[,
]/', [1]))));
}
if (empty()) {
    if (preg_match_all('/(\d{1,3}(?:\.\d{1,3}){3})/', , ))  = array_values(array_unique([1]));
}

echo json_encode(['ok' => true, 'raw' => substr(, 0, 2000), 'ips' => ]);
