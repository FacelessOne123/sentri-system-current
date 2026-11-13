<?php
/**
 * api/mobile.php - Comprehensive Native Mobile REST API for SenTri
 * ============================================================================
 * Powers the native Android Jetpack Compose client with full feature parity
 * to the web portals (Community, Barangay, LGU, and First Responder).
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/geo.php';
require_once __DIR__ . '/../config/address.php';

const MOBILE_TOKEN_SECRET = 'sentri_mobile_secure_hmac_secret_cavite_2026';
const TOKEN_EXPIRY_SECONDS = 86400 * 30; // 30 days


function mobile_format_iso(?string $dt_str): ?string {
    if (!$dt_str) return null;
    try {
        $tz = new DateTimeZone('Asia/Manila');
        $dt = new DateTime($dt_str, $tz);
        return $dt->format(DateTime::ATOM);
    } catch (Exception $e) {
        return $dt_str;
    }
}

function mobile_respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function generate_token(array $user): string {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'sub' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'barangay' => $user['barangay_name'] ?? '',
        'iat' => time(),
        'exp' => time() + TOKEN_EXPIRY_SECONDS
    ]));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", MOBILE_TOKEN_SECRET, true));
    return "$header.$payload.$signature";
}

function verify_token(?string $token, mysqli $conn): ?array {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;
    $expectedSig = base64url_encode(hash_hmac('sha256', "$header.$payload", MOBILE_TOKEN_SECRET, true));
    if (!hash_equals($expectedSig, $signature)) return null;

    $data = json_decode(base64url_decode($payload), true);
    if (!$data || !isset($data['sub']) || ($data['exp'] ?? 0) < time()) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, role, barangay_name, resident_address, phone_number, is_approved, email_verified, avatar_color, responder_type FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $data['sub']);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function get_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    return $_REQUEST['token'] ?? null;
}

function get_json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
    }
    return $_POST;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
$input = get_json_input();

switch ($action) {
    case 'login': {
        $email = trim($input['email'] ?? '');
        $password = trim($input['password'] ?? '');

        if (!$email || !$password) {
            mobile_respond(400, ['status' => 'error', 'message' => 'Email and password required']);
        }

        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password'])) {
            mobile_respond(401, ['status' => 'error', 'message' => 'Invalid email or password']);
        }

        if (empty($user['email_verified']) && in_array($user['role'], ['community', 'user'])) {
            mobile_respond(403, [
                'status' => 'error',
                'message' => 'Please verify your email before signing in. Check your inbox (or spam folder) for the verification link.',
                'unverified' => true
            ]);
        }

        $token = generate_token($user);
        unset($user['password'], $user['verification_token'], $user['reset_token']);

        mobile_respond(200, [
            'status' => 'success',
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'phone_number' => $user['phone_number'],
                'barangay' => $user['barangay_name'],
                'resident_address' => $user['resident_address'],
                'avatar_color' => $user['avatar_color'] ?? '#0a3d62',
                'responder_type' => $user['responder_type'] ?? null
            ]
        ]);
        break;
    }

    case 'register': {
        $first_name = trim($input['first_name'] ?? '');
        $last_name = trim($input['last_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = trim($input['password'] ?? '');
        $phone = trim($input['phone_number'] ?? '');
        $barangay = trim($input['barangay'] ?? $input['barangay_name'] ?? '');
        $address = trim($input['resident_address'] ?? '');

        if (!$first_name || !$last_name || !$email || !$password) {
            mobile_respond(400, ['status' => 'error', 'message' => 'First name, last name, email, and password required']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            mobile_respond(400, ['status' => 'error', 'message' => 'Invalid email address format']);
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            mobile_respond(409, ['status' => 'error', 'message' => 'Email is already registered']);
        }
        $stmt->close();

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $role = 'community';
        $is_approved = 1;
        $email_verified = 0;
        $ver_token = bin2hex(random_bytes(32));
        $token_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $colors = ['#0a3d62', '#1a5276', '#166534', '#b91c1c', '#6d28d9', '#d97706'];
        $avatar_color = $colors[array_rand($colors)];

        $muni = 'Imus';
        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, phone_number, barangay_name, resident_address, municipality, role, is_approved, email_verified, verification_token, token_expires_at, avatar_color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            mobile_respond(500, ['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
        $stmt->bind_param('sssssssssiisss', $first_name, $last_name, $email, $hash, $phone, $barangay, $address, $muni, $role, $is_approved, $email_verified, $ver_token, $token_expires, $avatar_color);
        
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $stmt->close();

            $emailSent = false;
            try {
                require_once __DIR__ . '/../core/SenTriMailer.php';
                $emailSent = sendVerificationEmail($email, $first_name, $ver_token);
            } catch (Throwable $e) {
                error_log('SenTri mobile signup email error: ' . $e->getMessage());
            }

            mobile_respond(201, [
                'status' => 'success',
                'requires_verification' => true,
                'email_sent' => $emailSent,
                'message' => 'Account created! Please check your email and click the verification link before signing in.'
            ]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            mobile_respond(500, ['status' => 'error', 'message' => 'Failed to create account: ' . $err]);
        }
        break;
    }

    case 'me':
    case 'profile': {
        $token = get_bearer_token();
        $user = verify_token($token, $conn);
        if (!$user) {
            mobile_respond(401, ['status' => 'error', 'message' => 'Unauthorized or expired token']);
        }
        mobile_respond(200, ['status' => 'success', 'user' => $user]);
        break;
    }

    case 'get_reports': {
        $token = get_bearer_token();
        $auth_user = verify_token($token, $conn);
        $auth_uid = $auth_user ? (int)$auth_user['id'] : 0;

        $category = trim($_GET['category'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $barangay = trim($_GET['barangay'] ?? '');
        $time_filter = trim($_GET['time'] ?? 'all');
        $search = trim($_GET['q'] ?? '');
        $limit = min(max((int)($_GET['limit'] ?? 100), 1), 200);

        $is_mine = (!empty($_GET['mine']) || !empty($_GET['mine_only']));
        $allow_archived = !empty($_GET['include_archived']) || ($status === 'safe') || ($status === 'archived');

        $query = "SELECT r.*, u.first_name, u.last_name, u.avatar_color, 
                         resp.first_name AS responder_first, resp.last_name AS responder_last, resp.responder_type AS responder_agency,
                         v.vote AS user_vote
                  FROM reports r 
                  LEFT JOIN users u ON r.user_id = u.id 
                  LEFT JOIN users resp ON r.assigned_to = resp.id
                  LEFT JOIN report_votes v ON v.report_id = r.id AND v.user_id = ?
                  WHERE ";
        $params = [$auth_uid];
        $types = "i";

        if ($is_mine) {
            $query .= "r.user_id = ?";
            $params[] = $auth_uid;
            $types .= "i";
        } else {
            if ($allow_archived) {
                $query .= "1=1";
            } else {
                $query .= "r.is_archived = 0 AND r.status != 'safe'";
            }
        }

        if ($category && $category !== 'all') {
            $query .= " AND r.category = ?";
            $params[] = $category;
            $types .= "s";
        }
        if ($status && $status !== 'all') {
            $query .= " AND r.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        if ($barangay && $barangay !== 'all') {
            $query .= " AND r.barangay = ?";
            $params[] = $barangay;
            $types .= "s";
        }
        if ($search !== '') {
            $query .= " AND (r.title LIKE ? OR r.description LIKE ? OR r.location_name LIKE ?)";
            $wild = '%' . $search . '%';
            $params[] = $wild;
            $params[] = $wild;
            $params[] = $wild;
            $types .= "sss";
        }
        if ($time_filter === '24h') {
            $query .= " AND r.created_at >= NOW() - INTERVAL 24 HOUR";
        } elseif ($time_filter === '7d') {
            $query .= " AND r.created_at >= NOW() - INTERVAL 7 DAY";
        } elseif ($time_filter === '30d') {
            $query .= " AND r.created_at >= NOW() - INTERVAL 30 DAY";
        }


        $query .= " ORDER BY r.created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $conn->prepare($query);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $reports = [];
        $report_ids = [];

        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['latitude'] = $row['latitude'] !== null ? (float)$row['latitude'] : null;
            $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
            $row['radius_m'] = (int)($row['radius_m'] ?? 200);
            $row['upvotes'] = (int)$row['upvotes'];
            $row['downvotes'] = (int)$row['downvotes'];
            $row['user_vote'] = !empty($row['user_vote']) ? (string)$row['user_vote'] : null;
            $row['escalated_to_lgu'] = (int)($row['escalated_to_lgu'] ?? 0);
            if (!empty($row['created_at'])) {
                $row['created_at'] = mobile_format_iso($row['created_at']);
            }
            if (!empty($row['updated_at'])) {
                $row['updated_at'] = mobile_format_iso($row['updated_at']);
            }
            $row['images'] = [];
            $reports[$row['id']] = $row;
            $report_ids[] = $row['id'];
        }
        $stmt->close();

        // Attach images
        if (!empty($report_ids)) {
            $in_clause = implode(',', array_fill(0, count($report_ids), '?'));
            $img_stmt = $conn->prepare("SELECT report_id, file_name, original_name FROM report_images WHERE report_id IN ($in_clause)");
            $types_in = str_repeat('i', count($report_ids));
            $img_stmt->bind_param($types_in, ...$report_ids);
            $img_stmt->execute();
            $img_res = $img_stmt->get_result();
            while ($img = $img_res->fetch_assoc()) {
                $rid = (int)$img['report_id'];
                if (isset($reports[$rid])) {
                    $reports[$rid]['images'][] = [
                        'file_name' => $img['file_name'],
                        'url' => '/uploads/reports/' . $img['file_name']
                    ];
                }
            }
            $img_stmt->close();
        }

        mobile_respond(200, [
            'status' => 'success',
            'count' => count($reports),
            'reports' => array_values($reports)
        ]);
        break;
    }

    case 'report_detail':
    case 'get_timeline': {
        $token = get_bearer_token();
        $auth_user = verify_token($token, $conn);
        $auth_uid = $auth_user ? (int)$auth_user['id'] : 0;

        $report_id = (int)($_GET['report_id'] ?? $input['report_id'] ?? 0);
        if (!$report_id) {
            mobile_respond(400, ['status' => 'error', 'message' => 'Report ID required']);
        }

        $stmt = $conn->prepare("SELECT r.*, u.first_name, u.last_name, u.avatar_color, u.email as reporter_email,
                                       resp.first_name AS responder_first, resp.last_name AS responder_last, resp.responder_type AS responder_agency,
                                       v.vote AS user_vote
                                FROM reports r 
                                LEFT JOIN users u ON r.user_id = u.id 
                                LEFT JOIN users resp ON r.assigned_to = resp.id
                                LEFT JOIN report_votes v ON v.report_id = r.id AND v.user_id = ?
                                WHERE r.id = ? AND r.is_archived = 0 LIMIT 1");
        $stmt->bind_param('ii', $auth_uid, $report_id);
        $stmt->execute();
        $report = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$report) {
            mobile_respond(404, ['status' => 'error', 'message' => 'Report not found']);
        }
        $report['user_vote'] = !empty($report['user_vote']) ? (string)$report['user_vote'] : null;
        $report['created_at'] = mobile_format_iso($report['created_at']);
        $report['updated_at'] = mobile_format_iso($report['updated_at']);

        // Attach images
        $img_stmt = $conn->prepare("SELECT id, file_name, original_name, mime_type, file_size, uploaded_at FROM report_images WHERE report_id = ?");
        $img_stmt->bind_param('i', $report_id);
        $img_stmt->execute();
        $img_res = $img_stmt->get_result();
        $images = [];
        while ($img = $img_res->fetch_assoc()) {
            $img['url'] = '/uploads/reports/' . $img['file_name'];
            $images[] = $img;
        }
        $img_stmt->close();
        $report['images'] = $images;

        // Lifecycle steps
        $timeline = [
            [
                'step' => 'Reported',
                'completed' => true,
                'timestamp' => mobile_format_iso($report['created_at']),
                'description' => 'Incident reported and broadcasted to neighborhood network.'
            ],
            [
                'step' => 'Acknowledged / Assigned',
                'completed' => !empty($report['accepted_at']),
                'timestamp' => mobile_format_iso($report['accepted_at']),
                'description' => !empty($report['responder_first']) ? "Assigned to {$report['responder_first']} {$report['responder_last']} ({$report['responder_agency']})" : "Pending assignment by LGU/Barangay desk."
            ],
            [
                'step' => 'Responded',
                'completed' => !empty($report['responded_at']),
                'timestamp' => mobile_format_iso($report['responded_at']),
                'description' => !empty($report['responded_at']) ? 'Units arrived on scene.' : 'Responders en route.'
            ],
            [
                'step' => 'Resolved',
                'completed' => !empty($report['resolved_at']) || $report['status'] === 'safe',
                'timestamp' => mobile_format_iso($report['resolved_at']),
                'description' => !empty($report['resolved_at']) ? 'Hazard cleared and verified safe.' : 'Incident active.'
            ]
        ];

        mobile_respond(200, [
            'status' => 'success',
            'report' => $report,
            'timeline' => $timeline
        ]);
        break;
    }

    case 'submit_report': {
        $token = get_bearer_token();
        $user = verify_token($token, $conn);
        if (!$user) {
            mobile_respond(401, ['status' => 'error', 'message' => 'Unauthorized']);
        }

        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $category = trim($input['category'] ?? 'other');
        $status = trim($input['status'] ?? 'caution');
        $location_name = trim($input['location_name'] ?? '');
        $barangay = trim($input['barangay'] ?? $user['barangay_name'] ?? 'Imus');
        $city = trim($input['city'] ?? 'Imus');
        $province = trim($input['province'] ?? 'Cavite');
        $radius_m = (int)($input['radius_m'] ?? 200);
        $latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : null;
        $longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;

        if (!$title || !$description) {
            mobile_respond(400, ['status' => 'error', 'message' => 'Title and description are required']);
        }

        $valid_categories = ['crime', 'accident', 'flooding', 'fire', 'health', 'infrastructure', 'other'];
        if (!in_array($category, $valid_categories, true)) {
            $category = 'other';
        }
        $valid_status = ['dangerous', 'caution', 'safe'];
        if (!in_array($status, $valid_status, true)) {
            $status = 'caution';
        }

        $is_archived = ($status === 'safe') ? 1 : 0;
        $stmt = $conn->prepare("INSERT INTO reports (user_id, title, description, location_name, barangay, city, province, latitude, longitude, radius_m, category, status, is_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssssddissi', $user['id'], $title, $description, $location_name, $barangay, $city, $province, $latitude, $longitude, $radius_m, $category, $status, $is_archived);

        if ($stmt->execute()) {
            $report_id = $conn->insert_id;
            $stmt->close();

            // Handle optional image upload (accepts 'image', 'photo', or 'photos')
            $uploaded_image_url = null;
            $img_file = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $img_file = $_FILES['image'];
            } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $img_file = $_FILES['photo'];
            } elseif (isset($_FILES['photos']) && !is_array($_FILES['photos']['name']) && $_FILES['photos']['error'] === UPLOAD_ERR_OK) {
                $img_file = $_FILES['photos'];
            }
            if ($img_file) {
                $upload_dir = __DIR__ . '/../uploads/reports/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $ext = strtolower(pathinfo($img_file['name'], PATHINFO_EXTENSION));
                $safe_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (in_array($ext, $safe_exts, true)) {
                    $new_name = 'report_' . $report_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest = $upload_dir . $new_name;
                    if (move_uploaded_file($img_file['tmp_name'], $dest)) {
                        $fsize = filesize($dest);
                        $mime = mime_content_type($dest);
                        $orig = $img_file['name'];

                        $img_stmt = $conn->prepare("INSERT INTO report_images (report_id, file_name, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)");
                        $img_stmt->bind_param('isssi', $report_id, $new_name, $orig, $mime, $fsize);
                        $img_stmt->execute();
                        $img_stmt->close();

                        $uploaded_image_url = '/uploads/reports/' . $new_name;
                    }
                }
            }

            mobile_respond(201, [
                'status' => 'success',
                'message' => 'Incident report submitted successfully',
                'report_id' => $report_id,
                'image_url' => $uploaded_image_url
            ]);
        } else {
            $stmt->close();
            mobile_respond(500, ['status' => 'error', 'message' => 'Failed to save report']);
        }
        break;
    }

    case 'update_status': {
        $token = get_bearer_token();
        $user = verify_token($token, $conn);
        if (!$user) {
            mobile_respond(401, ['status' => 'error', 'message' => 'Unauthorized']);
        }

        $report_id = (int)($input['report_id'] ?? 0);
        $new_status = trim($input['status'] ?? '');
        $action_type = trim($input['action_type'] ?? ''); // 'escalate', 'respond', 'resolve', 'update'

        if (!$report_id) {
            mobile_respond(400, ['status' => 'error', 'message' => 'Report ID required']);
        }

        if ($action_type === 'escalate') {
            $stmt = $conn->prepare("UPDATE reports SET escalated_to_lgu = 1 WHERE id = ?");
            $stmt->bind_param('i', $report_id);
            $stmt->execute();
            $stmt->close();
            mobile_respond(200, ['status' => 'success', 'message' => 'Incident escalated to City LGU DRRMO']);
        } elseif ($action_type === 'respond') {
            $stmt = $conn->prepare("UPDATE reports SET responded_at = NOW(), assigned_to = ? WHERE id = ?");
            $stmt->bind_param('ii', $user['id'], $report_id);
            $stmt->execute();
            $stmt->close();
            mobile_respond(200, ['status' => 'success', 'message' => 'Response timestamp recorded']);
        } elseif ($action_type === 'resolve') {
            $stmt = $conn->prepare("UPDATE reports SET status = 'safe', resolved_at = NOW(), is_archived = 1 WHERE id = ?");
            $stmt->bind_param('i', $report_id);
            $stmt->execute();
            $stmt->close();
            mobile_respond(200, ['status' => 'success', 'message' => 'Incident resolved and verified safe (archived)']);
        } else {
            if (!in_array($new_status, ['dangerous', 'caution', 'safe'], true)) {
                mobile_respond(400, ['status' => 'error', 'message' => 'Invalid status']);
            }
            if ($new_status === 'safe') {
                $stmt = $conn->prepare("UPDATE reports SET status = 'safe', resolved_at = NOW(), is_archived = 1 WHERE id = ?");
                $stmt->bind_param('i', $report_id);
            } else {
                $stmt = $conn->prepare("UPDATE reports SET status = ?, is_archived = 0 WHERE id = ?");
                $stmt->bind_param('si', $new_status, $report_id);
            }
            $stmt->execute();
            $stmt->close();
            mobile_respond(200, ['status' => 'success', 'message' => 'Status updated']);
        }
        break;
    }

    case 'vote': {
        $token = get_bearer_token();
        $user = verify_token($token, $conn);
        if (!$user) {
            mobile_respond(401, ['status' => 'error', 'message' => 'Unauthorized']);
        }

        $report_id = (int)($input['report_id'] ?? 0);
        $vote_type = ($input['vote'] ?? '') === 'down' ? 'down' : 'up';

        if (!$report_id) {
            mobile_respond(400, ['status' => 'error', 'message' => 'Report ID required']);
        }

        $uid = (int)$user['id'];

        // Check existing vote to support toggle behavior (same as web portal)
        $chk = $conn->prepare("SELECT id, vote FROM report_votes WHERE report_id = ? AND user_id = ? LIMIT 1");
        $chk->bind_param('ii', $report_id, $uid);
        $chk->execute();
        $ex_res = $chk->get_result();
        $ex_vote = $ex_res->fetch_assoc();
        $chk->close();

        $new_user_vote = null;
        if ($ex_vote) {
            if ($ex_vote['vote'] === $vote_type) {
                // Tapping the active vote cancels/toggles it off
                $del = $conn->prepare("DELETE FROM report_votes WHERE id = ?");
                $del->bind_param('i', $ex_vote['id']);
                $del->execute();
                $del->close();
                $new_user_vote = null;
            } else {
                // Switching vote from up to down or vice-versa
                $upd = $conn->prepare("UPDATE report_votes SET vote = ?, created_at = NOW() WHERE id = ?");
                $upd->bind_param('si', $vote_type, $ex_vote['id']);
                $upd->execute();
                $upd->close();
                $new_user_vote = $vote_type;
            }
        } else {
            // New vote
            $ins = $conn->prepare("INSERT INTO report_votes (report_id, user_id, vote) VALUES (?, ?, ?)");
            $ins->bind_param('iis', $report_id, $uid, $vote_type);
            $ins->execute();
            $ins->close();
            $new_user_vote = $vote_type;
        }

        $count_stmt = $conn->prepare("SELECT 
            SUM(CASE WHEN vote = 'up' THEN 1 ELSE 0 END) AS up_total,
            SUM(CASE WHEN vote = 'down' THEN 1 ELSE 0 END) AS down_total
            FROM report_votes WHERE report_id = ?");
        $count_stmt->bind_param('i', $report_id);
        $count_stmt->execute();
        $counts = $count_stmt->get_result()->fetch_assoc();
        $count_stmt->close();

        $up = (int)($counts['up_total'] ?? 0);
        $down = (int)($counts['down_total'] ?? 0);

        $up_stmt = $conn->prepare("UPDATE reports SET upvotes = ?, downvotes = ? WHERE id = ?");
        $up_stmt->bind_param('iii', $up, $down, $report_id);
        $up_stmt->execute();
        $up_stmt->close();

        mobile_respond(200, [
            'status' => 'success',
            'upvotes' => $up,
            'downvotes' => $down,
            'user_vote' => $new_user_vote
        ]);
        break;
    }

    case 'address_terms': {
        $rawBrgy = trim((string)($_GET['barangay'] ?? $input['barangay'] ?? ''));
        $brgy = function_exists('sentri_barangay_canonical') ? sentri_barangay_canonical($rawBrgy) : null;
        if ($brgy === null && $rawBrgy !== '') {
            $brgy = $rawBrgy;
        }
        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));

        $terms = [];

        // 1. Check MySQL database table first
        try {
            $hasTable = $conn->query("SHOW TABLES LIKE 'barangay_address_terms'");
            if ($hasTable && $hasTable->num_rows > 0) {
                $hasTable->free();
                $sql = "SELECT DISTINCT term, term_type, parent_term, latitude, longitude, barangay_name FROM barangay_address_terms WHERE is_active = 1";
                $types = '';
                $params = [];

                $isCanonical = function_exists('sentri_barangay_is_valid') && sentri_barangay_is_valid($rawBrgy);
                if ($isCanonical) {
                    $sql .= " AND barangay_name = ?";
                    $types .= 's';
                    $params[] = $brgy;
                } else if ($rawBrgy !== '') {
                    $sql .= " AND (barangay_name = ? OR barangay_name LIKE ?)";
                    $types .= 'ss';
                    $likeBrgy = '%' . $rawBrgy . '%';
                    $params[] = $rawBrgy;
                    $params[] = $likeBrgy;
                }

                if ($q !== '') {
                    $sql .= " AND (term LIKE ? OR parent_term LIKE ?)";
                    $types .= 'ss';
                    $likeQ = '%' . $q . '%';
                    $params[] = $likeQ;
                    $params[] = $likeQ;
                }

                $sql .= " ORDER BY (CASE WHEN term_type = 'street' THEN 1 WHEN term_type = 'subdivision' THEN 2 ELSE 3 END), term LIMIT 50";

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $terms[] = [
                            'term' => (string)$row['term'],
                            'type' => (string)$row['term_type'],
                            'parent' => $row['parent_term'] !== null ? (string)$row['parent_term'] : null,
                            'lat' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                            'lng' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                            'barangay' => (string)$row['barangay_name']
                        ];
                    }
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            error_log('mobile address_terms DB error: ' . $e->getMessage());
        }

        // 2. Snapshot JSON fallback if terms is still empty
        if (empty($terms)) {
            $snapshotFile = __DIR__ . '/../assets/geo/imus-address-terms.json';
            if (file_exists($snapshotFile)) {
                $data = json_decode(file_get_contents($snapshotFile), true);
                $bList = $data['barangays'] ?? $data;

                $targetKeys = [];
                if ($brgy && isset($bList[$brgy])) {
                    $targetKeys[] = $brgy;
                } else if ($rawBrgy !== '') {
                    $cleanRaw = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($rawBrgy));
                    foreach ($bList as $k => $items) {
                        $cleanKey = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($k));
                        if (strpos($cleanKey, $cleanRaw) !== false || strpos($cleanRaw, $cleanKey) !== false) {
                            $targetKeys[] = $k;
                        }
                    }
                }

                foreach ($targetKeys as $k) {
                    if (isset($bList[$k]) && is_array($bList[$k])) {
                        foreach ($bList[$k] as $item) {
                            $tName = is_array($item) ? ($item['term'] ?? '') : (string)$item;
                            if ($q === '' || stripos($tName, $q) !== false) {
                                $terms[] = [
                                    'term' => $tName,
                                    'type' => is_array($item) ? ($item['type'] ?? 'street') : 'street',
                                    'lat' => is_array($item) ? ($item['lat'] ?? null) : null,
                                    'lng' => is_array($item) ? ($item['lng'] ?? null) : null,
                                    'barangay' => $k
                                ];
                            }
                            if (count($terms) >= 50) break 2;
                        }
                    }
                }
            }
        }

        mobile_respond(200, [
            'status' => 'success',
            'barangay' => $brgy ?: $rawBrgy,
            'count' => count($terms),
            'terms' => $terms
        ]);
        break;
    }

    case 'weather': {
        require_once dirname(__DIR__) . '/core/weather.php';
        $force_refresh = !empty($_GET['refresh']) || (isset($_GET['force']) && $_GET['force'] === '1');
        $weather_data = sentri_get_imus_weather($force_refresh);
        mobile_respond(200, $weather_data);
        break;
    }

    case 'safety_guides': {
        mobile_respond(200, [
            'status' => 'success',
            'go_bag_checklist' => [
                ['id' => 1, 'category' => 'Hydration & Food', 'item' => 'Drinking Water (1 gallon per person per day for at least 3 days)'],
                ['id' => 2, 'category' => 'Hydration & Food', 'item' => 'Ready-to-eat non-perishable food (canned meats, energy bars, biscuits)'],
                ['id' => 3, 'category' => 'Emergency Tools', 'item' => 'High-lumen LED flashlight with extra alkaline batteries'],
                ['id' => 4, 'category' => 'Emergency Tools', 'item' => 'High-decibel emergency whistle (to signal rescuers)'],
                ['id' => 5, 'category' => 'Emergency Tools', 'item' => 'Heavy-duty 20,000mAh powerbank with waterproof charging cables'],
                ['id' => 6, 'category' => 'Medical & Health', 'item' => 'Complete First Aid Kit (sterile gauze, betadine, bandages, burn cream)'],
                ['id' => 7, 'category' => 'Medical & Health', 'item' => 'Personal prescription maintenance medications (7-day supply minimum)'],
                ['id' => 8, 'category' => 'Important Documents', 'item' => 'Waterproof zip pouch with government IDs, birth certificates, land titles, and cash'],
                ['id' => 9, 'category' => 'Hygiene & Protection', 'item' => 'N95 masks, alcohol spray (70%), wet wipes, and sanitary supplies'],
                ['id' => 10, 'category' => 'Communication', 'item' => 'Battery-operated AM/FM pocket radio for emergency broadcasts'],
                ['id' => 11, 'category' => 'Clothing & Warmth', 'item' => 'Emergency thermal blanket, rain poncho, and spare dry clothes'],
                ['id' => 12, 'category' => 'Utility', 'item' => 'Multi-tool knife / scissors and heavy-duty duct tape']
            ],
            'first_aid' => [
                [
                    'protocol' => 'Hands-Only Adult CPR',
                    'cadence' => '100 - 120 compressions per minute (110 BPM target)',
                    'steps' => [
                        'Ensure scene safety and check victim responsiveness.',
                        'Call 911 or CDRRMO Imus hotlines immediately.',
                        'Place heel of one hand in the center of the chest, interlock other hand on top.',
                        'Push hard and fast at 110 BPM in the center of the chest (depth 2 inches). Allow full chest recoil.'
                    ]
                ],
                [
                    'protocol' => 'Severe Bleeding & Hemorrhage',
                    'cadence' => 'Immediate direct pressure',
                    'steps' => [
                        'Apply direct pressure over the wound using sterile gauze or clean cloth.',
                        'Do not remove blood-soaked dressings; add more pads on top.',
                        'Elevate the injured limb above heart level if no fracture is suspected.',
                        'Apply tourniquet 2-3 inches above bleeding site on extremities only if arterial bleed persists.'
                    ]
                ],
                [
                    'protocol' => 'Thermal / Scald Burn Treatment',
                    'cadence' => 'Cool water immediately for 10-20 minutes',
                    'steps' => [
                        'Immediately cool the burn under gentle running cool water (NOT ice).',
                        'Do not apply toothpaste, oil, butter, or puncture blisters.',
                        'Cover loosely with sterile, non-adherent dressing.'
                    ]
                ],
                [
                    'protocol' => 'Choking (Heimlich Maneuver)',
                    'cadence' => '5 back blows followed by 5 abdominal thrusts',
                    'steps' => [
                        'Stand behind victim, wrap arms around waist.',
                        'Make a fist with one hand just above navel, grasp with other hand.',
                        'Give quick inward and upward thrusts until airway obstruction is expelled.'
                    ]
                ]
            ],
            'safety_protocols' => [
                [
                    'hazard' => 'Earthquake Safety',
                    'instructions' => 'Drop to your hands and knees. Cover your head and neck under sturdy furniture. Hold on until shaking stops completely. Watch for falling glass and power poles.'
                ],
                [
                    'hazard' => 'Flood & Flash Surge Evacuation',
                    'instructions' => 'Never walk or drive through moving floodwaters (6 inches can sweep an adult, 12 inches can carry vehicles). Disconnect main electrical circuit breakers before water enters the house.'
                ],
                [
                    'hazard' => 'Downed Live Electrical Cables',
                    'instructions' => 'Treat all downed wires as energized and lethal. Maintain a minimum safe distance of 35 feet (10 meters). Immediately tag on SenTri and notify BFP/Meralco dispatch.'
                ]
            ]
        ]);
        break;
    }

    case 'contacts': {
        $stmt = $conn->prepare("SELECT id, name, type, barangay, city, contact_number, contact_email FROM emergency_contacts WHERE is_active = 1 ORDER BY type ASC, name ASC");
        $stmt->execute();
        $res = $stmt->get_result();
        $contacts = [];
        while ($row = $res->fetch_assoc()) {
            $contacts[] = $row;
        }
        $stmt->close();

        mobile_respond(200, [
            'status' => 'success',
            'count' => count($contacts),
            'contacts' => $contacts
        ]);
        break;
    }

    case 'reverse_geocode': {
        $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : (isset($input['lat']) ? (float)$input['lat'] : null);
        $lon = isset($_GET['lng']) ? (float)$_GET['lng'] : (isset($_GET['lon']) ? (float)$_GET['lon'] : (isset($input['lng']) ? (float)$input['lng'] : (isset($input['lon']) ? (float)$input['lon'] : null)));

        if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            mobile_respond(400, ['status' => 'error', 'message' => 'Valid coordinates required']);
        }

        $detectedBrgy = null;
        $closestStreet = null;

        // 1. Try finding official barangay using GeoJSON point-in-polygon if available
        $geoJsonPath = __DIR__ . '/../assets/geo/imus-barangays.geojson';
        if (file_exists($geoJsonPath)) {
            $rawGeo = @file_get_contents($geoJsonPath);
            if ($rawGeo) {
                $fc = json_decode($rawGeo, true);
                if (isset($fc['features']) && is_array($fc['features'])) {
                    foreach ($fc['features'] as $feat) {
                        $geom = $feat['geometry'] ?? null;
                        if (!$geom) continue;
                        $polys = ($geom['type'] === 'Polygon') ? [$geom['coordinates']] : (($geom['type'] === 'MultiPolygon') ? $geom['coordinates'] : []);
                        foreach ($polys as $poly) {
                            if (!empty($poly)) {
                                $outerRing = $poly[0];
                                $inside = false;
                                for ($i = 0, $j = count($outerRing) - 1; $i < count($outerRing); $j = $i++) {
                                    $xi = $outerRing[$i][0]; $yi = $outerRing[$i][1];
                                    $xj = $outerRing[$j][0]; $yj = $outerRing[$j][1];
                                    $intersects = (($yi > $lat) !== ($yj > $lat)) && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);
                                    if ($intersects) $inside = !$inside;
                                }
                                if ($inside) {
                                    $detectedBrgy = $feat['properties']['name'] ?? null;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        // 2. Try closest street from imus-streets.json
        $streetsPath = __DIR__ . '/../assets/geo/imus-streets.json';
        if ($detectedBrgy && file_exists($streetsPath)) {
            $streetsData = json_decode(@file_get_contents($streetsPath), true);
            if ($streetsData && isset($streetsData['streetsByBarangay'][$detectedBrgy])) {
                $streets = $streetsData['streetsByBarangay'][$detectedBrgy];
                $minDist = INF;
                foreach ($streets as $s) {
                    $key = $detectedBrgy . '|||' . $s;
                    if (isset($streetsData['streetCoordinates'][$key])) {
                        $c = $streetsData['streetCoordinates'][$key];
                        $dLat = ($c[0] - $lat) * 111000;
                        $dLng = ($c[1] - $lon) * 111000 * cos(deg2rad($lat));
                        $d = sqrt($dLat * $dLat + $dLng * $dLng);
                        if ($d < $minDist) {
                            $minDist = $d;
                            $closestStreet = $s;
                        }
                    }
                }
                if ($minDist > 500) {
                    $closestStreet = null;
                }
            }
        }

        // 3. Fallback or augment with Nominatim reverse geocode
        $url = sprintf('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=%s&lon=%s&zoom=18&addressdetails=1', urlencode((string)$lat), urlencode((string)$lon));
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: SenTri/1.0\r\nAccept-Language: en\r\n",
                'timeout' => 5,
            ]
        ]);
        $nomRaw = @file_get_contents($url, false, $ctx);
        $nomData = $nomRaw ? json_decode($nomRaw, true) : null;
        if ($nomData && isset($nomData['address'])) {
            $a = $nomData['address'];
            $nomRoad = $a['road'] ?? $a['pedestrian'] ?? $a['residential'] ?? $a['amenity'] ?? $a['building'] ?? $a['hamlet'] ?? $a['neighbourhood'] ?? '';
            $nomBrgy = $a['quarter'] ?? $a['neighbourhood'] ?? $a['suburb'] ?? $a['village'] ?? $a['hamlet'] ?? '';
            if (!$closestStreet && $nomRoad) {
                $closestStreet = $nomRoad;
            }
            if (!$detectedBrgy && $nomBrgy) {
                if (function_exists('sentri_barangay_canonical')) {
                    $detectedBrgy = sentri_barangay_canonical($nomBrgy) ?: $nomBrgy;
                } else {
                    $detectedBrgy = $nomBrgy;
                }
            }
        }

        mobile_respond(200, [
            'status' => 'success',
            'latitude' => $lat,
            'longitude' => $lon,
            'barangay' => $detectedBrgy ?? '',
            'street' => $closestStreet ?? '',
            'city' => 'Imus',
            'province' => 'Cavite'
        ]);
        break;
    }

    default:
        mobile_respond(400, [
            'status' => 'error',
            'message' => 'Invalid or missing action parameter.'
        ]);
        break;
}
