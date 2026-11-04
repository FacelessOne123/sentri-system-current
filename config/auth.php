<?php
/**
 * config/auth.php - Identity access, role guards and portal URLs
 *
 * ALL guards operate on the CURRENT realm's session (config/session.php).
 * Identity lives at $_SESSION['identity'] inside the realm's own cookie
 * (sentri-comm / sentri-gov / sentri-admin), so several realms can hold a
 * login at once and these functions only ever touch the session the calling
 * page booted - never another realm's.
 *
 * This file must be required before any output (its require chain emits
 * security headers and the guards may redirect/destroy sessions, all of
 * which send headers).
 */
require_once __DIR__ . '/session.php'; // realm boot/destroy fns + session machinery (cycle-safe)
require_once __DIR__ . '/csrf.php';   // CSRF helpers (unchanged behavior)

/**
 * The signed-in account of the CURRENT realm session, or null when
 * anonymous. Shape: ['user_id','first_name','last_name','role',
 * 'is_approved','email_verified'].
 */
function sentri_identity(): ?array {
  $identity = $_SESSION['identity'] ?? null;
  return is_array($identity) ? $identity : null;
}

// Each role signs in on its own page:
// community -> login.php, gov roles -> gov/login.php, admin -> admin.php
function login_url_for_role($role) {
  switch($role) {
  case 'barangay':
  case 'lgu':
  case 'first_responder': return '/gov/login.php';
  case 'admin': return '/admin.php';
  case 'community':
  case 'user':
  default: return '/login.php';
  }
}

function require_login($allowedRoles = null) {
  $identity = sentri_identity();
  if ($identity === null) {
    // Direct to the sign-in page of the realm whose page asked for a login
    // (never cross realms: gov pages must not bounce visitors to community).
    $hint = is_array($allowedRoles) && $allowedRoles ? reset($allowedRoles) : 'community';
    header('Location: ' . login_url_for_role($hint)); exit;
  }
}

function require_role($allowed) {
  require_login($allowed);
  $role = sentri_identity()['role'] ?? '';
  if (!in_array($role, (array)$allowed, true)) {
    header('Location: ' . portal_url($role)); exit;
  }
}

function require_approved() {
  require_login();
  $identity = sentri_identity();
  if (!($identity['is_approved'] ?? false)) {
    $role = $identity['role'] ?? 'community';
    // Unapproved accounts must not keep a pending realm session: drop it
    // (only the current realm's cookie/session - others are separate).
    sentri_destroy_current_realm();
    header('Location: ' . login_url_for_role($role) . '?pending=1'); exit;
  }
}

function portal_url($role) {
  switch($role) {
  case 'barangay': return '/portal/barangay.php';
  case 'lgu': return '/portal/lgu.php';
  case 'first_responder': return '/portal/responder.php';
  case 'admin': return '/admin.php';
  case 'community':
  case 'user': return '/portal/community.php';
  default: return '/portal/community.php';
  }
}

function redirect_to_portal() {
  $identity = sentri_identity();
  header('Location: ' . portal_url($identity['role'] ?? 'community')); exit;
}

function gov_login_url() {
  return '/gov/login.php';
}

/**
 * The password-reset page that may set a password for a role. Realms are
 * fully separated: officials use gov/reset_password.php, administrators use
 * admin/reset_password.php, community members use reset_password.php.
 */
function reset_url_for_role($role) {
  switch($role) {
  case 'barangay':
  case 'lgu':
  case 'first_responder': return '/gov/reset_password.php';
  case 'admin':           return '/admin/reset_password.php';
  case 'community':
  case 'user':
  default: return '/reset_password.php';
  }
}

function role_label($role) {
  switch($role) {
  case 'community': return 'Community Member';
  case 'barangay': return 'Barangay Official';
  case 'lgu': return 'LGU Official';
  case 'first_responder': return 'First Responder';
  case 'admin': return 'System Administrator';
  default: return 'User';
  }
}

function role_badge_color($role) {
  switch($role) {
  case 'community': return '#2563eb';
  case 'barangay': return '#166534';
  case 'lgu': return '#0a3d62';
  case 'first_responder': return '#b91c1c';
  case 'admin': return '#6d28d9';
  default: return '#555';
  }
}
