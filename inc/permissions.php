<?php
/**
 * OPNMGR Role-Based Access Control
 *
 * Permissions are defined once, here, as a capability => roles matrix. Pages
 * and endpoints ask `can('backup.restore')` rather than testing role strings
 * inline, so the answer to "who is allowed to do X" lives in one readable table
 * instead of being scattered across sixty files.
 *
 * OPNMGR is self-hosted software for an MSP's own staff. Customers are
 * organisational containers for grouping firewalls; they do not log in, and
 * there is deliberately no customer, reseller or tenant role.
 *
 * Roles
 * -----
 *   admin      Full access: users, settings, secrets, raw shell, restores.
 *   technician Day-to-day operations across the managed fleet: monitoring,
 *              diagnostics, approved commands, backups, updates. No
 *              application-wide security, settings or user administration.
 *   readonly   View and report only. No action that changes anything.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/audit.php';

if (!defined('OPNMGR_ROLES')) {
    define('OPNMGR_ROLES', ['admin', 'technician', 'readonly']);
}

if (!function_exists('opnmgr_permission_matrix')) {
    /**
     * capability => roles allowed to exercise it.
     *
     * 'admin' is listed explicitly rather than being implied, so that reading a
     * single row tells you the whole answer.
     *
     * @return array<string, string[]>
     */
    function opnmgr_permission_matrix(): array {
        $all        = ['admin', 'technician', 'readonly'];
        $operators  = ['admin', 'technician'];
        $adminOnly  = ['admin'];

        return [
            // --- viewing -----------------------------------------------------
            'dashboard.view'        => $all,
            'firewall.view'         => $all,
            'customer.view'         => $all,
            'site.view'             => $all,
            'backup.view'           => $all,
            'report.view'           => $all,
            'alert.view'            => $all,
            'audit.view'            => $all,
            'command.view'          => $all,
            'health.view'           => $all,
            'drift.view'            => $all,
            'config.search'         => $all,

            // --- fleet operations --------------------------------------------
            'firewall.manage'       => $operators,  // add, edit, tag, assign
            'firewall.enroll'       => $operators,
            'firewall.delete'       => $adminOnly,  // destroys history

            'customer.manage'       => $operators,
            'site.manage'           => $operators,

            'command.diagnostic'    => $operators,  // LOW risk structured actions
            'command.operational'   => $operators,  // MEDIUM risk structured actions
            'command.privileged'    => $adminOnly,  // HIGH/CRITICAL structured actions
            'command.raw'           => $adminOnly,  // free-form shell
            'command.cancel'        => $operators,

            'backup.create'         => $operators,
            'backup.download'       => $operators,
            'backup.restore'        => $adminOnly,  // overwrites a live firewall
            'backup.delete'         => $adminOnly,

            'update.check'          => $operators,
            'update.install'        => $operators,
            'update.schedule'       => $operators,
            'agent.update'          => $operators,
            'agent.restart'         => $operators,

            'tunnel.open'           => $operators,
            'tunnel.close'          => $operators,

            'drift.acknowledge'     => $operators,
            'drift.set_baseline'    => $operators,

            'alert.manage'          => $operators,
            'alert.acknowledge'     => $operators,
            'maintenance.manage'    => $operators,

            'bulk.operate'          => $operators,  // bulk safe operations
            'bulk.privileged'       => $adminOnly,  // bulk high-risk operations

            // --- application administration -----------------------------------
            'user.manage'           => $adminOnly,
            'role.manage'           => $adminOnly,
            'settings.manage'       => $adminOnly,
            'secret.manage'         => $adminOnly,
            'ai.manage'             => $adminOnly,
            'integration.manage'    => $adminOnly,
            'security.manage'       => $adminOnly,
            'system.maintenance'    => $adminOnly,
        ];
    }
}

if (!function_exists('current_role')) {
    /**
     * Role of the signed-in user, or null when not signed in.
     *
     * Unknown values fall back to the least-privileged role rather than being
     * treated as an error, so a corrupted session cannot escalate.
     */
    function current_role(): ?string {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $role = $_SESSION['role'] ?? '';
        return in_array($role, OPNMGR_ROLES, true) ? $role : 'readonly';
    }
}

if (!function_exists('can')) {
    /**
     * Whether the signed-in user holds a capability.
     *
     * An unknown capability returns false and is logged: a typo in a permission
     * name must deny access, never grant it.
     *
     * @param string $capability e.g. 'backup.restore'
     */
    function can(string $capability): bool {
        $role = current_role();
        if ($role === null) {
            return false;
        }

        $matrix = opnmgr_permission_matrix();

        if (!isset($matrix[$capability])) {
            error_log("OPNMGR: unknown permission '{$capability}' checked - denying by default");
            return false;
        }

        return in_array($role, $matrix[$capability], true);
    }
}

