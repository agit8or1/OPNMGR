<?php
/**
 * Former "HTTP-only" installer endpoint. It built the manager URL from the
 * Host header and accepted any token.
 *
 * Kept as an address so existing links keep working, but it no longer builds
 * anything itself: like every installer endpoint it serves the one validated
 * script from inc/enrollment_script.php (token format, live unused record,
 * configured manager URL, shell-quoted values).
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/enrollment_script.php';

enrollment_serve_installer();
