<?php
/**
 * config/session.php - Realm-scoped session bootstrap (canonical spec)
 *
 * SenTri keeps ONE browser session PER REALM instead of one global session,
 * so a citizen can stay signed in to the community portal while a government
 * official is simultaneously signed in elsewhere - each realm has its own
 * cookie and its own $_SESSION snapshot:
 *
 *   realm       cookie name     roles living there
 *   ─────────   ─────────────   ─────────────────────────────────
 *   community   sentri-comm     community (alias: user)
 *   gov         sentri-gov      barangay | lgu | first_responder
 *   admin       sentri-admin    admin
 *
 * PHP session_name() forbids underscores, hence the dashes. Realm cookie
 * names are NOT identities: several realms may hold a login at once, and the
 * account details live in $_SESSION['identity'] inside whichever realm
 * session is currently booted (see sentri_identity() in config/auth.php).
 *
 * STRICT ORDER RULE (headers!): every boot/destroy function here sends or
 * expires cookies, so it MUST run before ANY output (HTML, echo, or even a
 * blank line after a closing ?> tag). Pattern every page must follow:
 *
 *   <?php
 *   ini_set('display_errors', 0);
 *   ob_start();                        // no byte may escape before boot
 *   require_once __DIR__ . '/config/session.php';
 *   sentri_boot_realm('community');    // or 'gov' / 'admin' ...
 *   ...                                // guards, db, then output
 *
 * Including this file alone is sufficient: it also pulls in config/auth.php
 * (guards + sentri_identity()), which itself is safe to include first (the
 * mutual require_once is cycle-safe).
 */

require_once __DIR__ . '/headers.php'; // security headers (no output, no session)
require_once __DIR__ . '/auth.php';   // identity + role guards for pages including only this file

/**
 * realm => session cookie name. (No underscore in names: PHP's
 * session_name() only accepts [A-Za-z0-9,-], so use dashes.)
 */
const SENTRI_REALM_COOKIES = [
  'community' => 'sentri-comm',
  'gov'       => 'sentri-gov',
  'admin'     => 'sentri-admin',
];

/**
 * Cookie params shared by every realm session: browser-session lifetime,
 * HttpOnly, Lax SameSite (session boot is per-request; logins use
 * session_regenerate_id with the same params).
 */
function sentri_apply_cookie_params(): void {
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']),
  ]);
}

/**
 * Map a user role to the realm it belongs to. Unknown/legacy roles ('user')
 * fall back to the community realm.
 */
function sentri_realm_for_role(string $role): string {
  switch ($role) {
    case 'barangay':
    case 'lgu':
    case 'first_responder': return 'gov';
    case 'admin':           return 'admin';
    default:                return 'community'; // community | user | ''
  }
}

/**
 * Cookie name for a realm. Throws on an unknown realm so pages fail loudly
 * instead of silently sharing the wrong session.
 */
function sentri_realm_cookie_name(string $realm): string {
  if (!array_key_exists($realm, SENTRI_REALM_COOKIES)) {
    throw new InvalidArgumentException("Unknown SENTrI session realm: {$realm}");
  }
  return SENTRI_REALM_COOKIES[$realm];
}

/**
 * Bind the session ID to THIS realm's request cookie before start, or reset
 * it so PHP generates a fresh one. Without this, switching realm cookies
 * within one request can make PHP reuse the previous realm's in-process ID,
 * pointing two realm cookies at the same backing store.
 */
function sentri_select_session_id(string $cookieName): void {
  $id = '';
  if (isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName])
    && preg_match('/^[A-Za-z0-9,\-]{1,128}$/', $_COOKIE[$cookieName])) {
    $id = $_COOKIE[$cookieName]; // valid-format id: resume this exact session
  }
  // '' forces PHP to mint a brand-new ID on session_start().
  session_id($id);
}

/**
 * Boot a realm session (rename session + harden cookie + start). Idempotent:
 * if a session with this realm's cookie is already active, this is a no-op;
 * if a session of ANOTHER realm is active, it is closed first (switching).
 * Must be called before any output (it sets cookies).
 */
