<?php
/**
 * In-app documentation.
 *
 * This was 669 lines of hardcoded HTML, last meaningfully updated in October
 * 2025, describing a product that had moved on considerably. Meanwhile
 * doc_viewer.php rendered the same documentation from the documentation_pages
 * table, scripts/migrate_docs_to_db.php existed to move it there, and the
 * sidebar's isActive() already listed both files - a migration that was started
 * and never finished, leaving two documentation systems with one of them stale
 * and the only one linked.
 *
 * The URL is kept because it is what anyone has bookmarked, and because the
 * sidebar, the header search and external links all point at it.
 *
 * Content lives in the database and is rebuilt by
 * scripts/build_user_documentation.php, so it has a source, a diff and a review
 * rather than being edited in place.
 */

require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();

header('Location: /doc_viewer.php?page=documentation', true, 302);
exit;
