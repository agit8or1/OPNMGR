<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Rebuild the in-app User Documentation page.
 *
 * The page was last written in October 2025 and describes a product that has
 * changed a great deal since. It is regenerated from this script rather than
 * edited in the database, so the content has a source, a diff and a review.
 *
 * Screenshots come from docs/images/github/, captured by
 * scripts/capture_demo_screenshots.js against the isolated demo fleet seeded by
 * scripts/demo_fixture.php. They contain no real hostnames, addresses or
 * customers - the alternative is a redaction step that can silently fail.
 *
 * Annotations are markers positioned over the image in percentages rather than
 * pixels burned into it, so they stay aligned when the image is scaled and can
 * be corrected without recapturing.
 *
 * Usage:
 *   php scripts/build_user_documentation.php            show what would change
 *   php scripts/build_user_documentation.php --apply
 *
 * @since 3.61.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$opts  = getopt('', ['apply', 'help']);
$apply = isset($opts['apply']);

if (isset($opts['help'])) {
    echo "Rebuild the in-app User Documentation page.\n";
    echo "  --apply   write it (otherwise report only)\n";
    exit(0);
}

const SHOT_BASE = '/docs/images/github/';

/**
 * An annotated screenshot.
 *
 * @param array<int,array{x:float,y:float,text:string}> $markers
 */
function figure(string $image, string $caption, array $markers): string
{
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);

    $pins = '';
    $legend = '';
    foreach ($markers as $i => $m) {
        $n = $i + 1;
        $pins .= sprintf(
            '<span class="doc-pin" style="left:%.2f%%;top:%.2f%%">%d</span>',
            $m['x'], $m['y'], $n
        );
        $legend .= sprintf('<li><span class="doc-pin-inline">%d</span>%s</li>', $n, $m['text']);
    }

    return '<figure class="doc-fig">'
         . '<div class="doc-fig-frame">'
         . '<img src="' . SHOT_BASE . $h($image) . '" alt="' . $h($caption) . '" loading="lazy">'
         . $pins
         . '</div>'
         . '<figcaption>' . $caption . '</figcaption>'
         . '<ol class="doc-legend">' . $legend . '</ol>'
         . '</figure>';
}

$style = <<<'CSS'
<style>
.doc-fig { margin: 28px 0 34px; }
.doc-fig-frame { position: relative; display: block; border-radius: 8px; overflow: hidden;
    border: 1px solid rgba(128,128,128,0.35); line-height: 0; }
.doc-fig-frame img { width: 100%; height: auto; display: block; }
.doc-pin { position: absolute; transform: translate(-50%, -50%);
    width: 26px; height: 26px; border-radius: 50%;
    background: #e53935; color: #fff; font: 700 13px/26px system-ui, sans-serif;
    text-align: center; box-shadow: 0 0 0 3px rgba(229,57,53,0.30), 0 1px 4px rgba(0,0,0,0.5); }
.doc-fig figcaption { font-size: 0.9rem; opacity: 0.75; margin-top: 10px; line-height: 1.5; }
.doc-legend { list-style: none; padding: 0; margin: 14px 0 0; }
.doc-legend li { position: relative; padding: 0 0 0 34px; margin-bottom: 9px; line-height: 1.55; }
.doc-pin-inline { position: absolute; left: 0; top: 1px;
    width: 22px; height: 22px; border-radius: 50%;
    background: #e53935; color: #fff; font: 700 12px/22px system-ui, sans-serif;
    text-align: center; }
.doc-note { border-left: 4px solid #4fc3f7; background: rgba(79,195,247,0.10);
    padding: 14px 18px; border-radius: 0 6px 6px 0; margin: 20px 0; }
.doc-warn { border-left: 4px solid #ffb300; background: rgba(255,179,0,0.10);
    padding: 14px 18px; border-radius: 0 6px 6px 0; margin: 20px 0; }
.doc-fig code, .doc-note code, .doc-warn code { font-size: 0.86em; }
.doc-cmd { display: block; background: #0d1117; color: #c9d1d9; padding: 12px 14px;
    border-radius: 6px; font-family: ui-monospace, Menlo, monospace; font-size: 0.8rem;
    white-space: pre-wrap; word-break: break-all; margin: 12px 0; }
