<?php
/**
 * tools/gps-bridge/bridge.php - local GPS bridge daemon
 * ============================================================================
 * Runs on the machine that has the GPS hardware and bridges that location to
 * the SenTri server, so the web app keeps working when the browser cannot
 * obtain a fix (no geolocation permission, insecure origin, no GPS chip).
 *
 * It is a tiny HTTP daemon:
 *   GET  /            -> status page (is it paired? is it posting?)
 *   POST /pair        {code,label,server} -> pairs with the SenTri server
 *   POST /update      {lat,lng,accuracy}   -> forwards a fix
 *   POST /unpair                                -> forgets the device token
 *
 * The device token is stored in ~/.sentri-gps-bridge.json (mode 0600) and is
 * only ever sent to the paired SenTri server over the configured URL.
 *
 * Usage
 *   php tools/gps-bridge/bridge.php --server=http://host/sentri-system \
 *       --port=8787 --label="Kitchen tablet"
 *
 * To feed it a location, POST to /update from any local process, e.g.
 *   curl -X POST http://127.0.0.1:8787/update \
 *        -d '{"lat":14.4218,"lng":120.9160,"accuracy":12}'
 *
 * Or point the SenTri browser app at the bridge: the portal can read the
 * bridge position directly if it is reachable (see --allow-lan).
 */

declare(strict_types=1);

const DEFAULT_PORT = 8787;
const STATE_DIR = '.sentri';
const STATE_FILE = 'gps-bridge.json';
const PAIR_TTL_MINUTES = 15;

// ── CLI options ──────────────────────────────────────────────────────────────
$options = [
    'server' => '',
    'port'   => DEFAULT_PORT,
    'label'  => 'GPS bridge (' . gethostname() . ')',
    'code'   => '',
    'lat'    => '',
    'lng'    => '',
    'accuracy' => '',
    'allow_lan' => false,
    'once'   => false,
];
// $argv is absent when this file is included by the built-in server's router
// (see router.php); fall back to an empty list.
$argv = isset($argv) && is_array($argv) ? $argv : [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $arg, $m)) {
        $key = $m[1];
        if ($key === 'allow_lan') $options[$key] = ($m[2] !== '0' && strtolower($m[2]) !== 'false');
        else $options[$key] = $m[2];
    } elseif (preg_match('/^--([a-z_]+)$/', $arg, $m)) {
        // Bare flags: --once, --allow-lan
        $options[$m[1]] = true;
    }
}

// ── State ────────────────────────────────────────────────────────────────────
function state_path(): string {
    $home = getenv('HOME');
    if (!$home || !is_dir($home)) $home = sys_get_temp_dir();
    $dir = rtrim($home, '/') . '/' . STATE_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . STATE_FILE;
}

function load_state(): array {
    $path = state_path();
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_state(array $state): bool {
    $path = state_path();
    $ok = @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok !== false) @chmod($path, 0600);
    return $ok !== false;
}

/**
 * POST JSON to the SenTri server using streams (no cURL dependency).
 * @return array{ok:bool,status:int,body:string}
 */
function http_post_json(string $url, array $payload, ?string $bearer = null): array {
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n"
                             . "Accept: application/json\r\n"
                             . "User-Agent: SenTri-GPS-Bridge/1.0\r\n"
                             . ($bearer !== null ? "Authorization: Bearer {$bearer}\r\n" : ''),
            'content'       => json_encode($payload),
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'Could not reach ' . $url];
    }
    $status = 0;
    // $http_response_header is populated in the local scope of the call.
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body];
}

/** GET JSON from the SenTri server (used to check a pairing code). */
function http_get_json(string $url): array {
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "Accept: application/json\r\nUser-Agent: SenTri-GPS-Bridge/1.0\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'Could not reach ' . $url];
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body];
}

function normalize_server(string $server): string {
    $server = trim($server);
    if ($server === '') return '';
    if (!preg_match('#^https?://#i', $server)) $server = 'http://' . $server;
    return rtrim($server, '/');
}

// ── Pairing ──────────────────────────────────────────────────────────────────
function pair_with_server(string $server, string $code, string $label): array {
    $server = normalize_server($server);
    if ($server === '') return ['ok' => false, 'message' => 'A SenTri server URL is required.'];
    if (!preg_match('/^[0-9]{8}$/', $code)) return ['ok' => false, 'message' => 'The pairing code must be 8 digits.'];

    $res = http_post_json($server . '/api/gps_bridge.php?action=pair', [
        'code'  => $code,
        'label' => $label,
    ]);
    $data = json_decode($res['body'], true);
    if (!$res['ok'] || !is_array($data) || ($data['status'] ?? '') !== 'success') {
        $msg = is_array($data) ? (string)($data['message'] ?? 'Pairing failed.') : 'Pairing failed (HTTP ' . $res['status'] . ').';
        return ['ok' => false, 'message' => $msg];
    }
    $state = [
        'server'       => $server,
        'token'        => (string)$data['token'],
        'device_id'    => (int)($data['device_id'] ?? 0),
        'label'        => (string)($data['label'] ?? $label),
        'paired_at'    => date('c'),
        'last_fix_at'  => null,
        'last_status'  => 'paired',
    ];
    save_state($state);
    return ['ok' => true, 'message' => 'Paired successfully.', 'state' => $state];
}

