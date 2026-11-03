<?php
/**
 * debug.php — SenTri standalone developer diagnostics page.
 * COMPLETELY SEPARATE from the product UI on purpose (own theme, no sidebar,
 * no site CSS/assets) so it never leaks into the graded product surface.
 *
 * ENABLED ONLY when .env has APP_DEBUG=1 (or MAIL_OUTBOX=1). On a production
 * box it renders a plain 404-style page and dies before doing anything.
 *
 * What it solves (faculty/localhost workflow):
 *   • Account creation blocked by real-time email verification — with
 *     APP_DEBUG=1 every outgoing mail is diverted to logs/mail/*.eml and this
 *     page lists every message with CLICKABLE verification / reset links, so
 *     signup + login work fully offline.
 *   • Inspect realm sessions, cookies, DB sanity, geo asset, PHP lint.
 *
 * NEVER ship with APP_DEBUG=1. logs/ is git-ignored.
 */
if (PHP_SAPI !== 'cli') {
 require_once __DIR__ . '/config/email.php'; // boots .env loader (no DB needed)
 $devMode = defined('MAIL_DEBUG_OUTBOX') && MAIL_DEBUG_OUTBOX;
 if (!$devMode) {
 http_response_code(404);
 exit('<!doctype html><title>404</title><div style="font-family:sans-serif;padding:48px;text-align:center;color:#666;">Not Found — debug tooling is disabled (set <code>APP_DEBUG=1</code> in <code>.env</code> to enable).</div>');
 }
} else {
 require_once __DIR__ . '/config/email.php';
}
require_once __DIR__ . '/config/db.php';

$ok = fn(string $s) => '<span style="color:#4ade80;font-weight:700;">PASS</span>';
$bad = fn(string $s) => '<span style="color:#f87171;font-weight:700;">FAIL</span>' . ($s ? " — " . htmlspecialchars($s) : '');
$warn = fn(string $s) => '<span style="color:#fbbf24;font-weight:700;">WARN</span>' . ($s ? " — " . htmlspecialchars($s) : '');

// ── Optional actions ─────────────────────────────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wipe_demo_reports') {
 // Kills only smoke/pytest demo reports (sentinel-named), keeps real data.
 $conn->query("DELETE r FROM reports r WHERE r.title LIKE '[SMOKE]%' OR r.title LIKE 'Test %' OR r.title LIKE 'Smoke %'");
 $conn->query("DELETE l FROM report_audit_logs l LEFT JOIN reports r ON r.id = l.report_id WHERE r.id IS NULL");
 $actionMsg = 'Demo/smoke reports wiped.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SenTri · debug console</title>
