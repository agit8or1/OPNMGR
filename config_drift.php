<?php
/**
 * Configuration Drift.
 *
 * Shows, for every managed firewall, whether its current configuration still
 * matches the one an operator approved as the baseline.
 *
 * Drift is never acted on automatically. Nothing on this page restores a
 * configuration; the restore path is the existing, separately confirmed one.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/config_drift.php';

require_permission('drift.view');

$message   = '';
$can_manage = can('drift.acknowledge');
$detail_id  = (int)($_GET['firewall'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $message = '<div class="alert alert-danger">Invalid CSRF token.</div>';
    } elseif (!$can_manage) {
        $message = '<div class="alert alert-danger">Your role does not permit changing drift state.</div>';
    } else {
        $fwId = (int)($_POST['firewall_id'] ?? 0);

        if (isset($_POST['acknowledge'])) {
            $r = drift_acknowledge($fwId, trim($_POST['note'] ?? ''));
            $message = $r['ok']
                ? '<div class="alert alert-success">Drift acknowledged.</div>'
                : '<div class="alert alert-warning">' . htmlspecialchars($r['error']) . '</div>';

        } elseif (isset($_POST['set_baseline'])) {
            if (!can('drift.set_baseline')) {
                $message = '<div class="alert alert-danger">Your role does not permit setting a baseline.</div>';
            } else {
                $r = drift_set_baseline($fwId, (int)$_POST['backup_id'], trim($_POST['note'] ?? ''));
                $message = $r['ok']
                    ? '<div class="alert alert-success">Baseline updated. This configuration is now the approved one.</div>'
                    : '<div class="alert alert-danger">' . htmlspecialchars($r['error']) . '</div>';
            }

        } elseif (isset($_POST['recheck'])) {
            drift_evaluate($fwId);
            $message = '<div class="alert alert-info">Re-checked.</div>';

        } elseif (isset($_POST['recheck_all'])) {
            $n = 0;
            foreach (db()->query('SELECT id FROM firewalls')->fetchAll(PDO::FETCH_COLUMN) as $id) {
                drift_evaluate((int)$id);
                $n++;
            }
            $message = '<div class="alert alert-info">Re-checked ' . $n . ' firewall(s).</div>';
        }
    }
}

$fleet = drift_fleet_status();

$counts = ['drifted' => 0, 'match' => 0, 'unknown' => 0, 'error' => 0];
foreach ($fleet as $row) {
    $st = $row['status'] ?: 'unknown';
    $counts[$st] = ($counts[$st] ?? 0) + 1;
}

// --- detail view -----------------------------------------------------------
$detail = null;
if ($detail_id > 0) {
    $baseline = drift_get_baseline($detail_id);
    $latest   = drift_latest_backup($detail_id);

    $fwStmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
    $fwStmt->execute([$detail_id]);
    $fwRow = $fwStmt->fetch(PDO::FETCH_ASSOC);

    if ($fwRow) {
        $detail = ['firewall' => $fwRow, 'baseline' => $baseline, 'latest' => $latest, 'diff' => [], 'error' => ''];

        if ($baseline && $latest) {
            $baseXml = drift_load_backup_xml((int)$baseline['backup_id']);
            $curXml  = drift_load_backup_xml((int)$latest['id']);

            if ($baseXml['ok'] && $curXml['ok']) {
                $cmp = drift_compare($baseXml['xml'], $curXml['xml']);
                $detail['compare'] = $cmp;
                foreach (array_merge($cmp['modified'], $cmp['added'], $cmp['removed']) as $section) {
                    $detail['diff'][$section] = drift_section_diff($baseXml['xml'], $curXml['xml'], $section, 60);
                }
            } else {
                $detail['error'] = $baseXml['ok'] ? $curXml['error'] : $baseXml['error'];
            }
        }

        // Backups that could be promoted to baseline.
        $bk = db()->prepare(
            'SELECT id, backup_file, file_size, COALESCE(uploaded_at, created_at) AS at
               FROM backups WHERE firewall_id = ?
              ORDER BY COALESCE(uploaded_at, created_at) DESC, id DESC LIMIT 200'
        );
        $bk->execute([$detail_id]);
        $detail['backups'] = array_values(array_filter(
            $bk->fetchAll(PDO::FETCH_ASSOC),
            fn($b) => resolve_backup_path($b) !== null
        ));
    }
}

/** Badge colour for a drift status. */
function drift_badge(string $status): string {
    return match ($status) {
        'drifted' => 'warning text-dark',
        'match'   => 'success',
        'error'   => 'danger',
        default   => 'secondary',
    };
}

