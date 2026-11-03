<?php
/**
 * email_config.php - SenTri Email Configuration
 *
 * Loads settings from .env file in root directory if present.
 * Copy .env.example to .env and adjust your settings.
 */

// Simple lightweight .env loader
(function() {
 $envPath = dirname(__DIR__) . '/.env';
 if (file_exists($envPath)) {
 $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
 foreach ($lines as $line) {
 $line = trim($line);
 if ($line === '' || $line[0] === '#') continue;
 if (strpos($line, '=') !== false) {
 list($name, $value) = explode('=', $line, 2);
 $name = trim($name);
 $value = trim($value, " \t\n\r\0\x0B\"'");
 if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
 putenv("{$name}={$value}");
 $_ENV[$name] = $value;
 $_SERVER[$name] = $value;
 }
 }
 }
 }
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