h2.doc-h { margin-top: 42px; padding-bottom: 8px; border-bottom: 1px solid rgba(128,128,128,0.25); }
</style>
CSS;

$content = $style . <<<'HTML'

<div style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); padding: 30px; border-radius: 10px; margin-bottom: 8px;">
    <h1 style="color: white; margin: 0;">OPNManager User Guide</h1>
    <p style="color: #e0e7ff; margin: 10px 0 0 0; font-size: 1.15rem;">
        Managing a fleet of OPNsense firewalls &mdash; v{{APP_VERSION}}, agent v{{AGENT_VERSION}}
    </p>
</div>
<p style="opacity:0.7;font-size:0.9rem;">
    Screenshots are taken from a demonstration fleet. Every hostname, address and
    customer in them is fictitious.
</p>

<h2 class="doc-h">How it works</h2>
<p>
    OPNManager does not connect out to your firewalls to poll them. Each firewall runs a
    small <strong>agent</strong> that checks in over HTTPS every two minutes, reports its
    health, and collects any commands waiting for it. Nothing needs to be open inbound to
    the firewall for normal operation.
</p>
<p>
    That has one consequence worth understanding before anything else: <strong>almost
    everything here depends on the agent checking in</strong>. A firewall whose agent has
    stopped will keep working perfectly as a firewall while going dark in this interface,
    and commands queued for it wait rather than fail.
</p>

HTML;

$content .= '<h2 class="doc-h">The dashboard</h2>';
$content .= '<p>The dashboard answers one question: is anything wrong right now, and where.</p>';
$content .= figure('fleet-dashboard-dark.png',
    'The fleet dashboard. Counters across the top, every firewall beneath, and a map of where they are.',
    [
        ['x' => 8.5,  'y' => 19.5, 'text' => '<b>Navigation.</b> Grouped by what you are doing: the fleet itself under <em>Main</em>, everything that watches it under <em>Monitoring</em>, your own account below that.'],
        ['x' => 20.0, 'y' => 7.5, 'text' => '<b>Fleet counters.</b> Total, online, offline, updates pending, average health. Each is a link into the filtered list &mdash; click <em>Offline</em> to see only those.'],
        ['x' => 46.5, 'y' => 15.5, 'text' => '<b>Problem tiles.</b> These appear <em>only when the number is not zero</em>: reboots required, gateways down, VPNs down, drift, expiring certificates, critical incidents. A row of zeros would be noise, so an empty strip here means nothing needs you.'],
        ['x' => 93.0, 'y' => 19.8, 'text' => '<b>Auto-refresh.</b> Off by default. The dashboard is a snapshot, not a live feed &mdash; agents report every two minutes, so refreshing faster than that shows you the same data again.'],
        ['x' => 84.0, 'y' => 38.0, 'text' => '<b>Firewall health.</b> One row per firewall with its health score, uptime, last check-in and agent version. <em>Checkin</em> is the column that matters most: if it is drifting past a few minutes, that firewall is not reporting.'],
    ]
);

$content .= '<h2 class="doc-h">Adding a firewall</h2>';
$content .= '<p>Enrollment installs the agent and registers the firewall in one command. You need shell access to the firewall once; after that it checks in by itself.</p>';
$content .= figure('enroll-firewall-light.png',
    'Add New Firewall. The command is generated per enrollment and carries a token that binds it to this manager.',
    [
        ['x' => 45.0, 'y' => 57.0, 'text' => '<b>The enrollment command.</b> Paste it into an SSH session on the firewall. It installs the agent, points it at this manager, and registers the firewall automatically.'],
        ['x' => 29.0, 'y' => 63.0, 'text' => '<b>Copy it</b> rather than retyping. Transcription errors in a token are indistinguishable from a rejected enrollment.'],
        ['x' => 85.0, 'y' => 30.0, 'text' => '<b>What to expect.</b> Around two minutes, then the firewall appears in your list on its own. There is no further configuration step.'],
        ['x' => 85.0, 'y' => 47.0, 'text' => '<b>The token is single-use and expires in 24 hours.</b> If enrollment fails after that, generate a fresh command rather than reusing this one.'],
    ]
);

