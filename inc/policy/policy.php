<?php
/**
 * OPNMGR shared policies and configuration templates: single include.
 *
 *   model.php       components, compatibility, variable types, template lifecycle
 *   assignment.php  scopes and precedence, conflicts, variables, exceptions, mappings
 *   render.php      planning: structured DOM edits, managed identity, ordering, safety
 *   device.php      apply / confirm / rollback shell with the local recovery timer
 *   deploy.php      deployments, approval binding, locks, HA, rings, reconcile
 *   compliance.php  desired vs applied vs actual, per firewall and template
 *
 * See docs/POLICY_TEMPLATES.md.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/model.php';
require_once __DIR__ . '/assignment.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/device.php';
require_once __DIR__ . '/deploy.php';
require_once __DIR__ . '/compliance.php';
