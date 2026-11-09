<?php
/**
 * scripts/build-address-terms.php
 * ============================================================================
 * Build assets/geo/imus-address-terms.json - the barangay-scoped address
 * dictionary used by the residence suggestion box.
 *
 * Data sources
 *  - OpenStreetMap snapshot (ODbL), cached at assets/geo/osm-imus-addresses.json
 *  - LGU override CSV at assets/geo/imus-address-overrides.csv (optional)
 *  - Existing assets/geo/imus-streets.json (street coverage fallback)
 *
 * Usage
 *   php scripts/build-address-terms.php            build from the cached snapshot
 *   php scripts/build-address-terms.php --fetch    refresh the snapshot from Overpass first
 *   php scripts/build-address-terms.php --check    exit 1 unless all 95 barangays have terms
 *
 * This script never writes to MySQL; run scripts/seed-address-terms.php next.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/geo.php';
require_once $root . '/config/address.php';

$fetch   = in_array('--fetch', $argv, true);
$check   = in_array('--check', $argv, true);
$outPath = $root . '/assets/geo/imus-address-terms.json';
$rawPath = $root . '/assets/geo/osm-imus-addresses.json';
$ovrPath = $root . '/assets/geo/imus-address-overrides.csv';

foreach ($argv as $arg) {
    if (strpos($arg, '--out=') === 0) $outPath = substr($arg, 6);
}

function fail(string $msg): void {
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function logline(string $msg): void {
    fwrite(STDOUT, $msg . "\n");
}

// ── 1. Overpass snapshot ─────────────────────────────────────────────────────
$overpassQuery = '[out:json][timeout:90];'
    . 'area["name"="Imus"]["boundary"="administrative"]->.a;'
    . '('
    . 'way["highway"]["name"](area.a);'
    . 'way["landuse"="residential"]["name"](area.a);'
    . 'relation["landuse"="residential"]["name"](area.a);'
    . 'node["place"]["name"](area.a);'
    . 'way["addr:place"](area.a);'
    . 'node["addr:place"](area.a);'
    . ');out tags center;';

if ($fetch) {
    logline('Fetching Overpass snapshot for Imus...');
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: SenTri-AddressTerms/1.0 (contact: dev@localhost)\r\n",
            'timeout' => 120,
        ],
    ]);
    $url = 'https://overpass-api.de/api/interpreter?data=' . rawurlencode($overpassQuery);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || strlen($raw) < 100) fail('Overpass fetch failed (HTTP error or empty response).');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['elements'])) fail('Overpass response was not valid JSON.');
    if (!is_dir(dirname($rawPath))) @mkdir(dirname($rawPath), 0775, true);
    file_put_contents($rawPath, $raw);
    logline('Snapshot saved to ' . $rawPath . ' (' . count($decoded['elements']) . ' elements)');
}

if (!is_file($rawPath)) {
    fail('Missing snapshot ' . $rawPath . '. Run with --fetch first.');
}
$raw = file_get_contents($rawPath);
$osm = json_decode($raw, true);
if (!is_array($osm) || !is_array($osm['elements'] ?? null)) fail('Snapshot is not valid Overpass JSON.');
$osmTimestamp = (string)($osm['osm3s']['timestamp_osm_base'] ?? '');

// ── 2. Barangay polygons (point-in-polygon assignment) ───────────────────────
$geoRaw = @file_get_contents($root . '/assets/geo/imus-barangays.geojson');
if ($geoRaw === false) fail('Cannot read assets/geo/imus-barangays.geojson');
$geo = json_decode($geoRaw, true);
if (!is_array($geo) || !is_array($geo['features'] ?? null)) fail('Barangay GeoJSON is invalid.');

$barangays = []; // name => ['rings'=>[outer rings], 'bbox'=>[minLat,minLng,maxLat,maxLng]]
foreach ($geo['features'] as $f) {
    $name = trim((string)($f['properties']['name'] ?? ''));
    if ($name === '') continue;
    $type = $f['geometry']['type'] ?? '';
    $coords = $f['geometry']['coordinates'] ?? [];
    $rings = [];
    if ($type === 'Polygon') {
        $rings[] = $coords[0] ?? [];
    } elseif ($type === 'MultiPolygon') {
        foreach ($coords as $poly) $rings[] = $poly[0] ?? [];
    }
    if (!$rings) continue;
    $minLat = 90; $minLng = 180; $maxLat = -90; $maxLng = -180;
    foreach ($rings as $ring) {
        foreach ($ring as $pt) {
            $lng = (float)$pt[0]; $lat = (float)$pt[1];
            if ($lat < $minLat) $minLat = $lat;
            if ($lat > $maxLat) $maxLat = $lat;
            if ($lng < $minLng) $minLng = $lng;
            if ($lng > $maxLng) $maxLng = $lng;
        }
    }
    $barangays[$name] = ['rings' => $rings, 'bbox' => [$minLat, $minLng, $maxLat, $maxLng]];
}
if (!$barangays) fail('No barangay polygons loaded.');

/** Ray-casting point-in-ring. Ring points are [lng, lat]. */
function point_in_ring(float $lat, float $lng, array $ring): bool {
    $n = count($ring);
    if ($n < 3) return false;
    $inside = false;
    $j = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        $xi = (float)$ring[$i][0]; $yi = (float)$ring[$i][1];
        $xj = (float)$ring[$j][0]; $yj = (float)$ring[$j][1];
        $intersects = (($yi > $lat) !== ($yj > $lat))
            && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
        if ($intersects) $inside = !$inside;
        $j = $i;
    }
    return $inside;
}

