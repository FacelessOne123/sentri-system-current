<?php
/**
 * api/gps_bridge.php - GPS bridge: pairing + location ingestion
 * ============================================================================
 * A local "GPS bridge" runs on the machine that has the GPS hardware (often a
 * phone or a laptop without a browser-based geolocation permission) and
 * forwards fixes to the SenTri server.
 *
 * Flow
 *  1. The signed-in user opens My Profile and generates a pairing code.
 *  2. The bridge (server-side, on the user's machine) POSTs that code plus a
 *     device label and receives a long-lived device token.
 *  3. The bridge POSTs fixes with that token. They are stored in
 *     user_gps_fixes and mirrored onto users.gps_lat/gps_lng/gps_at.
 *  4. The community portal polls get_my_gps and shows the bridge position as
 *     a fallback when the browser's own geolocation is unavailable.
 *
 * Actions
 *   POST action=pair            {code,label}            -> device token
 *   POST action=update          Bearer <token> {lat,lng,accuracy,recorded_at}
 *   GET  action=list_devices                            (session)
 *   POST action=revoke          {device_id}              (session)
 *   GET  action=get_my_gps                               (session)
 *   POST action=create_pair_code                        (session)
 *
 * Security notes
 *  - Pair codes are single-use and expire; only their SHA-256 hash is stored.
 *  - Device tokens are shown once and only their hash is stored.
 *  - The session-scoped actions require a signed-in community account and a
 *    valid CSRF token, so a browser cannot silently pair a device.
 *  - No location history is exposed to other users or to the public feed.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/session.php';

// ── Realm resolution ─────────────────────────────────────────────────────────
// pair/update are called by the bridge (no browser session), the rest by the
// signed-in community user.
$action = trim((string)($_REQUEST['action'] ?? ''));

$PUBLIC_ACTIONS = ['pair', 'update'];

if (!in_array($action, $PUBLIC_ACTIONS, true)) {
    // Probe the community realm FIRST. A responder signed in on the same
    // browser must not shadow the community account here - otherwise the
    // community portal would read the responder's position.
    $order = [SENTRI_REALM_COOKIES['community'], SENTRI_REALM_COOKIES['gov']];
    sentri_boot_auto_realm(['community', 'user', 'first_responder'], $order);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function bridge_json(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bridge_input(): array {
    $raw = file_get_contents('php://input');
    $data = [];
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = $decoded;
    }
    foreach ($_POST as $k => $v) if (!isset($data[$k])) $data[$k] = $v;
    return $data;
}

function bridge_bearer(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) { $hdr = $value; break; }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$hdr), $m)) return trim($m[1]);
    return null;
}

function bridge_device_from_token(mysqli $conn, string $token): ?array {
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT id,user_id,label FROM user_gps_devices
                            WHERE token_hash=? AND revoked_at IS NULL LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** True when the table exists (guards a not-yet-migrated deployment). */
function bridge_table_ready(mysqli $conn, string $table): bool {
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$res) return false;
    $ok = $res->num_rows > 0;
    $res->free();
    return $ok;
}

