<?php
/**
 * Firewall policy script generation.
 *
 * These scripts edit /conf/config.xml on a live customer firewall, so the
 * generator is tested rather than trusted: the output must be valid shell, must
 * be idempotent, must never remove a rule it did not write, and must never
 * produce an allow-list that excludes this manager.
 *
 * What this cannot test is the effect on a real OPNsense box. See the note at
 * the end of the file.
 *
 * Run with: php tests/firewall_policy_test.php
 *
 * @since 3.28.0
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/firewall_policy.php';

// ---------------------------------------------------------------------------
T::group('Address list parsing');

$r = policy_parse_ip_list('203.0.113.10, 198.51.100.0/24  192.0.2.5;2001:db8::1');
T::eq(['203.0.113.10', '198.51.100.0/24', '192.0.2.5', '2001:db8::1'], $r['ips'],
      'commas, spaces and semicolons all separate, IPv4, CIDR and IPv6 all parse');
T::eq([], $r['rejected'], 'nothing valid is rejected');

$r = policy_parse_ip_list('203.0.113.10, not-an-ip, 10.0.0.1/99, 192.0.2.1');
T::eq(['203.0.113.10', '192.0.2.1'], $r['ips'], 'valid entries survive');
T::eq(['not-an-ip', '10.0.0.1/99'], $r['rejected'],
      'garbage and an out-of-range prefix are reported, not silently dropped');

$r = policy_parse_ip_list('192.0.2.1, 192.0.2.1');
T::eq(['192.0.2.1'], $r['ips'], 'duplicates collapse');
T::eq([], policy_parse_ip_list('   ')['ips'], 'an empty list is empty, not [""]');

// ---------------------------------------------------------------------------
T::group('Web GUI lockdown');

$script = policy_webgui_lockdown_script(['203.0.113.10'], '198.51.100.7', 443, true);

T::ok(str_contains($script, '198.51.100.7'), 'the manager address is in the permit list');
T::ok(str_contains($script, '203.0.113.10'), 'the operator address is in the permit list');
T::ok(strpos($script, '198.51.100.7') < strpos($script, '203.0.113.10'),
      'the manager is permitted first, so a later entry cannot displace it');
T::ok(str_contains($script, '<type>block</type>'), 'a deny rule follows the permits');
T::ok(strpos($script, '<type>block</type>') > strpos($script, '203.0.113.10'),
      'the deny comes after the permits, which is what makes the policy work');
T::ok(str_contains($script, '<interface>wan</interface>'), 'applied on WAN');
T::ok(!str_contains($script, '<interface>lan</interface>'),
      'never applied on LAN - locking an operator out of their own LAN is worse than an open GUI');
T::eq(1, substr_count($script, OPNMGR_WEBGUI_MARKER . ' permit 198.51.100.7'), 'manager permit is marked');
T::eq(1, substr_count($script, OPNMGR_WEBGUI_MARKER . ' permit 203.0.113.10'), 'operator permit is marked');
T::eq(1, substr_count($script, OPNMGR_WEBGUI_MARKER . ' deny'), 'exactly one deny rule, and it is marked');
// Two permits (manager + operator) and one deny, each carrying the marker in
// its descr. Counting <rule> directly would also match the awk program, which
// contains the literal as a pattern.
T::eq(3, substr_count($script, '<descr>' . OPNMGR_WEBGUI_MARKER),
      'every rule the script writes carries the marker in its descr, so removal is exact');

$cidr = policy_webgui_lockdown_script(['198.51.100.0/24'], '198.51.100.7', 443, true);
T::ok(str_contains($cidr, '<network>198.51.100.0/24</network>'), 'a CIDR becomes <network>');
T::ok(str_contains($script, '<address>203.0.113.10</address>'), 'a bare address becomes <address>');

$port = policy_webgui_lockdown_script([], '198.51.100.7', 8443, true);
T::ok(str_contains($port, '<port>8443</port>'), 'the configured GUI port is used, not a hardcoded 443');

$off = policy_webgui_lockdown_script(['203.0.113.10'], '198.51.100.7', 443, false);
T::ok(!str_contains($off, '<type>pass</type>'), 'disabling adds no rules');
T::ok(str_contains($off, 'removing all rules marked'), 'disabling removes the managed rules');

$threw = false;
try {
    policy_webgui_lockdown_script(['203.0.113.10'], '', 443, true);
} catch (RuntimeException $e) {
    $threw = true;
}
T::ok($threw, 'refuses to build a permit list when the manager address is unknown');

// ---------------------------------------------------------------------------
T::group('Outbound lockdown');

$out = policy_outbound_lockdown_script(true);
T::ok(str_contains($out, '<port>53</port>'), 'DNS to the firewall is permitted');
T::ok(str_contains($out, '<protocol>udp</protocol>'), 'DNS over UDP is permitted, not only TCP');
T::ok(str_contains($out, '<port>80</port>') && str_contains($out, '<port>443</port>'),
      'HTTP and HTTPS are permitted');
T::ok(str_contains($out, '<type>block</type>') && str_contains($out, '<log>1</log>'),
      'everything else is blocked and logged, as the UI promises');
T::ok(strpos($out, '<type>block</type>') > strpos($out, '<port>443</port>'),
      'the block follows the permits');
T::ok(!str_contains($out, '<interface>wan</interface>'),
      'the outbound policy does not touch WAN rules');

$outOff = policy_outbound_lockdown_script(false);
T::ok(!str_contains($outOff, '<type>pass</type>'), 'disabling adds no rules');

// ---------------------------------------------------------------------------
T::group('Every script is safe to run');

foreach ([
    'webgui on'   => policy_webgui_lockdown_script(['203.0.113.10'], '198.51.100.7', 443, true),
    'webgui off'  => policy_webgui_lockdown_script([], '198.51.100.7', 443, false),
    'outbound on' => policy_outbound_lockdown_script(true),
    'outbound off'=> policy_outbound_lockdown_script(false),
] as $name => $s) {
    $tmp = tempnam(sys_get_temp_dir(), 'pol') . '.sh';
    file_put_contents($tmp, $s);
    $rc = 0; $o = [];
    exec('sh -n ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
    @unlink($tmp);
    T::eq(0, $rc, "{$name}: generated script is valid POSIX shell" . ($rc ? ': ' . implode(' ', $o) : ''));

    T::ok(str_contains($s, 'cp "$CONFIG" "$BACKUP"'), "{$name}: backs up the configuration first");
    T::ok(str_contains($s, 'index(buf, marker) == 0'), "{$name}: removes only rules carrying the marker");
    T::ok(str_contains($s, 'configctl filter reload'), "{$name}: reloads the filter");
    T::ok(str_contains($s, 'cp "$BACKUP" "$CONFIG"'), "{$name}: restores the backup if the reload fails");
    T::ok(str_contains($s, 'not well-formed XML'), "{$name}: refuses to install unparseable XML");
}

// ---------------------------------------------------------------------------
T::group('Idempotence');

// Applying twice must not accumulate rules: the script strips its own marker
// before inserting, so the second run replaces rather than appends.
$once = policy_webgui_lockdown_script(['203.0.113.10'], '198.51.100.7', 443, true);
T::eq(1, substr_count($once, 'awk -v rules='),
      'rules are inserted in exactly one pass');
T::ok(str_contains($once, '&& !done'),
      'insertion happens at the first </filter> only, so nested sections cannot duplicate it');

// ---------------------------------------------------------------------------
T::group('The script does what it claims, run against a sample configuration');

// A configuration containing a human-written rule and a stale rule from a
// previous run of this policy. Applying must keep the first and replace the
// second, which is the behaviour the whole design depends on.
$sampleConfig = <<<XML
<?xml version="1.0"?>
<opnsense>
  <filter>
    <rule>
      <type>pass</type>
      <interface>wan</interface>
      <descr>Human rule - must survive</descr>
      <destination><port>22</port></destination>
    </rule>
    <rule>
      <type>pass</type>
      <interface>wan</interface>
      <descr>OPNMANAGER-WEBGUI-LOCKDOWN permit 10.9.9.9</descr>
      <destination><port>443</port></destination>
    </rule>
  </filter>
</opnsense>
XML;

$dir = sys_get_temp_dir() . '/opnmgr-policy-' . bin2hex(random_bytes(4));
mkdir($dir);
$cfg  = $dir . '/config.xml';
$stub = $dir . '/reload-stub';
file_put_contents($stub, "#!/bin/sh
exit 0
");
chmod($stub, 0755);

/** Run a generated policy script against the sample config. */
$run = static function (string $script) use ($dir, $cfg, $stub, $sampleConfig): array {
    file_put_contents($cfg, $sampleConfig);
    $path = $dir . '/policy.sh';
    file_put_contents($path, $script);
    $out = [];
    $rc  = 0;
    exec('OPNMGR_CONFIG_PATH=' . escapeshellarg($cfg)
         . ' OPNMGR_RELOAD_CMD=' . escapeshellarg($stub)
         . ' sh ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out), 'xml' => (string) file_get_contents($cfg)];
};

$res = $run(policy_webgui_lockdown_script(['203.0.113.10'], '198.51.100.7', 443, true));
T::eq(0, $res['rc'], 'applying the Web GUI policy succeeds: ' . $res['out']);
T::ok(str_contains($res['xml'], 'Human rule - must survive'),
      'the human-written rule is still there');
T::ok(!str_contains($res['xml'], 'permit 10.9.9.9'),
      'the stale rule from the previous run was removed');
T::ok(str_contains($res['xml'], 'permit 198.51.100.7') && str_contains($res['xml'], 'permit 203.0.113.10'),
      'the new permits were written');
T::ok(@simplexml_load_string($res['xml']) !== false, 'the result is well-formed XML');
T::eq(1, preg_match_all('/<descr>OPNMANAGER-WEBGUI-LOCKDOWN permit 198\.51\.100\.7<\/descr>/', $res['xml']),
      'the manager permit appears exactly once');

// Applying the same policy again must not accumulate rules.
$before = substr_count($res['xml'], '<rule>');
file_put_contents($cfg, $res['xml']);
$path = $dir . '/policy2.sh';
file_put_contents($path, policy_webgui_lockdown_script(['203.0.113.10'], '198.51.100.7', 443, true));
exec('OPNMGR_CONFIG_PATH=' . escapeshellarg($cfg) . ' OPNMGR_RELOAD_CMD=' . escapeshellarg($stub)
     . ' sh ' . escapeshellarg($path) . ' 2>&1', $o2, $rc2);
$after = substr_count((string) file_get_contents($cfg), '<rule>');
T::eq(0, $rc2, 'a second application succeeds');
T::eq($before, $after, 'applying twice produces the same number of rules - it replaces, never appends');

// Disabling must remove every managed rule and keep everything else.
$res = $run(policy_webgui_lockdown_script([], '198.51.100.7', 443, false));
T::eq(0, $res['rc'], 'disabling succeeds');
T::ok(str_contains($res['xml'], 'Human rule - must survive'), 'disabling keeps the human rule');
T::ok(!str_contains($res['xml'], 'OPNMANAGER-WEBGUI-LOCKDOWN'), 'disabling removes every managed rule');

// The outbound policy, on the same configuration.
$res = $run(policy_outbound_lockdown_script(true));
T::eq(0, $res['rc'], 'applying the outbound policy succeeds: ' . $res['out']);
T::ok(@simplexml_load_string($res['xml']) !== false, 'the result is well-formed XML');
T::ok(str_contains($res['xml'], 'Human rule - must survive'), 'the human rule survives');
T::ok(str_contains($res['xml'], 'OPNMANAGER-WEBGUI-LOCKDOWN permit 10.9.9.9'),
      'the outbound policy leaves the other policy\'s rules alone - markers do not collide');

// A configuration the edit would corrupt must not be installed.
file_put_contents($cfg, "<opnsense><filter><rule><descr>x</descr></rule></filter>");  // truncated, no close
$path = $dir . '/policy3.sh';
file_put_contents($path, policy_outbound_lockdown_script(true));
$o3 = []; $rc3 = 0;
exec('OPNMGR_CONFIG_PATH=' . escapeshellarg($cfg) . ' OPNMGR_RELOAD_CMD=' . escapeshellarg($stub)
     . ' sh ' . escapeshellarg($path) . ' 2>&1', $o3, $rc3);
T::eq(1, $rc3, 'a malformed result is refused rather than installed');
T::ok(str_contains(implode(' ', $o3), 'not well-formed'), 'and it says why');

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(T::summary());