/** "3 days ago" style age. */
function drift_age(?string $ts): string {
    if (!$ts) return '—';
    $d = time() - strtotime($ts);
    if ($d < 3600)  return max(1, (int)($d / 60)) . 'm';
    if ($d < 86400) return (int)($d / 3600) . 'h';
    return (int)($d / 86400) . 'd';
}

include __DIR__ . '/inc/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-code-compare me-2"></i>Configuration Drift</h4>
            <small class="text-muted">
                Compares each firewall's newest configuration backup against the baseline you approved.
                Serialisation noise and volatile fields are ignored. Nothing here restores anything.
            </small>
        </div>
        <?php if ($can_manage): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <button class="btn btn-sm btn-outline-secondary" name="recheck_all">
                <i class="fas fa-rotate me-1"></i>Re-check all
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php echo $message; ?>

    <div class="row g-2 mb-3">
        <?php foreach ([
            ['Drifted', $counts['drifted'] ?? 0, 'warning'],
            ['Matching baseline', $counts['match'] ?? 0, 'success'],
            ['No baseline', $counts['unknown'] ?? 0, 'secondary'],
            ['Error', $counts['error'] ?? 0, 'danger'],
        ] as [$label, $n, $colour]): ?>
        <div class="col-6 col-md-3">
            <div class="card h-100"><div class="card-body py-2">
                <div class="text-muted small"><?php echo $label; ?></div>
                <div class="fs-4 text-<?php echo $colour; ?>"><?php echo $n; ?></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Firewall</th>
                        <th>Customer / Site</th>
                        <th>Drift</th>
                        <th>Sections changed</th>
                        <th>Detected</th>
                        <th>Baseline age</th>
                        <th>Latest config</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$fleet): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No firewalls.</td></tr>
                <?php else: foreach ($fleet as $row):
                    $status = $row['status'] ?: 'unknown';
                    $sections = json_decode((string)$row['sections_changed'], true) ?: [];
                    $names = array_merge($sections['modified'] ?? [], $sections['added'] ?? [], $sections['removed'] ?? []);
                ?>
                    <tr>
                        <td>
                            <a href="firewall_details.php?id=<?php echo (int)$row['id']; ?>">
                                <?php echo htmlspecialchars($row['hostname']); ?>
                            </a>
                        </td>
                        <td class="small text-muted">
                            <?php echo htmlspecialchars($row['customer_name'] ?: 'Unassigned'); ?>
                            <?php if ($row['site_name']): ?>/ <?php echo htmlspecialchars($row['site_name']); ?><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo drift_badge($status); ?>">
                                <?php echo htmlspecialchars(ucfirst($status)); ?>
                            </span>
                            <?php if ($row['acknowledged_at']): ?>
                                <i class="fas fa-check text-success ms-1"
                                   title="Acknowledged by <?php echo htmlspecialchars($row['acknowledged_by'] ?? ''); ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($names): ?>
                                <?php foreach (array_slice($names, 0, 4) as $n): ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($n); ?></span>
                                <?php endforeach; ?>
                                <?php if (count($names) > 4): ?>
                                    <span class="text-muted">+<?php echo count($names) - 4; ?></span>
                                <?php endif; ?>
                            <?php elseif ($row['detail']): ?>
                                <span class="text-muted"><?php echo htmlspecialchars($row['detail']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo drift_age($row['first_detected_at']); ?></td>
                        <td class="small text-muted"><?php echo drift_age($row['baseline_set_at']); ?></td>
                        <td class="small text-muted"><?php echo drift_age($row['latest_backup_at']); ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="?firewall=<?php echo (int)$row['id']; ?>">Review</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($detail): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?php echo htmlspecialchars($detail['firewall']['hostname']); ?></strong>
            <a class="btn btn-sm btn-outline-secondary" href="config_drift.php">Close</a>
        </div>
        <div class="card-body">
            <?php if ($detail['error']): ?>
                <div class="alert alert-warning"><?php echo htmlspecialchars($detail['error']); ?></div>
            <?php endif; ?>

            <div class="row small mb-3">
                <div class="col-md-6">
                    <strong>Baseline:</strong>
                    <?php if ($detail['baseline']): ?>
                        backup #<?php echo (int)$detail['baseline']['backup_id']; ?>,
                        set <?php echo htmlspecialchars($detail['baseline']['set_at']); ?>
                        by <?php echo htmlspecialchars($detail['baseline']['set_by_username'] ?? 'unknown'); ?>
                        <?php if ($detail['baseline']['notes']): ?>
                            <br><span class="text-muted"><?php echo htmlspecialchars($detail['baseline']['notes']); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">none set</span>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <strong>Current:</strong>
                    <?php if ($detail['latest']): ?>
                        backup #<?php echo (int)$detail['latest']['id']; ?>,
                        <?php echo htmlspecialchars($detail['latest']['uploaded_at'] ?: $detail['latest']['created_at']); ?>
                    <?php else: ?>
                        <span class="text-muted">no usable backup</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($detail['diff']): ?>
                <h6 class="small text-uppercase text-muted">Differences from baseline</h6>
                <?php foreach ($detail['diff'] as $section => $changes): ?>
                    <div class="mb-3">
                        <div class="fw-bold small mb-1">
                            <?php echo htmlspecialchars($section); ?>
                            <span class="text-muted">(<?php echo count($changes); ?>)</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <tbody>
                                <?php foreach ($changes as $c): ?>
                                    <tr>
                                        <td style="width:90px">
                                            <span class="badge bg-<?php
                                                echo $c['change'] === 'added' ? 'success'
                                                   : ($c['change'] === 'removed' ? 'danger' : 'warning text-dark'); ?>">
                                                <?php echo htmlspecialchars($c['change']); ?>
                                            </span>
                                        </td>
                                        <td class="small font-monospace"><?php echo htmlspecialchars($c['path']); ?></td>
                                        <td class="small">
                                            <?php if ($c['from'] !== null): ?>
                                                <span class="text-danger"><?php echo htmlspecialchars($c['from']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($c['from'] !== null && $c['to'] !== null): ?> &rarr; <?php endif; ?>
                                            <?php if ($c['to'] !== null): ?>
                                                <span class="text-success"><?php echo htmlspecialchars($c['to']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php elseif ($detail['baseline'] && $detail['latest'] && !$detail['error']): ?>
                <div class="alert alert-success mb-3">
                    This configuration matches the approved baseline.
                </div>
            <?php endif; ?>

            <?php if ($can_manage): ?>
            <hr>
            <div class="row g-3">
                <div class="col-md-6">
                    <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="firewall_id" value="<?php echo (int)$detail['firewall']['id']; ?>">
                        <input type="text" name="note" class="form-control form-control-sm" placeholder="Acknowledgement note (optional)">
                        <button class="btn btn-sm btn-warning text-nowrap" name="acknowledge">
                            <i class="fas fa-check me-1"></i>Acknowledge
                        </button>
                    </form>
                    <div class="form-text">Marks the change as reviewed. The baseline is unchanged.</div>
                </div>
                <?php if (can('drift.set_baseline') && $detail['backups']): ?>
                <div class="col-md-6">
                    <form method="post" class="d-flex gap-2"
                          onsubmit="return confirm('Make this configuration the approved baseline? Existing drift findings for this firewall will be cleared.')">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="firewall_id" value="<?php echo (int)$detail['firewall']['id']; ?>">
                        <select name="backup_id" class="form-select form-select-sm">
                            <?php foreach ($detail['backups'] as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>">
                                    #<?php echo (int)$b['id']; ?> &middot; <?php echo htmlspecialchars($b['at']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-primary text-nowrap" name="set_baseline">
                            <i class="fas fa-flag me-1"></i>Set baseline
                        </button>
                    </form>
                    <div class="form-text">Promotes a backup to the approved configuration.</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
