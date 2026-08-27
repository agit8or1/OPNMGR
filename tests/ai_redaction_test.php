<?php
/**
 * OPNMGR AI redaction tests.
 *
 * These guard a data-disclosure boundary: everything here is about what must
 * NOT leave the server.
 *
 * Run with: php tests/ai_redaction_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/ai_redaction.php';

$config = <<<'XML'
<?xml version="1.0"?>
<opnsense>
  <system>
    <hostname>fw-test</hostname>
    <user>
      <name>root</name>
      <password>$2y$10$REALPASSWORDHASHVALUE</password>
      <authorizedkeys>ssh-rsa AAAAB3NzaC1yc2EAAAADAQ</authorizedkeys>
      <otp_seed>JBSWY3DPEHPK3PXP</otp_seed>
    </user>
    <ssh><permitrootlogin>1</permitrootlogin></ssh>
  </system>
  <cert uuid="abc-123">
    <refid>5f2a</refid>
    <descr>Web GUI TLS certificate</descr>
    <crt>LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0t</crt>
    <prv>LS0tLS1CRUdJTiBQUklWQVRFIEtFWS0tLS0t</prv>
  </cert>
  <OPNsense>
    <wireguard>
      <server>
        <name>wg0</name>
        <privkey>8Ht5HLKeojaHtcCJdZlSvOwH7qLstojePJ5xuwy8Ug=</privkey>
        <pubkey>PUBLICKEYVALUEHERE=</pubkey>
        <port>51820</port>
      </server>
    </wireguard>
  </OPNsense>
  <ipsec><phase1><pre-shared-key>SuperSecretPSK123</pre-shared-key></phase1></ipsec>
  <snmpd><rocommunity>public-community-string</rocommunity></snmpd>
  <filter>
    <rule>
      <type>pass</type><interface>wan</interface>
      <descr>Allow HTTPS</descr>
      <destination><port>443</port></destination>
    </rule>
  </filter>
</opnsense>
XML;

// ===========================================================================
T::group('Configuration redaction');

$r = ai_redact_config($config);
T::ok($r['ok'], 'a well-formed configuration is redacted');

$out = $r['xml'];

// The whole point: none of this may survive.
$secrets = [
    '$2y$10$REALPASSWORDHASHVALUE'          => 'password hash',
    'LS0tLS1CRUdJTiBQUklWQVRFIEtFWS0tLS0t'  => 'X.509 private key',
    'LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0t'  => 'certificate body',
    '8Ht5HLKeojaHtcCJdZlSvOwH7qLstojePJ5xuwy8Ug=' => 'WireGuard private key',
    'SuperSecretPSK123'                     => 'IPsec pre-shared key',
    'public-community-string'               => 'SNMP community',
    'JBSWY3DPEHPK3PXP'                      => 'MFA seed',
    'ssh-rsa AAAAB3NzaC1yc2EAAAADAQ'        => 'authorised SSH key',
];
foreach ($secrets as $needle => $what) {
    T::ok(!str_contains($out, $needle), "the {$what} is removed");
}

// Structure and non-secret context must survive, or the analysis is useless.
T::ok(str_contains($out, '<hostname>fw-test</hostname>'), 'the hostname survives');
T::ok(str_contains($out, 'Allow HTTPS'),                  'firewall rule descriptions survive');
T::ok(str_contains($out, '<port>443</port>'),             'rule ports survive');
T::ok(str_contains($out, '<permitrootlogin>1</permitrootlogin>'),
      'security-relevant settings survive, which is the point of the analysis');
T::ok(str_contains($out, '<refid>5f2a</refid>'),          'certificate refid survives');
T::ok(str_contains($out, 'Web GUI TLS certificate'),      'certificate description survives');
T::ok(str_contains($out, '<port>51820</port>'),           'WireGuard port survives');
T::ok(str_contains($out, '[REDACTED]'),                   'redacted elements are marked, not deleted');

T::ok($r['bytes_removed'] > 0, 'the byte count of removed material is reported');
T::ok(count($r['redacted']) > 0, 'a manifest of what was redacted is returned');

// A document that cannot be parsed must NOT fall through to the raw text.
$bad = ai_prepare_config('<opnsense><unclosed>');
T::ok(!$bad['ok'], 'an unparseable configuration is refused');
T::eq('', $bad['xml'], 'and nothing is returned to send');

$prepared = ai_prepare_config($config);
T::ok($prepared['ok'], 'ai_prepare_config succeeds on a valid configuration');
T::ok(!str_contains($prepared['xml'], 'SuperSecretPSK123'), 'and its output is redacted');
T::ok($prepared['summary'] !== '', 'and it reports a summary of what was removed');

T::group('Log redaction');

$log = "Aug 27 10:00:01 fw sshd[1]: Accepted publickey for root\n"
     . "password=hunter2 api_key=sk-abcdef123456\n"
     . "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9\n"
     // Synthetic fixture, not a key: the body is a marker string so a secret
     // scan over this repository does not flag the test that proves keys are
     // stripped.
     . "-----BEGIN PRIVATE KEY-----\nNOT-A-REAL-KEY-TEST-FIXTURE-ONLY\n-----END PRIVATE KEY-----\n";

$lr = ai_redact_text($log);
T::ok(!str_contains($lr['text'], 'hunter2'),        'a password in a log line is removed');
T::ok(!str_contains($lr['text'], 'sk-abcdef123456'),'an API key in a log line is removed');
T::ok(!str_contains($lr['text'], 'BEGIN PRIVATE KEY'), 'a PEM block in a log is removed');
T::ok(!str_contains($lr['text'], 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'), 'a bearer token is removed');
T::ok(str_contains($lr['text'], 'sshd'),            'ordinary log content survives');
T::ok($lr['redactions'] > 0,                        'the number of redactions is reported');

T::group('AI is optional');

$disclosure = ai_disclosure();
T::ok(count($disclosure['sent']) > 0,       'the disclosure lists what is sent');
T::ok(count($disclosure['never_sent']) > 0, 'and what is never sent');
T::ok(in_array('User passwords and password hashes', $disclosure['never_sent'], true),
      'password hashes are declared as never sent');

// AI is opt-in: absent setting means off.
db()->prepare('DELETE FROM settings WHERE name = ?')->execute(['ai_enabled']);
T::ok(!ai_enabled(), 'with no setting at all, AI is off (opt-in, not opt-out)');

db()->prepare('INSERT INTO settings (name,value) VALUES ("ai_enabled","0")')->execute();
T::ok(!ai_enabled(), 'explicitly disabled is off');

db()->prepare('UPDATE settings SET value = "1" WHERE name = "ai_enabled"')->execute();
T::ok(ai_enabled(), 'explicitly enabled is on');

// Restore the installation default.
db()->prepare('UPDATE settings SET value = "0" WHERE name = "ai_enabled"')->execute();

exit(T::summary());