function assign_barangay(float $lat, float $lng, array $barangays): ?string {
    foreach ($barangays as $name => $b) {
        [$minLat, $minLng, $maxLat, $maxLng] = $b['bbox'];
        if ($lat < $minLat || $lat > $maxLat || $lng < $minLng || $lng > $maxLng) continue;
        foreach ($b['rings'] as $ring) {
            if (point_in_ring($lat, $lng, $ring)) return $name;
        }
    }
    return null;
}

// ── 3. Accumulate terms ──────────────────────────────────────────────────────
$terms = []; // "brgy|type|key" => row
$sourceRank = ['osm' => 1, 'lgu' => 2, 'manual' => 3];
$validTypes = ['street','road','subdivision','phase','block','purok','sitio','landmark','other'];

function add_term(array &$terms, string $brgy, string $name, string $type,
                  ?string $parent, $lat, $lng, string $source): void {
    global $sourceRank, $validTypes;
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '' || !in_array($type, $validTypes, true)) return;

    $display = sentri_address_titlecase($name);
    $key = sentri_address_key($display);
    if ($key === '' || strlen($key) < 2) return;

    // Never suggest a barangay name as a street/place.
    if (sentri_barangay_canonical($display) !== null) return;
    if (sentri_address_is_business($display)) return;

    $id = $brgy . '|' . $type . '|' . $key;
    if (isset($terms[$id]) && $sourceRank[$terms[$id]['source']] > $sourceRank[$source]) return;

    $terms[$id] = [
        'barangay' => $brgy,
        'term'     => $display,
        'key'      => $key,
        'type'     => $type,
        'parent'   => ($parent !== null && $parent !== '') ? $parent : null,
        'lat'      => ($lat !== null && $lat !== '') ? round((float)$lat, 6) : null,
        'lng'      => ($lng !== null && $lng !== '') ? round((float)$lng, 6) : null,
        'source'   => $source,
    ];
}

$seen = 0; $assigned = 0; $skippedNoPoint = 0; $skippedNoBrgy = 0;
foreach ($osm['elements'] as $el) {
    $seen++;
    $tags = $el['tags'] ?? [];
    if (!is_array($tags) || !$tags) continue;

    // Never turn the city itself into an address suggestion.
    if (($tags['place'] ?? '') === 'city') continue;

    $lat = $lng = null;
    if (isset($el['lat'], $el['lon'])) {
        $lat = (float)$el['lat']; $lng = (float)$el['lon'];
    } elseif (isset($el['center']['lat'], $el['center']['lon'])) {
        $lat = (float)$el['center']['lat']; $lng = (float)$el['center']['lon'];
    }
    if ($lat === null) { $skippedNoPoint++; continue; }

    $brgy = assign_barangay($lat, $lng, $barangays);
    if ($brgy === null) { $skippedNoBrgy++; continue; }
    $assigned++;

    $hint = '';
    if (isset($tags['highway'])) $hint = 'highway';
    elseif (isset($tags['addr:place'])) $hint = 'addr_place';
    elseif (($tags['landuse'] ?? '') === 'residential') $hint = 'residential';
    elseif (isset($tags['place'])) $hint = 'place';

    $name = trim((string)($tags['name'] ?? ($tags['addr:place'] ?? '')));
    if ($name !== '') {
        [$type, $parent] = sentri_address_classify($name, $hint);
        add_term($terms, $brgy, $name, $type, $parent, $lat, $lng, 'osm');

        // Phase/Block names imply their parent subdivision exists too.
        if ($parent !== null && in_array($type, ['phase', 'block'], true)) {
            add_term($terms, $brgy, $parent, 'subdivision', null, $lat, $lng, 'osm');
        }
    }

    // Address points often carry an addr:street that no named highway covers.
    $street = trim((string)($tags['addr:street'] ?? ''));
    if ($street !== '') {
        [$stype, $sparent] = sentri_address_classify($street, 'highway');
        add_term($terms, $brgy, $street, $stype, $sparent, $lat, $lng, 'osm');
    }
}

