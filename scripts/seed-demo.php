<?php
/**
 * scripts/seed-demo.php — idempotent demo data seeder (CLI).
 *
 * Usage: php scripts/seed-demo.php
 *
 * Creates (upsert-safe, safe to re-run):
 *   • 5 demo accounts: community@/barangay@/responder@/lgu@demo.ph (Demo@1234),
 *     pending@demo.ph (responder awaiting approval), admin@sentri.ph (Admin@1234)
 *   • sample [SMOKE]-tagged incidents at real Imus coordinates spread across
 *     barangays (wiped later by debug.php 'Wipe smoke reports' or smoke-test.sh)
 *
 * Reads DB connection from .env via the same loader the app uses.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('CLI only'); }

$__root = dirname(__DIR__);
require_once $__root . '/config/email.php';   // .env loader + get_env_var

$host = get_env_var('DB_HOST', 'localhost') ?: 'localhost';
$user = get_env_var('DB_USER', 'root') ?: 'root';
$pass = get_env_var('DB_PASS', '');
$name = get_env_var('DB_NAME', 'sentri') ?: 'sentri';
$port = (int)(get_env_var('DB_PORT', '3306') ?: '3306');

// Prefer socket when host is localhost (MariaDB often only exposes socket
// auth for root) — mirrors config/db.php behaviour.
if ($host === 'localhost') {
    $conn = @new mysqli(null, $user, $pass, $name, $port, '/var/run/mysqld/mysqld.sock');
    if ($conn->connect_error) {
        try { $conn = new mysqli('127.0.0.1', $user, $pass, $name, $port); }
        catch (mysqli_sql_exception $e) { fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n"); exit(1); }
    }
} else {
    try { $conn = new mysqli($host, $user, $pass, $name, $port); }
    catch (mysqli_sql_exception $e) { fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n"); exit(1); }
}
echo "connected: $user@$host → $name (server {$conn->server_info})\n";

// ── users ────────────────────────────────────────────────────────────────────
$hash = password_hash('Demo@1234', PASSWORD_BCRYPT, ['cost' => 10]);
$adminHash = password_hash('Admin@1234', PASSWORD_BCRYPT, ['cost' => 10]);

$users = [
    ['Demo',   'Citizen',     'community@demo.ph',  '09171000001', $hash,     'community',       '',      'Imus', '',        1],
    ['Demo',   'BarnOff',     'barangay@demo.ph',   '09171000002', $hash,     'barangay',        'Bucandala I', 'Imus', '',  1],
    ['Demo',   'Respondr',    'responder@demo.ph',  '09171000003', $hash,     'first_responder', 'Imus',  'Imus', 'medical', 1],
    ['Demo',   'LGUsk',       'lgu@demo.ph',        '09171000004', $hash,     'lgu',             'Imus',  'Imus', '',        1],
    ['Demo',   'PendRes',     'pending@demo.ph',    '09171000005', $hash,     'first_responder', 'Imus',  'Imus', 'fire',    0],
];
$insU = $conn->prepare("INSERT INTO users (first_name,last_name,email,phone_number,password,role,barangay_name,municipality,responder_type,is_approved,email_verified)
 VALUES (?,?,?,?,?,?,?,?,?,?,1)
 ON DUPLICATE KEY UPDATE first_name=VALUES(first_name), last_name=VALUES(last_name),
   phone_number=VALUES(phone_number), is_approved=VALUES(is_approved)");
$insU->bind_param('sssssssssi', $f, $l, $e, $p, $h, $r, $b, $m, $rt, $ap);
foreach ($users as $u) {
    [$f, $l, $e, $p, $h, $r, $b, $m, $rt, $ap] = $u;
    $insU->execute();
}
echo "users upserted." . PHP_EOL;

// admin (different password)
$chk = $conn->query("SELECT id FROM users WHERE email='admin@sentri.ph'");
if ($chk->num_rows === 0) {
    $st = $conn->prepare("INSERT INTO users (first_name,last_name,email,password,role,is_approved,email_verified) VALUES ('Demo','Admin','admin@sentri.ph',?,'admin',1,1)");
    $st->bind_param('s', $adminHash); $st->execute(); $st->close();
    echo "admin@sentri.ph seeded (Admin@1234).\n";
} else {
    echo "admin@sentri.ph exists — password left untouched.\n";
}

// ── sample incidents ([SMOKE]-tagged, removed by smoke-test.sh / debug.php) ──
if (!is_file($__root . '/assets/geo/imus-barangays.geojson')) {
    echo "geojson not found — skipping coordinate samples.\n";
} else {
    $geo = json_decode(file_get_contents($__root . '/assets/geo/imus-barangays.geojson'), true);
    $byName = [];
    foreach ($geo['features'] as $f) $byName[$f['properties']['name']] = $f;

    $communityId = 0;
    if ($r = $conn->query("SELECT id FROM users WHERE email='community@demo.ph'")) { $row = $r->fetch_row(); $communityId = (int)($row[0] ?? 0); $r->free(); }

    $samples = [
        ['[SMOKE] Broken streetlight',        'Dark stretch of road — safety hazard.', 'caution',   'infrastructure'],
        ['[SMOKE] Garbage pile by canal',     'Encroaching on flow path.',             'caution',   'other'],
        ['[SMOKE] Live wire dangling',        'Exposed wire on pole after rain.',      'dangerous', 'infrastructure'],
        ['[SMOKE] Road subsidence',           'Sinkhole forming at junction.',         'dangerous', 'infrastructure'],
        ['[SMOKE] Trail cleared',             'Debris removed after barangay action.', 'safe',      'other'],
    ];
    $pick = ['Bucandala I', 'Anabu I-A', 'Mariano Espeleta I', 'Alapan II-A', 'Bayan Luma IV'];

    $insR = $conn->prepare("INSERT INTO reports (user_id,title,description,location_name,barangay,city,province,latitude,longitude,radius_m,status,category)
        VALUES (?,?,?,?,?,'Imus','Cavite',?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE description=VALUES(description), latitude=VALUES(latitude), status=VALUES(status)");
    // add a UNIQUE constraint target on title? not present; dedupe by deleting prior soak rows instead:
    $conn->query("DELETE r FROM reports r WHERE r.title LIKE '[SMOKE]%'");

    foreach ($samples as $i => $s) {
        $bn = $pick[$i % count($pick)];
        $f = $byName[$bn] ?? null;
        if (!$f) { fwrite(STDERR, "geojson missing barangay '$bn' — skipping sample '$s[0]'\n"); continue; }
        $lon = $f['properties']['center'][0]; $lat = $f['properties']['center'][1];
        $title = $s[0]; $desc = $s[1]; $st = $s[2]; $cat = $s[3]; $radius = 120;
        $locName = "Sample site, $bn";
        $insR->bind_param('issssddiss', $communityId, $title, $desc, $locName, $bn, $lat, $lon, $radius, $st, $cat);
        $insR->execute();
    }
    echo "sample [SMOKE] incidents seeded at verified perimeter centers." . PHP_EOL;
}

echo "SEED OK\n";

