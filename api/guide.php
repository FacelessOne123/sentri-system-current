<?php
// Returns {"show":true} only ONCE per user (their first login), then never again.
header('Content-Type: application/json');
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);

if (empty($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['show' => false]);
    exit;
}

$show = false;
try {
    require_once __DIR__ . '/../config/db.php';
    $uid = (int)$_SESSION['user_id'];

    // Atomic: only the first request flips 0 -> 1, so only that one gets show:true
    $st = $conn->prepare("UPDATE users SET guide_seen = 1 WHERE id = ? AND guide_seen = 0");
    $st->bind_param("i", $uid);
    $st->execute();
    $show = ($st->affected_rows === 1);
    $st->close();
} catch (Throwable $e) {
    $show = false; // any DB problem -> skip the video, never block login
}

echo json_encode(['show' => $show]);