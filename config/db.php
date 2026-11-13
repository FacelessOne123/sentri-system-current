<?php
// Bootstrap security headers before any output or redirect.
require_once __DIR__ . '/headers.php';

// Enforce Philippine Standard Time (UTC+8) across all date/time functions
date_default_timezone_set('Asia/Manila');

// .env-aware credentials: default remains root@socket / sentri; override via
// .env keys DB_HOST / DB_USER / DB_PASS / DB_NAME (used on Linux Mint dev
// setups where MySQL root carries a password, e.g. XAMPP/LAMP).
if (!defined('APP_URL') && !function_exists('get_env_var')) {
 require_once __DIR__ . '/email.php';
}
if (function_exists('get_env_var')) {
 $db_host = get_env_var('DB_HOST', 'localhost') ?: 'localhost';
 $db_user = get_env_var('DB_USER', 'root') ?: 'root';
 $db_pass = get_env_var('DB_PASS', '');
 $dbname  = get_env_var('DB_NAME', 'sentri') ?: 'sentri';
} else {
 $db_host = 'localhost';
 $db_user = 'root';
 $db_pass = '';
 $dbname  = 'sentri';
}
$servername = $db_host;

// Try Unix socket first, fallback to TCP. (mysqli throws since PHP 8.1; the
// socket attempt must be caught so the TCP fallback can run on hosts without
// a Unix socket file, e.g. Android tmpfs.)
try {
 $conn = @new mysqli(null, $db_user, $db_pass, $dbname, 3306, '/var/run/mysqld/mysqld.sock');
 if ($conn->connect_error) {
  throw new mysqli_sql_exception($conn->connect_error);
 }
} catch (mysqli_sql_exception $e) {
 $conn = new mysqli('127.0.0.1', $db_user, $db_pass, $dbname, 3306);
}
if ($conn->connect_error) {
 error_log("SenTri DB Error: " . $conn->connect_error);
 die(json_encode(['status'=>'error','message'=>'Database connection failed.']));
}
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

if (!function_exists('sentri_table_has_column')) {
 function sentri_table_has_column(mysqli $conn, string $table, string $column): bool {
 static $cache = [];
 $key = $table . '.' . $column;
 if (array_key_exists($key, $cache)) {
 return $cache[$key];
 }

 $schema = null;
 if ($dbRes = $conn->query("SELECT DATABASE()")) {
 $dbRow = $dbRes->fetch_row();
 $schema = $dbRow[0] ?? null;
 $dbRes->free();
 }
 if (!$schema) {
 return $cache[$key] = false;
 }

 $stmt = $conn->prepare(
 "SELECT 1
 FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = ?
 AND TABLE_NAME = ?
 AND COLUMN_NAME = ?
 LIMIT 1"
 );
 if (!$stmt) {
 return $cache[$key] = false;
 }
 $stmt->bind_param('sss', $schema, $table, $column);
 $stmt->execute();
 $stmt->store_result();
 $exists = $stmt->num_rows > 0;
 $stmt->close();

 return $cache[$key] = $exists;
 }
}

