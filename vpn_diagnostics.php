<?php
/**
 * VPN Diagnostics: findings and the guided, bounded probe workflow.
 *
 * Probes run through the existing structured-command path, are validated and
 * rate-limited, and are bound to the firewall's own address on the protected
 * network. They run ON the firewall: see VPN_PROBE_DISCLAIMER.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vpn/vpn.php';

require_permission('vpn.view');
$canDiagnose = can('vpn.diagnose');

$live     = vpn_topology_live(false);
$findings = vpn_diagnose($live['topology'], $live['devices']);
$scoped   = vpn_scope_filter($live['topology'], $findings, vpn_allowed_firewall_ids());
$topology = $scoped['topology'];
$findings = $scoped['findings'];

$focusFw     = (int)($_GET['firewall'] ?? 0);
$focusTunnel = preg_match('/^[0-9a-f]{12}$/', (string)($_GET['tunnel'] ?? '')) ? $_GET['tunnel'] : '';
$pre = [
    'method' => in_array($_GET['method'] ?? '', ['icmp','tcp','udp','traceroute'], true) ? $_GET['method'] : 'icmp',
    'dest'   => preg_match('#^[0-9./]{0,18}$#', (string)($_GET['dest'] ?? '')) ? (string)$_GET['dest'] : '',
    'size'   => ctype_digit((string)($_GET['size'] ?? '')) ? (string)$_GET['size'] : '',
    'incident' => (int)($_GET['incident'] ?? 0),
];

$tunnelOpts = [];
foreach ($topology['tunnels'] as $t) {
    foreach ($t['firewall_ids'] as $fid) {
        $tunnelOpts[] = ['id' => $t['id'], 'fw' => $fid, 'label' => $t['name'] . ' (' . $t['protocol'] . ', ' . $t['state'] . ')', 'both' => $t['b'] !== null];
    }
}
$firewalls = array_map(fn($d) => ['id' => $d['id'], 'name' => $d['hostname'], 'platform' => $d['platform_label']], $topology['devices']);

$incidents = [];
try {
    $ids = array_column($firewalls, 'id');
    if ($ids) {
        $s = db()->prepare('SELECT id, firewall_id, title FROM alert_incidents WHERE status IN ("open","acknowledged") AND firewall_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT 100');
        $s->execute($ids);
        $incidents = $s->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

$recent = [];
try {
    $ids = array_column($firewalls, 'id');
    if ($ids) {
        $s = db()->prepare('SELECT r.*, f.hostname FROM vpn_diagnostic_runs r JOIN firewalls f ON f.id = r.firewall_id
                             WHERE r.firewall_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.id DESC LIMIT 25');
        $s->execute($ids);
        $recent = $s->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cfg = ['csrf' => csrf_token(), 'canDiagnose' => $canDiagnose, 'tunnels' => $tunnelOpts, 'focusFw' => $focusFw, 'focusTunnel' => $focusTunnel, 'pre' => $pre];

include __DIR__ . '/inc/header.php';
?>
<link href="/assets/css/vpn_topology.css" rel="stylesheet">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            <h4 class="mb-0"><i class="fas fa-stethoscope me-2"></i>VPN Diagnostics</h4>
            <small class="text-muted">Findings from stored configuration and telemetry, and bounded probes you run on request.</small>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="/vpn_topology.php"><i class="fas fa-project-diagram me-1"></i>Topology</a>
    </div>

    <div class="alert alert-info small" role="note"><i class="fas fa-info-circle me-1"></i><?php echo $h(VPN_PROBE_DISCLAIMER); ?></div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3"><div class="card-header">Guided diagnostic</div><div class="card-body">
            <?php if (!$canDiagnose): ?>
                <p class="text-muted mb-0">Your role can view diagnostics but not run probes.</p>
            <?php else: ?>
                <form id="probe-form" autocomplete="off">
                    <div class="mb-2"><label class="form-label small mb-1" for="p-fw">Source firewall</label>
                        <select id="p-fw" class="form-select form-select-sm" required>
                            <option value="">Choose…</option>
                            <?php foreach ($firewalls as $f): ?><option value="<?php echo (int)$f['id']; ?>" <?php echo $focusFw === $f['id'] ? 'selected' : ''; ?>><?php echo $h($f['name'] . ' — ' . $f['platform']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="mb-2"><label class="form-label small mb-1" for="p-tunnel">Tunnel (when known)</label>
                        <select id="p-tunnel" class="form-select form-select-sm"><option value="">Not specified</option></select></div>
                    <div class="mb-2"><label class="form-label small mb-1" for="p-dest">Destination IP or subnet</label>
                        <input id="p-dest" class="form-control form-control-sm" value="<?php echo $h($pre['dest']); ?>" placeholder="10.10.0.5 or 10.10.0.0/24" required>
                        <div class="form-text">A literal IPv4 address. A subnet probes its first host.</div></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small mb-1" for="p-method">Protocol</label>
                            <select id="p-method" class="form-select form-select-sm">
                                <?php foreach (['icmp'=>'ICMP ping','tcp'=>'TCP connect','udp'=>'UDP (inconclusive by nature)','traceroute'=>'Traceroute'] as $k=>$l): ?>
                                <option value="<?php echo $k; ?>" <?php echo $pre['method'] === $k ? 'selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-3"><label class="form-label small mb-1" for="p-port">Port</label>
                            <input id="p-port" class="form-control form-control-sm" inputmode="numeric" placeholder="443"></div>
                        <div class="col-3"><label class="form-label small mb-1" for="p-size" title="ICMP only; sets don't-fragment, for MTU checks">Size</label>
                            <input id="p-size" class="form-control form-control-sm" inputmode="numeric" value="<?php echo $h($pre['size']); ?>" placeholder="opt."></div>
                    </div>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="p-both"><label class="form-check-label small" for="p-both">Also check from the far end when both ends are managed (reverse path)</label></div>
                    <div class="mb-2"><label class="form-label small mb-1" for="p-inc">Link to incident (optional)</label>
                        <select id="p-inc" class="form-select form-select-sm"><option value="">None</option>
                        <?php foreach ($incidents as $i): ?><option value="<?php echo (int)$i['id']; ?>" data-fw="<?php echo (int)$i['firewall_id']; ?>" <?php echo $pre['incident'] === (int)$i['id'] ? 'selected' : ''; ?>>#<?php echo (int)$i['id']; ?> — <?php echo $h(mb_substr($i['title'], 0, 70)); ?></option><?php endforeach; ?>
                        </select></div>
                    <div id="p-preview" class="small border rounded p-2 mb-2 d-none"></div>
                    <div id="p-msg" class="small mb-2"></div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm" type="button" id="p-preview-btn">Show source address</button>
                        <button class="btn btn-primary btn-sm" type="submit" id="p-run">Run probe</button>
                    </div>
                </form>
            <?php endif; ?>
            </div></div>

            <div class="card"><div class="card-header">Results</div><div class="card-body" id="p-results">
                <p class="text-muted small mb-0">Results appear here once the firewall's agent has run the probe (usually within a couple of minutes).</p>
            </div></div>
        </div>

        <div class="col-lg-7">
            <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Findings</span><span class="badge text-bg-secondary"><?php echo count($findings); ?></span></div>
            <div class="card-body">
                <?php if ($scoped['hidden_findings']): ?><p class="small text-muted"><?php echo (int)$scoped['hidden_findings']; ?> finding(s) involve firewalls outside your access scope and are not shown.</p><?php endif; ?>
                <?php if (!$findings): ?><p class="text-muted mb-0">No findings. This reflects stored configuration and telemetry only.</p><?php endif; ?>
                <?php foreach ($findings as $f):
                    if ($focusFw && !in_array($focusFw, $f['affected']['firewalls'], true)) { continue; } ?>
                <div class="card mb-2 vpn-finding vpn-sev-<?php echo $h($f['severity']); ?>" id="f-<?php echo $h($f['id']); ?>"><div class="card-body py-2">
                    <span class="badge vpn-badge vpn-sevb-<?php echo $h($f['severity']); ?>"><?php echo $h($f['severity']); ?></span>
                    <span class="badge vpn-badge vpn-basis-<?php echo $h($f['basis']); ?>"><?php echo $h($f['basis']); ?></span>
                    <span class="badge text-bg-secondary">confidence <?php echo $h($f['confidence']); ?></span>
                    <strong class="ms-1"><?php echo $h($f['title']); ?></strong>
                    <p class="small mb-1"><?php echo $h($f['explanation']); ?></p>
                    <details class="small"><summary>Evidence, freshness and next step</summary>
                        <div class="fw-semibold mt-1">Evidence</div><ul class="mb-1"><?php foreach ($f['evidence'] as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul>
                        <div class="fw-semibold">Data freshness</div><ul class="mb-1"><?php foreach ($f['freshness'] as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul>
                        <?php if ($f['limitation']): ?><div><span class="fw-semibold">Limitation:</span> <?php echo $h($f['limitation']); ?></div><?php endif; ?>
                        <div><span class="fw-semibold">Next diagnostic:</span> <?php echo $h($f['next_step']); ?>
                        <?php if ($canDiagnose && $f['probe'] && $f['affected']['firewalls']): ?>
                            <a class="ms-2" href="/vpn_diagnostics.php?firewall=<?php echo (int)$f['affected']['firewalls'][0]; ?><?php echo $f['affected']['tunnels'] ? '&tunnel=' . $h($f['affected']['tunnels'][0]) : ''; ?>&method=<?php echo $h($f['probe']['method']); ?><?php echo !empty($f['probe']['target']) ? '&dest=' . $h($f['probe']['target']) : ''; ?><?php echo !empty($f['probe']['size']) ? '&size=' . (int)$f['probe']['size'] : ''; ?>">Prefill the probe</a>
                        <?php endif; ?></div>
                        <div class="mt-1">Affected:
                            <?php foreach ($f['affected']['firewalls'] as $fid): ?>
                                <a href="/firewall_health.php?firewall=<?php echo (int)$fid; ?>">health #<?php echo (int)$fid; ?></a> ·
                                <a href="/firewall_details.php?id=<?php echo (int)$fid; ?>">details</a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div></div>
                <?php endforeach; ?>
            </div></div>

            <div class="card"><div class="card-header">Recent probes</div>
            <div class="table-responsive"><table class="table table-sm mb-0 small">
                <thead><tr><th>When</th><th>Firewall</th><th>Probe</th><th>Source</th><th>Outcome</th><th>By</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?php echo $h($r['created_at']); ?></td><td><?php echo $h($r['hostname']); ?> <span class="text-muted">(<?php echo $h($r['leg']); ?>)</span></td>
                        <td><?php echo $h($r['method'] . ' ' . $r['target'] . ($r['port'] ? ':' . $r['port'] : '')); ?></td>
                        <td><?php echo $h(($r['source_address'] ?: 'default') . ($r['source_interface'] ? ' (' . $r['source_interface'] . ')' : '')); ?></td>
                        <td title="<?php echo $h($r['summary']); ?>"><?php echo $h($r['outcome'] ?: $r['status']); ?></td>
                        <td><?php echo $h($r['username']); ?></td>
                        <td><?php if ($r['incident_id']): ?><a href="/incidents.php?incident=<?php echo (int)$r['incident_id']; ?>">incident</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?><tr><td colspan="7" class="text-muted">No probes have been run.</td></tr><?php endif; ?>
                </tbody></table></div></div>
        </div>
    </div>
</div>

<script type="application/json" id="vpn-diag-config"><?php echo json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script>
(function () {
  var CFG = JSON.parse(document.getElementById('vpn-diag-config').textContent);
  var form = document.getElementById('probe-form'); if (!form) return;
  function $(id) { return document.getElementById(id); }
  function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x !== undefined) e.textContent = x; return e; }
  function fillTunnels() {
    var fw = parseInt($('p-fw').value || '0', 10), s = $('p-tunnel'); while (s.options.length > 1) s.remove(1);
    CFG.tunnels.filter(function (t) { return t.fw === fw; }).forEach(function (t) { var o = el('option', '', t.label); o.value = t.id; o.setAttribute('data-both', t.both ? '1' : ''); if (t.id === CFG.focusTunnel) o.selected = true; s.appendChild(o); });
  }
  function body() {
    var b = { firewall_id: parseInt($('p-fw').value || '0', 10), tunnel_id: $('p-tunnel').value || null, dest: $('p-dest').value.trim(), method: $('p-method').value,
      port: $('p-port').value.trim(), size: $('p-size').value.trim(), both_ends: $('p-both').checked ? 1 : 0, incident_id: $('p-inc').value || null, csrf: CFG.csrf };
    return b;
  }
  function post(extra) {
    var b = body(); for (var k in extra) b[k] = extra[k];
    return fetch('/api/vpn_probe.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(b) })
      .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Bad response' }; }); });
  }
  function showPlan(r, box) {
    box.textContent = ''; box.classList.remove('d-none');
    if (!r.success) { box.appendChild(el('div', 'text-danger', r.message || 'Not possible.')); return; }
    (r.legs || []).forEach(function (l) {
      var d = el('div', 'mb-1');
      d.appendChild(el('strong', '', (l.leg === 'peer' ? 'Far end: ' : 'Source: ') + l.hostname));
      d.appendChild(el('div', '', l.method + ' → ' + l.target + (l.port ? ':' + l.port : '') + ' from ' + (l.source_address || 'default address') + (l.source_interface ? ' on ' + l.source_interface : '')));
      d.appendChild(el('div', 'text-muted', l.source_reason)); box.appendChild(d);
    });
    (r.warnings || []).forEach(function (w) { box.appendChild(el('div', 'text-warning', w)); });
    (r.policy || []).forEach(function (p) { box.appendChild(el('div', 'text-muted', 'Policy preview — ' + p.where + ': ' + p.verdict.replace('_', ' ') + (p.rule ? ' (' + p.rule + ')' : '') + (p.uncertain ? ' [uncertain: unresolved aliases]' : ''))); });
  }
  $('p-fw').addEventListener('change', fillTunnels); fillTunnels();
  $('p-preview-btn').addEventListener('click', function () { post({ preview: 1 }).then(function (r) { showPlan(r, $('p-preview')); }); });
  var poll = null;
  function results(group, tries) {
    fetch('/api/vpn_probe.php?run_group=' + group, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
      var box = $('p-results'); box.textContent = '';
      if (!r.success) { box.appendChild(el('div', 'text-danger', r.message || 'Could not read results.')); return; }
      var pending = false;
      r.legs.forEach(function (l) {
        var d = el('div', 'border rounded p-2 mb-2'); d.appendChild(el('strong', '', (l.leg === 'peer' ? 'Far end ' : 'Source ') + l.hostname + ' — ' + l.method + ' ' + l.target + (l.port ? ':' + l.port : '')));
        d.appendChild(el('div', 'small text-muted', 'Sourced from ' + (l.source_address || 'default') + (l.source_interface ? ' (' + l.source_interface + ')' : '') + '. ' + (l.source_reason || '')));
        d.appendChild(el('div', 'mt-1', (l.outcome ? l.outcome.toUpperCase() + ' — ' : '') + (l.summary || l.status)));
        if (l.result_excerpt) { var det = el('details', 'small'); det.appendChild(el('summary', '', 'Raw output')); det.appendChild(el('pre', 'mb-0', l.result_excerpt)); d.appendChild(det); }
        if (l.status === 'queued') pending = true; box.appendChild(d);
      });
      box.appendChild(el('div', 'small text-muted', r.disclaimer));
      if (pending && tries < 120) { poll = setTimeout(function () { results(group, tries + 1); }, 5000); }
    });
  }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault(); clearTimeout(poll); $('p-msg').textContent = '';
    $('p-run').disabled = true;
    post({}).then(function (r) {
      $('p-run').disabled = false;
      if (!r.success) { $('p-msg').className = 'small mb-2 text-danger'; $('p-msg').textContent = r.message || 'Refused.'; return; }
      $('p-msg').className = 'small mb-2 text-success'; $('p-msg').textContent = 'Queued. Waiting for the agent.';
      showPlan(r, $('p-preview')); results(r.run_group, 0);
    });
  });
})();
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
