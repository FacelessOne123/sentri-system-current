<?php
/**
 * email_config.php - SenTri Email Configuration
 *
 * Loads settings from .env file in root directory if present.
 * Copy .env.example to .env and adjust your settings.
 *
 * Two files are read, in this order:
 *   .env       - the tracked defaults (safe to commit: no real credentials)
 *   .env.local - per-host overrides, git-ignored (e.g. APP_DEBUG=1 and a
 *                local APP_URL while developing)
 * A real environment variable (Apache/FPM/systemd) always beats both files.
 */

// Simple lightweight .env loader
(function() {
 $root = dirname(__DIR__);

 // Keys that already exist in the real process environment (set by the web
 // server or the shell) must never be overridden by a file.
 $preexisting = array_fill_keys(
  array_merge(array_keys($_ENV), array_keys($_SERVER)),
  true
 );

 $load = function (string $path, bool $isOverride) use ($preexisting): void {
 if (!file_exists($path)) return;
 $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
 foreach ($lines as $line) {
 $line = trim($line);
 if ($line === '' || $line[0] === '#') continue;
 if (strpos($line, '=') !== false) {
 list($name, $value) = explode('=', $line, 2);
 $name = trim($name);
 $value = trim($value, " \t\n\r\0\x0B\"'");
 if ($name === '') continue;
 if (isset($preexisting[$name])) continue; // real env var wins
 // .env.local is an explicit per-host override of .env.
 if (!$isOverride && array_key_exists($name, $_ENV)) continue;
 putenv("{$name}={$value}");
 $_ENV[$name] = $value;
 $_SERVER[$name] = $value;
 }
 }
 };

 $load($root . '/.env', false);
 $load($root . '/.env.local', true);
})();

if (!function_exists('get_env_var')) {
 function get_env_var(string $key, string $default = ''): string
 {
 $val = getenv($key);
 if ($val !== false && $val !== '') {
 return $val;
 }
 return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
 }
}

// ─── Gmail SMTP Settings ──────────────────────────────────────────────────────
define('MAIL_HOST', get_env_var('MAIL_HOST', 'smtp.gmail.com'));
define('MAIL_PORT', (int)get_env_var('MAIL_PORT', '587'));

// ── Your Gmail address ──
define('MAIL_USERNAME', get_env_var('MAIL_USERNAME', ''));

// ── Gmail App Password (16 chars, no spaces) ──
define('MAIL_PASSWORD', get_env_var('MAIL_PASSWORD', ''));

// ── Sender name & address shown to recipients ──
define('MAIL_FROM', get_env_var('MAIL_FROM', get_env_var('MAIL_USERNAME', '')));
define('MAIL_FROM_NAME', get_env_var('MAIL_FROM_NAME', 'SenTri'));

// ─── Application URL (no trailing slash) ─────────────────────────────────────
define('APP_URL', get_env_var('APP_URL', 'http://localhost'));

// ─── Development / offline mode ──────────────────────────────────────────────
// When APP_DEBUG is truthy (1/true/on) — or MAIL_OUTBOX is set explicitly —
// every transactional email (verification, password reset, notifications) is
// written to logs/mail/*.eml instead of attempting SMTP. This lets you sign up,
// verify accounts, and reset passwords FULLY OFFLINE on localhost: open
// debug.php, click the verification link, done. Never enable in production.
define('MAIL_DEBUG_OUTBOX', in_array(strtolower(get_env_var('APP_DEBUG', '')), ['1','true','on','yes'], true)
 || in_array(strtolower(get_env_var('MAIL_OUTBOX', '')), ['1','true','on','yes'], true));
define('MAIL_DEBUG_DIR', dirname(__DIR__) . '/logs/mail');