if (!function_exists('sentri_ensure_report_dispatch_schema')) {
 function sentri_ensure_report_dispatch_schema(mysqli $conn): void {
 if (!sentri_table_has_column($conn, 'reports', 'assigned_to')) {
 if (!$conn->query("ALTER TABLE `reports`
 ADD COLUMN `assigned_to` INT(11) DEFAULT NULL COMMENT 'user_id of assigned responder' AFTER `is_archived`")) {
 error_log('SenTri DB migration error (reports.assigned_to): ' . $conn->error);
 }
 }

 if (!sentri_table_has_column($conn, 'reports', 'accepted_at')) {
 if (!$conn->query("ALTER TABLE `reports`
 ADD COLUMN `accepted_at` DATETIME DEFAULT NULL AFTER `assigned_to`")) {
 error_log('SenTri DB migration error (reports.accepted_at): ' . $conn->error);
 }
 }

 if (!sentri_table_has_column($conn, 'reports', 'responded_at')) {
 if (!$conn->query("ALTER TABLE `reports`
 ADD COLUMN `responded_at` DATETIME DEFAULT NULL AFTER `accepted_at`")) {
 error_log('SenTri DB migration error (reports.responded_at): ' . $conn->error);
 }
 }

 if (!sentri_table_has_column($conn, 'reports', 'resolved_at')) {
 if (!$conn->query("ALTER TABLE `reports`
 ADD COLUMN `resolved_at` DATETIME DEFAULT NULL AFTER `responded_at`")) {
 error_log('SenTri DB migration error (reports.resolved_at): ' . $conn->error);
 }
 }

 $hasIndex = false;
 if ($idx = $conn->query("SHOW INDEX FROM `reports` WHERE Key_name = 'idx_assigned'")) {
 $hasIndex = $idx->num_rows > 0;
 $idx->free();
 }
 if (!$hasIndex && sentri_table_has_column($conn, 'reports', 'assigned_to')) {
 if (!$conn->query("ALTER TABLE `reports` ADD INDEX `idx_assigned` (`assigned_to`)")) {
 error_log('SenTri DB migration error (reports.idx_assigned): ' . $conn->error);
 }
 }
 }
}

if (!function_exists('sentri_ensure_audit_log_schema')) {
 function sentri_ensure_audit_log_schema(mysqli $conn): void {
 if (sentri_table_has_column($conn, 'report_audit_logs', 'action')) {
 $schema = null;
 if ($dbRes = $conn->query("SELECT DATABASE()")) {
 $dbRow = $dbRes->fetch_row();
 $schema = $dbRow[0] ?? null;
 $dbRes->free();
 }
 if ($schema) {
 $stmt = $conn->prepare(
 "SELECT DATA_TYPE
 FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = ?
 AND TABLE_NAME = 'report_audit_logs'
 AND COLUMN_NAME = 'action'
 LIMIT 1"
 );
 if ($stmt) {
 $stmt->bind_param('s', $schema);
 $stmt->execute();
 $stmt->bind_result($dataType);
 $stmt->fetch();
 $stmt->close();
 if (strtolower((string)$dataType) !== 'varchar') {
 if (!$conn->query("ALTER TABLE `report_audit_logs` MODIFY `action` VARCHAR(40) NOT NULL")) {
 error_log('SenTri DB migration error (report_audit_logs.action): ' . $conn->error);
 }
 }
 }
 }
 }
 }
}

sentri_ensure_report_dispatch_schema($conn);
sentri_ensure_audit_log_schema($conn);

if (!function_exists('sentri_generate_official_id')) {
 /**
 * Shared deterministic official-ID generator: 5 digits derived from the
 * official's first+last names + the 4-digit year of account creation →
 * e.g. "12345" + "2026" = "123452026" (9 chars). Salt-retries on collision;
 * the unique index on users.official_id is the final guarantee.
 */
 function sentri_generate_official_id(mysqli $conn, string $first, string $last): string {
 $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $first . $last));
 $salt = 0;
 do {
 $num = hexdec(substr(hash('crc32b', $base . '|' . $salt), 0, 8)) % 100000;
 $id = str_pad((string)$num, 5, '0', STR_PAD_LEFT) . date('Y');
 $chk = $conn->prepare("SELECT id FROM users WHERE official_id=? LIMIT 1");
 $chk->bind_param('s', $id); $chk->execute(); $chk->store_result();
 $exists = $chk->num_rows > 0;
 $chk->close();
 $salt++;
 } while ($exists && $salt < 200);
 if ($exists) { // name space exhausted (2 identical names + same year): random fallback
 $id = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT) . date('Y');
 }
 return $id;
 }
}

if (!function_exists('sentri_ensure_official_id_schema')) {
 function sentri_ensure_official_id_schema(mysqli $conn): void {
 if (!sentri_table_has_column($conn, 'users', 'official_id')) {
 if (!$conn->query("ALTER TABLE `users`
 ADD COLUMN `official_id` VARCHAR(12) DEFAULT NULL COMMENT '9-char official login ID: 5 name-derived digits + created year' AFTER `email`")) {
 error_log('SenTri DB migration error (users.official_id): ' . $conn->error);
 return;
 }
 }
 $hasIdx = false;
 if ($idx = $conn->query("SHOW INDEX FROM `users` WHERE Key_name = 'uq_official_id'")) {
 $hasIdx = $idx->num_rows > 0;
 $idx->free();
 }
 if (!$hasIdx && !$conn->query("ALTER TABLE `users` ADD UNIQUE KEY `uq_official_id` (`official_id`)")) {
 error_log('SenTri DB migration error (users.uq_official_id): ' . $conn->error);
 }
 // Backfill provisioned gov accounts deterministically from their names.
 if ($res = $conn->query("SELECT id, first_name, last_name FROM users
 WHERE role IN ('barangay','lgu','first_responder') AND official_id IS NULL")) {
 while ($u = $res->fetch_assoc()) {
 $id = sentri_generate_official_id($conn, (string)$u['first_name'], (string)$u['last_name']);
 $up = $conn->prepare("UPDATE users SET official_id=? WHERE id=?");
 $up->bind_param('si', $id, $u['id']); $up->execute(); $up->close();
 }
 $res->free();
 }
 }
}
sentri_ensure_official_id_schema($conn);

