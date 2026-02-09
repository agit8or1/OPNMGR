<?php
require_once __DIR__ . '/../inc/auth.php';
requireLogin();

/**
 * GeoIP Blocking API
 *
 * Endpoints:
 * - GET /api/geoip_api.php?action=list - Get all GeoIP blocks
 * - GET /api/geoip_api.php?action=enabled - Get only enabled blocks
 * - POST /api/geoip_api.php?action=add - Add a new block
 * - POST /api/geoip_api.php?action=toggle - Toggle block status
 * - POST /api/geoip_api.php?action=delete - Delete a block
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../inc/db.php';

$response = ['success' => false, 'error' => null, 'data' => null];

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'list':
            // Get all blocks
            $stmt = $DB->query('SELECT * FROM geoip_blocks ORDER BY country_name ASC');
            $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            $response['data'] = $blocks;
            break;

        case 'enabled':
            // Get only enabled blocks
            $stmt = $DB->query('SELECT * FROM geoip_blocks WHERE enabled = 1 ORDER BY country_name ASC');
            $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            $response['data'] = $blocks;
            break;

        case 'get_by_firewall':
            // Get blocks for a specific firewall (for agent integration)
            // This will be used by firewall agents to fetch current blocking rules
            $firewall_id = (int)($_GET['firewall_id'] ?? $_POST['firewall_id'] ?? 0);

            if ($firewall_id > 0) {
                $stmt = $DB->query('SELECT country_code, country_name, action FROM geoip_blocks WHERE enabled = 1');
                $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response['success'] = true;
                $response['data'] = $blocks;
            } else {
                $response['error'] = 'Invalid firewall_id';
            }
            break;

        case 'add':
            // Add a new block
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $response['error'] = 'POST method required';
                break;
            }

            $country_code = strtoupper(trim($_POST['country_code'] ?? ''));
            $country_name = trim($_POST['country_name'] ?? '');
            $action_type = $_POST['action_type'] ?? 'block';
            $description = trim($_POST['description'] ?? '');

            if (strlen($country_code) !== 2) {
                $response['error'] = 'Invalid country code';
                break;
            }

            if (empty($country_name)) {
                $response['error'] = 'Country name required';
                break;
            }

            try {
                $stmt = $DB->prepare('INSERT INTO geoip_blocks (country_code, country_name, action, description, enabled) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$country_code, $country_name, $action_type, $description]);
                $response['success'] = true;
                $response['data'] = ['id' => $DB->lastInsertId()];
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $response['error'] = 'Country already exists';
                } else {
                    error_log("geoip_api.php add error: " . $e->getMessage());
                    $response['error'] = 'Failed to add GeoIP block';
                }
            }
            break;

        case 'toggle':
            // Toggle enabled status
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $response['error'] = 'POST method required';
                break;
            }

            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $DB->prepare('UPDATE geoip_blocks SET enabled = NOT enabled WHERE id = ?');
                $stmt->execute([$id]);
                $response['success'] = true;
                $response['data'] = ['affected_rows' => $stmt->rowCount()];
            } else {
                $response['error'] = 'Invalid block ID';
            }
            break;

        case 'delete':
            // Delete a block
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $response['error'] = 'POST method required';
                break;
            }

            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $DB->prepare('DELETE FROM geoip_blocks WHERE id = ?');
                $stmt->execute([$id]);
                $response['success'] = true;
                $response['data'] = ['affected_rows' => $stmt->rowCount()];
            } else {
                $response['error'] = 'Invalid block ID';
            }
            break;

        case 'stats':
            // Get statistics
            $stmt = $DB->query('SELECT
                COUNT(*) as total_blocks,
                SUM(enabled) as enabled_blocks,
                SUM(action = "block") as block_rules,
                SUM(action = "allow") as allow_rules
                FROM geoip_blocks');
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $response['success'] = true;
            $response['data'] = $stats;
            break;

        default:
            $response['error'] = 'Invalid action';
            break;
    }
} catch (Exception $e) {
    error_log("geoip_api.php error: " . $e->getMessage());
    $response['error'] = 'Internal server error';
}

echo json_encode($response, JSON_PRETTY_PRINT);