function sentri_boot_realm(string $realm): void {
  $cookieName = sentri_realm_cookie_name($realm);
  if (session_status() === PHP_SESSION_ACTIVE) {
    if (session_name() === $cookieName) return; // already booted for this realm
    session_write_close(); // switching realms within one request
    $_SESSION = [];
  }
  sentri_apply_cookie_params();
  session_name($cookieName);
  if (session_status() === PHP_SESSION_NONE) {
    sentri_select_session_id($cookieName);
    session_start();
  }
}

/**
 * Realm cookies currently present in the browser, in candidate order:
 * gov, community, admin (gov first so privileged logins win auto-detection).
 */
function sentri_available_cookie_names(): array {
  $order = [
    SENTRI_REALM_COOKIES['gov'],
    SENTRI_REALM_COOKIES['community'],
    SENTRI_REALM_COOKIES['admin'],
  ];
  $present = [];
  foreach ($order as $cookieName) {
    if (isset($_COOKIE[$cookieName])) $present[] = $cookieName;
  }
  return $present;
}

/**
 * Open a session bound to a specific realm cookie name. If a session of a
 * different cookie is active, it is closed first. Must be called before any
 * output. Unknown cookie names are rejected.
 */
function sentri_boot_candidate(string $cookieName): void {
  if (!in_array($cookieName, SENTRI_REALM_COOKIES, true)) {
    throw new InvalidArgumentException("Unknown SENTrI session cookie: {$cookieName}");
  }
  if (session_status() === PHP_SESSION_ACTIVE) {
    if (session_name() === $cookieName) return; // already on this cookie
    session_write_close();
    $_SESSION = [];
  }
  sentri_apply_cookie_params();
  session_name($cookieName);
  if (session_status() === PHP_SESSION_NONE) {
    sentri_select_session_id($cookieName);
    session_start();
  }
}

/**
 * Persist and close the current session without destroying it (used when
 * switching between candidate realm cookies within one request).
 */
function sentri_close_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
  }
  $_SESSION = []; // never leak one realm's data into the next candidate's view
}

/**
 * Destroy ONLY the currently booted realm session and expire its cookie.
 * Other realms use separate cookies/sessions and are untouched.
 */
function sentri_destroy_current_realm(): void {
  $cookieName = session_name();
  $destroyed = false;
  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
    $destroyed = true;
  }
  $_SESSION = [];
  if (($destroyed || in_array($cookieName, SENTRI_REALM_COOKIES, true)) && !headers_sent()) {
    setcookie($cookieName, '', [
      'expires' => time() - 3600,
      'path' => '/',
      'httponly' => true,
      'samesite' => 'Lax',
      'secure' => !empty($_SERVER['HTTPS']),
    ]);
  }
}

/**
 * Find and OPEN the realm session that actually holds a signed-in identity,
 * probing candidate realm cookies in sentri_available_cookie_names() order.
 * A candidate whose identity is absent (or whose role is not listed in
 * $preferredRoles) is closed and skipped. If nothing matches, fall back to
 * booting the community realm (an anonymous session) so callers always have
 * a valid session.
 *
 * @param array|null $preferredRoles Restrict to e.g. ['barangay','lgu','first_responder'].
 * @return string The chosen realm COOKIE name (e.g. 'sentri-gov').
 *                IMPORTANT: the matching session is left OPEN on return.
 */
function sentri_boot_auto_realm(?array $preferredRoles = null, ?array $order = null): string {
  foreach ($order ?? sentri_available_cookie_names() as $cookieName) {
    sentri_boot_candidate($cookieName);
    $identity = sentri_identity();
    if ($identity !== null
      && ($preferredRoles === null || in_array(($identity['role'] ?? ''), $preferredRoles, true))) {
      return $cookieName; // chosen session stays OPEN
    }
    sentri_close_session();
  }
  // No realm cookie holds a matching identity: anonymous community realm.
  sentri_boot_realm('community');
  return SENTRI_REALM_COOKIES['community'];
}