function push_fix(array $state, array $fix): array {
    if (empty($state['server']) || empty($state['token'])) {
        return ['ok' => false, 'message' => 'Not paired with a SenTri server yet.'];
    }
    $payload = [
        'lat'         => (float)$fix['lat'],
        'lng'         => (float)$fix['lng'],
        'accuracy'    => isset($fix['accuracy']) ? (float)$fix['accuracy'] : null,
        'recorded_at' => date('c'),
    ];
    $res = http_post_json(
        $state['server'] . '/api/gps_bridge.php?action=update',
        $payload,
        (string)$state['token']
    );
    $data = json_decode($res['body'], true);
    if (!$res['ok'] || !is_array($data) || ($data['status'] ?? '') !== 'success') {
        $msg = is_array($data) ? (string)($data['message'] ?? 'Update rejected.') : 'Update failed (HTTP ' . $res['status'] . ').';
        return ['ok' => false, 'message' => $msg];
    }
    $state['last_fix_at'] = date('c');
    $state['last_status'] = 'ok';
    save_state($state);
    return ['ok' => true, 'message' => 'Fix delivered.'];
}

// ── Request handling ─────────────────────────────────────────────────────────
function send_json(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$state = load_state();
// CLI overrides win for one-off testing, but never overwrite a stored pairing.
if ($options['server'] !== '') {
    $state['server'] = normalize_server($options['server']);
    if (empty($state['label'])) $state['label'] = $options['label'];
}
if ($options['label'] !== '' && empty($state['label'])) $state['label'] = $options['label'];

if ($path === '/pair' && $method === 'POST') {
    $in = read_json_body();
    $result = pair_with_server(
        (string)($in['server'] ?? $state['server'] ?? ''),
        (string)($in['code'] ?? ''),
        (string)($in['label'] ?? $options['label'])
    );
    send_json($result['ok'] ? 200 : 400, ['status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']]);
}

if ($path === '/update' && $method === 'POST') {
    $in = read_json_body();
    if (!isset($in['lat'], $in['lng'])) send_json(400, ['status' => 'error', 'message' => 'lat and lng are required.']);
    $result = push_fix($state, $in);
    send_json($result['ok'] ? 200 : 502, ['status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']]);
}

if ($path === '/unpair' && $method === 'POST') {
    @unlink(state_path());
    send_json(200, ['status' => 'success', 'message' => 'Unpaired.']);
}

if ($path === '/status') {
    send_json(200, [
        'status'       => 'success',
        'paired'       => !empty($state['token']),
        'server'       => $state['server'] ?? '',
        'label'        => $state['label'] ?? '',
        'last_fix_at'  => $state['last_fix_at'] ?? null,
        'last_status'  => $state['last_status'] ?? null,
    ]);
}

// ── One-shot mode (useful for scripts and CI) ───────────────────────────────
if ($options['once']) {
    $fix = read_json_body();
    if ($fix === [] && $options['lat'] !== '' && $options['lng'] !== '') {
        $fix = ['lat' => (float)$options['lat'], 'lng' => (float)$options['lng']];
        if ($options['accuracy'] !== '') $fix['accuracy'] = (float)$options['accuracy'];
    }
    if (!isset($fix['lat'], $fix['lng'])) {
        fwrite(STDERR, "lat and lng are required (use --lat= --lng= or POST a JSON body)\n");
        exit(2);
    }
    // A one-shot run can pair first (--code=12345678) or reuse a stored pairing.
    if (!empty($options['code'])) {
        $pair = pair_with_server(
            (string)($options['server'] ?? ($state['server'] ?? '')),
            (string)$options['code'],
            (string)($options['label'] ?? 'GPS bridge')
        );
        if (!$pair['ok']) {
            fwrite(STDERR, "PAIR FAILED: {$pair['message']}\n");
            exit(1);
        }
        $state = $pair['state'];
        fwrite(STDOUT, "Paired. ");
    }
    $result = push_fix($state, $fix);
    echo ($result['ok'] ? "OK: " : "FAIL: ") . $result['message'] . "\n";
    exit($result['ok'] ? 0 : 1);
}

// ── Status page ──────────────────────────────────────────────────────────────
$bound = $options['allow_lan'] ? '0.0.0.0' : '127.0.0.1';
$paired = !empty($state['token']);
$server = $state['server'] ?? '';
$label = htmlspecialchars((string)($state['label'] ?? $options['label']), ENT_QUOTES, 'UTF-8');
$lastFix = $state['last_fix_at'] ?? 'never';
$boundAddr = $bound . ':' . (int)$options['port'];
$portNum = (int)$options['port'];

header('Content-Type: text/html; charset=utf-8');
$tpl = __DIR__ . '/status-page.php';
if (!is_file($tpl)) { echo 'SenTri GPS Bridge: status template missing.'; exit; }
$page = [
    'paired'    => $paired,
    'server'    => htmlspecialchars((string)$server, ENT_QUOTES, 'UTF-8'),
    'label'     => $label,
    'last_fix'  => htmlspecialchars((string)$lastFix, ENT_QUOTES, 'UTF-8'),
    'bound'     => htmlspecialchars($boundAddr, ENT_QUOTES, 'UTF-8'),
    'port'      => $portNum,
];
ob_start();
include $tpl;
echo ob_get_clean();
exit;