// ── 3b. Existing street dataset (guarantees every barangay is covered) ──────
$streetsPath = $root . '/assets/geo/imus-streets.json';
$streetAdded = 0;
if (is_file($streetsPath)) {
    $sd = json_decode((string)file_get_contents($streetsPath), true);
    if (is_array($sd) && is_array($sd['streetsByBarangay'] ?? null)) {
        $coords = is_array($sd['streetCoordinates'] ?? null) ? $sd['streetCoordinates'] : [];
        foreach ($sd['streetsByBarangay'] as $rawBrgy => $list) {
            $brgy = sentri_barangay_canonical((string)$rawBrgy);
            if ($brgy === null || !is_array($list)) continue;
            foreach ($list as $street) {
                $street = trim((string)$street);
                if ($street === '') continue;
                $c = $coords[$rawBrgy . '|||' . $street] ?? null;
                [$type, $parent] = sentri_address_classify($street, 'highway');
                add_term($terms, $brgy, $street, $type, $parent,
                    $c[0] ?? null, $c[1] ?? null, 'osm');
                $streetAdded++;
            }
        }
    }
}

// ── 3c. LGU override CSV (wins over OSM) ────────────────────────────────────
$overrideAdded = 0;
if (is_file($ovrPath)) {
    $fh = fopen($ovrPath, 'r');
    if ($fh) {
        $line = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if ($row === [null] || count($row) < 3) continue;
            $rawBrgy = trim((string)$row[0]);
            $term    = trim((string)$row[1]);
            $type    = strtolower(trim((string)$row[2]));
            $parent  = trim((string)($row[3] ?? ''));
            if ($line === 1 && strcasecmp($rawBrgy, 'barangay') === 0) continue;
            $brgy = sentri_barangay_canonical($rawBrgy);
            if ($brgy === null || $term === '' || !in_array($type, $validTypes, true)) {
                fwrite(STDERR, "  skip override line {$line}: invalid barangay/type\n");
                continue;
            }
            add_term($terms, $brgy, $term, $type, ($parent !== '' ? $parent : null), null, null, 'lgu');
            $overrideAdded++;
        }
        fclose($fh);
    }
}

// ── 4. Group, sort and write ────────────────────────────────────────────────
$order = sentri_address_type_order();
$grouped = [];
foreach (sentri_barangay_names() as $name) $grouped[$name] = [];
foreach ($terms as $row) {
    $b = $row['barangay'];
    if (!isset($grouped[$b])) $grouped[$b] = [];
    $grouped[$b][] = [
        'term'   => $row['term'],
        'key'    => $row['key'],
        'type'   => $row['type'],
        'parent' => $row['parent'],
        'lat'    => $row['lat'],
        'lng'    => $row['lng'],
        'source' => $row['source'],
    ];
}
foreach ($grouped as &$list) {
    usort($list, function ($a, $b) use ($order) {
        $ta = $order[$a['type']] ?? 99;
        $tb = $order[$b['type']] ?? 99;
        if ($ta !== $tb) return $ta <=> $tb;
        return strcasecmp($a['term'], $b['term']);
    });
}
unset($list);

$total = 0;
$byType = [];
$missing = [];
foreach ($grouped as $name => $list) {
    $total += count($list);
    if (!count($list)) $missing[] = $name;
    foreach ($list as $t) $byType[$t['type']] = ($byType[$t['type']] ?? 0) + 1;
}
ksort($byType);

$document = [
    'schema'        => 1,
    'attribution'   => 'Address suggestions © OpenStreetMap contributors (ODbL)',
    'osm_timestamp' => $osmTimestamp,
    'barangay_count'=> count($grouped),
    'term_count'    => $total,
    'types'         => $byType,
    'barangays'     => $grouped,
];

if (!is_dir(dirname($outPath))) @mkdir(dirname($outPath), 0775, true);
$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) fail('JSON encoding failed: ' . json_last_error_msg());
file_put_contents($outPath, $json . "\n");

logline('Address-term snapshot written: ' . $outPath);
logline('  OSM elements: ' . $seen . ' (assigned ' . $assigned . ', no point ' . $skippedNoPoint . ', outside Imus ' . $skippedNoBrgy . ')');
logline('  Street dataset rows: ' . $streetAdded . ', LGU overrides: ' . $overrideAdded);
logline('  Barangays: ' . count($grouped) . ', terms: ' . $total);
logline('  By type: ' . json_encode($byType));
if ($missing) {
    logline('  WARNING: ' . count($missing) . ' barangay(s) with no terms: ' . implode(', ', $missing));
}

if ($check && $missing) {
    fail('Coverage check failed: ' . count($missing) . ' barangay(s) have no address terms.');
}
if ($check && $total === 0) {
    fail('Coverage check failed: the dataset is empty.');
}
logline('OK');