// ── Public: pair a device ────────────────────────────────────────────────────
if ($action === 'pair') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bridge_json(405, ['status' => 'error', 'message' => 'POST required.']);
    if (!bridge_table_ready($conn, 'user_gps_devices')) {
        bridge_json(503, ['status' => 'error', 'message' => 'GPS bridge is not enabled on this server yet.']);
    }
    $in = bridge_input();
    $code = trim((string)($in['code'] ?? ''));
    $label = trim((string)($in['label'] ?? '')) ?: 'GPS bridge';
    $label = substr($label, 0, 100);
    if (!preg_match('/^[0-9]{8}$/', $code)) {
        bridge_json(400, ['status' => 'error', 'message' => 'Invalid pairing code.']);
    }

    $codeHash = hash('sha256', $code);
    $stmt = $conn->prepare("SELECT id,user_id FROM gps_pair_codes
                            WHERE code_hash=? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    if (!$stmt) bridge_json(500, ['status' => 'error', 'message' => 'Could not verify the pairing code.']);
    $stmt->bind_param('s', $codeHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) bridge_json(400, ['status' => 'error', 'message' => 'That pairing code is invalid or has expired.']);

    $codeId = (int)$row['id'];
    $userId = (int)$row['user_id'];

    // Burn the code so it cannot be replayed.
    $burn = $conn->prepare("UPDATE gps_pair_codes SET used_at=NOW() WHERE id=? AND used_at IS NULL");
    if ($burn) {
        $burn->bind_param('i', $codeId);
        $burn->execute();
        $burn->close();
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $ins = $conn->prepare("INSERT INTO user_gps_devices (user_id,label,token_hash) VALUES (?,?,?)");
    if (!$ins) bridge_json(500, ['status' => 'error', 'message' => 'Could not register the device.']);
    $ins->bind_param('iss', $userId, $label, $tokenHash);
    $ok = $ins->execute();
    $ins->close();
    if (!$ok) bridge_json(500, ['status' => 'error', 'message' => 'Could not register the device.']);

    bridge_json(200, [
        'status'     => 'success',
        'token'      => $token,
        'device_id'  => (int)$conn->insert_id,
        'label'      => $label,
        'message'    => 'Device paired. Keep this token - it is shown only once.',
    ]);
}

// ── Public: ingest a fix with a device token ─────────────────────────────────
if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bridge_json(405, ['status' => 'error', 'message' => 'POST required.']);
    if (!bridge_table_ready($conn, 'user_gps_fixes')) {
        bridge_json(503, ['status' => 'error', 'message' => 'GPS bridge is not enabled on this server yet.']);
    }
    $token = bridge_bearer();
    if ($token === null) {
        $in = bridge_input();
        $token = trim((string)($in['token'] ?? ''));
    }
    $device = bridge_device_from_token($conn, (string)$token);
    if (!$device) bridge_json(401, ['status' => 'error', 'message' => 'Invalid or revoked device token.']);

    $in = bridge_input();
    $lat = isset($in['lat']) ? (float)$in['lat'] : null;
    $lng = isset($in['lng']) ? (float)$in['lng'] : null;
    $acc = isset($in['accuracy']) && $in['accuracy'] !== '' ? (float)$in['accuracy'] : null;
    if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)
        || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        bridge_json(400, ['status' => 'error', 'message' => 'Invalid coordinates.']);
    }
    if ($acc !== null && (!is_finite($acc) || $acc < 0 || $acc > 100000)) $acc = null;

    $userId = (int)$device['user_id'];
    $deviceId = (int)$device['id'];
    $recordedAt = null;
    if (!empty($in['recorded_at'])) {
        $ts = strtotime((string)$in['recorded_at']);
        if ($ts !== false) $recordedAt = date('Y-m-d H:i:s', $ts);
    }

    $ins = $conn->prepare("INSERT INTO user_gps_fixes
        (user_id,device_id,latitude,longitude,accuracy,source,recorded_at)
        VALUES (?,?,?,?,?,'bridge',?)");
    if (!$ins) bridge_json(500, ['status' => 'error', 'message' => 'Could not store the location.']);
    $ins->bind_param('iiddds', $userId, $deviceId, $lat, $lng, $acc, $recordedAt);
    $ok = $ins->execute();
    $ins->close();
    if (!$ok) bridge_json(500, ['status' => 'error', 'message' => 'Could not store the location.']);

    // Mirror the newest fix onto the user row for fast reads elsewhere.
    $mir = $conn->prepare("UPDATE users SET gps_lat=?, gps_lng=?, gps_accuracy=?, gps_at=NOW(), gps_source='bridge'
                           WHERE id=?");
    if ($mir) {
        $mir->bind_param('dddi', $lat, $lng, $acc, $userId);
        $mir->execute();
        $mir->close();
    }
    $seen = $conn->prepare("UPDATE user_gps_devices SET last_seen_at=NOW() WHERE id=?");
    if ($seen) { $seen->bind_param('i', $deviceId); $seen->execute(); $seen->close(); }

    bridge_json(200, ['status' => 'success', 'received_at' => gmdate('c')]);
}

// ── Session-scoped actions ───────────────────────────────────────────────────
$ident = sentri_identity();
if (!$ident) bridge_json(401, ['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
$userId = (int)($ident['user_id'] ?? 0);
$role = $ident['role'] ?? 'user';
if (!in_array($role, ['community', 'user', 'first_responder'], true)) {
    bridge_json(403, ['status' => 'error', 'message' => 'This account cannot use the GPS bridge.']);
}

if (!bridge_table_ready($conn, 'user_gps_devices')) {
    bridge_json(503, ['status' => 'error', 'message' => 'GPS bridge is not enabled on this server yet.']);
}

// CSRF protects the session actions (pair/update are token/code authenticated).
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_validate();

if ($action === 'create_pair_code') {
    // One active code per user at a time.
    $conn->query("DELETE FROM gps_pair_codes WHERE user_id=$userId AND used_at IS NULL");
    $code = (string)random_int(10000000, 99999999);
    $hash = hash('sha256', $code);
    $stmt = $conn->prepare("INSERT INTO gps_pair_codes (user_id,code_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    if (!$stmt) bridge_json(500, ['status' => 'error', 'message' => 'Could not create a pairing code.']);
    $stmt->bind_param('is', $userId, $hash);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) bridge_json(500, ['status' => 'error', 'message' => 'Could not create a pairing code.']);
    bridge_json(200, ['status' => 'success', 'code' => $code, 'expires_in' => 900]);
}

if ($action === 'list_devices') {
    $stmt = $conn->prepare("SELECT id,label,last_seen_at,revoked_at,created_at FROM user_gps_devices
                            WHERE user_id=? ORDER BY id DESC");
    $devices = [];
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $devices[] = [
                'id'          => (int)$r['id'],
                'label'       => (string)$r['label'],
                'last_seen_at'=> $r['last_seen_at'],
                'revoked_at'  => $r['revoked_at'],
                'created_at'  => $r['created_at'],
            ];
        }
        $stmt->close();
    }
    bridge_json(200, ['status' => 'success', 'devices' => $devices]);
}

if ($action === 'revoke') {
    $in = bridge_input();
    $deviceId = (int)($in['device_id'] ?? 0);
    if (!$deviceId) bridge_json(400, ['status' => 'error', 'message' => 'Invalid device id.']);
    $stmt = $conn->prepare("UPDATE user_gps_devices SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL");
    if (!$stmt) bridge_json(500, ['status' => 'error', 'message' => 'Could not revoke the device.']);
    $stmt->bind_param('ii', $deviceId, $userId);
    $stmt->execute();
    $stmt->close();
    bridge_json(200, ['status' => 'success']);
}

if ($action === 'get_my_gps') {
    $fix = null;
    $stmt = $conn->prepare("SELECT latitude,longitude,accuracy,source,recorded_at,received_at
                            FROM user_gps_fixes WHERE user_id=? ORDER BY received_at DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($r) {
            $fix = [
                'lat'         => (float)$r['latitude'],
                'lng'         => (float)$r['longitude'],
                'accuracy'    => $r['accuracy'] !== null ? (float)$r['accuracy'] : null,
                'source'      => (string)$r['source'],
                'recorded_at' => $r['recorded_at'],
                'received_at' => $r['received_at'],
            ];
        }
    }
    // Fall back to the mirrored user row if the history table is empty.
    if ($fix === null) {
        $stmt = $conn->prepare("SELECT gps_lat,gps_lng,gps_accuracy,gps_at,gps_source FROM users WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($r && $r['gps_lat'] !== null && $r['gps_lng'] !== null) {
                $fix = [
                    'lat'         => (float)$r['gps_lat'],
                    'lng'         => (float)$r['gps_lng'],
                    'accuracy'    => $r['gps_accuracy'] !== null ? (float)$r['gps_accuracy'] : null,
                    'source'      => (string)($r['gps_source'] ?: 'browser'),
                    'recorded_at' => $r['gps_at'],
                    'received_at' => $r['gps_at'],
                ];
            }
        }
    }
    $age = null;
    if ($fix && $fix['received_at']) {
        $age = max(0, time() - strtotime((string)$fix['received_at']));
    }
    bridge_json(200, ['status' => 'success', 'fix' => $fix, 'age' => $age]);
}

bridge_json(400, ['status' => 'error', 'message' => 'Unknown action.']);
