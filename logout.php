<?php
/**
 * logout.php - Sign out of the COMMUNITY realm session ONLY.
 *
 * Each realm has its own logout page so community pages never touch
 * government or administrator sessions (separation by design):
 *   community -> logout.php          (this page)
 *   gov       -> gov/logout.php
 *   admin     -> admin/logout.php
 *
 * Only the community realm's own cookie (sentri-comm) is read and destroyed.
 * If the visitor holds no community identity, nothing is destroyed - other
 * realms' sessions are never signed out from a community page.
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/config/session.php';
sentri_boot_realm('community');

$identity = sentri_identity();
$has_identity = $identity !== null;
$was_role = $identity['role'] ?? 'community';

if ($has_identity) {
  sentri_destroy_current_realm(); // community realm cookie/session only
}

// Always return to the community sign-in page. Government/admin visitors
// signing out land on their own pages via gov/logout.php / admin/logout.php.
header('Location: ' . login_url_for_role($was_role !== '' ? $was_role : 'community'));
exit;