$content .= <<<'HTML'
<div class="doc-note">
    <strong>Reinstalling later is different from enrolling.</strong> Every firewall's page
    carries a reinstall command specific to that firewall, which keeps its identity,
    settings and history. Use that one to repair an agent &mdash; a fresh enrollment on a
    firewall that already exists creates a <em>second</em> record and abandons the first.
</div>
HTML;

$content .= '<h2 class="doc-h">Working with the fleet</h2>';
$content .= figure('fleet-firewalls-light.png',
    'Firewall Management. Every firewall, filterable, with the operational columns visible at once.',
    [
        ['x' => 26.0, 'y' => 14.0, 'text' => '<b>Filters.</b> Free text, tag, status and sort. They combine, so "all PCI-scope firewalls that are offline" is two clicks.'],
        ['x' => 95.0, 'y' => 14.5, 'text' => '<b>Update All</b> starts a fleet-wide OPNsense update. It runs in rings rather than all at once &mdash; see <em>Fleet Updates</em> for the rollout itself.'],
        ['x' => 75.0, 'y' => 30.0, 'text' => '<b>Tags</b> are how you group firewalls that have nothing else in common: a compliance scope, a support contract, a hardware generation. Filters and bulk operations both work on them.'],
        ['x' => 83.0, 'y' => 24.0, 'text' => '<b>Checkin</b> is the health of the <em>agent</em>, not the firewall. Minutes are normal; hours mean the agent has stopped and this row is stale.'],
        ['x' => 92.5, 'y' => 40.0, 'text' => '<b>Health grade</b> summarises gateways, VPNs, services, certificates and resource use into one letter, so a fleet can be scanned rather than read.'],
    ]
);

$content .= <<<'HTML'
<h2 class="doc-h">When a firewall stops reporting</h2>
<p>
    This is the failure you will actually meet, so it is worth knowing the shape of it.
    The firewall is usually fine; it is the agent that has stopped. The interface cannot
    tell the difference on its own, which is why a firewall can sit at
    <em>offline</em> while happily passing traffic.
</p>
<p>From agent v1.6.6 onward this mostly repairs itself:</p>
<ul>
    <li>The agent is supervised, so a crash or a self-update restarts it.</li>
    <li>A watchdog runs every five minutes and restarts an agent that is alive but no longer checking in.</li>
</ul>
<p>
    If it is still silent, open that firewall's page and use the reinstall command under
    <strong>Reinstall at the console</strong>. It looks like this, and the hardware ID in
    it is what binds the reinstall to that specific firewall:
</p>
<div class="doc-cmd">fetch -o - https://your-manager.example/downloads/plugins/install_opnmanager_agent.sh \
  | env OPNMGR_BASE_URL=https://your-manager.example \
        OPNMGR_HARDWARE_ID=&lt;that firewall's id&gt; sh</div>
<p>
    It keeps everything: identity, stored credentials, agent configuration, and the
    firewall's history in this manager. Running it on a healthy firewall is safe &mdash;
    an identity already present is never overwritten.
</p>
<div class="doc-warn">
    <strong>Commands queued for an offline firewall wait rather than fail.</strong> They
    are collected at its next check-in, which is usually what you want: the thing most
    likely to be queued for a firewall that has gone quiet is the instruction that brings
    it back. They are abandoned after seven days.
</div>

<h2 class="doc-h">Reaching a firewall's web interface</h2>
<p>
    You do not need a VPN or an inbound rule to open a firewall's own GUI. Use
    <strong>Connect</strong> on the firewall's page: the manager opens an SSH tunnel to it,
    binds a local port, and proxies your browser through it. The tunnel is bound to
    loopback, expires on its own, and is torn down when the session ends.
</p>
<p>
    If a connection fails, the error now tells you why rather than timing out &mdash; a
    refused host key, a port already in use, or an agent that is not reachable are all
    reported distinctly.
</p>

<h2 class="doc-h">Keeping firewalls current</h2>
<p>
    <strong>Fleet Updates</strong> rolls an OPNsense update across the fleet in rings
    &mdash; canary, then pilot, then production &mdash; so a bad release meets one
    firewall rather than all of them. HA pairs are handled so both halves are never taken
    at once.
</p>
<p>
    Agent updates are separate and deliberately manual. Publishing a new agent version
    does <em>not</em> deploy it: a release is held until it is promoted by name, and can
    go to a pilot group first. That is a change from older behaviour, where publishing
    reached every firewall within a check-in.