if (!function_exists('require_permission')) {
    /**
     * Enforce a capability, or terminate the request.
     *
     * Answers JSON for API/AJAX callers and redirects browsers, matching the
     * existing behaviour of requireLogin().
     *
     * @param string $capability
     * @param array  $context Extra audit context (firewall_id, object_id, ...)
     */
    function require_permission(string $capability, array $context = []): void {
        if (function_exists('requireLogin')) {
            requireLogin();
        }

        if (can($capability)) {
            return;
        }

        audit_log('authz.denied', array_merge($context, [
            'success'  => false,
            'message'  => sprintf(
                'Role "%s" denied capability "%s"',
                current_role() ?? 'anonymous',
                $capability
            ),
            'metadata' => ['capability' => $capability],
        ]));

        $wantsJson = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
            || str_contains($_SERVER['REQUEST_URI'] ?? '', '/ajax/')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

        if ($wantsJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Your role does not permit this operation',
            ]);
            exit;
        }

        http_response_code(403);
        if (function_exists('render_permission_denied')) {
            render_permission_denied($capability);
            exit;
        }
        header('Location: /dashboard.php?denied=' . urlencode($capability));
        exit;
    }
}

if (!function_exists('role_label')) {
    /**
     * Human-readable role name for the UI.
     */
    function role_label(?string $role): string {
        return match ($role) {
            'admin'      => 'Administrator',
            'technician' => 'Technician',
            'readonly'   => 'Read Only',
            default      => 'Unknown',
        };
    }
}

if (!function_exists('role_description')) {
    /**
     * One-line explanation shown on the user administration screen.
     */
    function role_description(string $role): string {
        return match ($role) {
            'admin'      => 'Full access, including users, settings, secrets, raw shell commands and configuration restores.',
            'technician' => 'Day-to-day fleet operations: monitoring, diagnostics, approved commands, backups and updates. No application administration.',
            'readonly'   => 'View dashboards, firewalls, reports and audit history. Cannot change anything.',
            default      => '',
        };
    }
}

if (!function_exists('capabilities_for_role')) {
    /**
     * Every capability a role holds. Used by the role matrix screen and tests.
     *
     * @return string[]
     */
    function capabilities_for_role(string $role): array {
        $held = [];
        foreach (opnmgr_permission_matrix() as $capability => $roles) {
            if (in_array($role, $roles, true)) {
                $held[] = $capability;
            }
        }
        sort($held);
        return $held;
    }
}

if (!function_exists('risk_to_capability')) {
    /**
     * Map a structured command's risk level onto the capability it needs.
     *
     * Keeps api/queue_action.php from re-deciding policy: the catalogue says how
     * dangerous an action is, and this says who may run something that
     * dangerous.
     */
    function risk_to_capability(string $risk): string {
        return match (strtoupper($risk)) {
            'LOW'            => 'command.diagnostic',
            'MEDIUM'         => 'command.operational',
            'HIGH', 'CRITICAL' => 'command.privileged',
            default          => 'command.privileged',
        };
    }
}

if (!function_exists('sanitize_role')) {
    /**
     * Validate a role coming from a form.
     *
     * users.php previously wrote $_POST['role'] straight into the UPDATE, with
     * no allow-list at all.
     *
     * @param mixed  $value
     * @param string $default Role used when the input is not recognised
     */
    function sanitize_role($value, string $default = 'readonly'): string {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        // Accept the pre-3.12 value so an old bookmarked form still works.
        if ($value === 'user') {
            $value = 'technician';
        }

        return in_array($value, OPNMGR_ROLES, true) ? $value : $default;
    }
}

if (!function_exists('render_role_options')) {
    /**
     * <option> list for a role selector.
     *
     * @param string|null $selected Currently assigned role
     */
    function render_role_options(?string $selected = null): string {
        $html = '';
        foreach (OPNMGR_ROLES as $role) {
            $html .= sprintf(
                '<option value="%s"%s title="%s">%s</option>',
                htmlspecialchars($role),
                $selected === $role ? ' selected' : '',
                htmlspecialchars(role_description($role)),
                htmlspecialchars(role_label($role))
            );
        }
        return $html;
    }
}

if (!function_exists('role_badge_class')) {
    /**
     * Bootstrap badge colour for a role, so the users table reads at a glance.
     */
    function role_badge_class(?string $role): string {
        return match ($role) {
            'admin'      => 'danger',
            'technician' => 'primary',
            'readonly'   => 'secondary',
            default      => 'secondary',
        };
    }
}
