<?php
require_once __DIR__ . '/../config/session.php';
sentri_boot_realm('gov');
require_once __DIR__ . '/../config/auth.php';
require_role(['barangay']);
require_approved();
require_once __DIR__ . '/../config/db.php';

$ident = sentri_identity() ?? [];
$uid = (int)($ident['user_id'] ?? 0);
$fname = $ident['first_name'] ?? '';
$view = $_GET['view'] ?? 'overview';

$stmt = $conn->prepare("SELECT email,org_name,`position`,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i",$uid); $stmt->execute();
$prof = $stmt->get_result()->fetch_assoc(); $stmt->close();
$brgy = $prof['barangay_name'] ?? '';
$city = $prof['municipality'] ?? '';
$org = $prof['org_name'] ?? 'Barangay Office';
$pos = $prof['position'] ?? 'Barangay Official';
$juri = $brgy ?: ($city ?: 'All Areas');

function cq($conn,$sql,$types='',$params=[]){
 $s=$conn->prepare($sql);
 if($types && count($params)){
 $refs=[];
 foreach($params as &$v) $refs[]=&$v;
 array_unshift($refs,$types);
 call_user_func_array([$s,'bind_param'],$refs);
 }
 $s->execute();$s->bind_result($n);$s->fetch();$s->close();return(int)$n;
}


// Normalized barangay match. Reports are strictly scoped to the official's assigned
// barangay territory based on the incident's location (r.barangay).
function brgy_norm($v){
 $v = mb_strtolower(trim((string)$v), 'UTF-8');
 $v = strtr($v, ['ñ'=>'n','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
 $v = str_replace(['barangay','brgy'], '', $v);
 return preg_replace('/[^a-z0-9]/', '', $v);
}
function brgy_sql($col){
 return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE($col,''))),'ñ','n'),'barangay',''),'brgy',''),'.',''),',',''),'-',''),'_',''),' ','')";
}
$brgy_key = brgy_norm($brgy);
$BRGY_COND = brgy_sql('r.barangay') . " = ?";

// Stats - strictly scoped to this barangay official's assigned territory.
// If no barangay is assigned, stats remain 0 to prevent unauthorized citywide data leak.
if ($brgy) {
 $total = cq($conn,"SELECT COUNT(*) FROM reports r WHERE r.is_archived=0 AND $BRGY_COND","s",[$brgy_key]);
 $danger = cq($conn,"SELECT COUNT(*) FROM reports r WHERE r.status='dangerous' AND r.is_archived=0 AND $BRGY_COND","s",[$brgy_key]);
 $caution = cq($conn,"SELECT COUNT(*) FROM reports r WHERE r.status='caution' AND r.is_archived=0 AND $BRGY_COND","s",[$brgy_key]);
 $safe = cq($conn,"SELECT COUNT(*) FROM reports r WHERE r.status='safe' AND r.is_archived=0 AND $BRGY_COND","s",[$brgy_key]);
} else {
 $total = 0; $danger = 0; $caution = 0; $safe = 0;
}

$cat_icons = ['crime'=>'fa-user-slash','accident'=>'fa-car-burst','flooding'=>'fa-water','fire'=>'fa-fire','health'=>'fa-heart-pulse','infrastructure'=>'fa-road-barrier','other'=>'fa-circle-exclamation'];
$type_icons = ['lgu'=>'fa-landmark','hospital'=>'fa-hospital','traffic'=>'fa-traffic-light','barangay'=>'fa-house-flag','police'=>'fa-shield','fire'=>'fa-fire-extinguisher','other'=>'fa-phone'];
$type_colors = ['lgu'=>['#f0f7ff','#0a3d62'],'hospital'=>['#ecfdf5','#059669'],'traffic'=>['#fffbeb','#d97706'],'barangay'=>['#f0fdf4','#166534'],'police'=>['#eff6ff','#2563eb'],'fire'=>['#fef2f2','#dc2626'],'other'=>['#f5f3ff','#7c3aed']];

// Per-view data
$reports = $contacts = $residents = [];

if (in_array($view, ['overview','reports'])) {
 $limit = ($view === 'overview') ? 20 : 100;
 // Ensure escalated_to_lgu column exists (added in a later migration)
 $db_b = $conn->query("SELECT DATABASE()")->fetch_row()[0];
 $bc = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db_b' AND TABLE_NAME='reports' AND COLUMN_NAME='escalated_to_lgu'");
 $has_esc_col = ($bc && $bc->num_rows > 0);
 $esc_sel = $has_esc_col ? ',r.escalated_to_lgu' : ',0 AS escalated_to_lgu';
 if ($brgy) {
  $brgy_where = " AND $BRGY_COND";
  $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.created_at,r.description,r.assigned_to,r.resolved_at{$esc_sel},u.first_name,u.last_name,u.barangay_name,u.resident_address FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0{$brgy_where} ORDER BY FIELD(r.status,'dangerous','caution','safe'),r.created_at DESC LIMIT $limit");
  $s->bind_param("s", $brgy_key);
  $s->execute(); $res=$s->get_result();
  while($row=$res->fetch_assoc()) $reports[]=$row;
  $s->close();
 } else {
  $reports = [];
 }
 if (isset($_GET['ajax_reports'])) {
  header('Content-Type: application/json');
  ob_start();
  if (empty($reports)) {
   echo '<div class="empty"><i class="fas fa-folder-open"></i><p>No reports match your view yet.</p></div>';
  } else {
   include __DIR__.'/../portal/_report_table.php';
  }
  $table_html = ob_get_clean();
  echo json_encode([
   'status' => 'success',
   'total' => $total,
   'danger' => $danger,
   'caution' => $caution,
   'safe' => $safe,
   'count' => count($reports),
   'html' => $table_html
  ]);
  exit;
 }
}

if ($view === 'contacts') {
 if ($brgy) {
  $q = "SELECT * FROM emergency_contacts WHERE is_active=1 AND (barangay=? OR barangay IS NULL) ORDER BY type,name";
  $s = $conn->prepare($q); $s->bind_param("s",$brgy); $s->execute();
  $res=$s->get_result();
  while($r=$res->fetch_assoc()) $contacts[]=$r;
  $s->close();
 } else {
  $contacts = [];
 }
}

if ($view === 'residents') {
 if ($brgy) {
  $q = "SELECT id,first_name,last_name,email,phone_number,resident_address,created_at FROM users WHERE role IN('community','user') AND " . brgy_sql('barangay_name') . "=? ORDER BY last_name";
  $s=$conn->prepare($q); $s->bind_param("s",$brgy_key); $s->execute();
  $res=$s->get_result();
  while($r=$res->fetch_assoc()) $residents[]=$r;
  $s->close();
 } else {
  $residents = [];
 }
}

$map_reports = [];
if ($view === 'map') {
 if ($brgy) {
  $brgy_where = " AND $BRGY_COND";
  $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,u.first_name,u.last_name,u.barangay_name,u.resident_address FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 AND r.latitude IS NOT NULL AND r.longitude IS NOT NULL{$brgy_where} ORDER BY r.created_at DESC LIMIT 500");
  $s->bind_param("s", $brgy_key);
  $s->execute(); $res=$s->get_result();
  while($row=$res->fetch_assoc()) $map_reports[]=$row;
  $s->close();
 }
}
$map_total_all = $brgy
 ? cq($conn,"SELECT COUNT(*) FROM reports r WHERE r.is_archived=0 AND $BRGY_COND","s",[$brgy_key])
 : 0;

