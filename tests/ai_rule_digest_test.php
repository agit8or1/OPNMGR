<?php
/**
 * The rules must reach the model, whichever section holds them.
 *
 * Run with: php tests/ai_rule_digest_test.php
 *
 * OPNsense keeps firewall rules in two places. The legacy <filter> section is
 * the one named "filter", and on a current installation it is frequently
 * `<filter/>` - self-closing and empty. The rules actually compiled into pf live
 * in <OPNsense><Firewall><Filter><rules>, further down, under a heading that
 * does not announce itself.
 *
 * One installation is exactly that case: `<filter/>`, and 61 MVC rules including
 *
 *     interface=wan  source=any  destination=(self)  port=443  action=pass
 *
 * which is the firewall's own web GUI, open to the internet, and confirmed in
 * the compiled ruleset as
 *
 *     pass in quick on ix0 ... from any to (self) port = https
 *
 * A scan reading only <filter/> sees a firewall with no rules at all. It can
 * neither find that nor rule it out, and any statement it makes about "the WAN
 * policy" is then guesswork.
 */

require_once dirname(__DIR__) . '/inc/ai_redaction.php';

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

// A configuration shaped like the real one: empty legacy section, rules in MVC.
$xml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system><webgui><protocol>https</protocol><port>443</port></webgui></system>
  <filter/>
  <OPNsense>
    <Firewall>
      <Filter>
        <rules>
          <rule uuid="a"><interface>wan</interface><action>pass</action>
            <protocol>TCP</protocol><source_net>any</source_net>
            <destination_net>(self)</destination_net><destination_port>443</destination_port>
            <enabled>1</enabled><description>HTTPS Allow</description></rule>
          <rule uuid="b"><interface>wan</interface><action>block</action>
            <protocol>any</protocol><source_net>GeoIP_BLOCK</source_net>
            <destination_net>any</destination_net>
            <enabled>0</enabled><description>GeoIP Block</description></rule>
          <rule uuid="c"><interface>wan</interface><action>pass</action>
            <protocol>TCP</protocol><source_net>any</source_net>
            <destination_net>192.168.22.106/32</destination_net><destination_port>443</destination_port>
            <enabled>1</enabled><description>a host behind it</description></rule>
        </rules>
      </Filter>
    </Firewall>
  </OPNsense>
</opnsense>
XML;

$digest = ai_rule_digest($xml);

check('a digest is produced', $digest !== '');
check('rules in the MVC section are found',
    str_contains($digest, 'HTTPS Allow'),
    'this is the section an empty <filter/> hides');
check('an empty legacy section does not end the search',
    substr_count($digest, 'wan') >= 3,
    '<filter/> is self-closing on a current installation');
check('the digest says the empty element is not the whole story',
    str_contains($digest, 'does NOT mean the firewall has no rules'),
    'a model that stops at <filter/> concludes there is no policy');
check('the digest is named authoritative',
    str_contains($digest, 'Treat THIS table as the authoritative rule set'));

check('rules targeting the firewall itself are counted',
    (bool) preg_match('/1 enabled pass rule\(s\) target the firewall itself/', $digest),
    'one of the three is (self); the others are a host behind it and a disabled block');
check('(self) is explained rather than left as jargon',
    str_contains($digest, 'means the firewall itself'));
check('a disabled rule is marked disabled',
    (bool) preg_match('/GeoIP Block/', $digest) && (bool) preg_match('/no\s+GeoIP Block/', $digest),
    'a disabled rule is evidence about intent, so it is listed, not dropped');
check('a disabled rule is not counted as exposure',
    !preg_match('/2 enabled pass rule/', $digest));

// Legacy rules must still be read where they do exist.
$legacy = <<<XML
<?xml version="1.0"?>
<opnsense>
  <filter>
    <rule><interface>wan</interface><type>pass</type><protocol>tcp</protocol>
      <source><any/></source>
      <destination><any/><port>22</port></destination>
      <descr>legacy ssh</descr></rule>
  </filter>
</opnsense>
XML;
$legacyDigest = ai_rule_digest($legacy);
check('the legacy section is still read', str_contains($legacyDigest, 'legacy ssh'));
check('legacy and current rules are distinguishable',
    str_contains($legacyDigest, 'legacy') && str_contains($digest, 'mvc'),
    'so a reader can tell which section a rule came from');

// A configuration with neither must say so, rather than implying safety.
$none = ai_rule_digest('<?xml version="1.0"?><opnsense><filter/></opnsense>');
check('no rules at all is stated explicitly',
    str_contains($none, 'none found in either'),
    'silence would read as "nothing to report"');

// Malformed input must not throw.
check('unparseable input returns empty rather than raising',
    ai_rule_digest('<opnsense><filter>') === '');

// --- the digest must not become a route around redaction ----------------------

$scanRaw = (string) @file_get_contents($root . '/api/ai_scan.php');

// Code only: the fix quotes the instruction it replaced, so a "must not contain"
// assertion against the raw file fails on the explanation rather than the code.
$scan = implode("\n", array_filter(explode("\n", $scanRaw), static function (string $l): bool {
    $t = ltrim($l);
    return $t !== '' && !str_starts_with($t, '//') && !str_starts_with($t, '*')
        && !str_starts_with($t, '/*') && !str_starts_with($t, '#');
}));
check('the digest is built from the redacted document',
    str_contains($scan, "ai_rule_digest(\$redacted['xml'])"),
    'building it from the raw config would bypass ai_redact_config entirely');
check('it is not built from the raw config',
    !str_contains($scan, "ai_rule_digest(\$config_data"));
check('it is placed before the XML it summarises',
    (bool) preg_match('/\$prompt \.= \$digest;[\s\S]{0,400}\$prompt \.= \$redacted\[.xml.\]/', $scan));

