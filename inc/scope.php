<?php
/**
 * OPNMGR staff access scopes.
 *
 * Roles (inc/permissions.php) say what a user may do; scopes say to which
 * customers. A user with no rows in user_customer_scopes is unrestricted,
 * which is how every account behaved before 3.79. Admins are always
 * unrestricted. Firewalls with no customer are visible only to unrestricted
 * users.
 *
 * Every module that shows or sends customer data (reports, integrations,
 * VPN topology, templates) filters through here rather than re-deriving it.
 *
 * @since 3.79.0
 */

if (!function_exists('scope_customer_ids')) {
    /**
     * Customer ids the current user may access, or null for "all".
     * CLI and system contexts (no session) are unrestricted.
     */
    function scope_customer_ids(?int $userId = null): ?array {
        $userId = $userId ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        if ($userId === null) {
            return null;
        }
        if (($userId === (int)($_SESSION['user_id'] ?? -1)) && (($_SESSION['role'] ?? '') === 'admin')) {
            return null;
        }
        static $cache = [];
        if (!array_key_exists($userId, $cache)) {
            try {
                $s = db()->prepare('SELECT customer_id FROM user_customer_scopes WHERE user_id = ?');
                $s->execute([$userId]);
                $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
                $cache[$userId] = $ids ? $ids : null;
            } catch (Throwable $e) {
                // Fail closed: an unreadable scope table must not widen access.
                $cache[$userId] = [];
            }
        }
        return $cache[$userId];
    }
}

if (!function_exists('scope_can_customer')) {
    function scope_can_customer(?int $customerId, ?int $userId = null): bool {
        $ids = scope_customer_ids($userId);
        if ($ids === null) {
            return true;
        }
        return $customerId !== null && in_array($customerId, $ids, true);
    }
}

if (!function_exists('scope_can_firewall')) {
    function scope_can_firewall(int $firewallId, ?int $userId = null): bool {
        if (scope_customer_ids($userId) === null) {
            return true;
        }
        $s = db()->prepare('SELECT customer_id FROM firewalls WHERE id = ?');
        $s->execute([$firewallId]);
        $c = $s->fetchColumn();
        return $c !== false && $c !== null && scope_can_customer((int)$c, $userId);
    }
}

if (!function_exists('scope_sql')) {
    /**
     * SQL condition restricting a customer_id column to the user's scope.
     * Returns [sql, params]; "1=1" when unrestricted.
     *
     *   [$w, $p] = scope_sql('f.customer_id');
     *   db()->prepare("SELECT ... WHERE $w")->execute($p);
     */
    function scope_sql(string $column, ?int $userId = null): array {
        $ids = scope_customer_ids($userId);
        if ($ids === null) {
            return ['1=1', []];
        }
        if (!$ids) {
            return ['1=0', []];
        }
        return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }
}