<style>
 :root{--bg:#0a0c14;--panel:#11141f;--panel2:#151928;--line:#232946;--txt:#e2e8f0;--dim:#8b95b5;--acc:#38bdf8;--ok:#4ade80;--warn:#fbbf24;--bad:#f87171;}
 *{box-sizing:border-box;margin:0;padding:0;}
 body{background:var(--bg);color:var(--txt);font:14px/1.55 'JetBrains Mono',ui-monospace,Menlo,Consolas,monospace;padding:26px;}
 header{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:22px;}
 header h1{font-size:1.15rem;letter-spacing:.12em;color:var(--acc);}
 header .badge{border:1px solid var(--line);border-radius:8px;padding:4px 10px;font-size:.72rem;color:var(--dim);}
 header .badge.hot{color:var(--warn);border-color:var(--warn);}
 .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;}
 .panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px 18px;}
 .panel h2{font-size:.8rem;letter-spacing:.14em;text-transform:uppercase;color:var(--acc);margin-bottom:12px;}
 .kv{display:flex;justify-content:space-between;gap:12px;padding:3px 0;border-bottom:1px dotted #1c2237;}
 .kv:last-child{border-bottom:none;}
 .kv span:first-child{color:var(--dim);}
 table{width:100%;border-collapse:collapse;font-size:.76rem;}
 th{color:var(--dim);text-align:left;font-weight:600;padding:3px 6px;border-bottom:1px solid var(--line);}
 td{padding:3px 6px;border-bottom:1px dotted #1c2237;}
 a{color:var(--acc);text-decoration:none;} a:hover{text-decoration:underline;}
 .mail .link{display:block;background:var(--panel2);border:1px solid var(--line);border-left:3px solid var(--ok);border-radius:8px;padding:8px 12px;margin:7px 0;word-break:break-all;}
 .btn{display:inline-block;background:#1d2939;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:7px 14px;font:inherit;font-size:.76rem;cursor:pointer;}
 .btn:hover{border-color:var(--acc);color:var(--acc);}
 .btn.danger{border-color:#7f1d1d;color:#fca5a5;}
 .msg{background:#122117;border:1px solid #1d4d2b;color:var(--ok);border-radius:8px;padding:8px 12px;margin-bottom:14px;}
 .warnbox{background:#1f1a10;border:1px solid var(--line);color:var(--warn);border-radius:8px;padding:8px 12px;margin-top:18px;font-size:.74rem;}
 .card{display:flex;gap:8px;flex-wrap:wrap;}
 .card a{background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:10px 14px;color:var(--txt);font-size:.78rem;}
 .card a:hover{border-color:var(--acc);color:var(--acc);}
 code{background:var(--panel2);border-radius:4px;padding:1px 6px;font-size:.78rem;color:#a5b4fc;}
</style>
</head>
<body>
<header>
 <h1>SENTRI · debug</h1>
 <span class="badge hot">DEV CONSOLE — APP_DEBUG=1</span>
 <span class="badge"><?= htmlspecialchars(PHP_VERSION) ?></span>
 <span class="badge"><?= htmlspecialchars(gethostname()) ?></span>
</header>

<?php if ($actionMsg): ?><div class="msg"><?= htmlspecialchars($actionMsg) ?></div><?php endif; ?>

<div class="grid">

 <!-- ── Environment ─────────────────────────────────────────────────── -->
 <div class="panel">
 <h2>Environment</h2>
 <div class="kv"><span>PHP</span><span><?= htmlspecialchars(PHP_VERSION) ?></span></div>
 <div class="kv"><span>mysqli / curl / pdo</span><span><?= (extension_loaded('mysqli')?'y':'<b style="color:#f87171">n</b>') ?> / <?= (extension_loaded('curl')?'y':'n') ?> / <?= (extension_loaded('pdo')?'y':'n') ?></span></div>
 <div class="kv"><span>SMTP</span><span><?= MAIL_DEBUG_OUTBOX ? '<b style="color:#4ade80">OFFLINE OUTBOX</b> (' . htmlspecialchars(MAIL_DEBUG_DIR) . ')' : '<b style="color:#fbbf24">LIVE SMTP</b>' . htmlspecialchars(MAIL_HOST . ':' . MAIL_PORT) ?></span></div>
 <div class="kv"><span>APP_URL</span><span><?= htmlspecialchars(APP_URL) ?></span></div>
 <?php
 $dbInfo = 'connected';
 $connReady = $conn instanceof mysqli && !$conn->connect_error;
 if (!$connReady) $dbInfo = 'CONNECTION FAILED';
 ?>
 <div class="kv"><span>Database</span><span><?= $connReady ? '<b style="color:#4ade80">sentri · up</b> (MariaDB ' . (function_exists('mysqli_get_server_info') ? htmlspecialchars($conn->server_info) : '') . ')' : $bad($dbInfo) ?></span></div>
 <?php $lock = file_exists(__DIR__ . '/config/install.lock'); ?>
 <div class="kv"><span>install.lock</span><span><?= $lock ? 'present (re-running installer requires deleting it)' : $warn('missing — install.php is LIVE') ?></span></div>
 </div>

 <!-- ── Realm sessions (dual sign-in inspector) ─────────────────────── -->
 <?php
 $realms = ['sentri-comm' => 'community', 'sentri-gov' => 'gov', 'sentri-admin' => 'admin'];
 $realmRows = [];
 foreach ($realms as $cookieName => $realmLabel) {
 $presentCookie = isset($_COOKIE[$cookieName]);
 $active = false; $ident = null;
 if ($presentCookie) {
 // probe that realm's session store
 sentri_boot_candidate($cookieName);
 $active = session_status() === PHP_SESSION_ACTIVE;
 $ident = function_exists('sentri_identity') ? sentri_identity() : null;
 sentri_close_session();
 }
 $realmRows[] = [$cookieName, $realmLabel, $presentCookie, $ident];
 }
 ?>
 <div class="panel">
 <h2>Realm sessions · dual sign-in inspector</h2>
 <table>
 <tr><th>cookie</th><th>realm</th><th>browser has it?</th><th>identity in store</th></tr>
 <?php foreach ($realmRows as [$cn, $label, $present, $ident]): ?>
 <tr><td><?= htmlspecialchars($cn) ?></td><td><?= $label ?></td>
 <td><?= $present ? $ok('') : '<span style="color:#64748b">no</span>' ?></td>
 <td><?= $ident ? '<b>#' . (int)$ident['user_id'] . '</b> · ' . htmlspecialchars($ident['role'] ?? '') . ' · ' . htmlspecialchars(($ident['first_name'] ?? '') . ' ' . ($ident['last_name'] ?? '')) : '<span style="color:#64748b">anonymous</span>' ?></td></tr>
 <?php endforeach; ?>
 </table>
 <p style="color:var(--dim);font-size:.74rem;margin-top:8px;">Community + government sessions coexist — logging in to one must never clear the other.</p>
 </div>

 <!-- ── Offline verification & mail outbox ──────────────────────────── -->
 <div class="panel mail">
 <h2>Offline mail outbox → click to verify / reset</h2>
 <?php
 // 1) unverified accounts direct from DB (fastest path)
 $pending = $conn->query("SELECT id, first_name, last_name, email, verification_token FROM users WHERE email_verified = 0") ?? [];
 $rows = [];
 if (method_exists($pending, 'fetch_all')) $rows = $pending->fetch_all(MYSQLI_ASSOC);
 if ($rows) {
 echo '<table><tr><th>account</th><th>email</th><th>one-click verify</th></tr>';
 foreach ($rows as $r) {
 $link = rtrim(APP_URL,'/') . '/verify_email.php?token=' . urlencode($r['verification_token']);
 echo '<tr><td>#' . (int)$r['id'] . ' ' . htmlspecialchars($r['first_name']) . '</td><td>' . htmlspecialchars($r['email']) . '</td><td><a href="' . htmlspecialchars($link) . '">VERIFY NOW →</a></td></tr>';
 }
 echo '</table>';
 } else {
 echo '<div class="kv"><span>unverified accounts</span><span style="color:#4ade80">none — everyone verified</span></div>';
 }
 // 2) outbox eml files (fresh, reverse-chron)
 $files = glob(MAIL_DEBUG_DIR . '/*.eml'); usort($files, fn($a,$b) => filemtime($b) <=> filemtime($a));
 echo '<p style="color:var(--dim);font-size:.75rem;margin-top:10px;">Outbox: ' . count($files) . ' message(s) in <code>logs/mail/</code></p>';
 foreach (array_slice($files, 0, 6) as $f) {
 $c = file_get_contents($f);
 $links = [];
 if (preg_match_all('#(https?://[^\"<\s]+(?:verify_email|reset_password)\.php[^\"<\s]*)#', $c, $m)) $links = $m[1];
 preg_match('/^Subject: (.*)$/mi', $c, $s);
 preg_match('/^To: (.*)$/mi', $c, $t);
 echo '<div class="link"><div style="font-size:.74rem;color:var(--info,#8b95b5);">' . htmlspecialchars(date('H:i:s', filemtime($f))) . ' → ' . htmlspecialchars($t[1] ?? '?') . ' · ' . htmlspecialchars($s[1] ?? '?') . '</div>';
 if ($links) echo '<a href="' . htmlspecialchars($links[0]) . '">Open link →</a>';
 echo ' <a href="javascript:void(0)" onclick="this.textContent=this.parentNode.querySelector(\'pre\').hidden?\'Hide\':\'View raw\'">View raw</a><pre hidden style="font-size:.66rem;white-space:pre-wrap;max-height:200px;overflow:auto;color:#a5b4fc;">' . htmlspecialchars($c) . '</pre></div>';
 }
 ?>
 </div>

 <!-- ── Data snapshot ───────────────────────────────────────────────── -->
 <div class="panel">
 <h2>Data snapshot</h2>
 <table>
 <tr><th>table</th><th>rows</th></tr>
 <?php
 foreach (['users','reports','report_votes','report_images','emergency_contacts','contact_notifications','report_audit_logs','login_logs','security_scans'] as $t) {
 $n = '—';
 if ($res = @$conn->query("SELECT COUNT(*) c FROM `$t`")) { $n = (int)$res->fetch_row()[0]; }
 else { $n = '<span style="color:#fbbf24">missing table</span>'; }
 echo '<tr><td>' . $t . '</td><td>' . $n . '</td></tr>';
 }
 ?>
 </table>
 <form method="post" style="margin-top:10px;">
 <input type="hidden" name="action" value="wipe_demo_reports">
 <button class="btn danger" onclick="return confirm('Delete [SMOKE]/Test demo reports?')">🧹 Wipe smoke reports</button>
 </form>
 </div>

 <!-- ── Geo integrity ───────────────────────────────────────────────── -->
 <div class="panel">
 <h2>Geo / barangay perimeter integrity</h2>
 <?php
 $geoPath = __DIR__ . '/assets/geo/imus-barangays.geojson';
 if (!is_file($geoPath)) { echo $bad('assets/geo/imus-barangays.geojson MISSING'); }
 else {
 $gj = json_decode(file_get_contents($geoPath), true);
 if (!is_array($gj)) echo $bad('geojson does not parse');
 else {
 $n = count($gj['features'] ?? []);
 $dwg = filesize($geoPath) / 1024;
 echo '<div class="kv"><span>features</span><span>' . $n . '/95 ' . ($n >= 90 ? $ok('') : $warn('expect 95')) . '</span></div>';
 echo '<div class="kv"><span>size</span><span>' . number_format($dwg, 0) . ' KB</span></div>';
 $rng = 0; foreach ($gj['features'] as $f) { $cn = $f['properties']['center'] ?? null; if ($cn) $rng++; }
 echo '<div class="kv"><span>features with center</span><span>' . $rng . '</span></div>';
 }
 }
 ?>
 <div class="kv"><span>sentri-geo.js</span><span><?= is_file(__DIR__ . '/assets/js/sentri-geo.js') ? $ok('') : $bad('missing') ?></span></div>
 <div class="kv"><span>leaflet vendored</span><span><?= is_file(__DIR__ . '/assets/vendor/leaflet/leaflet.js') ? $ok('') : $bad('missing') ?></span></div>
 </div>

 <!-- ── Sessions & cookies raw ──────────────────────────────────────── -->
 <div class="panel">
 <h2>Cookies this browser sent</h2>
 <table>
 <?php foreach ($_COOKIE as $k => $v): ?>
 <tr><td><?= htmlspecialchars($k) ?></td><td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;color:var(--dim);"><?= htmlspecialchars(substr($v, 0, 40)) ?>…</td></tr>
 <?php endforeach; ?>
 <?php if (!$_COOKIE): ?><tr><td colspan="2" style="color:#64748b">no cookies in this request</td></tr><?php endif; ?>
 </table>
 </div>

 <!-- ── Quick jump ──────────────────────────────────────────────────── -->
 <div class="panel">
 <h2>Quick jump</h2>
 <div class="card">
 <?php foreach (['dashboard.php' => 'Community feed', 'portal/community.php' => 'citizen portal', 'login.php' => 'community login', 'signup.php' => 'sign up', 'portal/barangay.php' => 'barangay', 'portal/lgu.php' => 'LGU', 'portal/responder.php' => 'responder', 'admin.php' => 'admin', 'gov/login.php' => 'gov sign-in'] as $u => $l): ?>
 <a href="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($l) ?></a>
 <?php endforeach; ?>
 </div>
 <p style="color:var(--dim);font-size:.74rem;margin-top:10px;">Demo accounts (when seeded): community@/barangay@/responder@/lgu@demo.ph · <code>Demo@1234</code> · admin@sentri.ph · <code>Admin@1234</code></p>
 </div>
</div>

<div class="warnbox">⚠ Debug page is deliberately OUTSIDE the product UI. It only comes alive while <code>APP_DEBUG=1</code> / <code>MAIL_OUTBOX=1</code> stays in <code>.env</code> (git-ignored). Remove that line before deploying.</div>
</body>
</html>
