<?php
/**
 * logout.php - Sign out of ONE realm session
 *
 * Every realm keeps its own cookie (config/session.php: sentri-comm /
 * sentri-gov / sentri-admin), so logging out tears down exactly one realm
 * and returns the user to that role's sign-in page. Other realms stay
 * signed in.
 *
 * Realm selection, in priority order:
 *   1. ?realm=community|gov|admin   (explicit, preferred; portal pages should
 *      link e.g. /logout.php?realm=gov)
 *   2. HTTP referrer path (portal pages may link plain logout.php)
 *   3. First realm cookie that actually holds an identity (order: gov,
 *      community, admin) via sentri_boot_auto_realm()
 *   4. Nothing signed in anywhere: straight redirect, no session work.
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/config/session.php';

$realm = (string)($_GET['realm'] ?? '');
if (!array_key_exists($realm, SENTRI_REALM_COOKIES)) {
  $realm = null;

  // Infer from the page that linked here where sensible.
  $refPath = '';
  if (!empty($_SERVER['HTTP_REFERER'])) {
    $refPath = (string)parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
  }
  if ($refPath !== '') {
    $isGov = strpos($refPath, '/gov/') === 0
      || strpos($refPath, '/portal/barangay') === 0
      || strpos($refPath, '/portal/lgu') === 0
      || strpos($refPath, '/portal/responder') === 0;
    if ($isGov) {
      $realm = 'gov';
    } elseif (strpos($refPath, '/admin.php') === 0) {
      $realm = 'admin';
    } elseif (strpos($refPath, '/portal/') === 0) {
      $realm = 'community';
    }
  }

  if ($realm !== null) {
    sentri_boot_realm($realm);
  } elseif (sentri_available_cookie_names() === []) {
    // Nothing to sign out anywhere; just route the visitor to a login page.
    header('Location: ' . login_url_for_role('community'));
    exit;
  } else {
    // Open whichever realm session actually holds an identity (session stays
    // OPEN on return) and map the chosen cookie name back to its realm.
    $chosen = sentri_boot_auto_realm();
    $realm = array_search($chosen, SENTRI_REALM_COOKIES, true) ?: 'community';
  }
} else {
  sentri_boot_realm($realm);
}

// Capture the identity role BEFORE destroying the session.
$identity = sentri_identity();
$was_role = $identity['role'] ?? '';

// Destroy ONLY the current realm's session and its cookie. Nothing is read
// from other realms here - they are separate cookies/sessions.
sentri_destroy_current_realm();

// Return the user to the sign-in page for their portal
header('Location: ' . login_url_for_role($was_role !== '' ? $was_role : 'community'));
exit;