</p>

<h2 class="doc-h">Health, drift and incidents</h2>
<ul>
    <li><strong>Health</strong> collects gateways, VPN tunnels, CARP state, services and certificates from each firewall and grades them.</li>
    <li><strong>Config Drift</strong> compares each firewall's configuration against its own recent history, so an unplanned change is visible without anyone diffing by hand.</li>
    <li><strong>Config Search</strong> searches across every firewall's configuration at once &mdash; which firewalls have a given alias, rule or interface.</li>
    <li><strong>Incidents</strong> and <strong>Alerts</strong> raise the things that need a person, with repeat suppression so one broken gateway does not become fifty notifications.</li>
</ul>

<h2 class="doc-h">Backups</h2>
<p>
    Each firewall's <code>config.xml</code> is backed up on a schedule and kept under a
    retention policy you set. Backups can be compared against each other and restored to
    the firewall they came from. Restores are deliberately explicit: they are recorded in
    the audit log and cannot be triggered in bulk.
</p>

<h2 class="doc-h">AI analysis</h2>
<p>
    AI-assisted configuration review is <strong>opt-in and off by default</strong>. Nothing
    else in the product depends on it &mdash; search, health, updates, drift, alerting and
    backups all work with it switched off.
</p>
<p>
    Enable it under <strong>Settings &rarr; AI Analysis</strong>
    (<code>/ai_settings.php</code>). That page states exactly what would be transmitted to
    the provider before you turn it on; read it first, because configuration data leaves
    your installation when it is enabled.
</p>

<h2 class="doc-h">Access and accountability</h2>
<ul>
    <li><strong>Users &amp; Roles</strong> control who can see and do what. Disabling an account ends its existing sessions rather than waiting for the next login.</li>
    <li><strong>Two-factor authentication</strong> is available per account and can be required for administrators.</li>
    <li><strong>Audit Log</strong> records privileged actions &mdash; logins, command execution, backup restores, settings changes, agent releases &mdash; with who did it and what changed.</li>
</ul>

<div class="doc-note">
    <strong>Where to look when something is wrong.</strong> Start at the dashboard's
    problem tiles; they only appear when they are non-zero. If a firewall is the problem,
    its own page carries health, recent commands, backups and the reinstall command. If
    the manager itself is the problem, <em>Manager Health</em> and <em>Scheduled Jobs</em>
    show whether its own background work is running.
</div>
HTML;

// --- write it ----------------------------------------------------------------

$stmt = db()->prepare('SELECT content FROM documentation_pages WHERE page_key = ?');
$stmt->execute(['documentation']);
$existing = (string) ($stmt->fetchColumn() ?: '');

printf("Existing content: %d bytes\n", strlen($existing));
printf("New content:      %d bytes\n", strlen($content));
// Count the figures themselves: 'doc-fig-frame' also appears in the stylesheet,
// which would report more figures than the page contains.
printf("Figures:          %d annotated screenshots\n", substr_count($content, '<figure class="doc-fig">'));
printf("Markers:          %d\n", substr_count($content, 'class="doc-pin"'));

// Every referenced screenshot must exist, or the page renders broken images.
$missing = [];
if (preg_match_all('#' . preg_quote(SHOT_BASE, '#') . '([\w.-]+\.png)#', $content, $m)) {
    foreach (array_unique($m[1]) as $img) {
        if (!is_file(dirname(__DIR__) . '/docs/images/github/' . $img)) {
            $missing[] = $img;
        }
    }
}
if ($missing) {
    fwrite(STDERR, "ERROR: missing screenshots: " . implode(', ', $missing) . "\n");
    exit(1);
}
echo "Screenshots:      all referenced files exist\n";

if (!$apply) {
    echo "\nNothing written. Re-run with --apply.\n";
    exit(0);
}

$upd = db()->prepare(
    "UPDATE documentation_pages
        SET content = ?, title = 'User Guide', last_updated = NOW(), updated_by = 'build_user_documentation.php'
      WHERE page_key = 'documentation'"
);
$upd->execute([$content]);
printf("\nWrote %d byte(s) to documentation_pages.documentation\n", strlen($content));
