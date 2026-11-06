<?php
/**
 * VPN Topology.
 *
 * A map and a searchable table of every tunnel the fleet knows about, built
 * from stored configuration snapshots and agent telemetry. Nothing on this
 * page contacts a firewall or changes a tunnel; operator pairing only changes
 * how this application interprets what it already has.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vpn/vpn.php';

require_permission('vpn.view');

$config = [
    'csrf'        => csrf_token(),
    'canManage'   => can('vpn.manage'),
    'canDiagnose' => can('vpn.diagnose'),
];

include __DIR__ . '/inc/header.php';
?>
<link href="/assets/css/vpn_topology.css" rel="stylesheet">

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-2">
        <div>
            <h4 class="mb-0"><i class="fas fa-project-diagram me-2"></i>VPN Topology</h4>
            <small class="text-muted">
                Built from stored configuration snapshots and agent telemetry; no firewall is contacted.
                <span id="vpn-asof"></span>
            </small>
        </div>
        <div class="d-flex gap-1 mt-2 mt-md-0">
            <a class="btn btn-sm btn-outline-primary" href="/vpn_diagnostics.php"><i class="fas fa-stethoscope me-1"></i>Diagnostics</a>
            <button class="btn btn-sm btn-outline-secondary" id="btn-refresh" type="button"><i class="fas fa-sync me-1"></i>Refresh</button>
        </div>
    </div>

    <div id="vpn-error" class="alert alert-danger d-none"></div>
    <div id="vpn-hidden" class="small text-muted"></div>
    <div id="vpn-summary" class="d-flex flex-wrap gap-2 mb-3"></div>

    <div class="card mb-3"><div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3"><label class="form-label small mb-1" for="f-q">Search</label>
                <input id="f-q" class="form-control form-control-sm" placeholder="name, network, address…" autocomplete="off"></div>
            <?php foreach ([['customer','Customer'],['site','Site'],['platform','Platform'],['protocol','Protocol'],['state','State'],['relationship','Relationship'],['kind','Type']] as [$k,$l]): ?>
            <div class="col-6 col-md-2 col-xl"><label class="form-label small mb-1" for="f-<?php echo $k; ?>"><?php echo $l; ?></label>
                <select id="f-<?php echo $k; ?>" class="form-select form-select-sm"><option value="">All</option>
                <?php if ($k === 'protocol'): foreach (['wireguard','ipsec','openvpn'] as $o): ?><option value="<?php echo $o; ?>"><?php echo $o; ?></option><?php endforeach; endif; ?>
                <?php if ($k === 'state'): foreach (array_keys(vpn_state_meta()) as $o): ?><option value="<?php echo $o; ?>"><?php echo ucfirst($o); ?></option><?php endforeach; endif; ?>
                <?php if ($k === 'relationship'): foreach (['observed','inferred','manual','unpaired'] as $o): ?><option value="<?php echo $o; ?>"><?php echo ucfirst($o); ?></option><?php endforeach; endif; ?>
                <?php if ($k === 'kind'): foreach (['site_to_site'=>'Site-to-site','remote_access'=>'Remote access','external'=>'External peer','ambiguous'=>'Ambiguous','unknown'=>'Unknown'] as $o=>$ol): ?><option value="<?php echo $o; ?>"><?php echo $ol; ?></option><?php endforeach; endif; ?>
                </select></div>
            <?php endforeach; ?>
        </div>
    </div></div>

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
        <div class="btn-group btn-group-sm" role="group" aria-label="View">
            <button id="btn-map" class="btn btn-outline-primary" type="button"><i class="fas fa-sitemap me-1"></i>Map</button>
            <button id="btn-table" class="btn btn-outline-primary" type="button"><i class="fas fa-table me-1"></i>Table</button>
            <button id="btn-findings" class="btn btn-outline-primary" type="button"><i class="fas fa-search me-1"></i>Findings <span class="badge text-bg-secondary" id="vpn-finding-count">0</span></button>
        </div>
        <div class="small text-muted"><span id="vpn-count"></span> <span id="vpn-loading" class="spinner-border spinner-border-sm d-none" role="status"></span></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div id="tab-map">
                <div id="vpn-map"></div>
                <div class="vpn-legend small text-muted mt-2">
                    <span><svg width="34" height="8"><line x1="0" y1="4" x2="34" y2="4" stroke="currentColor" stroke-width="3"/></svg> observed</span>
                    <span><svg width="34" height="8"><line x1="0" y1="4" x2="34" y2="4" stroke="currentColor" stroke-width="3" stroke-dasharray="8 6"/></svg> inferred from configuration</span>
                    <span><svg width="34" height="8"><line x1="0" y1="4" x2="34" y2="4" stroke="currentColor" stroke-width="4" stroke-dasharray="14 4 2 4"/></svg> set by an operator</span>
                    <span>Colour = state (up, degraded, idle, down, stale, unknown, disabled). Drag nodes to arrange; the layout is saved to your account.</span>
                    <button class="btn btn-link btn-sm p-0" id="btn-reset-layout" type="button">Reset layout</button>
                </div>
            </div>
            <div id="tab-table" class="d-none card"><div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr>
                        <th data-sort="name" role="button">Tunnel</th><th data-sort="protocol" role="button">Protocol</th>
                        <th data-sort="kind" role="button">Type</th><th data-sort="state" role="button">State</th>
                        <th data-sort="relationship" role="button">Relationship</th><th data-sort="activity" role="button">Last activity</th><th>Findings</th>
                    </tr></thead>
                    <tbody id="vpn-rows"></tbody>
                </table></div></div>
            <div id="tab-findings" class="d-none">
                <p class="small text-muted">Each finding shows its evidence, how fresh the data is, whether it was observed or inferred, and the next diagnostic to try. Nothing here changes a tunnel.</p>
                <div id="vpn-findings"></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card"><div class="card-body" id="vpn-detail">
                <p class="text-muted mb-0">Select a tunnel on the map or in the table to see its detail.</p>
            </div></div>
        </div>
    </div>
</div>

<script type="application/json" id="vpn-config"><?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script src="/assets/js/vpn_topology.js"></script>
<?php include __DIR__ . '/inc/footer.php'; ?>
