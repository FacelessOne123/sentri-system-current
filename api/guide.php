<?php
/**
 * api/guide.php - First-login guide video flag (community realm)
 *
 * Returns {"show":true} exactly ONCE per account: on the first sign-in
 * after the guide video was published, the account's guide_seen flag is
 * flipped 0 -> 1 and the caller shows the tutorial popup.
 *
 * Realm-aware: the identity comes from the CURRENT realm session
 * (config/session.php + config/auth.php), never from a raw $_SESSION read.
 * The community realm is booted explicitly, so this endpoint only ever acts
 * on the community session - a government or admin sign-in (each with its
 * own realm cookie) is never touched.
 *
 * Never blocks sign-in: any database problem returns {"show":false} so the
 * user still lands on their portal when the guide cannot be shown.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/session.php';
sentri_boot_realm('community');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

header('Content-Type: application/json');

// Only a signed-in community account has a guide to show.
$identity = sentri_identity();
$role = $identity['role'] ?? '';
if ($identity === null || !in_array($role, ['community', 'user'], true)) {
    echo json_encode(['show' => false]);
    exit;
}

// The flag is flipped by a POST, so the request is CSRF-protected like every
// other state-changing API endpoint in this codebase.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['show' => false]);
    exit;
}
csrf_validate();

$show = false;
try {
    require_once __DIR__ . '/../config/db.php';
    $uid = (int)$identity['user_id'];

    // Atomic: only the request that flips 0 -> 1 gets show:true, so a
    // double-click or a second tab cannot replay the popup.
    $st = $conn->prepare('UPDATE users SET guide_seen = 1 WHERE id = ? AND guide_seen = 0');
    $st->bind_param('i', $uid);
    $st->execute();
    $show = ($st->affected_rows === 1);
    $st->close();
} catch (Throwable $e) {
    $show = false; // any DB problem -> skip the guide, never block sign-in
}

echo json_encode(['show' => $show]);