$nav_items = [
 'overview' => ['icon'=>'fa-gauge', 'label'=>'Dashboard'],
 'reports' => ['icon'=>'fa-file-lines', 'label'=>'Incident Reports'],
 'map' => ['icon'=>'fa-map-location-dot', 'label'=>'Incident Map'],
 'contacts' => ['icon'=>'fa-address-book', 'label'=>'Emergency Contacts'],
 'residents' => ['icon'=>'fa-users', 'label'=>'Residents'],
 'profile' => ['icon'=>'fa-id-card', 'label'=>'My Profile'],
];
$page_titles = [
 'overview' => 'Barangay Dashboard',
 'reports' => 'Incident Reports',
 'map' => 'Incident Map',
 'contacts' => 'Emergency Contacts',
 'residents' => 'Residents',
 'profile' => 'My Profile',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $page_titles[$view] ?? 'Barangay Portal' ?> | SenTri</title>
<?= csrf_meta_tag() ?>
<link rel="stylesheet" href="../assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<style>
:root{--green:#166534;--green-dark:#052e16;--green-mid:#14532d;--green-light:#16a34a;--gold:#f39c12;--navy:#0a3d62;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--bg:#f1f5f9;--card:#fff;--sidebar-w:256px;--sentri-danger:#b91c1c;--sentri-caution:#b45309;--sentri-safe:#166534;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html,body{height:100%;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;}
/* SIDEBAR */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:linear-gradient(180deg,var(--green-dark) 0%,var(--green-mid) 50%,var(--green) 100%);color:#fff;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:1100;box-shadow:4px 0 20px rgba(0,0,0,0.25);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);}
.sb-header{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.sb-brand{display:flex;align-items:center;gap:10px;}
.sb-seal{width:40px;height:40px;border-radius:50%;background:rgba(243,156,18,0.2);border:2px solid rgba(243,156,18,0.45);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);flex-shrink:0;}
.sb-title{font-size:0.96rem;font-weight:800;line-height:1.2;}
.sb-sub{font-size:0.58rem;color:rgba(255,255,255,0.45);letter-spacing:1.5px;text-transform:uppercase;}
.sb-close{background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.1rem;cursor:pointer;padding:4px 6px;border-radius:6px;display:none;flex-shrink:0;}
.sb-juri{padding:10px 16px;background:rgba(0,0,0,0.2);border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;}
.sb-juri p{font-size:0.65rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.sb-juri strong{font-size:0.82rem;color:#fff;font-weight:700;display:block;word-break:break-word;}
.sb-nav{padding:12px 8px;flex:1;overflow-y:auto;}
.sb-nav a{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.7);text-decoration:none;font-size:0.84rem;font-weight:500;padding:10px 12px;border-radius:10px;transition:all 0.18s;margin-bottom:2px;white-space:nowrap;}
.sb-nav a:hover{background:rgba(255,255,255,0.1);color:#fff;}
.sb-nav a.active{background:rgba(255,255,255,0.15);color:#fff;font-weight:700;}
.sb-nav a i{width:18px;text-align:center;font-size:0.9rem;flex-shrink:0;}
.sb-section{font-size:0.62rem;color:rgba(255,255,255,0.28);letter-spacing:2px;text-transform:uppercase;padding:14px 12px 5px;font-weight:700;}
.sb-footer{padding:12px 16px;border-top:1px solid rgba(255,255,255,0.1);flex-shrink:0;}
.sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.sb-avatar{width:34px;height:34px;border-radius:50%;flex-shrink:0;background:rgba(243,156,18,0.25);display:flex;align-items:center;justify-content:center;font-size:0.88rem;font-weight:800;color:var(--gold);}
.sb-uname{font-size:0.82rem;font-weight:700;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-upos{font-size:0.65rem;color:rgba(255,255,255,0.4);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-logout{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.55);text-decoration:none;font-size:0.8rem;font-weight:600;padding:8px 10px;border-radius:8px;transition:all 0.18s;}
.sb-logout:hover{background:rgba(220,38,38,0.2);color:#fca5a5;}
/* MAIN */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-width:0;min-height:100vh;}
/* TOPBAR */
.topbar{background:#fff;border-bottom:4px solid var(--green);padding:0 24px;min-height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 10px rgba(0,0,0,0.07);gap:10px;}
.topbar-header-row{display:contents;}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0;}
.ham-btn{background:none;border:none;font-size:1.15rem;color:var(--muted);cursor:pointer;padding:7px;border-radius:8px;display:none;flex-shrink:0;}
.ham-btn:hover{background:#f3f4f6;}
.page-title{font-size:1rem;font-weight:800;color:var(--green-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.page-sub{font-size:0.72rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.badge-brgy{background:#f0fdf4;color:var(--green);font-size:0.71rem;font-weight:700;padding:5px 12px;border-radius:20px;border:1px solid #bbf7d0;white-space:nowrap;flex-shrink:0;}
.topbar-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
/* CONTENT */
.content{padding:22px 24px;flex:1;}
/* STATS */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border-radius:14px;padding:18px 16px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);display:flex;align-items:center;gap:14px;}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;}
.stat-num{font-size:1.6rem;font-weight:800;line-height:1;color:var(--text);}
.stat-lbl{font-size:0.71rem;color:var(--muted);font-weight:600;margin-top:3px;}
/* CARD */
.card{background:var(--card);border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid var(--border);overflow:hidden;margin-bottom:18px;}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;}
.card-header h3{font-size:0.88rem;font-weight:700;min-width:0;}
.card-meta{font-size:0.72rem;color:var(--muted);white-space:nowrap;}
/* TABLE */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;border-collapse:collapse;min-width:520px;}
thead tr{background:#f8fafc;}
th{padding:10px 14px;font-size:0.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
td{padding:11px 14px;font-size:0.82rem;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
/* PILLS */
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;white-space:nowrap;}
.pill-dangerous{background:#fef2f2;color:var(--sentri-danger);}
.pill-caution{background:#fffbeb;color:var(--sentri-caution);}
.pill-safe{background:#f0fdf4;color:var(--sentri-safe);}
.cat-chip{display:inline-flex;align-items:center;gap:5px;font-size:0.72rem;font-weight:600;color:var(--navy);background:#eff6ff;padding:3px 8px;border-radius:6px;white-space:nowrap;}
/* ACTION BTNS */
.btn-icon{width:30px;height:30px;border:none;border-radius:7px;cursor:pointer;font-size:0.8rem;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;}
.btn-view{background:#eff6ff;color:#2563eb;} .btn-view:hover{background:#dbeafe;}
/* TOAST NOTIFICATIONS */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{display:flex;align-items:center;gap:10px;padding:13px 18px;border-radius:12px;font-size:0.84rem;font-weight:600;box-shadow:0 8px 30px rgba(0,0,0,0.18);pointer-events:all;animation:toastIn 0.3s cubic-bezier(0.34,1.56,0.64,1);min-width:260px;max-width:360px;}
.toast-success{background:#166534;color:#fff;}
.toast-error{background:#991b1b;color:#fff;}
.toast-info{background:#1e40af;color:#fff;}
.toast i{font-size:1rem;flex-shrink:0;}
@keyframes toastIn{from{opacity:0;transform:translateY(16px) scale(0.96);}to{opacity:1;transform:translateY(0) scale(1);}}
/* ACTION BUTTON STATES */
.btn-action:disabled{opacity:0.65;cursor:not-allowed;}
.btn-action.btn-done{background:#6b7280 !important;color:#fff !important;cursor:default;}
/* TABLE QUICK-ACTION BUTTONS */
.btn-icon.btn-escalate{background:#fff7ed;color:#c2410c;} .btn-icon.btn-escalate:hover{background:#ffedd5;}
.btn-icon.btn-resolve{background:#f0fdf4;color:#16a34a;} .btn-icon.btn-resolve:hover{background:#dcfce7;}
.btn-icon:disabled{opacity:0.5;cursor:not-allowed;}
/* CONTACTS */
.contact-row{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.contact-row:last-child{border-bottom:none;}
.contact-icon{width:40px;height:40px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem;}
.contact-name{font-size:0.88rem;font-weight:700;margin-bottom:3px;}
.contact-meta{font-size:0.75rem;color:var(--muted);line-height:1.6;}
.contact-type-badge{display:inline-block;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;padding:2px 8px;border-radius:4px;margin-bottom:4px;}
/* RESIDENT ROW */
.resident-row{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #f3f4f6;}
.resident-row:last-child{border-bottom:none;}
.resident-av{width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:800;flex-shrink:0;}
.resident-name{font-size:0.86rem;font-weight:700;}
.resident-email{font-size:0.75rem;color:var(--muted);}
/* EMPTY */
.empty{padding:48px 20px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;width:100%;color:var(--muted);}
.empty > i, .empty > svg, .empty > .svg-inline--fa{font-size:2.2rem;display:block;margin:0 auto 12px auto;opacity:0.3;}
.empty p{font-size:0.86rem;}
/* COMING SOON */
.coming-soon{padding:60px 24px;text-align:center;}
.coming-soon i{font-size:3rem;color:var(--green);opacity:0.2;display:block;margin-bottom:16px;}
.coming-soon h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.coming-soon p{font-size:0.85rem;color:var(--muted);}
/* MAP */
.map-wrap{position:relative;z-index:0;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);border:1px solid var(--border);isolation:isolate;}
#incidentMap{height:490px;width:100%;background:#e8f4e8;}
.map-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;background:#fff;border-bottom:1px solid var(--border);border-radius:14px 14px 0 0;}
.map-controls h3{font-size:0.88rem;font-weight:700;margin-right:4px;}
.filter-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.76rem;font-weight:700;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
.filter-btn.active-all{background:var(--green);color:#fff;border-color:var(--green);}
.filter-btn.active-dangerous{background:#dc2626;color:#fff;border-color:#dc2626;}
.filter-btn.active-caution{background:#d97706;color:#fff;border-color:#d97706;}
.filter-btn.active-safe{background:#16a34a;color:#fff;border-color:#16a34a;}
.map-legend{position:absolute;bottom:16px;right:12px;background:rgba(255,255,255,0.95);border-radius:10px;padding:11px 14px;font-size:0.76rem;box-shadow:0 2px 12px rgba(0,0,0,0.15);z-index:400;border:1px solid var(--border);}
.legend-row{display:flex;align-items:center;gap:7px;margin-bottom:5px;font-weight:600;}
.legend-row:last-child{margin-bottom:0;}
.legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
.map-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.map-stat{background:#fff;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);}
.map-stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.map-stat-num{font-size:1.3rem;font-weight:800;line-height:1;}
.map-stat-lbl{font-size:0.68rem;color:var(--muted);font-weight:600;margin-top:2px;}
.no-coords-notice{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:11px 15px;font-size:0.82rem;color:#166534;margin-bottom:14px;display:flex;align-items:center;gap:9px;}
/* REPORTS FILTER */
.reports-filter-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--border);background:#f8fafc;}
.rf-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.75rem;font-weight:700;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
.rf-btn.rf-active{background:var(--green);color:#fff;border-color:var(--green);}
.rf-search{flex:1;min-width:160px;max-width:260px;padding:6px 12px;border:1.5px solid var(--border);border-radius:20px;font-size:0.8rem;font-family:'Inter',sans-serif;outline:none;}
.rf-search:focus{border-color:#86efac;}
.rpt-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
@media(max-width:700px){.map-stats,.rpt-stats{grid-template-columns:1fr 1fr;}#incidentMap{height:340px;}}
/* REPORT DETAIL MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1200;align-items:center;justify-content:center;padding:20px;}
.modal-bg.show{display:flex;}
.modal{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);}
.modal-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.modal-header h3{font-size:0.95rem;font-weight:800;}
.modal-close{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--muted);padding:4px 6px;border-radius:6px;}
.modal-close:hover{background:#f3f4f6;}
.modal-body{padding:20px 22px;}
.detail-row{margin-bottom:14px;}
.detail-lbl{font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;}
.detail-val{font-size:0.88rem;color:var(--text);}
.modal-actions{padding:16px 22px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;}
.btn-action{padding:8px 16px;border-radius:9px;font-size:0.82rem;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;transition:all 0.18s;}
.btn-resolve-full{background:#16a34a;color:#fff;} .btn-resolve-full:hover{background:#166534;}
.btn-escalate-full{background:#d97706;color:#fff;} .btn-escalate-full:hover{background:#b45309;}
/* OVERLAY */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1050;}
.overlay.show{display:block;}
/* RESPONSIVE */
@media(max-width:1000px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:860px){
 .sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}.sb-close{display:flex;}.main{margin-left:0;}.ham-btn{display:flex;}.content{padding:14px;}
 .topbar{padding:10px 14px 8px;height:auto;min-height:56px;flex-direction:column;align-items:stretch;gap:8px;}
 .topbar-header-row{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;}
 .topbar-actions{display:flex;align-items:center;gap:6px;overflow-x:auto;padding-bottom:4px;-webkit-overflow-scrolling:touch;scrollbar-width:none;width:100%;flex-wrap:nowrap;}
 .topbar-actions::-webkit-scrollbar{display:none;}
 .topbar-actions button{white-space:nowrap;flex-shrink:0;font-size:0.72rem;padding:5px 10px;}
 .stat-grid{grid-template-columns:1fr 1fr;gap:10px;}
 .stat-card{padding:12px 14px;gap:10px;}
 .stat-icon{width:38px;height:38px;font-size:1rem;border-radius:10px;}
 .stat-num{font-size:1.3rem;}
}
@media(max-width:480px){
 .stat-grid{grid-template-columns:1fr 1fr;gap:8px;}
 .page-sub{display:none;}
 .badge-brgy{font-size:0.65rem;padding:4px 8px;}
}
/* NEW-REPORT ALERT POPUP (matches responder / LGU portals) */
.alert-toast{position:relative;display:flex;align-items:flex-start;gap:12px;background:var(--card);border-radius:14px;padding:14px 16px;box-shadow:0 8px 32px rgba(0,0,0,0.15);border-left:5px solid #ccc;pointer-events:auto;min-width:300px;max-width:340px;overflow:hidden;animation:alertToastSlide 0.4s cubic-bezier(0.34,1.56,0.64,1) both;}
.alert-toast.alert-dangerous{border-left-color:#dc2626;}
.alert-toast.alert-caution{border-left-color:#d97706;}
.alert-toast.alert-escalated{border-left-color:#2563eb;}
.alert-toast.alert-info{border-left-color:#64748b;}
.alert-toast.alert-out{animation:alertToastFade 0.4s ease both;}
.alert-toast-icon{font-size:1.3rem;flex-shrink:0;margin-top:2px;color:#dc2626;}
.alert-toast.alert-dangerous .alert-toast-icon{color:#dc2626;}
.alert-toast.alert-caution .alert-toast-icon{color:#d97706;}
.alert-toast.alert-escalated .alert-toast-icon{color:#2563eb;}
.alert-toast.alert-info .alert-toast-icon{color:#64748b;}
.alert-toast-body{min-width:0;flex:1;padding-right:14px;}
.alert-toast-title{font-size:0.83rem;font-weight:700;color:var(--text);margin-bottom:3px;}
.alert-toast-text{display:flex;flex-direction:column;gap:2px;font-size:0.76rem;color:var(--muted);line-height:1.55;}
.alert-toast-text strong{color:var(--text);font-weight:700;}
.alert-toast-desc{font-size:0.74rem;color:var(--muted);}
.alert-toast-link{display:inline-flex;align-items:center;gap:5px;margin-top:6px;font-size:0.75rem;font-weight:700;color:#1d4ed8;text-decoration:none;}
.alert-toast-link:hover{text-decoration:underline;}
.alert-toast-close{position:absolute;top:8px;right:8px;background:none;border:none;cursor:pointer;color:var(--muted);font-size:0.78rem;padding:2px 5px;border-radius:5px;}
.alert-toast-close:hover{background:#f1f5f9;color:var(--text);}
.alert-toast-progress{position:absolute;bottom:0;left:0;height:3px;width:100%;background:rgba(0,0,0,0.1);animation-name:alertToastProgress;animation-timing-function:linear;animation-fill-mode:both;}
@keyframes alertToastSlide{from{opacity:0;transform:translateX(120%);}to{opacity:1;transform:translateX(0);}}
@keyframes alertToastFade{from{opacity:1;transform:translateX(0);}to{opacity:0;transform:translateX(120%);}}
@keyframes alertToastProgress{from{width:100%;}to{width:0%;}}
@media(max-width:480px){.alert-toast{min-width:0;max-width:calc(100vw - 48px);}}
</style>
<script src="../assets/js/sentri-report-alerts.js?v=4"></script>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toastContainer"></div>

<!-- REPORT DETAIL MODAL -->
<div class="modal-bg" id="reportModal">
 <div class="modal">
 <div class="modal-header">
 <h3 id="modalTitle">Incident Report</h3>
 <button class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="modal-body" id="modalBody"></div>
 <div class="modal-actions" id="modalActions">
 <!-- populated dynamically by openModal() based on report state -->
 </div>
 </div>
</div>

<aside class="sidebar" id="sidebar">
 <div class="sb-header">
 <div class="sb-brand">
 <div class="sb-seal"><i class="fas fa-house-flag"></i></div>
 <div><div class="sb-title">SenTri</div><div class="sb-sub">Barangay Portal</div></div>
 </div>
 <button class="sb-close" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="sb-juri">
 <p>Jurisdiction</p>
 <strong><?= htmlspecialchars($juri) ?><?= $city && $brgy ? ', '.htmlspecialchars($city) : '' ?></strong>
 </div>
 <nav class="sb-nav">
 <?php foreach($nav_items as $key => $item): ?>
 <?php if($key === 'contacts'): ?><div class="sb-section">Management</div><?php endif; ?>
 <?php if($key === 'profile'): ?><div class="sb-section">Account</div><?php endif; ?>
 <a href="barangay.php?view=<?= $key ?>" class="<?= $view===$key?'active':'' ?>">
 <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
 </a>
 <?php endforeach; ?>
 </nav>
 <div class="sb-footer">
 <div class="sb-user">
 <div class="sb-avatar"><?= strtoupper(substr($fname,0,1)) ?></div>
 <div style="min-width:0;"><div class="sb-uname"><?= htmlspecialchars($fname) ?></div><div class="sb-upos"><?= htmlspecialchars($pos) ?></div></div>
 </div>
 <a href="../gov/logout.php" class="sb-logout"><i class="fas fa-right-from-bracket"></i> Sign Out</a>
 </div>
</aside>

<div class="main">
 <div class="topbar">
  <div class="topbar-header-row">
   <div class="topbar-left">
    <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
    <div style="min-width:0;">
     <div class="page-title"><?= htmlspecialchars($page_titles[$view] ?? 'Barangay Portal') ?></div>
     <div class="page-sub"><?= htmlspecialchars($org) ?><?= $city ? ' - '.htmlspecialchars($city) : '' ?></div>
    </div>
   </div>
   <span class="badge-brgy"><i class="fas fa-house-flag"></i>&nbsp; Barangay Official</span>
  </div>
  <div class="topbar-actions">
   <button onclick="openEvacModal()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'"><i class="fas fa-person-shelter"></i> Evac Centers (3 Active)</button>
   <button onclick="openReliefModal()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'"><i class="fas fa-boxes-stacked"></i> Relief Inventory</button>
   <button onclick="openReliefClaimsModal()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#fefce8;border:1px solid #fde047;color:#854d0e;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='#fef9c3'" onmouseout="this.style.background='#fefce8'"><i class="fas fa-hand-holding-hand"></i> Relief Claims</button>
   <button onclick="openBroadcastModal()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"><i class="fas fa-bullhorn"></i> Mass Broadcast</button>
   <button onclick="openVulnerableModal()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#fdf4ff;border:1px solid #f0abfc;color:#86198f;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='#fae8ff'" onmouseout="this.style.background='#fdf4ff'"><i class="fas fa-person-cane"></i> Vulnerable Registry</button>
  </div>
 </div>

 <div class="content">

 <?php if(!$brgy): ?>
 <div style="background:#fef2f2;border:1.5px solid #fecaca;border-left:5px solid #dc2626;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
  <i class="fas fa-triangle-exclamation" style="font-size:1.3rem;color:#dc2626;"></i>
  <div style="font-size:0.85rem;color:#991b1b;line-height:1.5;">
   <b>No barangay assigned to your official account.</b><br>
   Official access is strictly scoped per barangay. Please contact your system administrator to assign your jurisdiction.
  </div>
 </div>
 <?php endif; ?>

 <?php if($view === 'overview'): ?>
 <div class="stat-grid">
 <div class="stat-card"><div class="stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-file-lines"></i></div><div><div class="stat-num" id="statTotal"><?= $total ?></div><div class="stat-lbl">Total Reports</div></div></div>
 <div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-num" id="statDanger"><?= $danger ?></div><div class="stat-lbl">Dangerous</div></div></div>
 <div class="stat-card"><div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="stat-num" id="statCaution"><?= $caution ?></div><div class="stat-lbl">Caution</div></div></div>
 <div class="stat-card"><div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="stat-num" id="statSafe"><?= $safe ?></div><div class="stat-lbl">Resolved</div></div></div>
 </div>
 <div class="card">
 <div class="card-header"><h3><i class="fas fa-list" style="color:var(--green);margin-right:6px;"></i>Recent Incidents</h3><span class="card-meta" id="recentCount"><?= count($reports) ?> records</span></div>
 <div id="brgyTableWrap">
 <?php if(empty($reports)): ?><div class="empty"><i class="fas fa-folder-open"></i><p>No reports match your view yet.</p></div>
 <?php else: ?><?php include_once __DIR__.'/../portal/_report_table.php'; endif; ?>
 </div>
 </div>

 <?php elseif($view === 'reports'): ?>
 <div class="rpt-stats">
 <div class="map-stat"><div class="map-stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-file-lines"></i></div><div><div class="map-stat-num" id="rptStatTotal"><?= $total ?></div><div class="map-stat-lbl">Total</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="map-stat-num" id="rptStatDanger"><?= $danger ?></div><div class="map-stat-lbl">Dangerous</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="map-stat-num" id="rptStatCaution"><?= $caution ?></div><div class="map-stat-lbl">Caution</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="map-stat-num" id="rptStatSafe"><?= $safe ?></div><div class="map-stat-lbl">Resolved</div></div></div>
 </div>
 <div class="card">
 <div class="card-header">
 <h3><i class="fas fa-file-lines" style="color:var(--green);margin-right:6px;"></i>All Incident Reports</h3>
 <span class="card-meta" id="rptCount"><?= count($reports) ?> records</span>
 </div>
 <div class="reports-filter-bar" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
 <button class="rf-btn rf-active" onclick="filterReports('all',this)">All</button>
 <button class="rf-btn" onclick="filterReports('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
 <button class="rf-btn" onclick="filterReports('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
 <button class="rf-btn" onclick="filterReports('safe',this)"><i class="fas fa-circle" style="color:#16a34a;font-size:0.6rem;"></i> Safe</button>
 <input type="search" class="rf-search" id="rptSearch" placeholder="Search reports…" oninput="searchReports(this.value)" style="flex:1;min-width:180px;">
 <a href="../api/reports.php?action=export&type=reports&format=csv<?= $brgy ? '&barangay='.rawurlencode($brgy) : '' ?>" class="rf-btn" style="text-decoration:none;display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-weight:700;"><i class="fas fa-file-csv"></i> Export CSV</a>
 </div>
 <div id="brgyTableWrap">
 <?php if(empty($reports)): ?><div class="empty"><i class="fas fa-folder-open"></i><p>No reports match your view yet.</p></div>
 <?php else: ?><?php include_once __DIR__.'/../portal/_report_table.php'; endif; ?>
 </div>
 </div>

 <?php elseif($view === 'contacts'): ?>
 <div class="card">
 <div class="card-header"><h3><i class="fas fa-address-book" style="color:var(--green);margin-right:6px;"></i>Emergency Contacts</h3><span class="card-meta"><?= count($contacts) ?> contacts</span></div>
 <div style="padding:12px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:flex-end;">
 <button onclick="openAddContact()" style="background:var(--green);color:#fff;border:none;padding:7px 15px;border-radius:8px;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:'Inter',sans-serif;"><i class="fas fa-plus"></i> Add Contact</button>
 </div>
 <?php if(empty($contacts)): ?><div class="empty"><i class="fas fa-address-book"></i><p>No emergency contacts on file.</p></div>
 <?php else: foreach($contacts as $c): $tc=$type_colors[$c['type']]??['#f5f3ff','#7c3aed']; $ti=$type_icons[$c['type']]??'fa-phone'; ?>
 <div class="contact-row">
 <div class="contact-icon" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><i class="fas <?= $ti ?>"></i></div>
 <div style="flex:1;min-width:0;">
 <div class="contact-type-badge" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><?= strtoupper($c['type']) ?></div>
 <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
 <div class="contact-meta">
 <?php if($c['contact_number']): ?><span><i class="fas fa-phone" style="margin-right:4px;color:var(--green);"></i><?= htmlspecialchars($c['contact_number']) ?></span>&ensp;<?php endif; ?>
 <?php if($c['contact_email']): ?><span><i class="fas fa-envelope" style="margin-right:4px;color:var(--green);"></i><?= htmlspecialchars($c['contact_email']) ?></span>&ensp;<?php endif; ?>
 <?php if($c['barangay']): ?><span><i class="fas fa-location-dot" style="margin-right:4px;color:var(--muted);"></i><?= htmlspecialchars($c['barangay']) ?><?= $c['city'] ? ', '.htmlspecialchars($c['city']) : '' ?></span><?php endif; ?>
 </div>
 </div>
 </div>
 <?php endforeach; endif; ?>
 </div>

 <?php elseif($view === 'residents'): ?>
 <div class="card">
 <div class="card-header"><h3><i class="fas fa-users" style="color:var(--green);margin-right:6px;"></i>Registered Residents<?= $brgy ? ' - '.htmlspecialchars($brgy) : '' ?></h3><span class="card-meta"><?= count($residents) ?> residents</span></div>
 <?php if(empty($residents)): ?><div class="empty"><i class="fas fa-users"></i><p>No registered residents found<?= $brgy ? ' in '.htmlspecialchars($brgy) : '' ?>.</p></div>
 <?php else: foreach($residents as $r): ?>
 <div class="resident-row">
 <div class="resident-av"><?= strtoupper(substr($r['first_name'],0,1)) ?></div>
 <div style="flex:1;min-width:0;">
 <div class="resident-name"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></div>
 <div class="resident-email"><?= htmlspecialchars($r['email']) ?><?= $r['phone_number'] ? ' &nbsp;&middot;&nbsp; '.$r['phone_number'] : '' ?></div>
 <?php if(!empty($r['resident_address'])): ?><div class="resident-email"><i class="fas fa-location-dot" style="margin-right:4px;color:var(--green);"></i><?= htmlspecialchars($r['resident_address']) ?></div><?php endif; ?>
 </div>
 <span style="font-size:0.73rem;color:var(--muted);"><?= date('M j, Y g:i A',strtotime($r['created_at'])) ?></span>
 </div>
 <?php endforeach; endif; ?>
 </div>


 <?php elseif($view === 'map'): ?>
 <!-- ── INCIDENT MAP ── -->
 <link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css">
 <script src="../assets/vendor/leaflet/leaflet.js"></script>
 <script src="../assets/js/sentri-geo.js"></script>
 <?php
 $mapped = count($map_reports);
 $unmapped = $map_total_all - $mapped;
 $m_danger = count(array_filter($map_reports, function($r){ return $r['status']==='dangerous'; }));
 $m_caution = count(array_filter($map_reports, function($r){ return $r['status']==='caution'; }));
 $m_safe = count(array_filter($map_reports, function($r){ return $r['status']==='safe'; }));
 ?>
 <?php if($unmapped > 0): ?>
 <div class="no-coords-notice"><i class="fas fa-circle-info"></i><?= $unmapped ?> report<?= $unmapped>1?'s':'' ?> without GPS coordinates are not shown on the map.</div>
 <?php endif; ?>
 <div class="map-stats">
 <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fas fa-map-pin"></i></div><div><div class="map-stat-num"><?= $mapped ?></div><div class="map-stat-lbl">Mapped Reports</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_danger ?></div><div class="map-stat-lbl">Dangerous</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_caution ?></div><div class="map-stat-lbl">Caution</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="map-stat-num"><?= $m_safe ?></div><div class="map-stat-lbl">Safe / Resolved</div></div></div>
 </div>
 <div class="map-wrap">
 <div class="map-controls">
 <h3><i class="fas fa-map-location-dot" style="color:var(--green);margin-right:6px;"></i>Incident Map<?= $brgy ? ' - '.htmlspecialchars($brgy) : '' ?></h3>
 <button class="filter-btn active-all" onclick="filterMap('all',this)">All</button>
 <button class="filter-btn" onclick="filterMap('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
 <button class="filter-btn" onclick="filterMap('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
 <button class="filter-btn" onclick="filterMap('safe',this)"><i class="fas fa-circle" style="color:#16a34a;font-size:0.6rem;"></i> Safe</button>
 <button class="filter-btn" id="toggleHazardRings" onclick="toggleHazardBuffer(this)" title="Toggle 250m/150m safety hazard buffer rings"><i class="fas fa-bullseye" style="color:#ef4444;font-size:0.7rem;"></i> Hazard Buffers</button>
 <button class="filter-btn" id="toggleHeatmap" onclick="toggleHeatmapDensity(this)" title="Toggle incident intensity cluster overlay"><i class="fas fa-fire-flame-curved" style="color:#f59e0b;font-size:0.7rem;"></i> Density Heatmap</button>
 <div style="position:relative;margin-left:auto;">
 <input type="text" id="mapSearchInput" placeholder="Filter map pins..." oninput="filterMapBySearch(this.value)" style="padding:4px 8px 4px 22px;border:1.5px solid var(--border);border-radius:6px;font-size:0.74rem;outline:none;background:#fff;width:140px;">
 <i class="fas fa-magnifying-glass" style="position:absolute;left:7px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:0.7rem;"></i>
 </div>
 <div style="font-size:0.74rem;color:var(--muted);">© OpenStreetMap</div>
 </div>
 <div style="position:relative;">
 <div id="incidentMap"></div>
 <div class="map-legend">
 <div class="legend-row"><div class="legend-dot" style="background:#dc2626;"></div>Dangerous</div>
 <div class="legend-row"><div class="legend-dot" style="background:#d97706;"></div>Caution</div>
 <div class="legend-row"><div class="legend-dot" style="background:#16a34a;"></div>Safe</div>
 <div class="legend-row" style="margin-top:4px;padding-top:4px;border-top:1px dashed #e2e8f0;font-size:0.7rem;color:#64748b;"><i class="fas fa-circle-dot" style="color:#dc2626;margin-right:4px;"></i>Buffer: 250m / 150m</div>
 <div class="legend-row" style="margin-top:4px;padding-top:4px;border-top:1px dashed #e2e8f0;font-size:0.7rem;color:#2563eb;"><i class="fas fa-vector-square" style="color:#2563eb;margin-right:4px;"></i>Brgy boundary (official perimeter)</div>
 </div>
 </div>
 </div>
 <script>
 var mapReports = <?= json_encode(array_map(function($r){
 return ['id'=>(int)$r['id'],'title'=>htmlspecialchars($r['title'],ENT_QUOTES),'category'=>$r['category'],
 'status'=>$r['status'],'barangay'=>htmlspecialchars($r['barangay']??$r['city']??'',ENT_QUOTES),
 'lat'=>(float)$r['latitude'],'lng'=>(float)$r['longitude'],
 'reporter'=>htmlspecialchars(trim($r['first_name'].' '.$r['last_name']),ENT_QUOTES),
 'reporter_brgy'=>htmlspecialchars(trim((string)($r['barangay_name']??'')),ENT_QUOTES),
 'reporter_address'=>htmlspecialchars(trim((string)($r['resident_address']??'')),ENT_QUOTES),
 'date'=>date('M j, Y g:i A',strtotime($r['created_at'])),'desc'=>htmlspecialchars($r['description']??'',ENT_QUOTES)];
 }, $map_reports)) ?>;
 var markerColors = {dangerous:'#dc2626',caution:'#d97706',safe:'#16a34a'};
 var catLabels = {crime:'Crime',accident:'Accident',flooding:'Flooding',fire:'Fire',health:'Health',infrastructure:'Infrastructure',other:'Other'};
 function makeMapIcon(color){
 return L.divIcon({className:'',html:'<div style="width:14px;height:14px;border-radius:50%;background:'+color+';border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.35);"></div>',iconSize:[14,14],iconAnchor:[7,7]});
 }
 var lmap = L.map('incidentMap');
 // Hard-lock the viewport to the Imus perimeter (same rule as the community
 // incidents map). fit:false keeps the existing "frame my barangay" behavior
 // below; the max-bounds viscosity stops drags from leaving the city.
 if(typeof SentriGeo !== 'undefined' && SentriGeo.lockToImus){
  SentriGeo.lockToImus(lmap, { fit:false, pad:0 });
  lmap.options.maxBoundsViscosity = 1;
 }
 L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(lmap);
 var allMapMarkers = [];
 var hazardCircles = [];
 var showHazardRings = false;
 var currentMapStatus = 'all';
 var currentMapQuery = '';
 var mapPinPts = [];

 function renderReportMarkers(){
 // Only plot pins that fall inside the Imus perimeter. Wait for the GeoJSON
 // cache (geoReady) so isInImus() can actually answer; before it loads every
 // point reports false and would be hidden.
 var canFilter = (typeof SentriGeo !== 'undefined' && SentriGeo.geoReady && SentriGeo.geoReady());
 mapReports.forEach(function(r){
  if(!isFinite(r.lat) || !isFinite(r.lng)) return;
  if(canFilter && !SentriGeo.isInImus(r.lat, r.lng)) return; // outside Imus
  mapPinPts.push([r.lat, r.lng]);
  var color = markerColors[r.status]||'#888';
  var m = L.marker([r.lat,r.lng],{icon:makeMapIcon(color)});
  m.reportData = r;
  m.bindPopup(
  '<div style="min-width:200px;font-family:Inter,sans-serif;">'+
  '<div style="font-weight:800;font-size:0.9rem;margin-bottom:6px;">'+r.title+'</div>'+
  '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">'+
  '<span style="background:'+(r.status==='dangerous'?'#fef2f2':r.status==='caution'?'#fffbeb':'#f0fdf4')+';color:'+(r.status==='dangerous'?'var(--sentri-danger)':r.status==='caution'?'var(--sentri-caution)':'var(--sentri-safe)')+';padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:700;">'+(r.status.charAt(0).toUpperCase()+r.status.slice(1))+'</span>'+
  '<span style="background:#f0fdf4;color:#166534;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;">'+(catLabels[r.category]||r.category)+'</span></div>'+
  (r.barangay?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Location:</b> '+r.barangay+'</div>':'')+
  '<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Reported by:</b> '+r.reporter+'</div>'+
  (r.reporter_brgy?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Resident of:</b> Brgy '+r.reporter_brgy+(r.reporter_address?', '+r.reporter_address:'')+'</div>':'')+
  (r.desc?'<div style="font-size:0.78rem;color:#374151;margin-top:6px;">'+r.desc.substring(0,120)+(r.desc.length>120?'…':'')+'</div>':'')+
  '<div style="font-size:0.72rem;color:#9ca3af;margin-top:7px;">'+r.date+' &middot; Report #'+r.id+'</div></div>',
  {maxWidth:260}
  );
  m.addTo(lmap);
  allMapMarkers.push(m);
 });
 if(allMapMarkers.length>0){var g=L.featureGroup(allMapMarkers);lmap.fitBounds(g.getBounds().pad(0.15));}
 else{lmap.setView([14.4240,120.9215],12);}
 // Keep barangay labels clear of the report pins once both are on the map.
 if(typeof SentriGeo !== 'undefined' && SentriGeo.avoidLabelOverlap){
  SentriGeo.avoidLabelOverlap(lmap, mapPinPts);
 }
 }

 // Render pins only after the Imus geometry is available so out-of-city
 // coordinates can be rejected instead of shown.
 if(typeof SentriGeo !== 'undefined' && SentriGeo.loadBarangays){
  // Second argument handles load failure only; a render error must not rerun
  // the renderer and duplicate markers.
  SentriGeo.loadBarangays().then(renderReportMarkers, renderReportMarkers);
 } else {
  renderReportMarkers();
 }

 // ── Official barangay perimeters (Imus City GeoJSON) ──
 // All 95 outlines are drawn and labelled; this barangay official's own
 // village is highlighted (filled) by the shared library via onlyName.
 // Cross-barangay drill-down is NOT offered: clicking another polygon only
 // shows a scoping notice.
 var BRGY_OWN = <?= json_encode($brgy ?: '', JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
 function brgyKey(v){
  v = String(v||'').toLowerCase();
  v = v.replace(/ñ/g,'n').replace(/[áéíóú]/g,function(c){return {'á':'a','é':'e','í':'i','ó':'o','ú':'u'}[c]||c;});
  return v.replace(/barangay|brgy/g,'').replace(/[^a-z0-9]/g,'');
 }
 var OWN_KEY = brgyKey(BRGY_OWN);
 function onEachOwnBrgy(feature, layer){
  var nm = (feature.properties && (feature.properties.name || feature.properties.ref)) || '';
  if(OWN_KEY && brgyKey(nm) === OWN_KEY){
  window.__ownBrgyLayer = layer;
  layer.bindTooltip('<div style="font-weight:700;font-size:0.78rem;">'+nm+'<br><span style="font-weight:400;font-size:0.7rem;color:#6b7280;">Brgy boundary (official perimeter)</span></div>',{sticky:true});
  } else {
  layer.bindPopup('<div style="min-width:180px;font-family:Inter,sans-serif;"><div style="font-weight:700;font-size:0.82rem;margin-bottom:4px;">'+nm+'</div><div style="font-size:0.76rem;color:#6b7280;">View this barangay? Official access is scoped to your barangay.</div></div>',{maxWidth:240});
  }
 }
 if(typeof SentriGeo !== 'undefined' && SentriGeo.addBarangayOverlay){
  var resolveOwnName = (BRGY_OWN && SentriGeo.loadBarangays)
  ? SentriGeo.loadBarangays().then(function(gj){
  var feats = (gj && gj.features) || [];
  for(var i=0;i<feats.length;i++){
  var p = feats[i].properties || {};
  if(brgyKey(p.name) === OWN_KEY || brgyKey(p.ref) === OWN_KEY) return p.name;
  }
  return BRGY_OWN; // fallback: let the library attempt its own match
  }).catch(function(){ return BRGY_OWN; })
  : Promise.resolve(BRGY_OWN || undefined);
  resolveOwnName.then(function(ownName){
  return SentriGeo.addBarangayOverlay(lmap, { onlyName: ownName || undefined, onEach: onEachOwnBrgy, labels: true }).then(function(gjLayer){
  // Frame the official barangay perimeter as the map's subject
  var ownL = window.__ownBrgyLayer;
  var fitLayer = (ownL && ownL.getBounds && ownL.getBounds().isValid()) ? ownL : (gjLayer && gjLayer.getBounds && gjLayer.getBounds().isValid() ? gjLayer : null);
  if(fitLayer){ lmap.fitBounds(fitLayer.getBounds(), { padding: [40, 40], maxZoom: 16 }); }
  if(typeof SentriGeo.avoidLabelOverlap === 'function'){ SentriGeo.avoidLabelOverlap(lmap, mapPinPts); }
  return gjLayer;
  });
  }).catch(function(){ /* boundary data unavailable — pins still render */ });
 }

 function clearHazardCircles() {
 hazardCircles.forEach(function(c){ lmap.removeLayer(c); });
 hazardCircles = [];
 }

 function drawHazardCircles() {
 clearHazardCircles();
 allMapMarkers.forEach(function(m){
 if(lmap.hasLayer(m) && m.reportData){
 var r = m.reportData;
 if(r.status === 'dangerous' || r.status === 'caution'){
 var rad = r.status === 'dangerous' ? 250 : 150;
 var col = r.status === 'dangerous' ? '#dc2626' : '#d97706';
 var circle = L.circle([r.lat, r.lng], {
 radius: rad,
 color: col,
 weight: 2,
 dashArray: '5 5',
 fillColor: col,
 fillOpacity: 0.16
 }).bindTooltip('<div style="font-weight:700;font-size:0.74rem;">'+(r.status==='dangerous'?'Hazard Perimeter (250m)':'Caution Zone (150m)')+'<br><span style="font-weight:400;font-size:0.7rem;">'+r.title+'</span></div>');
 circle.addTo(lmap);
 hazardCircles.push(circle);
 }
 }
 });
 }

 function toggleHazardBuffer(btn){
 showHazardRings = !showHazardRings;
 btn.classList.toggle('active-all', showHazardRings);
 if(showHazardRings){
 btn.style.background = '#fef2f2';
 btn.style.color = '#dc2626';
 btn.style.borderColor = '#fca5a5';
 drawHazardCircles();
 } else {
 btn.style.background = '';
 btn.style.color = '';
 btn.style.borderColor = '';
 clearHazardCircles();
 }
 }

 var heatmapCircles = [];
 var showHeatmap = false;

 function clearHeatmap() {
 heatmapCircles.forEach(function(c){ lmap.removeLayer(c); });
 heatmapCircles = [];
 }

 function drawHeatmap() {
 clearHeatmap();
 allMapMarkers.forEach(function(m){
 if(lmap.hasLayer(m) && m.reportData){
 var r = m.reportData;
 var weight = r.status === 'dangerous' ? 0.35 : (r.status === 'caution' ? 0.22 : 0.12);
 var rad = r.status === 'dangerous' ? 400 : (r.status === 'caution' ? 280 : 180);
 var color = r.status === 'dangerous' ? '#ef4444' : (r.status === 'caution' ? '#f59e0b' : '#10b981');
 var heatCircle = L.circle([r.lat, r.lng], {
 radius: rad,
 color: 'transparent',
 fillColor: color,
 fillOpacity: weight
 });
 heatCircle.addTo(lmap);
 heatmapCircles.push(heatCircle);
 }
 });
 }

 function toggleHeatmapDensity(btn){
 showHeatmap = !showHeatmap;
 btn.classList.toggle('active-all', showHeatmap);
 if(showHeatmap){
 btn.style.background = '#fffbeb';
 btn.style.color = '#b45309';
 btn.style.borderColor = '#fde68a';
 drawHeatmap();
 } else {
 btn.style.background = '';
 btn.style.color = '';
 btn.style.borderColor = '';
 clearHeatmap();
 }
 }

 function updateMapFilter(){
 allMapMarkers.forEach(function(m){
 var r = m.reportData;
 var matchStatus = (currentMapStatus === 'all' || r.status === currentMapStatus);
 var matchQuery = true;
 if(currentMapQuery){
 var text = (r.title + ' ' + (r.barangay||'') + ' ' + (r.desc||'') + ' ' + (r.reporter||'')).toLowerCase();
 matchQuery = text.indexOf(currentMapQuery) !== -1;
 }
 var show = matchStatus && matchQuery;
 if(show){ if(!lmap.hasLayer(m)) m.addTo(lmap); }
 else { if(lmap.hasLayer(m)) lmap.removeLayer(m); }
 });
 if(showHazardRings) drawHazardCircles();
 if(showHeatmap) drawHeatmap();
 }

 function filterMap(status,btn){
 currentMapStatus = status;
 document.querySelectorAll('.map-controls .filter-btn:not(#toggleHazardRings):not(#toggleHeatmap)').forEach(function(b){b.className='filter-btn';});
 btn.classList.add('active-'+status);
 updateMapFilter();
 }

 function filterMapBySearch(query){
 currentMapQuery = (query||'').trim().toLowerCase();
 updateMapFilter();
 }
 </script>

 <?php elseif($view === 'profile'): ?>
 <div class="card">
 <div class="card-header"><h3><i class="fas fa-id-card" style="color:var(--green);margin-right:6px;"></i>My Profile</h3></div>
 <div style="padding:24px;">
 <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
 <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Name</div><div style="font-size:0.9rem;font-weight:600;"><?= htmlspecialchars($fname) ?></div></div>
 <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Role</div><div style="font-size:0.9rem;">Barangay Official</div></div>
 <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Position</div><div style="font-size:0.9rem;"><?= htmlspecialchars($pos) ?></div></div>
 <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Office</div><div style="font-size:0.9rem;"><?= htmlspecialchars($org) ?></div></div>
 <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Barangay</div><div style="font-size:0.9rem;"><?= htmlspecialchars($brgy ?: 'Not set') ?></div></div>
 <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Municipality</div><div style="font-size:0.9rem;"><?= htmlspecialchars($city ?: 'Not set') ?></div></div>
 </div>
 </div>
 </div>

 <?php else: ?>
 <div class="card"><div class="coming-soon"><i class="fas <?= $nav_items[$view]['icon'] ?? 'fa-gear' ?>"></i><h3><?= htmlspecialchars($page_titles[$view] ?? ucfirst($view)) ?></h3><p>This section is under development.</p></div></div>
 <?php endif; ?>

 </div>
</div>

<script>
// Escape user-controlled values before they are concatenated into innerHTML.
function esc(s){if(s===null||s===undefined)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

// ── Toast notification system ─────────────────────────────────────────────
function showToast(message, type) {
 type = type || 'success';
 var icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' };
 var tc = document.getElementById('toastContainer');
 var t = document.createElement('div');
 t.className = 'toast toast-' + type;
 t.innerHTML = '<i class="fas ' + (icons[type]||'fa-circle-info') + '"></i><span>' + message + '</span>';
 tc.appendChild(t);
 setTimeout(function() {
 t.style.animation = 'none'; t.style.opacity = '0'; t.style.transform = 'translateY(8px) scale(0.96)';
 t.style.transition = 'all 0.25s ease';
 setTimeout(function(){ if(t.parentNode) tc.removeChild(t); }, 280);
 }, 3200);
}

// ── Modal state ───────────────────────────────────────────────────────────
var currentReportId = null;
var currentReportStatus = null;
var currentEscalated = false;
var currentResponderPending = false;

async function viewReport(id, title, category, status, barangay, reporter, date, desc, escalated, residence, responderPending) {
 currentReportId = id;
 currentReportStatus = status;
 currentEscalated = !!escalated;
 currentResponderPending = !!responderPending && status === 'dangerous';
 document.getElementById('modalTitle').textContent = title;

 // Status colour
 var statusColors = { dangerous: ['#fef2f2','var(--sentri-danger)'], caution: ['#fffbeb','var(--sentri-caution)'], safe: ['#f0fdf4','var(--sentri-safe)'] };
 var sc = statusColors[status] || ['#f3f4f6','#374151'];
 var isResolved = (status === 'safe');

 document.getElementById('modalBody').innerHTML =
 '<div class="detail-row"><div class="detail-lbl">Status</div><div class="detail-val"><span class="pill pill-'+status+'">' + status.charAt(0).toUpperCase()+status.slice(1) + '</span>' +
 (escalated ? ' &nbsp;<span style="display:inline-flex;align-items:center;gap:4px;font-size:0.72rem;font-weight:700;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:6px;border:1px solid #fde68a;"><i class="fas fa-arrow-up-from-bracket"></i> Escalated to LGU</span>' : '') +
 '</div></div>' +
 '<div class="detail-row"><div class="detail-lbl">Category</div><div class="detail-val">'+category.charAt(0).toUpperCase()+category.slice(1)+'</div></div>'+
 '<div class="detail-row"><div class="detail-lbl">Location</div><div class="detail-val">'+esc(barangay)+'</div></div>'+
 '<div class="detail-row"><div class="detail-lbl">Reported By</div><div class="detail-val">'+esc(reporter)+'</div></div>'+
 (residence?'<div class="detail-row"><div class="detail-lbl">Resident of</div><div class="detail-val">'+esc(residence)+'</div></div>':'')+
 '<div class="detail-row"><div class="detail-lbl">Date</div><div class="detail-val">'+esc(date)+'</div></div>'+
 (desc?'<div class="detail-row"><div class="detail-lbl">Description</div><div class="detail-val" style="line-height:1.6;">'+esc(desc)+'</div></div>':'')+
 '<div id="brgyModalTimelineWrap"><div style="font-size:0.75rem;color:var(--muted);padding:10px 0;"><i class="fas fa-spinner fa-spin"></i> Loading telemetry audit trail…</div></div>';

 try {
 fetch('../api/reports.php?action=get_lifecycle&report_id=' + id).then(r=>r.json()).then(data=>{
 if (data.status === 'success' && data.report) {
 var rep = data.report;
 var steps = [
 { title: 'Citizen Reported', icon: 'fa-bullhorn', color: '#3b82f6', done: true, time: rep.created_at ? new Date(rep.created_at).toLocaleString('en-PH') : date, desc: 'Reported by ' + (rep.reporter_name || reporter) },
 { title: 'Barangay / LGU Escalation', icon: 'fa-building-shield', color: '#f59e0b', done: !!rep.escalated_to_lgu || rep.status === 'dangerous' || !!escalated, time: (rep.escalated_to_lgu || escalated) ? 'Escalated to LGU' : 'Monitored locally', desc: (rep.escalated_to_lgu || escalated) ? 'Prioritized for municipal response' : 'Barangay tier monitoring' },
 { title: 'LGU Dispatch to Unit', icon: 'fa-truck-fast', color: '#8b5cf6', done: !!rep.assigned_to, time: rep.assigned_to ? ('Unit: ' + (rep.responder_name || 'Unit') + (rep.responder_agency ? ' (' + rep.responder_agency.toUpperCase() + ')' : '')) : 'Pending assignment', desc: rep.assigned_to ? 'Emergency unit assigned' : 'Awaiting LGU dispatch' },
 { title: 'Unit Response & En Route', icon: 'fa-person-running', color: '#06b6d4', done: !!rep.responded_at || !!rep.accepted_at, time: rep.responded_at ? new Date(rep.responded_at).toLocaleTimeString('en-PH') : (rep.accepted_at ? 'Accepted' : 'Standby'), desc: rep.responded_at ? 'Responders arrived on scene' : 'Dispatched unit in transit' },
 { title: 'Scene Cleared & Resolved', icon: 'fa-circle-check', color: '#10b981', done: (rep.status === 'safe' || !!rep.resolved_at || isResolved), time: rep.resolved_at ? new Date(rep.resolved_at).toLocaleString('en-PH') : (rep.status === 'safe' ? 'Resolved' : 'Active'), desc: (rep.status === 'safe' || !!rep.resolved_at || isResolved) ? 'Hazard safely resolved' : 'Active emergency resolution in progress' }
 ];

 var tlHtml = '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">' +
 '<div style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px;display:flex;align-items:center;gap:6px;">' +
 '<i class="fas fa-route" style="color:var(--green,#16a34a);"></i> Incident Response Journey &amp; Telemetry' +
 '</div>' +
 '<div style="display:flex;flex-direction:column;gap:9px;">';

 steps.forEach(function(s) {
 var bg = s.done ? s.color : '#e5e7eb';
 var textCol = s.done ? 'var(--text)' : '#9ca3af';
 tlHtml += '<div style="display:flex;align-items:flex-start;gap:12px;">' +
 '<div style="width:26px;height:26px;border-radius:50%;background:' + bg + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:0.72rem;flex-shrink:0;margin-top:1px;">' +
 '<i class="fas ' + s.icon + '"></i>' +
 '</div>' +
 '<div style="flex:1;min-width:0;">' +
 '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">' +
 '<span style="font-size:0.82rem;font-weight:700;color:' + textCol + ';">' + s.title + '</span>' +
 '<span style="font-size:0.7rem;color:var(--muted);">' + s.time + '</span>' +
 '</div>' +
 '<div style="font-size:0.74rem;color:var(--muted);margin-top:1px;">' + s.desc + '</div>' +
 '</div>' +
 '</div>';
 });
 tlHtml += '</div></div>';
 var tw = document.getElementById('brgyModalTimelineWrap');
 if (tw) tw.innerHTML = tlHtml;
 }
 }).catch(()=>{
 var tw = document.getElementById('brgyModalTimelineWrap');
 if (tw) tw.innerHTML = '';
 });
 } catch(e) {}

 // Build action buttons based on current state
 var actions = document.getElementById('modalActions');
 actions.innerHTML = '';

 if (!isResolved) {
 if (status === 'dangerous') {
 // Dangerous incidents are cleared by the LGU or the dispatched responder.
 var waitBtn = document.createElement('button');
 waitBtn.className = 'btn-action btn-done';
 waitBtn.id = 'modalResolveBtn';
 waitBtn.disabled = true;
 waitBtn.title = responderPending ? 'The assigned first responder must resolve this incident first' : 'Dangerous incidents must be resolved by the LGU or the assigned responder';
 waitBtn.innerHTML = responderPending ? '<i class="fas fa-hourglass-half"></i> Awaiting Responder' : '<i class="fas fa-hourglass-half"></i> LGU / Responder resolves';
 waitBtn.style.cssText = 'background:#fffbeb;color:#92400e;border:1.5px solid #fde68a;cursor:not-allowed;';
 actions.appendChild(waitBtn);
 } else {
 // Resolve button (caution reports are resolvable by the barangay)
 var resolveBtn = document.createElement('button');
 resolveBtn.className = 'btn-action btn-resolve-full';
 resolveBtn.id = 'modalResolveBtn';
 resolveBtn.innerHTML = '<i class="fas fa-circle-check"></i> Mark Resolved';
 resolveBtn.onclick = function() { doResolve(resolveBtn); };
 actions.appendChild(resolveBtn);
 }

 // Escalate button - show greyed "Already Escalated" if already escalated
 var escBtn = document.createElement('button');
 if (escalated) {
 escBtn.className = 'btn-action btn-done';
 escBtn.disabled = true;
 escBtn.innerHTML = '<i class="fas fa-check"></i> Already Escalated';
 } else {
 escBtn.className = 'btn-action btn-escalate-full';
 escBtn.id = 'modalEscalateBtn';
 escBtn.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i> Escalate to LGU';
 escBtn.onclick = function() { doEscalate(escBtn); };
 }
 actions.appendChild(escBtn);
 } else {
 // Already resolved - show read-only badge
 var badge = document.createElement('span');
 badge.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:0.82rem;font-weight:700;background:#f0fdf4;color:#166534;border:1.5px solid #bbf7d0;';
 badge.innerHTML = '<i class="fas fa-circle-check"></i> This report is resolved';
 actions.appendChild(badge);
 }

 // Status changer (always shown for non-resolved reports)
 if (!isResolved) {
 var sel = document.createElement('select');
 sel.id = 'modalStatusSelect';
 sel.style.cssText = 'padding:8px 12px;border-radius:9px;border:1.5px solid var(--border);font-size:0.82rem;font-weight:600;font-family:\'Inter\',sans-serif;cursor:pointer;background:#fff;';
 sel.innerHTML = '<option value="">Change Status</option><option value="dangerous">⛔ Mark Dangerous</option><option value="caution">⚠️ Mark Caution</option><option value="safe"' + (status === 'dangerous' ? ' disabled title="Dangerous incidents must be resolved by the LGU or the assigned responder"' : '') + '>✅ Mark Safe</option>';
 var safeOpt = sel.querySelector('option[value="safe"]');
 if (safeOpt && status === 'dangerous') safeOpt.textContent = '✅ Mark Safe (LGU / Responder resolves)';
 sel.onchange = function() { changeStatus(this.value); };
 actions.appendChild(sel);
 }

 document.getElementById('reportModal').classList.add('show');
}

document.getElementById('reportModal').addEventListener('click', function(e) { if(e.target===this) closeModal(); });

function closeModal() { document.getElementById('reportModal').classList.remove('show'); }

// ── Resolve ───────────────────────────────────────────────────────────────
async function doResolve(btn) {
 if (!currentReportId) return;
 if (currentReportStatus === 'dangerous') {
 showToast('Dangerous incidents must be resolved by the LGU or the assigned responder.', 'error');
 return;
 }
 btn.disabled = true;
 btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resolving…';
 try {
 var fd = new FormData();
 fd.append('action', 'resolve_report');
 fd.append('report_id', currentReportId);
 var res = await fetch('../api/reports.php', { method: 'POST', body: fd });
 var data = await res.json();
 if (data.status === 'success') {
 showToast('Report marked as resolved.', 'success');
 closeModal();
 refreshReportRow(currentReportId, 'safe', currentEscalated);
 } else {
 showToast(data.message || 'Could not resolve report.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-circle-check"></i> Mark Resolved';
 }
 } catch(e) {
 showToast('Network error - please try again.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-circle-check"></i> Mark Resolved';
 }
}

// ── Escalate ──────────────────────────────────────────────────────────────
async function doEscalate(btn) {
 if (!currentReportId) return;
 btn.disabled = true;
 btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Escalating…';
 try {
 var fd = new FormData();
 fd.append('action', 'escalate_report');
 fd.append('report_id', currentReportId);
 var res = await fetch('../api/reports.php', { method: 'POST', body: fd });
 var data = await res.json();
 if (data.status === 'success') {
 btn.className = 'btn-action btn-done';
 btn.innerHTML = '<i class="fas fa-check"></i> Escalated to LGU';
 showToast('Report escalated to LGU.', 'info');
 currentEscalated = true;
 // Update escalated badge in body
 var statusVal = document.querySelector('#modalBody .detail-val');
 if (statusVal && !statusVal.querySelector('.esc-tag')) {
 var tag = document.createElement('span');
 tag.className = 'esc-tag';
 tag.style.cssText = 'display:inline-flex;align-items:center;gap:4px;font-size:0.72rem;font-weight:700;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:6px;border:1px solid #fde68a;margin-left:6px;';
 tag.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i> Escalated to LGU';
 statusVal.appendChild(tag);
 }
 // Update table row badge
 updateEscalateBadgeInRow(currentReportId);
 setTimeout(function() { closeModal(); }, 1200);
 } else {
 showToast(data.message || 'Escalation failed.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i> Escalate to LGU';
 }
 } catch(e) {
 showToast('Network error - please try again.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i> Escalate to LGU';
 }
}

// ── Change Status (dropdown) ───────────────────────────────────────────────
async function changeStatus(newStatus) {
 if (!newStatus || !currentReportId) return;
 if (newStatus === 'safe' && currentReportStatus === 'dangerous') {
 showToast('Dangerous incidents must be resolved by the LGU or the assigned responder.', 'error');
 var s0 = document.getElementById('modalStatusSelect'); if (s0) s0.value = '';
 return;
 }
 var sel = document.getElementById('modalStatusSelect');
 sel.disabled = true;
 try {
 var fd = new FormData();
 fd.append('action', 'update_report_status');
 fd.append('report_id', currentReportId);
 fd.append('status', newStatus);
 var res = await fetch('../api/reports.php', { method: 'POST', body: fd });
 var data = await res.json();
 if (data.status === 'success') {
 showToast('Status updated to ' + newStatus + '.', 'success');
 closeModal();
 refreshReportRow(currentReportId, newStatus, currentEscalated);
 } else {
 showToast(data.message || 'Status change failed.', 'error');
 if(sel) { sel.disabled = false; sel.value = ''; }
 }
 } catch(e) {
 showToast('Network error - please try again.', 'error');
 if(sel) { sel.disabled = false; sel.value = ''; }
 }
}

// ── Quick inline actions (table row buttons) ──────────────────────────────
function quickResolve(id, btn) {
 var row = btn && btn.closest ? btn.closest('tr') : null;
 if (row && row.getAttribute('data-status') === 'dangerous') {
 showToast('Dangerous incidents must be resolved by the LGU or the assigned responder.', 'error');
 return;
 }
 if (!confirm('Mark this report as resolved?')) return;
 btn.disabled = true;
 btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
 var fd = new FormData();
 fd.append('action', 'resolve_report');
 fd.append('report_id', id);
 fetch('../api/reports.php', { method: 'POST', body: fd })
 .then(function(r) { return r.json(); })
 .then(function(data) {
 if (data.status === 'success') {
 showToast('Report marked as resolved.', 'success');
 refreshReportRow(id, 'safe', false);
 } else {
 showToast(data.message || 'Could not resolve.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-check"></i>';
 }
 })
 .catch(function() {
 showToast('Network error - please try again.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-check"></i>';
 });
}

function quickEscalate(id, btn) {
 if (!confirm('Escalate this report to LGU?')) return;
 btn.disabled = true;
 btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
 var fd = new FormData();
 fd.append('action', 'escalate_report');
 fd.append('report_id', id);
 fetch('../api/reports.php', { method: 'POST', body: fd })
 .then(function(r) { return r.json(); })
 .then(function(data) {
 if (data.status === 'success') {
 showToast('Report escalated to LGU.', 'info');
 btn.innerHTML = '<i class="fas fa-check"></i>';
 btn.title = 'Escalated to LGU';
 btn.classList.add('btn-done');
 btn.style.background = '#fef3c7';
 btn.style.color = '#92400e';
 } else {
 showToast(data.message || 'Escalation failed.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i>';
 }
 })
 .catch(function() {
 showToast('Network error - please try again.', 'error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i>';
 });
}

// ── DOM helpers: update table rows without full reload ────────────────────
function refreshReportRow(id, newStatus, escalated) {
 var rows = document.querySelectorAll('tbody tr');
 rows.forEach(function(row) {
 var viewBtn = row.querySelector('.btn-view');
 if (!viewBtn) return;
 var onclickAttr = viewBtn.getAttribute('onclick') || '';
 // Check if this row's view button references this report id
 if (!onclickAttr.match(new RegExp('viewReport\\s*\\(\\s*' + id + '[,\\s]'))) return;

 // Update status pill
 var pill = row.querySelector('.pill');
 if (pill) {
 pill.className = 'pill pill-' + newStatus;
 pill.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
 }
 row.setAttribute('data-status', newStatus);
 if (newStatus !== 'dangerous') {
 row.removeAttribute('data-responder-pending');
 var pendingChip = row.querySelector('.responder-pending-chip');
 if (pendingChip) pendingChip.remove();
 }
 var rowPending = row.getAttribute('data-responder-pending') === '1';

 // Remove or update action buttons
 var actionsCell = row.querySelector('td:last-child div');
 if (actionsCell) {
 // Remove old resolve/escalate buttons, leave view button
 actionsCell.querySelectorAll('.btn-resolve, .btn-escalate, .btn-resolve-pending').forEach(function(b) { b.remove(); });
 if (newStatus !== 'safe') {
 var rb = document.createElement('button');
 if (newStatus === 'dangerous') {
 rb.className = 'btn-icon btn-resolve-pending';
 rb.type = 'button';
 rb.disabled = true;
 rb.title = rowPending ? 'A first responder has been dispatched and must resolve this incident' : 'Dangerous incidents must be resolved by the LGU or the assigned responder';
 rb.style.cssText = 'background:#fffbeb;color:#92400e;opacity:.75;cursor:not-allowed;';
 rb.innerHTML = '<i class="fas fa-hourglass-half"></i>';
 } else {
 rb.className = 'btn-icon btn-resolve';
 rb.title = 'Mark Resolved';
 rb.innerHTML = '<i class="fas fa-check"></i>';
 rb.onclick = function() { quickResolve(id, rb); };
 }
 actionsCell.appendChild(rb);
 }
 }
 });
 // Reapply filter count
 applyRptFilters();
}

function updateEscalateBadgeInRow(id) {
 // No-op - escalation doesn't change the row's status pill, just a note
}

// ── Sidebar & filter ──────────────────────────────────────────────────────
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('overlay').classList.add('show');document.body.style.overflow='hidden';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('show');document.body.style.overflow='';}

var activeRptStatus = 'all';
function filterReports(status, btn) {
 activeRptStatus = status;
 document.querySelectorAll('.rf-btn').forEach(function(b) { b.classList.remove('rf-active'); });
 btn.classList.add('rf-active');
 applyRptFilters();
}
function searchReports(q) { applyRptFilters(); }
function applyRptFilters() {
 var q = (document.getElementById('rptSearch')||{value:''}).value.toLowerCase();
 var rows = document.querySelectorAll('tbody tr');
 var visible = 0;
 rows.forEach(function(row) {
 var statusEl = row.querySelector('.pill');
 var status = statusEl ? statusEl.className.replace(/pill\s*/g,'').replace('pill-','').trim() : '';
 var text = row.textContent.toLowerCase();
 var show = (activeRptStatus === 'all' || status === activeRptStatus) && (!q || text.includes(q));
 row.style.display = show ? '' : 'none';
 if (show) visible++;
 });
 var cnt = document.getElementById('rptCount');
 if (cnt) cnt.textContent = visible + ' records';
}

// ── 30s background auto-refresh for overview & reports ─────────
(function initBarangayTablePolling(){
 var currentView = '<?= esc($view) ?>';
 if (currentView !== 'overview' && currentView !== 'reports') return;
 var pollInterval = 30000;
 var inFlight = false;

 async function pollTable(){
  if (inFlight || document.hidden) return;
  // Don't interrupt while viewing report detail modal
  var detailModal = document.getElementById('reportModal');
  if (detailModal && detailModal.classList.contains('show')) return;

  inFlight = true;
  try {
   var res = await fetch('barangay.php?view=' + encodeURIComponent(currentView) + '&ajax_reports=1', {
    credentials: 'same-origin',
    cache: 'no-store'
   });
   var data = await res.json();
   if (data && data.status === 'success') {
    var wrap = document.getElementById('brgyTableWrap');
    if (wrap && wrap.innerHTML !== data.html) {
     wrap.innerHTML = data.html;
     applyRptFilters();
    }
    // Update overview stats
    if (document.getElementById('statTotal')) document.getElementById('statTotal').textContent = data.total;
    if (document.getElementById('statDanger')) document.getElementById('statDanger').textContent = data.danger;
    if (document.getElementById('statCaution')) document.getElementById('statCaution').textContent = data.caution;
    if (document.getElementById('statSafe')) document.getElementById('statSafe').textContent = data.safe;
    if (document.getElementById('recentCount')) document.getElementById('recentCount').textContent = data.count + ' records';
    // Update reports stats
    if (document.getElementById('rptStatTotal')) document.getElementById('rptStatTotal').textContent = data.total;
    if (document.getElementById('rptStatDanger')) document.getElementById('rptStatDanger').textContent = data.danger;
    if (document.getElementById('rptStatCaution')) document.getElementById('rptStatCaution').textContent = data.caution;
    if (document.getElementById('rptStatSafe')) document.getElementById('rptStatSafe').textContent = data.safe;
   }
  } catch(e) {
   // Silent background retry on next tick
  } finally {
   inFlight = false;
  }
 }

 setInterval(pollTable, pollInterval);
 document.addEventListener('visibilitychange', function(){
  if (document.visibilityState === 'visible') pollTable();
 });
})();

// ── New-report popup alerts via SentriReportAlerts ─────────────
var BRGY_ALERT_ICONS = {fire:'fa-fire',crime:'fa-user-shield',accident:'fa-car-burst',flooding:'fa-water',health:'fa-heart-pulse',infrastructure:'fa-road-barrier',other:'fa-circle-exclamation'};
var BRGY_ALERT_LABELS = {fire:'Fire',crime:'Crime',accident:'Accident',flooding:'Flooding',health:'Health',infrastructure:'Infrastructure',other:'Other'};
var brgyAlertFeedTimer = null;

function brgyAlertTone(report, reason) {
 if (reason === 'escalated') return 'escalated';
 if (report.status === 'caution') return 'caution';
 if (report.status === 'dangerous') return 'dangerous';
 return 'info';
}

function brgyAlertTime(report) {
 var raw = report.created_at || '';
 var d = new Date(String(raw).replace(' ', 'T'));
 if (isNaN(d.getTime())) return raw;
 var diff = Math.round((Date.now() - d.getTime()) / 1000);
 if (diff >= 0 && diff < 60) return 'just now';
 if (diff >= 60 && diff < 3600) return Math.round(diff / 60) + 'm ago';
 if (diff >= 3600 && diff < 86400) return Math.round(diff / 3600) + 'h ago';
 return d.toLocaleString('en-PH', {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
}

function dismissBrgyAlert(el) {
 if (!el || !el.parentNode) return;
 el.classList.add('alert-out');
 setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 380);
}

function renderBrgyAlert(report, label, reason) {
 var container = document.getElementById('toastContainer');
 if (!container) return;
 var tone = brgyAlertTone(report, reason);
 var category = BRGY_ALERT_LABELS[report.category] || report.category || 'Report';
 var location = [report.barangay, report.city].filter(function (part) {
  return part && String(part).trim() !== '';
 }).join(', ');
 var meta = [category, location, brgyAlertTime(report)].filter(function (part) {
  return part && String(part).trim() !== '';
 }).join(' \u00b7 ');
 var description = String(report.description || '');
 if (description.length > 120) description = description.slice(0, 119) + '\u2026';
 var icon = reason === 'escalated' ? 'fa-arrow-up-from-bracket' : (BRGY_ALERT_ICONS[report.category] || 'fa-circle-exclamation');

 var el = document.createElement('div');
 el.className = 'alert-toast alert-' + tone;
 el.setAttribute('role', 'alert');
 el.setAttribute('aria-live', 'assertive');
 el.innerHTML =
  '<div class="alert-toast-icon"><i class="fas ' + esc(icon) + '"></i></div>' +
  '<div class="alert-toast-body">' +
   '<div class="alert-toast-title">' + esc(label || 'New Report') + '</div>' +
   '<div class="alert-toast-text">' +
    '<strong>' + esc(report.title || 'Untitled report') + '</strong>' +
    (meta ? '<span>' + esc(meta) + '</span>' : '') +
    (description ? '<span class="alert-toast-desc">' + esc(description) + '</span>' : '') +
   '</div>' +
   '<a class="alert-toast-link" href="barangay.php?view=reports">View in reports <i class="fas fa-arrow-right"></i></a>' +
  '</div>' +
  '<button type="button" class="alert-toast-close" aria-label="Dismiss alert"><i class="fas fa-xmark"></i></button>' +
  '<div class="alert-toast-progress" style="animation-duration:7s;"></div>';

 container.appendChild(el);
 var close = el.querySelector('.alert-toast-close');
 if (close) close.onclick = function () { dismissBrgyAlert(el); };
 var visible = container.querySelectorAll('.alert-toast');
 if (visible.length > 3) {
  for (var i = 0; i < visible.length - 3; i++) dismissBrgyAlert(visible[i]);
 }
 setTimeout(function () { dismissBrgyAlert(el); }, 7000);
}

// Only the dashboard and reports views render the incident table.
function brgyFeedView() {
 var v = new URLSearchParams(window.location.search).get('view') || 'overview';
 return (v === 'overview' || v === 'reports') ? v : '';
}

// Re-render the feed from the same page without a full reload, so scroll,
// filters and any open modal survive. Errors are swallowed: the next poll retries.
function softRefreshFeed(highlightId) {
 if (!brgyFeedView()) return;
 fetch(window.location.pathname + window.location.search, { credentials: 'same-origin', cache: 'no-store' })
 .then(function (response) { return response.ok ? response.text() : null; })
 .then(function (html) {
  if (!html) return;
  var doc = new DOMParser().parseFromString(html, 'text/html');
  var searchEl = document.getElementById('rptSearch');
  var keepSearch = searchEl ? searchEl.value : '';

  var curCard = document.querySelector('.content > .card');
  var newCard = doc.querySelector('.content > .card');
  if (curCard && newCard) {
   curCard.replaceWith(newCard);
   var newSearch = document.getElementById('rptSearch');
   if (newSearch && keepSearch) newSearch.value = keepSearch;
  }

  ['stat-grid', 'rpt-stats'].forEach(function (cls) {
   var cur = document.querySelector('.content > .' + cls);
   var next = doc.querySelector('.content > .' + cls);
   if (cur && next) cur.replaceWith(next);
  });

  // Restore the active status filter button of the freshly parsed markup.
  if (typeof activeRptStatus !== 'undefined') {
   document.querySelectorAll('.rf-btn').forEach(function (btn) {
    btn.classList.remove('rf-active');
    var onclick = btn.getAttribute('onclick') || '';
    if (onclick.indexOf("filterReports('" + activeRptStatus + "'") !== -1) btn.classList.add('rf-active');
   });
  }
  if (typeof applyRptFilters === 'function') applyRptFilters();

  if (highlightId) {
   var rows = document.querySelectorAll('.content > .card tbody tr');
   rows.forEach(function (row) {
    var first = row.firstElementChild;
    if (first && first.textContent.trim() === '#' + highlightId) {
     row.style.transition = 'background 0.4s';
     row.style.background = '#fef9c3';
     setTimeout(function () { row.style.background = ''; }, 2600);
    }
   });
  }
 })
 .catch(function () { /* keep the existing feed; next poll retries */ });
}

function scheduleBrgyFeedRefresh(id) {
 if (brgyAlertFeedTimer) clearTimeout(brgyAlertFeedTimer);
 brgyAlertFeedTimer = setTimeout(function () {
  brgyAlertFeedTimer = null;
  softRefreshFeed(id);
 }, 700);
}

(function () {
 if (!window.SentriReportAlerts) return;
 SentriReportAlerts.create({
  api: '../api/reports.php',
  seenKey: 'sentri_brgy_alert_seen_v1',
  containerId: 'toastContainer',
  interval: 15000,
  onAlert: function (report, label, reason) {
   renderBrgyAlert(report, label, reason);
   scheduleBrgyFeedRefresh(report && report.id);
  }
 }).start();
})();
</script>

<!-- ADD CONTACT MODAL -->
<div class="modal-bg" id="contactModal">
 <div class="modal" style="max-width:480px;">
 <div class="modal-header">
 <h3><i class="fas fa-address-book" style="color:var(--green);margin-right:6px;"></i>Add Emergency Contact</h3>
 <button class="modal-close" onclick="closeContactModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="modal-body">
 <div style="display:grid;gap:12px;">
 <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Name *</label>
 <input id="cn_name" type="text" placeholder="e.g. Barangay Health Center" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
 <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
 <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Type *</label>
 <select id="cn_type" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;background:#fff;">
 <option value="barangay">Barangay</option><option value="hospital">Hospital</option>
 <option value="police">Police</option><option value="fire">Fire</option>
 <option value="lgu">LGU</option><option value="traffic">Traffic</option><option value="other">Other</option>
 </select></div>
 <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">City *</label>
 <input id="cn_city" type="text" placeholder="City/Municipality" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
 </div>
 <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Contact Number</label>
 <input id="cn_phone" type="tel" placeholder="+63 9XX XXX XXXX" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
 <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Email</label>
 <input id="cn_email" type="email" placeholder="contact@example.com" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
 <div id="cn_error" style="display:none;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:9px 12px;font-size:0.82rem;font-weight:600;"></div>
 </div>
 </div>
 <div class="modal-actions">
 <button class="btn-action btn-resolve-full" id="cn_saveBtn" onclick="saveContact()"><i class="fas fa-floppy-disk"></i> Save Contact</button>
 </div>
 </div>
</div>

<script>
function openAddContact(){ document.getElementById('contactModal').classList.add('show'); }
function closeContactModal(){ document.getElementById('contactModal').classList.remove('show'); document.getElementById('cn_error').style.display='none'; }
async function saveContact(){
 var name=document.getElementById('cn_name').value.trim();
 var type=document.getElementById('cn_type').value;
 var city=document.getElementById('cn_city').value.trim();
 var phone=document.getElementById('cn_phone').value.trim();
 var email=document.getElementById('cn_email').value.trim();
 var err=document.getElementById('cn_error');
 if(!name||!city){ err.textContent='Name and city are required.'; err.style.display='block'; return; }
 var btn=document.getElementById('cn_saveBtn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
 var fd=new FormData(); fd.append('action','create'); fd.append('name',name); fd.append('type',type);
 fd.append('city',city); fd.append('contact_number',phone); fd.append('contact_email',email);
 try {
 var res=await fetch('../api/contacts.php',{method:'POST',body:fd});
 var data=await res.json();
 if(data.status==='success'){ closeContactModal(); location.reload(); }
 else { err.textContent=data.message||'Save failed.'; err.style.display='block'; btn.disabled=false; btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save Contact'; }
 } catch(e){ err.textContent='Request failed.'; err.style.display='block'; btn.disabled=false; btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save Contact'; }
}
document.getElementById('contactModal').addEventListener('click',function(e){if(e.target===this)closeContactModal();});
</script>

<!-- EVACUATION CENTERS MODAL -->
<div class="modal-bg" id="evacModal">
 <div class="modal" style="max-width:580px;">
 <div class="modal-header">
 <h3><i class="fas fa-person-shelter" style="color:var(--green);margin-right:6px;"></i>Barangay Evacuation Centers Directory</h3>
 <button class="modal-close" onclick="closeEvacModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="modal-body">
 <div style="display:flex;flex-direction:column;gap:12px;">
 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px;">
 <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
 <strong style="font-size:0.9rem;">San Jose Covered Court</strong>
 <span style="background:#f0fdf4;color:#166534;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:12px;border:1px solid #bbf7d0;">Operational</span>
 </div>
 <div style="font-size:0.76rem;color:var(--muted);margin-bottom:8px;"><i class="fas fa-location-dot" style="margin-right:4px;"></i>Barangay Compound, J.P. Rizal St. &middot; Contact: Kagawad Perez (0917-555-0123)</div>
 <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.78rem;font-weight:600;margin-bottom:4px;">
 <span>Occupancy: <b id="evac1_occ">84</b> / 350 persons</span>
 <span id="evac1_pct" style="color:#16a34a;">24%</span>
 </div>
 <div style="width:100%;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:8px;">
 <div id="evac1_bar" style="width:24%;height:100%;background:#16a34a;border-radius:4px;transition:width 0.3s;"></div>
 </div>
 <div style="display:flex;gap:6px;justify-content:flex-end;">
 <button onclick="updateEvacCount(1, 5)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:0.74rem;font-weight:600;cursor:pointer;">+5 Evacuees</button>
 <button onclick="updateEvacCount(1, -5)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:0.74rem;font-weight:600;cursor:pointer;">-5 Evacuees</button>
 </div>
 </div>

 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px;">
 <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
 <strong style="font-size:0.9rem;">San Jose Elementary Gymnasium</strong>
 <span style="background:#eff6ff;color:#2563eb;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:12px;border:1px solid #bfdbfe;">Standby Ready</span>
 </div>
 <div style="font-size:0.76rem;color:var(--muted);margin-bottom:8px;"><i class="fas fa-location-dot" style="margin-right:4px;"></i>School Grounds, Mabini St. &middot; Contact: Principal Ramos (0918-555-0456)</div>
 <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.78rem;font-weight:600;margin-bottom:4px;">
 <span>Occupancy: <b id="evac2_occ">0</b> / 500 persons</span>
 <span id="evac2_pct" style="color:#2563eb;">0%</span>
 </div>
 <div style="width:100%;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:8px;">
 <div id="evac2_bar" style="width:0%;height:100%;background:#2563eb;border-radius:4px;transition:width 0.3s;"></div>
 </div>
 <div style="display:flex;gap:6px;justify-content:flex-end;">
 <button onclick="updateEvacCount(2, 10)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:0.74rem;font-weight:600;cursor:pointer;">+10 Evacuees</button>
 <button onclick="updateEvacCount(2, -10)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:0.74rem;font-weight:600;cursor:pointer;">-10 Evacuees</button>
 </div>
 </div>

 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px;">
 <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
 <strong style="font-size:0.9rem;">Barangay Health & Relief Annex</strong>
 <span style="background:#f0fdf4;color:#166534;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:12px;border:1px solid #bbf7d0;">Operational</span>
 </div>
 <div style="font-size:0.76rem;color:var(--muted);margin-bottom:8px;"><i class="fas fa-location-dot" style="margin-right:4px;"></i>Relief Operations Center &middot; Medical Staff On Duty</div>
 <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.78rem;font-weight:600;margin-bottom:4px;">
 <span>Occupancy: <b id="evac3_occ">15</b> / 120 persons</span>
 <span id="evac3_pct" style="color:#16a34a;">12%</span>
 </div>
 <div style="width:100%;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:8px;">
 <div id="evac3_bar" style="width:12%;height:100%;background:#16a34a;border-radius:4px;transition:width 0.3s;"></div>
 </div>
 <div style="display:flex;gap:6px;justify-content:flex-end;">
 <button onclick="updateEvacCount(3, 5)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:0.74rem;font-weight:600;cursor:pointer;">+5 Evacuees</button>
 <button onclick="updateEvacCount(3, -5)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:0.74rem;font-weight:600;cursor:pointer;">-5 Evacuees</button>
 </div>
 </div>
 </div>
 </div>
 </div>
</div>

<!-- RELIEF SUPPLIES INVENTORY MODAL -->
<div class="modal-bg" id="reliefModal">
 <div class="modal" style="max-width:600px;">
 <div class="modal-header">
 <h3><i class="fas fa-boxes-stacked" style="color:var(--navy);margin-right:6px;"></i>Barangay Emergency Relief & Supplies Tracker</h3>
 <button class="modal-close" onclick="closeReliefModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="modal-body">
 <div style="display:flex;flex-direction:column;gap:12px;">
 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px;">
 <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
 <strong style="font-size:0.88rem;"><i class="fas fa-bowl-rice" style="color:#d97706;margin-right:6px;"></i>Family Food Packs (FFP)</strong>
 <span id="relief1_tag" style="background:#f0fdf4;color:#166534;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:12px;border:1px solid #bbf7d0;">Optimal</span>
 </div>
 <div style="font-size:0.75rem;color:var(--muted);margin-bottom:6px;">Standard DSWD 3-day family provisions (Rice, canned goods, coffee, noodles)</div>
 <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.78rem;font-weight:700;margin-bottom:6px;">
 <span>Stock on hand: <b id="relief1_qty" style="color:var(--text);font-size:0.95rem;">420</b> / 500 packs</span>
 </div>
 <div style="display:flex;gap:6px;justify-content:flex-end;">
 <button onclick="updateReliefQty(1, 25)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:0.74rem;font-weight:600;cursor:pointer;">+25 Restock</button>
 <button onclick="updateReliefQty(1, -25)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:0.74rem;font-weight:600;cursor:pointer;">-25 Disburse</button>
 </div>
 </div>

 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px;">
 <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
 <strong style="font-size:0.88rem;"><i class="fas fa-bottle-water" style="color:#0284c7;margin-right:6px;"></i>Potable Drinking Water (5-Gal Bottles)</strong>
 <span id="relief2_tag" style="background:#f0fdf4;color:#166534;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:12px;border:1px solid #bbf7d0;">Optimal</span>
 </div>
 <div style="font-size:0.75rem;color:var(--muted);margin-bottom:6px;">Purified water containers for evacuation centers and community distribution</div>
 <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.78rem;font-weight:700;margin-bottom:6px;">
 <span>Stock on hand: <b id="relief2_qty" style="color:var(--text);font-size:0.95rem;">180</b> / 250 containers</span>
 </div>
 <div style="display:flex;gap:6px;justify-content:flex-end;">
 <button onclick="updateReliefQty(2, 10)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:0.74rem;font-weight:600;cursor:pointer;">+10 Restock</button>
 <button onclick="updateReliefQty(2, -10)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:0.74rem;font-weight:600;cursor:pointer;">-10 Disburse</button>
 </div>
 </div>

 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px;">
 <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
 <strong style="font-size:0.88rem;"><i class="fas fa-kit-medical" style="color:#dc2626;margin-right:6px;"></i>Emergency Trauma & Medical Kits</strong>
 <span id="relief3_tag" style="background:#fffbeb;color:#b45309;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:12px;border:1px solid #fde68a;">Restock Recommended</span>
 </div>
 <div style="font-size:0.75rem;color:var(--muted);margin-bottom:6px;">Bandages, antiseptic, burn dressings, splints, and OTC emergency medications</div>
 <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.78rem;font-weight:700;margin-bottom:6px;">
 <span>Stock on hand: <b id="relief3_qty" style="color:var(--text);font-size:0.95rem;">32</b> / 100 kits</span>
 </div>
 <div style="display:flex;gap:6px;justify-content:flex-end;">
 <button onclick="updateReliefQty(3, 5)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:0.74rem;font-weight:600;cursor:pointer;">+5 Restock</button>
 <button onclick="updateReliefQty(3, -5)" style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:0.74rem;font-weight:600;cursor:pointer;">-5 Disburse</button>
 </div>
 </div>
 </div>
 </div>
 </div>
</div>

<!-- MASS BROADCAST MODAL -->
<div class="modal-bg" id="broadcastModal">
 <div class="modal" style="max-width:580px;">
 <div class="modal-header">
 <h3><i class="fas fa-bullhorn" style="color:#dc2626;margin-right:6px;"></i>Barangay Emergency Mass Broadcast</h3>
 <button class="modal-close" onclick="closeBroadcastModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="modal-body">
 <div style="margin-bottom:12px;">
 <label style="font-size:0.76rem;font-weight:700;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:4px;">Alert Level</label>
 <select id="bc_severity" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.84rem;outline:none;font-family:'Inter',sans-serif;">
 <option value="advisory">🟡 General Advisory & Precautionary Notice</option>
 <option value="warning">🟠 Urgent Hazard Warning (Storm / Flooding)</option>
 <option value="evac">🔴 Mandatory Evacuation Order (Immediate Action)</option>
 </select>
 </div>
 <div style="margin-bottom:12px;">
 <label style="font-size:0.76rem;font-weight:700;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:4px;">Broadcast Channels</label>
 <div style="display:flex;gap:12px;font-size:0.8rem;font-weight:600;">
 <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" checked disabled> SMS Blast (Est. 2,450 Residents)</label>
 <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" checked disabled> Barangay PA Sirens</label>
 </div>
 </div>
 <div style="margin-bottom:14px;">
 <label style="font-size:0.76rem;font-weight:700;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:4px;">Announcement Message</label>
 <textarea id="bc_msg" rows="4" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.84rem;outline:none;font-family:'Inter',sans-serif;resize:vertical;" placeholder="Type urgent announcement here...">[BARANGAY ADVISORY] Due to rising water levels, low-lying residents in Purok 3 and 4 are advised to proceed to San Jose Covered Court evacuation center immediately.</textarea>
 </div>
 <div style="display:flex;justify-content:flex-end;gap:8px;">
 <button onclick="closeBroadcastModal()" style="padding:8px 16px;background:#f1f5f9;border:1px solid var(--border);border-radius:8px;font-size:0.82rem;font-weight:700;cursor:pointer;">Cancel</button>
 <button onclick="sendMassBroadcast()" style="padding:8px 18px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:0.82rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-tower-broadcast"></i> Transmit Broadcast</button>
 </div>
 </div>
 </div>
</div>

<!-- VULNERABLE RESIDENTS & HIGH-RISK HOUSEHOLDS REGISTRY MODAL -->
<div class="modal-bg" id="vulnerableModal">
 <div class="modal" style="max-width:680px;">
 <div class="modal-header">
 <h3><i class="fas fa-person-cane" style="color:#86198f;margin-right:6px;"></i>Barangay Vulnerable Sector & High-Risk Registry</h3>
 <button class="modal-close" onclick="closeVulnerableModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="modal-body">
 <div style="background:#fdf4ff;border:1px solid #f0abfc;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
 <div style="font-size:0.78rem;color:#701a75;">
 <strong>Priority Assistance Protocol:</strong> Track residents requiring specialized evacuation assistance (PWDs, non-ambulatory seniors, oxygen-dependent patients, and infants).
 </div>
 <span style="font-size:0.72rem;background:#fae8ff;color:#86198f;padding:3px 9px;border-radius:20px;font-weight:800;border:1px solid #e879f9;">4 Registered</span>
 </div>

 <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
 <button class="vuln-tab-btn" onclick="filterVulnResidents('all', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:var(--green);color:#fff;font-size:0.74rem;font-weight:700;cursor:pointer;">All Puroks</button>
 <button class="vuln-tab-btn" onclick="filterVulnResidents('Purok 1', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 1</button>
 <button class="vuln-tab-btn" onclick="filterVulnResidents('Purok 2', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 2</button>
 <button class="vuln-tab-btn" onclick="filterVulnResidents('Purok 3', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 3</button>
 <button class="vuln-tab-btn" onclick="filterVulnResidents('Purok 4', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 4</button>
 </div>

 <div class="table-wrap" style="max-height:45vh;overflow-y:auto;">
 <table>
 <thead>
 <tr>
 <th>Resident</th>
 <th>Purok / Zone</th>
 <th>Vulnerability / Medical</th>
 <th>Status</th>
 <th>Action</th>
 </tr>
 </thead>
 <tbody id="vulnTableBody">
 <tr class="vuln-row-item" data-purok="Purok 1">
 <td><strong>Maria Santos</strong><div style="font-size:0.72rem;color:var(--muted);">House #14, Riverside</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 1</span></td>
 <td><span style="background:#fef2f2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">Elderly (78yo) & Bedridden</span></td>
 <td><span id="vuln_status_1" style="background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Needs Assistance</span></td>
 <td><button onclick="toggleVulnStatus(1, this)" style="padding:4px 8px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Mark Safe</button></td>
 </tr>
 <tr class="vuln-row-item" data-purok="Purok 3">
 <td><strong>Danilo Cruz</strong><div style="font-size:0.72rem;color:var(--muted);">Blk 4 Lot 2, Lower Zone</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 3</span></td>
 <td><span style="background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">Wheelchair / Dialysis</span></td>
 <td><span id="vuln_status_2" style="background:#f0fdf4;color:#16a34a;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Evacuated & Safe</span></td>
 <td><button onclick="toggleVulnStatus(2, this)" style="padding:4px 8px;background:#f8fafc;border:1px solid var(--border);color:var(--text);border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Reset</button></td>
 </tr>
 <tr class="vuln-row-item" data-purok="Purok 2">
 <td><strong>Elena Reyes</strong><div style="font-size:0.72rem;color:var(--muted);">House #8, Alley 3</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 2</span></td>
 <td><span style="background:#fdf4ff;color:#86198f;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">Infant (3 mos) & Mother</span></td>
 <td><span id="vuln_status_3" style="background:#f0fdf4;color:#16a34a;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Evacuated & Safe</span></td>
 <td><button onclick="toggleVulnStatus(3, this)" style="padding:4px 8px;background:#f8fafc;border:1px solid var(--border);color:var(--text);border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Reset</button></td>
 </tr>
 <tr class="vuln-row-item" data-purok="Purok 4">
 <td><strong>Ricardo Gomez</strong><div style="font-size:0.72rem;color:var(--muted);">House #31, Creek Side</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 4</span></td>
 <td><span style="background:#fff7ed;color:#c2410c;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">Oxygen Concentrator Dep.</span></td>
 <td><span id="vuln_status_4" style="background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Needs Assistance</span></td>
 <td><button onclick="toggleVulnStatus(4, this)" style="padding:4px 8px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Mark Safe</button></td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>
 </div>
</div>

<!-- ── BARANGAY RELIEF DISTRIBUTION & QR VOUCHER CLAIMS MODAL ── -->
<div class="modal-bg" id="reliefClaimsModal">
 <div class="modal" style="max-width:760px;">
 <div class="modal-header">
 <h3><i class="fas fa-hand-holding-hand" style="color:#ca8a04;margin-right:6px;"></i>Barangay Calamity Relief & Food Pack Distribution Tracker</h3>
 <button class="modal-close" onclick="closeReliefClaimsModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="modal-body">
 <div style="background:#fefce8;border:1px solid #fde047;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
 <div style="font-size:0.78rem;color:#854d0e;">
 <strong>Disbursement Telemetry:</strong> Live food pack ration issuance and household QR voucher verification for San Jose.
 </div>
 <span style="font-size:0.72rem;background:#fef08a;color:#713f12;padding:3px 9px;border-radius:20px;font-weight:800;border:1px solid #eab308;">98 / 142 Claimed (69%)</span>
 </div>

 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:12px;">
 <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:8px 12px;text-align:center;">
 <div style="font-size:1.1rem;font-weight:800;color:var(--text);" id="claimTotalFamilies">142</div>
 <div style="font-size:0.7rem;color:var(--muted);">Target Families</div>
 </div>
 <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 12px;text-align:center;">
 <div style="font-size:1.1rem;font-weight:800;color:#16a34a;" id="claimCountClaimed">98</div>
 <div style="font-size:0.7rem;color:#15803d;">Claimed Rations</div>
 </div>
 <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;text-align:center;">
 <div style="font-size:1.1rem;font-weight:800;color:#d97706;" id="claimCountPending">44</div>
 <div style="font-size:0.7rem;color:#b45309;">Pending Voucher</div>
 </div>
 <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;text-align:center;">
 <div style="font-size:1.1rem;font-weight:800;color:#2563eb;">402</div>
 <div style="font-size:0.7rem;color:#1d4ed8;">Buffer Stock (Packs)</div>
 </div>
 </div>

 <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
 <button class="claim-tab-btn" onclick="filterReliefClaims('all', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#ca8a04;color:#fff;font-size:0.74rem;font-weight:700;cursor:pointer;">All Puroks</button>
 <button class="claim-tab-btn" onclick="filterReliefClaims('Purok 1', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 1</button>
 <button class="claim-tab-btn" onclick="filterReliefClaims('Purok 2', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 2</button>
 <button class="claim-tab-btn" onclick="filterReliefClaims('Purok 3', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 3</button>
 <button class="claim-tab-btn" onclick="filterReliefClaims('Purok 4', this)" style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--muted);font-size:0.74rem;font-weight:700;cursor:pointer;">Purok 4</button>
 </div>

 <div class="table-wrap" style="max-height:42vh;overflow-y:auto;">
 <table>
 <thead>
 <tr>
 <th style="white-space:nowrap;">Household Head</th>
 <th style="white-space:nowrap;">Purok</th>
 <th>Allocated Package</th>
 <th style="white-space:nowrap;">Voucher Status</th>
 <th style="white-space:nowrap;">Action</th>
 </tr>
 </thead>
 <tbody id="reliefClaimsTableBody">
 <tr class="claim-row-item" data-purok="Purok 1">
 <td><strong>Maria Santos</strong><div style="font-size:0.72rem;color:var(--muted);">5 Members &middot; House #14, Riverside</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 1</span></td>
 <td><span style="background:#f0fdf4;color:#166534;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">DSWD Food Pack + 5L Water</span></td>
 <td><span id="claim_status_1" style="background:#f0fdf4;color:#16a34a;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Claimed &middot; Aug 25</span></td>
 <td><button onclick="toggleClaimStatus(1, this)" style="padding:4px 8px;background:#f8fafc;border:1px solid var(--border);color:var(--text);border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Reset</button></td>
 </tr>
 <tr class="claim-row-item" data-purok="Purok 3">
 <td><strong>Danilo Cruz</strong><div style="font-size:0.72rem;color:var(--muted);">3 Members &middot; Blk 4 Lot 2, Lower Zone</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 3</span></td>
 <td><span style="background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">DSWD Food Pack + Trauma Kit</span></td>
 <td><span id="claim_status_2" style="background:#fffbeb;color:#d97706;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Pending Voucher</span></td>
 <td><button onclick="toggleClaimStatus(2, this)" style="padding:4px 8px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Mark Claimed</button></td>
 </tr>
 <tr class="claim-row-item" data-purok="Purok 2">
 <td><strong>Elena Reyes</strong><div style="font-size:0.72rem;color:var(--muted);">4 Members &middot; House #8, Alley 3</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 2</span></td>
 <td><span style="background:#fdf4ff;color:#86198f;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">DSWD Food Pack + Infant Care Kit</span></td>
 <td><span id="claim_status_3" style="background:#f0fdf4;color:#16a34a;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Claimed &middot; Aug 25</span></td>
 <td><button onclick="toggleClaimStatus(3, this)" style="padding:4px 8px;background:#f8fafc;border:1px solid var(--border);color:var(--text);border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Reset</button></td>
 </tr>
 <tr class="claim-row-item" data-purok="Purok 4">
 <td><strong>Ricardo Gomez</strong><div style="font-size:0.72rem;color:var(--muted);">6 Members &middot; House #31, Creek Side</div></td>
 <td><span style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:600;">Purok 4</span></td>
 <td><span style="background:#f0fdf4;color:#166534;padding:2px 6px;border-radius:4px;font-size:0.72rem;font-weight:700;">DSWD Food Pack (2x) + 5L Water</span></td>
 <td><span id="claim_status_4" style="background:#fffbeb;color:#d97706;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;">Pending Voucher</span></td>
 <td><button onclick="toggleClaimStatus(4, this)" style="padding:4px 8px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Mark Claimed</button></td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>
 </div>
</div>

<script>
function openEvacModal(){ document.getElementById('evacModal').classList.add('show'); }
function closeEvacModal(){ document.getElementById('evacModal').classList.remove('show'); }
const evacCaps = {1: 350, 2: 500, 3: 120};
let evacVals = {1: 84, 2: 0, 3: 15};
function updateEvacCount(id, delta){
 evacVals[id] = Math.max(0, Math.min(evacCaps[id], evacVals[id] + delta));
 const pct = Math.round((evacVals[id] / evacCaps[id]) * 100);
 document.getElementById(`evac${id}_occ`).textContent = evacVals[id];
 document.getElementById(`evac${id}_pct`).textContent = pct + '%';
 document.getElementById(`evac${id}_bar`).style.width = pct + '%';
 showToast(`Evacuation center updated: ${evacVals[id]} / ${evacCaps[id]} (${pct}%)`, 'success');
}
document.getElementById('evacModal').addEventListener('click',function(e){if(e.target===this)closeEvacModal();});

function openReliefModal(){ document.getElementById('reliefModal').classList.add('show'); }
function closeReliefModal(){ document.getElementById('reliefModal').classList.remove('show'); }
document.getElementById('reliefModal').addEventListener('click',function(e){if(e.target===this)closeReliefModal();});

let reliefStock = {1: 420, 2: 180, 3: 32};
function updateReliefQty(id, delta){
 reliefStock[id] = Math.max(0, reliefStock[id] + delta);
 document.getElementById(`relief${id}_qty`).textContent = reliefStock[id];
 showToast(`Relief inventory updated: ${reliefStock[id]} units remaining`, 'success');
}

function openReliefClaimsModal(){ document.getElementById('reliefClaimsModal').classList.add('show'); }
function closeReliefClaimsModal(){ document.getElementById('reliefClaimsModal').classList.remove('show'); }
document.getElementById('reliefClaimsModal').addEventListener('click',function(e){if(e.target===this)closeReliefClaimsModal();});

function filterReliefClaims(purok, btn){
 document.querySelectorAll('.claim-tab-btn').forEach(b=>{
 b.style.background='#fff';
 b.style.color='var(--muted)';
 });
 if(btn){
 btn.style.background='#ca8a04';
 btn.style.color='#fff';
 }
 document.querySelectorAll('.claim-row-item').forEach(row=>{
 if(purok==='all' || row.getAttribute('data-purok')===purok){
 row.style.display='';
 } else {
 row.style.display='none';
 }
 });
}

function toggleClaimStatus(id, btn){
 const el = document.getElementById(`claim_status_${id}`);
 if(!el) return;
 if(el.textContent.includes('Pending')){
 el.textContent = 'Claimed · Just now';
 el.style.background = '#f0fdf4';
 el.style.color = '#16a34a';
 btn.textContent = 'Reset';
 btn.style.background = '#f8fafc';
 btn.style.borderColor = 'var(--border)';
 btn.style.color = 'var(--text)';
 showToast('Household relief package marked as Claimed & verified.', 'success');
 } else {
 el.textContent = 'Pending Voucher';
 el.style.background = '#fffbeb';
 el.style.color = '#d97706';
 btn.textContent = 'Mark Claimed';
 btn.style.background = '#f0fdf4';
 btn.style.borderColor = '#86efac';
 btn.style.color = '#166534';
 showToast('Household relief status reset to Pending Voucher.', 'info');
 }
}

function openBroadcastModal(){ document.getElementById('broadcastModal').classList.add('show'); }
function closeBroadcastModal(){ document.getElementById('broadcastModal').classList.remove('show'); }
document.getElementById('broadcastModal').addEventListener('click',function(e){if(e.target===this)closeBroadcastModal();});

function sendMassBroadcast(){
 const msg = document.getElementById('bc_msg').value.trim();
 if(!msg){ showToast('Please enter an announcement message.', 'error'); return; }
 closeBroadcastModal();
 showToast('Emergency Mass Broadcast transmitted across 2,450 registered resident endpoints and PA sirens.', 'success');
}

function openVulnerableModal(){ document.getElementById('vulnerableModal').classList.add('show'); }
function closeVulnerableModal(){ document.getElementById('vulnerableModal').classList.remove('show'); }
document.getElementById('vulnerableModal').addEventListener('click',function(e){if(e.target===this)closeVulnerableModal();});

function filterVulnResidents(purok, btn){
 document.querySelectorAll('.vuln-tab-btn').forEach(b=>{
 b.style.background='#fff';
 b.style.color='var(--muted)';
 });
 if(btn){
 btn.style.background='var(--green)';
 btn.style.color='#fff';
 }
 document.querySelectorAll('.vuln-row-item').forEach(row=>{
 if(purok==='all' || row.getAttribute('data-purok')===purok){
 row.style.display='';
 } else {
 row.style.display='none';
 }
 });
}

function toggleVulnStatus(id, btn){
 const el = document.getElementById(`vuln_status_${id}`);
 if(!el) return;
 if(el.textContent.includes('Needs')){
 el.textContent = 'Evacuated & Safe';
 el.style.background = '#f0fdf4';
 el.style.color = '#16a34a';
 btn.textContent = 'Reset';
 btn.style.background = '#f8fafc';
 btn.style.borderColor = 'var(--border)';
 btn.style.color = 'var(--text)';
 showToast('Resident safety status marked as Evacuated & Safe.', 'success');
 } else {
 el.textContent = 'Needs Assistance';
 el.style.background = '#fef2f2';
 el.style.color = '#dc2626';
 btn.textContent = 'Mark Safe';
 btn.style.background = '#f0fdf4';
 btn.style.borderColor = '#86efac';
 btn.style.color = '#166534';
 showToast('Resident status updated to Needs Assistance.', 'info');
 }
}
</script>
<?= csrf_script() ?>
</body>
</html>

