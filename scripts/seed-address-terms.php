<?php
/**
 * scripts/seed-address-terms.php
 * ============================================================================
 * Upsert assets/geo/imus-address-terms.json into MySQL.
 *
 * Usage
 *   php scripts/seed-address-terms.php             upsert (idempotent)
 *   php scripts/seed-address-terms.php --fresh     clear the table first
 *
 * Safe to re-run: rows are matched on (barangay_name, term_key, term_type).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$fresh = in_array('--fresh', $argv, true);
$jsonPath = $root . '/assets/geo/imus-address-terms.json';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "ERROR: missing {$jsonPath}. Run scripts/build-address-terms.php first.\n");
    exit(1);
}
$doc = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($doc) || !is_array($doc['barangays'] ?? null)) {
    fwrite(STDERR, "ERROR: invalid address-term snapshot.\n");
    exit(1);
}

// Verify the table exists before touching anything.
$chk = $conn->query("SHOW TABLES LIKE 'barangay_address_terms'");
if (!$chk || $chk->num_rows === 0) {
    fwrite(STDERR, "ERROR: table barangay_address_terms is missing. Run sql/migrations/011_barangay_address_terms.sql first.\n");
    exit(1);
}
$chk->free();

if ($fresh) {
    if (!$conn->query("TRUNCATE TABLE `barangay_address_terms`")) {
        fwrite(STDERR, "ERROR: could not truncate table: {$conn->error}\n");
        exit(1);
    }
    echo "Table cleared.\n";
}

$sql = "INSERT INTO `barangay_address_terms`
    (`barangay_name`, `term`, `term_key`, `term_type`, `parent_term`,
     `latitude`, `longitude`, `source`, `is_active`)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE
        `term` = VALUES(`term`),
        `parent_term` = VALUES(`parent_term`),
        `latitude` = VALUES(`latitude`),
        `longitude` = VALUES(`longitude`),
        `source` = VALUES(`source`),
        `is_active` = 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    fwrite(STDERR, "ERROR: prepare failed: {$conn->error}\n");
    exit(1);
}

$inserted = 0;
$skipped = 0;
$conn->begin_transaction();
try {
    foreach ($doc['barangays'] as $brgy => $list) {
        if (!is_array($list)) continue;
        foreach ($list as $t) {
            $term   = trim((string)($t['term'] ?? ''));
            $key    = trim((string)($t['key'] ?? ''));
            $type   = (string)($t['type'] ?? 'other');
            $parent = isset($t['parent']) && $t['parent'] !== '' ? (string)$t['parent'] : null;
            $lat    = isset($t['lat']) && $t['lat'] !== null ? (float)$t['lat'] : null;
            $lng    = isset($t['lng']) && $t['lng'] !== null ? (float)$t['lng'] : null;
            $source = (string)($t['source'] ?? 'osm');
            if ($term === '' || $key === '') { $skipped++; continue; }
            if (!in_array($type, ['street','road','subdivision','phase','block','purok','sitio','landmark','other'], true)) $type = 'other';
            if (!in_array($source, ['osm','lgu','manual'], true)) $source = 'osm';

            $stmt->bind_param('sssssdds', $brgy, $term, $key, $type, $parent, $lat, $lng, $source);
            if (!$stmt->execute()) {
                throw new RuntimeException("insert failed for {$brgy}/{$term}: {$stmt->error}");
            }
            $inserted++;
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
$stmt->close();

$cnt = $conn->query("SELECT COUNT(*) FROM `barangay_address_terms` WHERE `is_active` = 1");
$total = $cnt ? (int)$cnt->fetch_row()[0] : 0;

echo "Seeded {$inserted} term(s)";
if ($skipped) echo " ({$skipped} skipped)";
echo ". Active rows in table: {$total}.\n";
