<?php
/**
 * The reinstall command shown under a firewall belongs to that firewall.
 *
 * Run with: php tests/agent_reinstall_command_test.php
 *
 * When an agent stops, the only recovery is someone at that firewall's console.
 * Twice this week that meant composing the installer invocation from memory, and
 * a generic command has a worse failure than being wrong: a firewall that has
 * lost its /usr/local/etc files enrols as a *new* record, silently abandoning
 * its history, alerts and backups while looking like a success.
 *
 * So the command carries the firewall's own hardware id. The installer seeds it
 * only when the firewall has none - an id already present is never overwritten,
 * which is what makes running this on a healthy firewall safe.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') { echo "      {$detail}\n"; }
}

$root      = dirname(__DIR__);
$page      = (string) @file_get_contents($root . '/firewall_details.php');
$installer = (string) @file_get_contents($root . '/downloads/plugins/install_opnmanager_agent.sh');

check('firewall_details.php is readable', $page !== '');
check('the installer is readable', $installer !== '');

// --- the page -----------------------------------------------------------------

check('a reinstall command is rendered', str_contains($page, 'id="reinstallCmd"'));
check('it is bound to this firewall, not a generic example',
    str_contains($page, "\$firewall['hardware_id']"),
    'a generic command lets a rebuilt firewall enrol as a new record');
check('the hardware id is validated before being shown',
    substr_count($page, "preg_match('/^[0-9a-f]{32}$/', \$reinstall_hwid)") >= 2,
    'a malformed id in a copy-paste command is worse than none');
check('the manager URL comes from configuration',
    str_contains($page, 'opnmgr_server_url()'),
    'never a hardcoded host');
check('a missing manager URL is explained rather than rendered as a broken command',
    str_contains($page, "Set this manager's URL in Settings"));
check('a firewall with no hardware id is warned about',
    str_contains($page, 'no hardware ID recorded'),
    'silently omitting the binding is how a firewall enrols twice');
check('the command is escaped into the page', str_contains($page, 'htmlspecialchars($reinstall_cmd)'));
check('the hostname in the caption is escaped', str_contains($page, "htmlspecialchars(\$firewall['hostname'])"));
check('there is a copy button', str_contains($page, 'copyReinstallCmd'));
check('copying works without a secure context',
    str_contains($page, 'fallbackCopy'),
    'navigator.clipboard is unavailable over plain http, which is where a broken fleet often is');

// --- the installer ------------------------------------------------------------

check('the installer accepts an identity', str_contains($installer, 'OPNMGR_HARDWARE_ID'));
check('it validates the id format',
    str_contains($installer, "grep -Eq '^[0-9a-f]{32}\$'"));
check('it refuses a malformed id rather than writing it',
    (bool) preg_match('/not a 32 character hex id[\s\S]{0,40}exit 1/', $installer));

// The property that makes this safe to run on a healthy firewall.
$block = '';
if (preg_match('/# Identity\.(.*?)\nfi\n/s', $installer, $m)) {
    $block = $m[1];
}
check('the identity block was found', $block !== '');
check('an existing id is never overwritten',
    str_contains($block, 'keeping the id already on this firewall'),
    'reinstalling must not change who a working firewall is');
check('the id is only written when the file is absent',
    (bool) preg_match('/if \[ -f "\$HARDWARE_ID_FILE" \][\s\S]*?else[\s\S]*?echo "\$OPNMGR_HARDWARE_ID" > "\$HARDWARE_ID_FILE"/', $block),
    'the write must sit in the else branch of the existence test');
check('a seeded id file is not world readable', str_contains($block, 'chmod 600 "$HARDWARE_ID_FILE"'));
check('the installer still does not remove the stored credentials',
    !preg_match('/rm -f[^\n]*opnmanager_api_key/', $installer),
    'that is the uninstaller\'s job; a reinstall must keep them');
check('the installer still does not remove the hardware id',
    !preg_match('/rm -f[^\n]*opnmanager_hardware_id/', $installer));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
