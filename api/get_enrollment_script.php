<?php
/**
 * Former minimal installer generator. It accepted any 32+ character token
 * and built the manager URL from the Host header.
 *
 * Kept as an address so existing links keep working, but it no longer builds
 * anything itself: like every installer endpoint it serves the one validated
 * script from inc/enrollment_script.php (token format, live unused record,
 * configured manager URL, shell-quoted values).
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/enrollment_script.php';

enrollment_serve_installer();
