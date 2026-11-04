<?php
/**
 * admin/logout.php - Sign out of the ADMIN realm session ONLY.
 *
 * The admin panel keeps its own logout so administrative sessions never
 * share a page with community sign-out logic (separation by design).
 * Destroys exactly the admin realm's cookie/session (sentri-admin) -
 * community/gov sessions are separate cookies and stay signed in.
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/../config/session.php';
sentri_boot_realm('admin');

$identity = sentri_identity();
$has_identity = $identity !== null;

if ($has_identity) {
  sentri_destroy_current_realm();
}

// Administrators sign back in on the admin panel's own sign-in page.
header('Location: /admin.php');
exit;
