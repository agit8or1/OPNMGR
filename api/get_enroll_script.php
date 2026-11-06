<?php
/**
 * Serve the enrollment installer for a token.
 *
 * The rendering - and the validation that makes it safe to paste values into
 * shell - lives in inc/enrollment_script.php, which substitutes
 * __PANEL_URL__, __ENROLLMENT_TOKEN__, __SERVER_SSH_KEY__ and
 * __MGMT_SERVER_IP__ in simple_enroll.sh. The other installer addresses
 * (get_enroll_script_http.php, get_enrollment_script.php,
 * enroll_firewall.php?action=download) all end up here.
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/enrollment_script.php';

enrollment_serve_installer();