// --- and the prompt must not forbid reporting what it finds -------------------
//
// "NEVER FLAG NAT RULES" was absolute. A port forward is how an administrative
// interface usually reaches the internet, so the instruction that was meant to
// stop noise about published services also suppressed the finding most worth
// having.

check('ordinary service forwards are still not flagged',
    str_contains($scan, 'these are intentional publishing, do not flag them'));
check('management interface exposure is explicitly flaggable',
    str_contains($scan, 'DO flag a forward or rule that exposes an ADMINISTRATIVE'));
check('the blanket prohibition is gone',
    !str_contains($scan, 'NEVER FLAG NAT RULES'),
    'it covered the exposure of management interfaces too');

// --- an exemption with a condition must not be written as absolute ------------
//
// "ABSOLUTE PROHIBITION - DO NOT CREATE FINDINGS FOR" headed a list whose
// entries carried conditions: "SSH root login ... THIS IS SECURE when SSH rules
// restrict source IPs". The heading is unconditional and the entry is not, and
// the heading won - root login was suppressed whether or not the condition held.
// The condition was also unverifiable until the rule digest existed, since the
// model saw an empty <filter/>. A firewall with permitrootlogin=1 and port 22
// open to any source scanned clean on the most dangerous combination there is.

check('the heading no longer claims to be absolute',
    !str_contains($scan, 'ABSOLUTE PROHIBITION'),
    'an unconditional heading over conditional entries is the defect');
check('the exemptions are named conditional',
    str_contains($scan, 'CONDITIONAL EXEMPTIONS'));
check('the condition must be verified rather than assumed',
    str_contains($scan, 'Verify each condition against the rule table above; do not assume it'));
check('root login is a finding when SSH is open to any source',
    str_contains($scan, 'root login IS a finding - report it as CRITICAL'),
    'this is the case the old wording silenced');
check('an unsettled condition is stated rather than resolved in favour of the exemption',
    str_contains($scan, 'say so in the finding rather than assuming the exemption'));
check('what is NOT exempt is stated outside the exemption list',
    (bool) preg_match('/NOT exempt, and to be judged on their own merits[\s\S]{0,200}passwordauth/', $scan),
    'an entry inside an exemption list saying "this is not exempt" reads as one');
check('password authentication is called out',
    str_contains($scan, 'SSH password authentication'));

// --- a citation must be readable ---------------------------------------------
//
// affected_rules came back accurate and unreadable: pasted XML fragments and
// lines repeating the column headings of the rule table the model was given.

check('a citation format is specified',
    str_contains($scan, '<interface> | <action> <proto> <source> -> <destination>:<port>'));
check('XML fragments are explicitly refused',
    str_contains($scan, 'Do NOT paste XML fragments'));
check('citations are bounded',
    str_contains($scan, 'Cite at most 4 entries per finding'),
    'a finding citing twenty rules establishes nothing');

$fmt = ai_format_affected_rules(
    "\u{2022} \"SOURCE INTERFACE ACTION PROTO FROM any TO (self) PORT 443 DESCRIPTION HTTPS Allow\"\n"
    . "\u{2022} \"<rule><interface>wan</interface><destination_port>443</destination_port></rule>\"\n"
    . "wan | pass TCP any -> (self):443 | HTTPS Allow\n"
    . "N/A\n");

check('each citation becomes one entry', count($fmt) === 3,
    'N/A is not a citation and is dropped');
check('bullets and quoting are stripped',
    !str_contains($fmt[0]['text'], '"') && !str_contains($fmt[0]['text'], "\u{2022}"));
check('repeated column headings are removed',
    !str_contains($fmt[0]['text'], 'INTERFACE') && str_contains($fmt[0]['text'], 'HTTPS Allow'),
    'the model echoed the table header back as if it were data');
check('an XML fragment is reduced to its fields rather than dropped',
    str_contains($fmt[1]['text'], 'interface=wan') && $fmt[1]['parsed'],
    'a citation that cannot be parsed is still evidence; hiding it would make an old report look unsupported');
check('a citation already in the requested shape is left alone',
    $fmt[2]['text'] === 'wan | pass TCP any -> (self):443 | HTTPS Allow');
check('reports stored before the format change still render',
    ai_format_affected_rules('<webgui><protocol>https</protocol></webgui>')[0]['text'] === 'protocol=https');

// --- a report opens where you are --------------------------------------------

$details = (string) @file_get_contents($root . '/firewall_details.php');
check('finishing a scan navigates in place',
    str_contains($details, "window.location.href = `/ai_reports.php?report_id=\${data.report_id}`"),
    'a popup blocker swallowing window.open made a finished scan look like it had done nothing');
check('no AI report is opened in a new tab',
    !preg_match('/ai_reports\.php[^`\'"]*`?[^>]{0,80}target="_blank"/', $details)
    && !str_contains($details, "window.open(`/ai_reports.php"));

// --- includes must not depend on the working directory ------------------------
//
// api/ai_scan.php resolved one of its includes relative to the CWD rather than
// to its own location. Under the web SAPI the CWD happens to be api/, so it
// worked; from the CLI it did not, and scripts/run_auto_scans.php died on
//
//   require_once(../inc/agent_version.php): Failed to open stream
//
// every time it ran. Its eleven neighbours in the same file all use __DIR__.

check('no include in api/ resolves against the working directory',
    !preg_match("/require(_once)? '\\.\\.\\//", $scanRaw),
    'the CWD is api/ under the web server and anything at all from the CLI');
check('the agent version include is anchored to the file',
    str_contains($scanRaw, "require_once __DIR__ . '/../inc/agent_version.php'"));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
