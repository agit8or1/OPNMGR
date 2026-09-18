<?php
/**
 * A column headed WAN IP must not show the LAN address.
 *
 * Run with: php tests/wan_address_test.php
 *
 * The agent reported wan_ip as
 *
 *     ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print $2}'
 *
 * which is the first IPv4 address ifconfig prints, in kernel interface order -
 * not the WAN interface's address. On a box whose LAN interface sorts ahead of
 * its WAN interface (the LAN interface named before the WAN one) that is the LAN address, so the fleet
 * view showed 192.168.50.1 under WAN IP, and the map geolocated a private
 * address and quietly placed no marker.
 *
 * The right answer was already being sent: wan_interface_stats carries every
 * interface with its address and gateway. The interface holding a default
 * gateway is the one facing the internet.
 */

require_once dirname(__DIR__) . '/inc/firewall_policy.php';

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

$root = dirname(__DIR__);

$stats = static fn(array $ifaces): string => json_encode($ifaces);

// The real shape: LAN listed first, WAN carrying the gateway.
$lanFirst = [
    'wan_ip' => '192.168.50.1',
    'wan_interface_stats' => $stats([
        ['interface' => 'bxe3', 'ip_address' => '192.168.50.1', 'gateway' => ''],
        ['interface' => 'ix0',  'ip_address' => '198.51.100.4', 'gateway' => '198.51.100.1'],
    ]),
];
check('the interface holding the default gateway wins',
    firewall_wan_address($lanFirst) === '198.51.100.4',
    'ifconfig order is not routing order');
check('the misreported address is not used when better data exists',
    firewall_wan_address($lanFirst) !== '192.168.50.1');

// An older agent that sends no stats at all.
check('the reported address is still used when there are no stats',
    firewall_wan_address(['wan_ip' => '203.0.113.9', 'wan_interface_stats' => '']) === '203.0.113.9',
    'an agent predating the field must not lose its address entirely');
check('unparseable stats fall back rather than throw',
    firewall_wan_address(['wan_ip' => '203.0.113.9', 'wan_interface_stats' => 'not json']) === '203.0.113.9');
check('a missing key is survivable',
    firewall_wan_address(['wan_ip' => '203.0.113.9']) === '203.0.113.9');
check('nothing at all yields an empty string, not a warning',
    firewall_wan_address([]) === '');

// No gateway anywhere: prefer a routable address over a private one.
check('a routable address is preferred when no gateway is reported',
    firewall_wan_address(['wan_ip' => '10.0.0.1', 'wan_interface_stats' => $stats([
        ['interface' => 'em0', 'ip_address' => '10.0.0.1', 'gateway' => ''],
        ['interface' => 'em1', 'ip_address' => '198.51.100.7', 'gateway' => ''],
    ])]) === '198.51.100.7');

check('an unconfigured interface is not offered as the answer',
    firewall_wan_address(['wan_ip' => '', 'wan_interface_stats' => $stats([
        ['interface' => 'em0', 'ip_address' => '0.0.0.0', 'gateway' => '192.0.2.1'],
        ['interface' => 'em1', 'ip_address' => '198.51.100.7', 'gateway' => '198.51.100.1'],
    ])]) === '198.51.100.7');

// --- every consumer must use it ----------------------------------------------

foreach ([
    'dashboard.php'              => 'the fleet table',
    'network_tools.php'          => 'the tool target list',
    'firewalls.php'              => 'the fleet list tooltip',
    'api/search.php'             => 'search results',
    'api/get_map_locations.php'  => 'the map',
] as $file => $what) {
    $src = (string) @file_get_contents($root . '/' . $file);
    check("{$what} uses the derived address", str_contains($src, 'firewall_wan_address('), $file);
    check("{$what} includes the helper", str_contains($src, "firewall_policy.php"), $file);
}

// A consumer that derives from stats it never selected gets the fallback and
// silently keeps the bug.
foreach (['dashboard.php', 'network_tools.php', 'inc/search.php', 'api/get_map_locations.php'] as $file) {
    $src = (string) @file_get_contents($root . '/' . $file);
    check("{$file} selects wan_interface_stats",
        str_contains($src, 'wan_interface_stats'),
        'deriving from a column that was never fetched returns the fallback');
}

check('the map geolocates the derived address',
    (bool) preg_match('/\$fw\[.wan_ip.\] = firewall_wan_address\(\$fw\);[\s\S]{0,600}geoip_lookup/', 
        (string) @file_get_contents($root . '/api/get_map_locations.php')),
    'geolocating an RFC1918 address returns nothing and the marker never appears');

// --- and the agent must stop reporting it wrongly ----------------------------

$agent = (string) @file_get_contents(
    $root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh');

check('the agent asks for the default route interface',
    str_contains($agent, "route -n get default") && str_contains($agent, '/interface:/'),
    'the first line of ifconfig is not the WAN');
check('it reads the address from that interface',
    str_contains($agent, 'ifconfig "$wan_if"'));
check('it still reports something when there is no default route',
    (bool) preg_match('/if \[ -z "\$wan_ip" \]; then[\s\S]{0,200}ifconfig \| grep .inet ./', $agent),
    'a box mid-reconfiguration should not report an empty address');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
