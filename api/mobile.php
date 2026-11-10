<?php
/**
 * api/mobile.php - Native Android / Mobile REST API for SenTri
 * ============================================================================
 * Provides token-based REST endpoints for native mobile clients (Jetpack Compose).
 * Supports authentication, reports feed, GPS geolocation tagging, incident submission
 * with photos, voting, and emergency contacts.
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

const MOBILE_TOKEN_SECRET = 'sentri_mobile_secure_hmac_secret_cavite_2026';
const TOKEN_EXPIRY_SECONDS = 86400 * 30; // 30 days

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

    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, role, barangay_name, resident_address, phone_number, is_approved, email_verified, avatar_color FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $data['sub']);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function get_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
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
                'avatar_color' => $user['avatar_color'] ?? '#0a3d62'
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

        // Check if email exists
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
        $email_verified = 1;
        $colors = ['#0a3d62', '#1a5276', '#166534', '#b91c1c', '#6d28d9', '#d97706'];
        $avatar_color = $colors[array_rand($colors)];

        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, phone_number, barangay_name, resident_address, role, is_approved, email_verified, avatar_color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssssssiis', $first_name, $last_name, $email, $hash, $phone, $barangay, $address, $role, $is_approved, $email_verified, $avatar_color);
        
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $stmt->close();

            $user = [
                'id' => $new_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'role' => $role,
                'barangay_name' => $barangay,
                'phone_number' => $phone,
                'resident_address' => $address,
                'avatar_color' => $avatar_color
            ];
            $token = generate_token($user);

            mobile_respond(201, [
                'status' => 'success',
                'message' => 'Account created successfully',
                'token' => $token,
                'user' => $user
            ]);
        } else {
            $stmt->close();
            mobile_respond(500, ['status' => 'error', 'message' => 'Failed to create account']);
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
        $category = trim($_GET['category'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $barangay = trim($_GET['barangay'] ?? '');
        $limit = min(max((int)($_GET['limit'] ?? 50), 1), 100);

        $query = "SELECT r.*, u.first_name, u.last_name, u.avatar_color FROM reports r LEFT JOIN users u ON r.user_id = u.id WHERE r.is_archived = 0";
        $params = [];
        $types = "";

        if ($category) {
            $query .= " AND r.category = ?";
            $params[] = $category;
            $types .= "s";
        }
        if ($status) {
            $query .= " AND r.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        if ($barangay) {
            $query .= " AND r.barangay = ?";
            $params[] = $barangay;
            $types .= "s";
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
            $row['upvotes'] = (int)$row['upvotes'];
            $row['downvotes'] = (int)$row['downvotes'];
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
        $barangay = trim($input['barangay'] ?? $user['barangay_name'] ?? 'Poblacion');
        $city = trim($input['city'] ?? 'Imus');
        $province = trim($input['province'] ?? 'Cavite');
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

        $stmt = $conn->prepare("INSERT INTO reports (user_id, title, description, location_name, barangay, city, province, latitude, longitude, category, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssssddss', $user['id'], $title, $description, $location_name, $barangay, $city, $province, $latitude, $longitude, $category, $status);

        if ($stmt->execute()) {
            $report_id = $conn->insert_id;
            $stmt->close();

            // Handle optional image upload
            $uploaded_image_url = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../uploads/reports/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $safe_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (in_array($ext, $safe_exts, true)) {
                    $new_name = 'report_' . $report_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest = $upload_dir . $new_name;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                        $fsize = filesize($dest);
                        $mime = mime_content_type($dest);
                        $orig = $_FILES['image']['name'];

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

        // Upsert vote in report_votes
        $v_stmt = $conn->prepare("INSERT INTO report_votes (report_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote)");
        $v_stmt->bind_param('iis', $report_id, $user['id'], $vote_type);
        $v_stmt->execute();
        $v_stmt->close();

        // Recalculate upvotes and downvotes
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
            'user_vote' => $vote_type
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

    default:
        mobile_respond(400, [
            'status' => 'error',
            'message' => 'Invalid or missing action parameter. Supported actions: login, register, me, get_reports, submit_report, vote, contacts.'
        ]);
        break;
}
