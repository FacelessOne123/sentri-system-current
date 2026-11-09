<?php
/**
 * api/address_terms.php - Barangay-scoped address suggestions
 * ============================================================================
 * Public read-only endpoint. Returns streets, subdivisions, phases, blocks,
 * puroks and sitios for one Imus barangay. The data is public map data - no
 * resident PII is ever returned.
 *
 *   GET api/address_terms.php?barangay=Bucandala%20I&q=riz
 *
 * Falls back to the committed assets/geo/imus-address-terms.json snapshot when
 * the database table is missing or empty, so the UI works before seeding.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/geo.php';
require_once __DIR__ . '/../config/address.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

function address_terms_respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBrgy = trim((string)($_GET['barangay'] ?? ''));
$brgy = sentri_barangay_canonical($rawBrgy);
if ($brgy === null) {
    address_terms_respond(400, [
        'status'  => 'error',
        'message' => 'Unknown or missing Imus barangay.',
    ]);
}

$q = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) $q = mb_substr($q, 0, 100, 'UTF-8'); else $q = substr($q, 0, 100);
$qNorm = sentri_address_normalize($q);

$attribution = 'Address suggestions © OpenStreetMap contributors (ODbL)';
$source = 'snapshot';
$terms = [];

// ── 1. Prefer the database ──────────────────────────────────────────────────
$dbRow = null;
try {
    require_once __DIR__ . '/../config/db.php';
    $hasTable = $conn->query("SHOW TABLES LIKE 'barangay_address_terms'");
    if ($hasTable && $hasTable->num_rows > 0) {
        $hasTable->free();
        $stmt = $conn->prepare(
            "SELECT term, term_type, parent_term, latitude, longitude, source
             FROM barangay_address_terms
             WHERE barangay_name = ? AND is_active = 1
             ORDER BY term_type, term"
        );
        if ($stmt) {
            $stmt->bind_param('s', $brgy);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $terms[] = [
                    'term'   => (string)$row['term'],
                    'type'   => (string)$row['term_type'],
                    'parent' => $row['parent_term'] !== null ? (string)$row['parent_term'] : null,
                    'lat'    => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                    'lng'    => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                    'source' => (string)$row['source'],
                ];
            }
            $stmt->close();
            if ($terms) $source = 'database';
        }
    }
} catch (Throwable $e) {
    error_log('address_terms DB fallback: ' . $e->getMessage());
    $terms = [];
}

// ── 2. Fall back to the committed snapshot ──────────────────────────────────
if (!$terms) {
    $snapPath = dirname(__DIR__) . '/assets/geo/imus-address-terms.json';
    $raw = @file_get_contents($snapPath);
    $snap = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($snap) && is_array($snap['barangays'][$brgy] ?? null)) {
        foreach ($snap['barangays'][$brgy] as $t) {
            $terms[] = [
                'term'   => (string)($t['term'] ?? ''),
                'type'   => (string)($t['type'] ?? 'other'),
                'parent' => isset($t['parent']) && $t['parent'] !== '' ? (string)$t['parent'] : null,
                'lat'    => $t['lat'] ?? null,
                'lng'    => $t['lng'] ?? null,
                'source' => (string)($t['source'] ?? 'osm'),
            ];
        }
        if ($terms) $source = 'snapshot';
    }
}

// ── 3. Optional text filter + stable ordering ──────────────────────────────
if ($qNorm !== '') {
    $terms = array_values(array_filter($terms, function ($t) use ($qNorm) {
        return strpos(sentri_address_normalize($t['term']), $qNorm) !== false
            || strpos((string)$t['parent'], $qNorm) !== false;
    }));
}

$order = sentri_address_type_order();
usort($terms, function ($a, $b) use ($order) {
    $ta = $order[$a['type']] ?? 99;
    $tb = $order[$b['type']] ?? 99;
    if ($ta !== $tb) return $ta <=> $tb;
    return strcasecmp($a['term'], $b['term']);
});

address_terms_respond(200, [
    'status'      => 'success',
    'barangay'    => $brgy,
    'count'       => count($terms),
    'source'      => $source,
    'attribution' => $attribution,
    'terms'       => $terms,
]);
