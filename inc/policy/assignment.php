<?php
/**
 * OPNMGR policy templates: assignment, inheritance, variables, exceptions.
 *
 * Precedence is fixed and displayed: global < customer < site < firewall.
 * For one template, the most specific scope that assigns it wins, and every
 * less specific assignment is shown as "overridden by" that one.
 *
 * What is never resolved silently:
 *
 *   - Two assignments of the same template at the same, winning scope that pin
 *     different revisions. There is no rule that says which one the operator
 *     meant, so the template is reported as a conflict and not planned.
 *   - Two different templates that would own the same object on one firewall
 *     (same alias name, or both setting the system DNS / NTP list). Both are
 *     reported as a conflict; neither is planned until one is changed.
 *
 * Variables inherit the same way (global < customer < site < firewall); at one
 * scope a template-specific value beats a value set for every template.
 *
 * Exceptions waive one managed object (or a whole template) on one firewall.
 * They need a reason and an owner, may expire, and are revoked rather than
 * deleted so the history stays in the table as well as in the audit log.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/model.php';

if (!function_exists('policy_scope_chain')) {
    /**
     * The scopes that apply to a firewall, least specific first.
     * @return array<int, array{0:string, 1:int}>
     */
    function policy_scope_chain(array $fw): array {
        $chain = [['global', 0]];
        if (!empty($fw['customer_id'])) { $chain[] = ['customer', (int)$fw['customer_id']]; }
        if (!empty($fw['site_id']))     { $chain[] = ['site', (int)$fw['site_id']]; }
        $chain[] = ['firewall', (int)$fw['id']];
        return $chain;
    }
}

if (!function_exists('policy_assignment_create')) {
    /** @return array{ok:bool, error:string, id:int} */
    function policy_assignment_create(int $templateId, int $revisionId, string $scopeType, ?int $scopeId): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0];
        if (!in_array($scopeType, POLICY_SCOPES, true)) {
            return $fail('Unknown scope');
        }
        $scopeId = $scopeType === 'global' ? null : (int)$scopeId;
        if ($scopeType !== 'global' && $scopeId <= 0) {
            return $fail('Choose the ' . $scopeType);
        }
        $rev = policy_revision_get($revisionId);
        if (!$rev || (int)$rev['template_id'] !== $templateId) {
            return $fail('Revision not found for this template');
        }
        if ($rev['status'] !== 'published') {
            return $fail('Only a published revision can be assigned');
        }
        if ($scopeType !== 'global') {
            $table = ['customer' => 'customers', 'site' => 'sites', 'firewall' => 'firewalls'][$scopeType];
            $s = db()->prepare("SELECT id FROM {$table} WHERE id = ?");
            $s->execute([$scopeId]);
            if (!$s->fetchColumn()) {
                return $fail(ucfirst($scopeType) . ' not found');
            }
        }
        db()->prepare('INSERT INTO policy_assignments (template_id, revision_id, scope_type, scope_id, created_by) VALUES (?,?,?,?,?)')
            ->execute([$templateId, $revisionId, $scopeType, $scopeId, policy_actor()]);
        $id = (int)db()->lastInsertId();
        audit_log('policy.assignment.create', [
            'object_type' => 'policy_assignment', 'object_id' => (string)$id,
            'firewall_id' => $scopeType === 'firewall' ? $scopeId : null,
            'customer_id' => $scopeType === 'customer' ? $scopeId : null,
            'site_id'     => $scopeType === 'site' ? $scopeId : null,
            'message'     => sprintf('Template #%d rev %d assigned at %s%s', $templateId, $rev['revision'],
                                     $scopeType, $scopeId ? " #{$scopeId}" : ''),
        ]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}

