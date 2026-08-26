<?php
/**
 * OPNMGR Customers and Sites
 *
 * A customer is an MSP customer organisation: a container used to group the
 * firewalls you manage for them. Customers have no accounts and do not log in.
 * Sites sit optionally beneath a customer:
 *
 *     Customer -> Site -> Firewall(s)
 *
 * Legacy note: firewalls were previously linked to a customer by two
 * independent free-text columns, customer_name and customer_group, with no
 * foreign key. Migration 0008 added customer_id/site_id and backfilled them.
 * The legacy columns are still written by save_firewall_assignment() so the
 * pages that read them keep working, but customer_id is the source of truth.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/audit.php';

if (!function_exists('customer_list')) {
    /**
     * All customers with their firewall and site counts.
     *
     * @param bool $activeOnly Exclude customers marked inactive
     * @return array<int, array>
     */
    function customer_list(bool $activeOnly = false): array {
        $where = $activeOnly ? 'WHERE c.is_active = 1' : '';
        try {
            // Counted through customer_id, not the old name match. The previous
            // query joined on c.name = f.customer_name, which was empty on
            // firewalls linked via customer_group, so customers with firewalls
            // showed a count of zero.
            return db()->query("
                SELECT c.*,
                       (SELECT COUNT(*) FROM firewalls f WHERE f.customer_id = c.id) AS firewall_count,
                       (SELECT COUNT(*) FROM sites s WHERE s.customer_id = c.id)     AS site_count
                  FROM customers c
                  {$where}
                 ORDER BY c.name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: customer_list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('customer_get')) {
    /**
     * One customer by id, or null.
     */
    function customer_get(int $id): ?array {
        $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('site_list')) {
    /**
     * Sites, optionally restricted to one customer.
     *
     * @param int|null $customerId
     * @return array<int, array>
     */
    function site_list(?int $customerId = null): array {
        try {
            if ($customerId !== null) {
                $stmt = db()->prepare("
                    SELECT s.*, c.name AS customer_name,
                           (SELECT COUNT(*) FROM firewalls f WHERE f.site_id = s.id) AS firewall_count
                      FROM sites s
                      JOIN customers c ON c.id = s.customer_id
                     WHERE s.customer_id = ?
                     ORDER BY s.name
                ");
                $stmt->execute([$customerId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            return db()->query("
                SELECT s.*, c.name AS customer_name,
                       (SELECT COUNT(*) FROM firewalls f WHERE f.site_id = s.id) AS firewall_count
                  FROM sites s
                  JOIN customers c ON c.id = s.customer_id
                 ORDER BY c.name, s.name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: site_list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('customer_save')) {
    /**
     * Create or update a customer.
     *
     * Deliberately limited to the fields an MSP needs to organise and contact a
     * customer. This is not a CRM: no invoices, contracts, subscriptions or
     * tickets belong here.
     *
     * @param array    $data name, code, contact_person, email, phone, address,
     *                       notes, timezone, tags, is_active, maintenance window
     * @param int|null $id   Null to create
     * @return array{ok:bool, error:string, id:int}
     */
    function customer_save(array $data, ?int $id = null): array {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Customer name is required', 'id' => 0];
        }

        $email = trim((string)($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Contact email is not a valid address', 'id' => 0];
        }

        $window = normalize_maintenance_window($data);
        if (!$window['ok']) {
            return ['ok' => false, 'error' => $window['error'], 'id' => 0];
        }

        $fields = [
            'name'                     => $name,
            'code'                     => strtoupper(trim((string)($data['code'] ?? ''))) ?: null,
            'contact_person'           => trim((string)($data['contact_person'] ?? '')) ?: null,
            'email'                    => $email ?: null,
            'phone'                    => trim((string)($data['phone'] ?? '')) ?: null,
            'address'                  => trim((string)($data['address'] ?? '')) ?: null,
            'notes'                    => trim((string)($data['notes'] ?? '')) ?: null,
            'timezone'                 => valid_timezone($data['timezone'] ?? ''),
            'tags'                     => normalize_tags($data['tags'] ?? ''),
            'is_active'                => !empty($data['is_active']) ? 1 : 0,
            'maintenance_window_start' => $window['start'],
            'maintenance_window_end'   => $window['end'],
            'maintenance_window_days'  => $window['days'],
        ];

        try {
            if ($id === null) {
                $cols = implode(',', array_keys($fields));
                $ph   = implode(',', array_fill(0, count($fields), '?'));
                db()->prepare("INSERT INTO customers ({$cols}) VALUES ({$ph})")
                    ->execute(array_values($fields));
                $id = (int)db()->lastInsertId();
                audit_log('customer.create', [
                    'object_type' => 'customer', 'object_id' => (string)$id, 'customer_id' => $id,
                    'message' => 'Customer created: ' . $name,
                ]);
            } else {
                $set = implode(' = ?, ', array_keys($fields)) . ' = ?';
                $params = array_values($fields);
                $params[] = $id;
                db()->prepare("UPDATE customers SET {$set} WHERE id = ?")->execute($params);

                // Keep the legacy display column on firewalls consistent with a
                // rename, so pages still reading customer_name do not go stale.
                db()->prepare('UPDATE firewalls SET customer_name = ? WHERE customer_id = ?')
                    ->execute([$name, $id]);

                audit_log('customer.update', [
                    'object_type' => 'customer', 'object_id' => (string)$id, 'customer_id' => $id,
                    'message' => 'Customer updated: ' . $name,
                ]);
            }
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                return ['ok' => false, 'error' => 'A customer with that name already exists', 'id' => 0];
            }
            error_log('OPNMGR: customer_save failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the customer', 'id' => 0];
        }

        return ['ok' => true, 'error' => '', 'id' => (int)$id];
    }
}

if (!function_exists('site_save')) {
    /**
     * Create or update a site.
     *
     * @return array{ok:bool, error:string, id:int}
     */
    function site_save(array $data, ?int $id = null): array {
        $customerId = (int)($data['customer_id'] ?? 0);
        $name       = trim((string)($data['name'] ?? ''));

        if ($customerId <= 0) {
            return ['ok' => false, 'error' => 'A site must belong to a customer', 'id' => 0];
        }
        if ($name === '') {
            return ['ok' => false, 'error' => 'Site name is required', 'id' => 0];
        }
        if (customer_get($customerId) === null) {
            return ['ok' => false, 'error' => 'That customer does not exist', 'id' => 0];
        }

        $window = normalize_maintenance_window($data);
        if (!$window['ok']) {
            return ['ok' => false, 'error' => $window['error'], 'id' => 0];
        }

        $fields = [
            'customer_id'              => $customerId,
            'name'                     => $name,
            'code'                     => strtoupper(trim((string)($data['code'] ?? ''))) ?: null,
            'timezone'                 => valid_timezone($data['timezone'] ?? ''),
            'address'                  => trim((string)($data['address'] ?? '')) ?: null,
            'notes'                    => trim((string)($data['notes'] ?? '')) ?: null,
            'is_active'                => !empty($data['is_active']) ? 1 : 0,
            'maintenance_window_start' => $window['start'],
            'maintenance_window_end'   => $window['end'],
            'maintenance_window_days'  => $window['days'],
        ];

        try {
            if ($id === null) {
                $cols = implode(',', array_keys($fields));
                $ph   = implode(',', array_fill(0, count($fields), '?'));
                db()->prepare("INSERT INTO sites ({$cols}) VALUES ({$ph})")
                    ->execute(array_values($fields));
                $id = (int)db()->lastInsertId();
                audit_log('site.create', [
                    'object_type' => 'site', 'object_id' => (string)$id,
                    'customer_id' => $customerId, 'site_id' => $id,
                    'message' => 'Site created: ' . $name,
                ]);
            } else {
                $set = implode(' = ?, ', array_keys($fields)) . ' = ?';
                $params = array_values($fields);
                $params[] = $id;
                db()->prepare("UPDATE sites SET {$set} WHERE id = ?")->execute($params);
                audit_log('site.update', [
                    'object_type' => 'site', 'object_id' => (string)$id,
                    'customer_id' => $customerId, 'site_id' => (int)$id,
                    'message' => 'Site updated: ' . $name,
                ]);
            }
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                return ['ok' => false, 'error' => 'That customer already has a site with this name', 'id' => 0];
            }
            error_log('OPNMGR: site_save failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the site', 'id' => 0];
        }

        return ['ok' => true, 'error' => '', 'id' => (int)$id];
    }
}

if (!function_exists('save_firewall_assignment')) {
    /**
     * Assign a firewall to a customer and optionally a site.
     *
     * Writes customer_id/site_id and keeps the legacy customer_name and
     * customer_group strings in step, because roughly nineteen pages still read
     * them. Rejects a site that belongs to a different customer, which the old
     * free-text scheme could not express at all.
     *
     * @return array{ok:bool, error:string}
     */
    function save_firewall_assignment(int $firewallId, ?int $customerId, ?int $siteId): array {
        if ($customerId !== null && $customerId > 0 && customer_get($customerId) === null) {
            return ['ok' => false, 'error' => 'That customer does not exist'];
        }

        $customerName = '';
        if ($customerId) {
            $customerName = (string)(customer_get($customerId)['name'] ?? '');
        }

        if ($siteId !== null && $siteId > 0) {
            $stmt = db()->prepare('SELECT customer_id FROM sites WHERE id = ?');
            $stmt->execute([$siteId]);
            $siteCustomer = $stmt->fetchColumn();

            if ($siteCustomer === false) {
                return ['ok' => false, 'error' => 'That site does not exist'];
            }
            if ($customerId && (int)$siteCustomer !== $customerId) {
                return ['ok' => false, 'error' => 'That site belongs to a different customer'];
            }
            if (!$customerId) {
                // Assigning a site implies its customer.
                $customerId   = (int)$siteCustomer;
                $customerName = (string)(customer_get($customerId)['name'] ?? '');
            }
        } else {
            $siteId = null;
        }

        try {
            db()->prepare(
                'UPDATE firewalls
                    SET customer_id = ?, site_id = ?, customer_name = ?, customer_group = ?
                  WHERE id = ?'
            )->execute([
                $customerId ?: null,
                $siteId ?: null,
                $customerName,
                $customerName,   // legacy: both columns held the same idea
                $firewallId,
            ]);
        } catch (Throwable $e) {
            error_log('OPNMGR: save_firewall_assignment failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the assignment'];
        }

        audit_log('firewall.assign', [
            'object_type' => 'firewall',
            'object_id'   => (string)$firewallId,
            'firewall_id' => $firewallId,
            'customer_id' => $customerId ?: null,
            'site_id'     => $siteId ?: null,
            'message'     => $customerId
                ? sprintf('Firewall assigned to %s%s', $customerName, $siteId ? " / site #{$siteId}" : '')
                : 'Firewall assignment cleared',
        ]);

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('normalize_maintenance_window')) {
    /**
     * Validate a default maintenance window.
     *
     * A window may be absent entirely; if either time is given, both must be.
     *
     * @return array{ok:bool, error:string, start:?string, end:?string, days:?string}
     */
    function normalize_maintenance_window(array $data): array {
        $start = trim((string)($data['maintenance_window_start'] ?? ''));
        $end   = trim((string)($data['maintenance_window_end'] ?? ''));
        $days  = $data['maintenance_window_days'] ?? '';

        if (is_array($days)) {
            $days = implode(',', $days);
        }
        $days = trim((string)$days);

        if ($start === '' && $end === '') {
            return ['ok' => true, 'error' => '', 'start' => null, 'end' => null, 'days' => null];
        }

        $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
        if (!preg_match($timeRe, $start) || !preg_match($timeRe, $end)) {
            return ['ok' => false, 'error' => 'Maintenance window times must be HH:MM',
                    'start' => null, 'end' => null, 'days' => null];
        }

        // Day numbers 0-6, Sunday first. An empty list means every day.
        $cleanDays = [];
        foreach (explode(',', $days) as $d) {
            $d = trim($d);
            if ($d === '') {
                continue;
            }
            if (!ctype_digit($d) || (int)$d > 6) {
                return ['ok' => false, 'error' => 'Maintenance window days must be numbers 0-6 (0 = Sunday)',
                        'start' => null, 'end' => null, 'days' => null];
            }
            $cleanDays[(int)$d] = true;
        }
        ksort($cleanDays);

        return [
            'ok'    => true,
            'error' => '',
            'start' => substr($start, 0, 5) . ':00',
            'end'   => substr($end, 0, 5) . ':00',
            'days'  => $cleanDays ? implode(',', array_keys($cleanDays)) : null,
        ];
    }
}

if (!function_exists('valid_timezone')) {
    /**
     * Return the value if it is a real timezone identifier, otherwise null.
     */
    function valid_timezone($value): ?string {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        return in_array($value, timezone_identifiers_list(), true) ? $value : null;
    }
}

if (!function_exists('normalize_tags')) {
    /**
     * Normalise a comma-separated tag list: trimmed, de-duplicated, lowercase.
     */
    function normalize_tags($value): ?string {
        if (is_array($value)) {
            $value = implode(',', $value);
        }
        $tags = [];
        foreach (explode(',', (string)$value) as $tag) {
            $tag = strtolower(trim($tag));
            $tag = preg_replace('/[^a-z0-9 _-]/', '', $tag);
            if ($tag !== '') {
                $tags[$tag] = true;
            }
        }
        return $tags ? implode(',', array_keys($tags)) : null;
    }
}
