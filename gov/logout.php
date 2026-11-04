<?php
/**
 * gov/logout.php - Sign out of the GOVERNMENT realm session ONLY.
 *
 * Government pages keep their own logout so officials never share a page
 * with community sign-out logic (separation by design). Destroys exactly the
 * gov realm's cookie/session (sentri-gov) - community/admin sessions are
 * separate cookies and stay signed in.
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../config/session.php';
sentri_boot_realm('gov');

$identity = sentri_identity();
$has_identity = $identity !== null;

if ($has_identity) {
  sentri_destroy_current_realm();
}

header('Location: ' . gov_login_url());
exit;