if (!function_exists('policy_assignment_remove')) {
    function policy_assignment_remove(int $id): array {
        $stmt = db()->prepare('UPDATE policy_assignments SET is_active = 0 WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Assignment not found'];
        }
        audit_log('policy.assignment.remove', ['object_type' => 'policy_assignment', 'object_id' => (string)$id,
            'message' => 'Template assignment removed (managed objects already on firewalls are left in place)']);
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('policy_assignment_list')) {
    function policy_assignment_list(?int $templateId = null): array {
        $sql = 'SELECT a.*, t.name AS template_name, r.revision, r.status AS revision_status,
                       c.name AS customer_name, s.name AS site_name, f.hostname AS firewall_hostname
                  FROM policy_assignments a
                  JOIN policy_templates t ON t.id = a.template_id
                  JOIN policy_template_revisions r ON r.id = a.revision_id
                  LEFT JOIN customers c ON a.scope_type = "customer" AND c.id = a.scope_id
                  LEFT JOIN sites s ON a.scope_type = "site" AND s.id = a.scope_id
                  LEFT JOIN firewalls f ON a.scope_type = "firewall" AND f.id = a.scope_id
                 WHERE a.is_active = 1' . ($templateId ? ' AND a.template_id = ?' : '') . '
                 ORDER BY FIELD(a.scope_type,"global","customer","site","firewall"), a.id';
        $s = db()->prepare($sql);
        $s->execute($templateId ? [$templateId] : []);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('policy_owned_objects')) {
    /**
     * The identities a revision would own on a firewall, for cross-template
     * conflict detection: alias names and singleton settings.
     */
    function policy_owned_objects(array $content): array {
        $own = [];
        foreach ($content['components'] ?? [] as $c) {
            switch ($c['type']) {
                case 'alias':
                    $own[] = 'alias:' . strtolower((string)$c['name']);
                    break;
                case 'management_access':
                    $own[] = 'alias:' . strtolower(policy_mgmt_alias_name($c));
                    break;
                case 'dns_servers':
                case 'ntp_servers':
                    $own[] = 'setting:' . $c['type'];
                    break;
            }
        }
        return array_values(array_unique($own));
    }
}

if (!function_exists('policy_mgmt_alias_name')) {
    function policy_mgmt_alias_name(array $c): string {
        $n = (string)($c['alias_name'] ?? '');
        return $n !== '' ? $n : substr('OPNMGR_MGMT_' . strtoupper($c['key']), 0, 31);
    }
}

if (!function_exists('policy_resolve_for_firewall')) {
    /**
     * Effective templates for a firewall, with the full precedence chain and
     * every conflict.
     *
     * @return array{
     *   effective: array<int, array{template_id:int, template_name:string, revision_id:int, revision:int,
     *                               scope_type:string, scope_id:?int, assignment_id:int}>,
     *   chain: array, conflicts: array<int, array{template_ids:int[], reason:string}>,
     *   invalid: array<int, string>
     * }
     */
    function policy_resolve_for_firewall(array $fw): array {
        $chain = policy_scope_chain($fw);
        $where = [];
        $params = [];
        foreach ($chain as [$type, $id]) {
            if ($type === 'global') {
                $where[] = '(a.scope_type = "global")';
            } else {
                $where[] = '(a.scope_type = ? AND a.scope_id = ?)';
                $params[] = $type;
                $params[] = $id;
            }
        }
        $s = db()->prepare('SELECT a.*, t.name AS template_name, r.revision, r.status AS revision_status
                              FROM policy_assignments a
                              JOIN policy_templates t ON t.id = a.template_id
                              JOIN policy_template_revisions r ON r.id = a.revision_id
                             WHERE a.is_active = 1 AND (' . implode(' OR ', $where) . ')
                             ORDER BY a.id');
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);

        $rank = array_flip(POLICY_SCOPES);
        $byTemplate = [];
        foreach ($rows as $r) {
            $byTemplate[(int)$r['template_id']][] = $r;
        }

        $effective = [];
        $display = [];
        $conflicts = [];
        $invalid = [];
        foreach ($byTemplate as $tid => $list) {
            $top = max(array_map(fn($r) => $rank[$r['scope_type']], $list));
            $winners = array_values(array_filter($list, fn($r) => $rank[$r['scope_type']] === $top));
            $revs = array_unique(array_map(fn($r) => (int)$r['revision_id'], $winners));
            foreach ($list as $r) {
                $state = $rank[$r['scope_type']] < $top ? 'overridden' : (count($revs) > 1 ? 'conflict' : 'effective');
                $display[] = ['assignment_id' => (int)$r['id'], 'template_id' => $tid, 'template_name' => $r['template_name'],
                              'revision' => (int)$r['revision'], 'scope_type' => $r['scope_type'],
                              'scope_id' => $r['scope_id'] !== null ? (int)$r['scope_id'] : null, 'state' => $state];
            }
            if (count($revs) > 1) {
                $conflicts[] = ['template_ids' => [$tid], 'reason' => sprintf(
                    "Template '%s' is assigned at %s scope with different revisions (%s); choose one.",
                    $winners[0]['template_name'], $winners[0]['scope_type'],
                    implode(', ', array_map(fn($r) => 'rev ' . $r['revision'], $winners)))];
                continue;
            }
            $w = $winners[0];
            if ($w['revision_status'] !== 'published') {
                $invalid[$tid] = sprintf("Assigned revision %d of '%s' is %s; assign a published revision.",
                    $w['revision'], $w['template_name'], $w['revision_status']);
                continue;
            }
            $effective[$tid] = ['template_id' => $tid, 'template_name' => $w['template_name'],
                                'revision_id' => (int)$w['revision_id'], 'revision' => (int)$w['revision'],
                                'scope_type' => $w['scope_type'],
                                'scope_id' => $w['scope_id'] !== null ? (int)$w['scope_id'] : null,
                                'assignment_id' => (int)$w['id']];
        }

        // Cross-template ownership conflicts.
        $owners = [];
        foreach ($effective as $tid => $e) {
            $rev = policy_revision_get($e['revision_id']);
            foreach (policy_owned_objects($rev['content_decoded'] ?? []) as $obj) {
                $owners[$obj][] = $tid;
            }
        }
        foreach ($owners as $obj => $tids) {
            if (count($tids) > 1) {
                $names = array_map(fn($t) => "'" . $effective[$t]['template_name'] . "'", $tids);
                $conflicts[] = ['template_ids' => $tids, 'reason' => sprintf(
                    'Templates %s would both own %s on this firewall.', implode(' and ', $names), $obj)];
            }
        }
        foreach ($conflicts as $c) {
            foreach ($c['template_ids'] as $tid) {
                unset($effective[$tid]);
                foreach ($display as &$d) {
                    if ($d['template_id'] === $tid && $d['state'] === 'effective') { $d['state'] = 'conflict'; }
                }
                unset($d);
            }
        }

        return ['effective' => $effective, 'chain' => $display, 'conflicts' => $conflicts, 'invalid' => $invalid];
    }
}

// ---------------------------------------------------------------------------
// Variables
// ---------------------------------------------------------------------------

if (!function_exists('policy_variable_set')) {
    /** Store a value at a scope (validated against the type given). */
    function policy_variable_set(string $scopeType, int $scopeId, int $templateId, string $name, string $type, $value): array {
        if (!in_array($scopeType, POLICY_SCOPES, true) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            return ['ok' => false, 'error' => 'Invalid scope or variable name'];
        }
        $v = policy_validate_value($type, $value);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => "{$name} {$v['error']}"];
        }
        db()->prepare('INSERT INTO policy_variable_values (scope_type, scope_id, template_id, name, value, updated_by)
                       VALUES (?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)')
            ->execute([$scopeType, $scopeType === 'global' ? 0 : $scopeId, $templateId, $name, json_encode($v['value']), policy_actor()]);
        audit_log('policy.variable.set', ['object_type' => 'policy_variable', 'object_id' => $name,
            'firewall_id' => $scopeType === 'firewall' ? $scopeId : null,
            'message' => sprintf('Variable %s set at %s%s', $name, $scopeType, $scopeType === 'global' ? '' : " #{$scopeId}"),
            'metadata' => ['template_id' => $templateId, 'value' => $v['value']]]);
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('policy_variable_delete')) {
    function policy_variable_delete(string $scopeType, int $scopeId, int $templateId, string $name): void {
        db()->prepare('DELETE FROM policy_variable_values WHERE scope_type = ? AND scope_id = ? AND template_id = ? AND name = ?')
            ->execute([$scopeType, $scopeType === 'global' ? 0 : $scopeId, $templateId, $name]);
        audit_log('policy.variable.delete', ['object_type' => 'policy_variable', 'object_id' => $name,
            'message' => "Variable {$name} cleared at {$scopeType}"]);
    }
}

if (!function_exists('policy_resolve_variables')) {
    /**
     * Resolve and validate every declared variable of a revision for one
     * firewall. Missing required values and invalid values are errors, and a
     * plan with errors is never approvable.
     *
     * @return array{ok:bool, values:array, errors:string[], sources:array}
     */
    function policy_resolve_variables(array $fw, int $templateId, array $content): array {
        $values = [];
        $sources = [];
        $s = db()->prepare('SELECT * FROM policy_variable_values WHERE template_id IN (0, ?)');
        $s->execute([$templateId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $chain = policy_scope_chain($fw);

        foreach ($chain as [$type, $id]) {
            foreach ([0, $templateId] as $tpl) {   // generic first, template-specific overrides
                foreach ($rows as $r) {
                    if ($r['scope_type'] === $type && (int)$r['scope_id'] === ($type === 'global' ? 0 : $id)
                        && (int)$r['template_id'] === $tpl) {
                        $values[$r['name']] = json_decode((string)$r['value'], true);
                        $sources[$r['name']] = $type . ($type === 'global' ? '' : " #{$id}") . ($tpl ? ' (template)' : '');
                    }
                }
            }
        }

        $out = [];
        $errors = [];
        foreach ($content['variables'] ?? [] as $var) {
            $n = $var['name'];
            $raw = $values[$n] ?? ($var['default'] ?? null);
            if (!array_key_exists($n, $values) && array_key_exists('default', $var)) {
                $sources[$n] = 'template default';
            }
            if ($raw === null || $raw === '' || $raw === []) {
                if (!empty($var['required'])) {
                    $errors[] = "Variable {$n} ({$var['label']}) has no value for this firewall";
                }
                continue;
            }
            $v = policy_validate_value($var['type'], $raw);
            if (!$v['ok']) {
                $errors[] = "Variable {$n} {$v['error']}";
                continue;
            }
            $out[$n] = ['type' => $var['type'], 'value' => $v['value']];
        }
        return ['ok' => !$errors, 'values' => $out, 'errors' => $errors,
                'sources' => array_intersect_key($sources, $out)];
    }
}

// ---------------------------------------------------------------------------
// Exceptions
// ---------------------------------------------------------------------------

if (!function_exists('policy_exception_create')) {
    /** @return array{ok:bool, error:string, id:int} */
    function policy_exception_create(int $firewallId, int $templateId, string $objectKey, string $reason,
                                     string $owner, ?string $expiresAt = null): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'id' => 0];
        $reason = trim($reason);
        $owner = trim($owner);
        if ($objectKey !== '*' && !preg_match('/^[a-z][a-z0-9_]{0,47}$/', $objectKey)) {
            return $fail('Choose the managed object (component key) or * for the whole template');
        }
        if (strlen($reason) < 5) {
            return $fail('An exception needs a reason');
        }
        if ($owner === '') {
            return $fail('An exception needs an owner');
        }
        $exp = null;
        if ($expiresAt !== null && trim($expiresAt) !== '') {
            $ts = strtotime($expiresAt);
            if ($ts === false || $ts <= time()) {
                return $fail('The expiry must be a future date');
            }
            $exp = date('Y-m-d H:i:s', $ts);
        }
        db()->prepare('INSERT INTO policy_exceptions (firewall_id, template_id, object_key, reason, owner, expires_at, created_by)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$firewallId, $templateId, $objectKey, substr($reason, 0, 512), substr($owner, 0, 128), $exp, policy_actor()]);
        $id = (int)db()->lastInsertId();
        audit_log('policy.exception.create', ['object_type' => 'policy_exception', 'object_id' => (string)$id,
            'firewall_id' => $firewallId,
            'message' => sprintf('Exception for template #%d object %s (owner %s%s)', $templateId, $objectKey, $owner,
                                 $exp ? ", expires {$exp}" : ''),
            'metadata' => ['reason' => $reason, 'owner' => $owner, 'expires_at' => $exp]]);
        return ['ok' => true, 'error' => '', 'id' => $id];
    }
}

if (!function_exists('policy_exception_revoke')) {
    function policy_exception_revoke(int $id): array {
        $stmt = db()->prepare('UPDATE policy_exceptions SET revoked_at = NOW(), revoked_by = ? WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([policy_actor(), $id]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Exception not found or already revoked'];
        }
        audit_log('policy.exception.revoke', ['object_type' => 'policy_exception', 'object_id' => (string)$id,
            'message' => 'Template exception revoked']);
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('policy_active_exceptions')) {
    /** Unrevoked, unexpired exceptions, keyed by object key ('*' = whole template). */
    function policy_active_exceptions(int $firewallId, int $templateId): array {
        $s = db()->prepare('SELECT * FROM policy_exceptions
                             WHERE firewall_id = ? AND template_id = ? AND revoked_at IS NULL
                               AND (expires_at IS NULL OR expires_at > NOW())');
        $s->execute([$firewallId, $templateId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['object_key']] = $r;
        }
        return $out;
    }
}

if (!function_exists('policy_exception_history')) {
    function policy_exception_history(?int $firewallId = null, ?int $templateId = null): array {
        $w = ['1=1'];
        $p = [];
        if ($firewallId) { $w[] = 'e.firewall_id = ?'; $p[] = $firewallId; }
        if ($templateId) { $w[] = 'e.template_id = ?'; $p[] = $templateId; }
        $s = db()->prepare('SELECT e.*, f.hostname, t.name AS template_name,
                                   CASE WHEN e.revoked_at IS NOT NULL THEN "revoked"
                                        WHEN e.expires_at IS NOT NULL AND e.expires_at <= NOW() THEN "expired"
                                        ELSE "active" END AS state
                              FROM policy_exceptions e
                              LEFT JOIN firewalls f ON f.id = e.firewall_id
                              LEFT JOIN policy_templates t ON t.id = e.template_id
                             WHERE ' . implode(' AND ', $w) . ' ORDER BY e.id DESC LIMIT 500');
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ---------------------------------------------------------------------------
// Explicit adoption of existing unmanaged objects
// ---------------------------------------------------------------------------

if (!function_exists('policy_mapping_set')) {
    /**
     * Map a template object to an existing unmanaged object on one firewall.
     * The next plan shows it as "adopt" with the exact field changes; nothing
     * is adopted until that plan is approved.
     */
    function policy_mapping_set(int $firewallId, int $templateId, string $objectKey, string $targetRef): array {
        if (!preg_match('/^[a-z][a-z0-9_]{0,47}$/', $objectKey) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $targetRef)) {
            return ['ok' => false, 'error' => 'Invalid object key or target reference'];
        }
        db()->prepare('INSERT INTO policy_object_mappings (firewall_id, template_id, object_key, target_ref, created_by)
                       VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE target_ref = VALUES(target_ref), created_by = VALUES(created_by)')
            ->execute([$firewallId, $templateId, $objectKey, $targetRef, policy_actor()]);
        audit_log('policy.mapping.set', ['object_type' => 'policy_mapping', 'object_id' => $objectKey,
            'firewall_id' => $firewallId,
            'message' => sprintf('Template #%d object %s mapped to existing %s', $templateId, $objectKey, $targetRef)]);
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('policy_mappings_for')) {
    function policy_mappings_for(int $firewallId, int $templateId): array {
        $s = db()->prepare('SELECT object_key, target_ref FROM policy_object_mappings WHERE firewall_id = ? AND template_id = ?');
        $s->execute([$firewallId, $templateId]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
}
