<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Add or update an AI provider without the key passing through a browser,
 * a shell history, or a chat transcript.
 *
 * The key is read from stdin and never echoed, never logged, and never printed
 * back - the script reports only the provider, the model, and the key's length
 * and last four characters, which is enough to confirm the right thing was
 * pasted and not enough to reconstruct it.
 *
 * Usage:
 *   php scripts/add_ai_provider.php --provider anthropic --model claude-opus-5
 *   php scripts/add_ai_provider.php --provider anthropic --model claude-opus-5 --activate
 *
 * @since 3.69.2
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/secrets.php';

$opts     = getopt('', ['provider:', 'model:', 'activate', 'help']);
$provider = strtolower(trim((string)($opts['provider'] ?? '')));
$model    = trim((string)($opts['model'] ?? ''));

if (isset($opts['help']) || $provider === '' || $model === '') {
    echo "Usage: php scripts/add_ai_provider.php --provider <name> --model <id> [--activate]\n";
    echo "The API key is read from stdin and is never echoed or stored in plaintext.\n";
    exit(isset($opts['help']) ? 0 : 1);
}
if (!preg_match('/^[a-z]+$/', $provider)) {
    fwrite(STDERR, "ERROR: provider must be a lowercase name such as 'anthropic'\n");
    exit(1);
}

// Read without echo when this is a terminal; fall back to a plain read when the
// key is piped in, so the script works unattended too.
if (stream_isatty(STDIN)) {
    fwrite(STDERR, "API key for {$provider} (input hidden): ");
    shell_exec('stty -echo 2>/dev/null');
    $key = trim((string) fgets(STDIN));
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDERR, "\n");
} else {
    $key = trim((string) fgets(STDIN));
}

if ($key === '') {
    fwrite(STDERR, "ERROR: no key supplied\n");
    exit(1);
}

$fingerprint = sprintf('%d chars, ending %s', strlen($key), substr($key, -4));

$stmt = db()->prepare('SELECT id FROM ai_settings WHERE provider = ? LIMIT 1');
$stmt->execute([$provider]);
$existingId = $stmt->fetchColumn();

// A provider added alongside others stays inactive unless asked for, matching
// the settings page: which LLM runs should be a deliberate choice.
$total = (int) db()->query('SELECT COUNT(*) FROM ai_settings')->fetchColumn();
$activate = isset($opts['activate']) || $total === 0;

if ($existingId) {
    db()->prepare('UPDATE ai_settings SET api_key = ?, model = ?, updated_at = NOW() WHERE id = ?')
        ->execute([opnmgr_encrypt($key), $model, $existingId]);
    $id = (int) $existingId;
    printf("Updated %s (%s) - key %s\n", $provider, $model, $fingerprint);
} else {
    db()->prepare('INSERT INTO ai_settings (provider, api_key, model, is_active) VALUES (?, ?, ?, 0)')
        ->execute([$provider, opnmgr_encrypt($key), $model]);
    $id = (int) db()->lastInsertId();
    printf("Added %s (%s) - key %s\n", $provider, $model, $fingerprint);
}

if ($activate) {
    db()->query('UPDATE ai_settings SET is_active = FALSE');
    db()->prepare('UPDATE ai_settings SET is_active = TRUE WHERE id = ?')->execute([$id]);
    printf("Selected %s (%s) as the LLM used for analysis.\n", $provider, $model);
} else {
    echo "Not selected for analysis. Re-run with --activate, or choose it in Settings > AI Analysis.\n";
}

audit_log('ai.provider.configured', [
    'message'  => ($existingId ? 'Updated' : 'Added') . " AI provider {$provider} ({$model})",
    'metadata' => ['provider' => $provider, 'model' => $model, 'activated' => $activate],
]);
