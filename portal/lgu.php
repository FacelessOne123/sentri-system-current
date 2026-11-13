<?php
require_once __DIR__ . '/../config/session.php';
sentri_boot_realm('gov');
require_once __DIR__ . '/../config/auth.php';
require_role(['lgu']);
require_approved();
require_once __DIR__ . '/../config/db.php';

$ident = sentri_identity() ?? [];
$uid = (int)($ident['user_id'] ?? 0);
$fname = $ident['first_name'] ?? '';
$view = $_GET['view'] ?? 'overview';
$has_assigned_to = sentri_table_has_column($conn, 'reports', 'assigned_to');
$has_esc_col = sentri_table_has_column($conn, 'reports', 'escalated_to_lgu');

$stmt = $conn->prepare("SELECT email,last_name,org_name,`position`,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i",$uid); $stmt->execute();
$prof = $stmt->get_result()->fetch_assoc(); $stmt->close();
$city = $prof['municipality'] ?? $prof['barangay_name'] ?? '';
$org = $prof['org_name'] ?? 'LGU Office';
$pos = $prof['position'] ?? 'LGU Official';

function cq($conn,$sql,$types='',$params=[]){
 $s=$conn->prepare($sql);
 if($types && count($params)){
 $refs=[];
 foreach($params as &$v) $refs[]=&$v;
 array_unshift($refs,$types);
 call_user_func_array([$s,'bind_param'],$refs);
 }
 $s->execute(); $s->bind_result($n); $s->fetch(); $s->close();
 return (int)$n;
}

$total = cq($conn,"SELECT COUNT(*) FROM reports WHERE (is_archived=0 OR status='safe' OR is_archived=1)");
$danger = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0");
$caution = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='caution' AND is_archived=0");
$safe = cq($conn,"SELECT COUNT(*) FROM reports WHERE (status='safe' OR is_archived=1)");
$contacts_count = cq($conn,"SELECT COUNT(*) FROM emergency_contacts WHERE is_active=1");

// ── Real DB-derived responder & resolution telemetry ──
$resp_total_cnt = cq($conn, "SELECT COUNT(*) FROM users WHERE role='first_responder'");
$resp_approved_cnt = cq($conn, "SELECT COUNT(*) FROM users WHERE role='first_responder' AND is_approved=1");
$has_resolved_col = sentri_table_has_column($conn, 'reports', 'resolved_at');
if ($has_resolved_col) {
 $resolved_today = cq($conn, "SELECT COUNT(*) FROM reports WHERE (status='safe' OR is_archived=1) AND resolved_at IS NOT NULL AND DATE(resolved_at)=CURDATE()");
} else {
 $resolved_today = 0;
}

// Optional GET filter used by the "Reports by Barangay" drill-down bars.
$brgy_filter = trim($_GET['barangay'] ?? '');
$brgy_unspecified = (strcasecmp($brgy_filter, 'Unspecified') === 0);

$cat_icons = ['crime'=>'fa-user-slash','accident'=>'fa-car-burst','flooding'=>'fa-water','fire'=>'fa-fire','health'=>'fa-heart-pulse','infrastructure'=>'fa-road-barrier','other'=>'fa-circle-exclamation'];

// ── Pre-loaded data for seamless client-side tabs ──────────────────────────
$danger_reports = $all_reports = $brgy_stats = $contacts = $map_reports = $responders = [];

$esc_sel = $has_esc_col ? ',r.escalated_to_lgu' : ',0 AS escalated_to_lgu';

// Load dangerous reports for Overview
$s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.created_at,r.description,r.assigned_to,r.resolved_at,r.is_archived{$esc_sel},(SELECT COUNT(*) FROM report_images WHERE report_id=r.id) AS image_count,u.first_name,u.last_name,u.barangay_name,u.resident_address FROM reports r JOIN users u ON u.id=r.user_id WHERE (r.is_archived=0 OR r.status='safe' OR r.is_archived=1) AND r.status='dangerous' ORDER BY r.created_at DESC LIMIT 15");
$s->execute(); $res=$s->get_result();
while($row=$res->fetch_assoc()) $danger_reports[]=$row;
$s->close();

// Load all reports for All Reports tab
$brgy_cond = '';
$bind_brgy = false;
if ($brgy_filter !== '') {
    if ($brgy_unspecified) {
        $brgy_cond = " AND (r.barangay IS NULL OR TRIM(r.barangay)='')";
    } else {
        $brgy_cond = " AND r.barangay = ?";
        $bind_brgy = true;
    }
}
$s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.created_at,r.description,r.assigned_to,r.resolved_at,r.is_archived{$esc_sel},(SELECT COUNT(*) FROM report_images WHERE report_id=r.id) AS image_count,u.first_name,u.last_name,u.barangay_name,u.resident_address FROM reports r JOIN users u ON u.id=r.user_id WHERE (r.is_archived=0 OR r.status='safe' OR r.is_archived=1) $brgy_cond ORDER BY r.created_at DESC LIMIT 100");
if ($bind_brgy) { $s->bind_param("s", $brgy_filter); }
$s->execute(); $res=$s->get_result();
while($row=$res->fetch_assoc()) $all_reports[]=$row;
$s->close();

// Barangay summaries
$bs = $conn->query("SELECT COALESCE(barangay,'Unspecified') as b, COUNT(*) as c, SUM(status='dangerous') as d FROM reports WHERE (is_archived=0 OR status='safe' OR is_archived=1) GROUP BY b ORDER BY c DESC LIMIT 15");
if ($bs) while($r=$bs->fetch_assoc()) $brgy_stats[]=$r;

// Emergency Contacts
$cs = $conn->query("SELECT id,name,type,contact_number,contact_email,barangay,city,is_active FROM emergency_contacts ORDER BY type,name");
if ($cs) while($r=$cs->fetch_assoc()) $contacts[]=$r;

// SLA KPIs
$sla_row = $conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, accepted_at, responded_at)) as avg_resp, COUNT(CASE WHEN status='safe' OR is_archived=1 THEN 1 END) as safe_cnt, COUNT(*) as total_cnt FROM reports WHERE (is_archived=0 OR status='safe' OR is_archived=1)")->fetch_assoc();
$avg_resp_time = round((float)($sla_row['avg_resp'] ?? 0), 1);
$resolution_rate = !empty($sla_row['total_cnt']) ? round(($sla_row['safe_cnt'] / $sla_row['total_cnt']) * 100, 1) : null;
$active_deployed_units = (int)cq($conn, "SELECT COUNT(DISTINCT assigned_to) FROM reports WHERE is_archived=0 AND status='dangerous' AND assigned_to IS NOT NULL");

// Map reports
$s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,r.is_archived,u.first_name,u.last_name,u.barangay_name,u.resident_address FROM reports r JOIN users u ON u.id=r.user_id WHERE (r.is_archived=0 OR r.status='safe' OR r.is_archived=1) AND r.latitude IS NOT NULL AND r.longitude IS NOT NULL ORDER BY r.created_at DESC LIMIT 500");
$s->execute(); $res=$s->get_result();
while($row=$res->fetch_assoc()) $map_reports[]=$row;
$s->close();
$map_total = cq($conn,"SELECT COUNT(*) FROM reports WHERE (is_archived=0 OR status='safe' OR is_archived=1)");

// Responders
if ($has_assigned_to) {
    $s=$conn->prepare("SELECT u.id,u.first_name,u.last_name,u.email,u.phone_number,u.org_name,u.`position`,u.responder_type,u.municipality,u.barangay_name,u.is_approved,COUNT(r.id) as active_count FROM users u LEFT JOIN reports r ON r.assigned_to=u.id AND r.is_archived=0 AND r.status='dangerous' WHERE u.role='first_responder' GROUP BY u.id ORDER BY u.is_approved ASC, u.responder_type, u.org_name");
} else {
    $s=$conn->prepare("SELECT u.id,u.first_name,u.last_name,u.email,u.phone_number,u.org_name,u.`position`,u.responder_type,u.municipality,u.barangay_name,u.is_approved,0 as active_count FROM users u WHERE u.role='first_responder' ORDER BY u.is_approved ASC, u.responder_type, u.org_name");
}
$s->execute(); $res=$s->get_result();
while($row=$res->fetch_assoc()){ $row['active_count']=(int)$row['active_count']; $responders[]=$row; }
$s->close();

// Profile save POST
$profile_msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_profile'])) {
    $p_fname = trim($_POST['first_name'] ?? '');
    $p_lname = trim($_POST['last_name'] ?? '');
    $p_phone = trim($_POST['phone'] ?? '');
    $p_org = trim($_POST['org_name'] ?? '');
    $p_pos = trim($_POST['position'] ?? '');
    $p_muni = trim($_POST['municipality'] ?? '');
    $p_pw = $_POST['new_password'] ?? '';
    $p_pw2 = $_POST['confirm_password'] ?? '';
    if ($p_pw && $p_pw !== $p_pw2) {
        $profile_msg = 'error:Passwords do not match.';
    } elseif (!$p_fname || !$p_lname) {
        $profile_msg = 'error:Name fields are required.';
    } else {
        if ($p_pw) {
            $h = password_hash($p_pw, PASSWORD_BCRYPT, ['cost'=>10]);
            $su=$conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,org_name=?,`position`=?,municipality=?,password=? WHERE id=?");
            $su->bind_param("sssssssi",$p_fname,$p_lname,$p_phone,$p_org,$p_pos,$p_muni,$h,$uid);
        } else {
            $su=$conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,org_name=?,`position`=?,municipality=? WHERE id=?");
            $su->bind_param("ssssssi",$p_fname,$p_lname,$p_phone,$p_org,$p_pos,$p_muni,$uid);
        }
        $su->execute(); $su->close();
        $fname = htmlspecialchars($p_fname,ENT_QUOTES,'UTF-8'); $org=$p_org?:$org; $city=$p_muni?:$city; $pos=$p_pos?:$pos;
        $stmt2=$conn->prepare("SELECT email,org_name,`position`,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
        $stmt2->bind_param("i",$uid); $stmt2->execute();
        $prof=$stmt2->get_result()->fetch_assoc(); $stmt2->close();
        $profile_msg = 'success:Profile updated successfully.';
    }
}

// Responder approve/reject POST
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_responder', 'reject_responder'])) {
    header('Content-Type: application/json');
    $act = $_POST['action'] ?? '';
    $tid = (int)($_POST['user_id'] ?? 0);
    if (!$tid) { echo json_encode(['status'=>'error','message'=>'Invalid user.']); exit; }
    if ($act === 'approve_responder') {
        $s=$conn->prepare("UPDATE users SET is_approved=1 WHERE id=? AND role='first_responder'");
        $s->bind_param("i",$tid); $s->execute(); $s->close();
        echo json_encode(['status'=>'success','message'=>'Responder approved.']); exit;
    }
    if ($act === 'reject_responder') {
        $s=$conn->prepare("DELETE FROM users WHERE id=? AND role='first_responder' AND is_approved=0");
        $s->bind_param("i",$tid); $s->execute(); $s->close();
        echo json_encode(['status'=>'success','message'=>'Responder rejected.']); exit;
    }
}

$nav_items = [
 'overview' => ['icon'=>'fa-gauge', 'label'=>'Overview'],
 'all_reports' => ['icon'=>'fa-file-lines', 'label'=>'All Incident Reports'],
 'map' => ['icon'=>'fa-map-location-dot', 'label'=>'Incident Map'],
 'contacts' => ['icon'=>'fa-address-book', 'label'=>'Emergency Contacts'],
 'barangays' => ['icon'=>'fa-house-flag', 'label'=>'Barangay Summaries'],
 'responders' => ['icon'=>'fa-truck-medical', 'label'=>'Responder Units'],
 'profile' => ['icon'=>'fa-id-card', 'label'=>'My Profile'],
];
$page_titles = [
 'overview' => 'LGU Operations Dashboard',
 'all_reports' => 'All Incident Reports',
 'map' => 'Incident Map',
 'contacts' => 'Emergency Contacts',
 'barangays' => 'Barangay Summaries',
 'responders' => 'Responder Units',
 'profile' => 'My Profile',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $page_titles[$view] ?? 'LGU Portal' ?> | SenTri</title>
<?= csrf_meta_tag() ?>
<link rel="stylesheet" href="../assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css">
<script src="../assets/vendor/leaflet/leaflet.js"></script>
<script src="../assets/js/sentri-geo.js"></script>
<style>
.tab-panel { display: none; }
.tab-panel.active { display: block; animation: fadeInView 0.22s ease both; }
@keyframes fadeInView { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
</style>
<style>
:root{
 --navy:#0a3d62;--navy-dark:#062444;--navy-light:#1a5276;
 --gold:#f39c12;--text:#111827;--muted:#6b7280;
 --border:#e5e7eb;--bg:#f1f5f9;--card:#fff;--sidebar-w:256px;
 --sentri-danger:#b91c1c;--sentri-caution:#b45309;--sentri-safe:#166534;
}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html,body{height:100%;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;}

/* ── SIDEBAR ── */
.sidebar{
 width:var(--sidebar-w);flex-shrink:0;
 background:linear-gradient(180deg,var(--navy-dark) 0%,var(--navy) 55%,var(--navy-light) 100%);
 color:#fff;display:flex;flex-direction:column;
 position:fixed;top:0;left:0;bottom:0;z-index:200;
 box-shadow:4px 0 20px rgba(0,0,0,0.25);
 transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);
}
.sb-header{
 padding:18px 16px;border-bottom:1px solid rgba(255,255,255,0.1);
 display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
}
.sb-brand{display:flex;align-items:center;gap:10px;}
.sb-seal{
 width:40px;height:40px;border-radius:50%;
 background:rgba(243,156,18,0.2);border:2px solid rgba(243,156,18,0.5);
 display:flex;align-items:center;justify-content:center;
 font-size:1.1rem;color:var(--gold);flex-shrink:0;
}
.sb-title{font-size:0.96rem;font-weight:800;line-height:1.2;}
.sb-sub{font-size:0.58rem;color:rgba(255,255,255,0.45);letter-spacing:1.5px;text-transform:uppercase;}
.sb-close{
 background:none;border:none;color:rgba(255,255,255,0.6);
 font-size:1.1rem;cursor:pointer;padding:4px 6px;border-radius:6px;
 display:none;flex-shrink:0;
}
.sb-office{
 padding:10px 16px;background:rgba(0,0,0,0.2);
 border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;
}
.sb-office p{font-size:0.65rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.sb-office strong{font-size:0.82rem;color:#fff;font-weight:700;display:block;word-break:break-word;}
.sb-nav{padding:12px 8px;flex:1;overflow-y:auto;}
.sb-nav a{
 display:flex;align-items:center;gap:10px;
 color:rgba(255,255,255,0.7);text-decoration:none;
 font-size:0.84rem;font-weight:500;
 padding:10px 12px;border-radius:10px;
 transition:all 0.18s;margin-bottom:2px;
 white-space:nowrap;overflow:hidden;
}
.sb-nav a:hover{background:rgba(255,255,255,0.1);color:#fff;}
.sb-nav a.active{background:rgba(255,255,255,0.15);color:#fff;font-weight:700;}
.sb-nav a i{width:18px;text-align:center;font-size:0.92rem;flex-shrink:0;}
.sb-nav a span{overflow:hidden;text-overflow:ellipsis;}
.sb-section{
 font-size:0.62rem;color:rgba(255,255,255,0.28);
 letter-spacing:2px;text-transform:uppercase;
 padding:14px 12px 5px;font-weight:700;
}
.sb-footer{
 padding:12px 16px;border-top:1px solid rgba(255,255,255,0.1);flex-shrink:0;
}
.sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.sb-avatar{
 width:34px;height:34px;border-radius:50%;flex-shrink:0;
 background:rgba(243,156,18,0.25);
 display:flex;align-items:center;justify-content:center;
 font-size:0.88rem;font-weight:800;color:var(--gold);
}
.sb-uname{font-size:0.82rem;font-weight:700;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-upos{font-size:0.65rem;color:rgba(255,255,255,0.4);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-logout{
 display:flex;align-items:center;gap:8px;
 color:rgba(255,255,255,0.55);text-decoration:none;
 font-size:0.8rem;font-weight:600;padding:8px 10px;
 border-radius:8px;transition:all 0.18s;
}
.sb-logout:hover{background:rgba(220,38,38,0.2);color:#fca5a5;}

/* ── MAIN ── */
.main{
 margin-left:var(--sidebar-w);flex:1;
 display:flex;flex-direction:column;min-width:0;
 min-height:100vh;
}

/* ── TOPBAR ── */
.topbar{
 background:#fff;
 border-bottom:4px solid var(--navy);
 padding:0 24px;min-height:64px;
 display:flex;align-items:center;justify-content:space-between;
 position:sticky;top:0;z-index:100;
 box-shadow:0 2px 12px rgba(0,0,0,0.08);
 position:relative; /* needed for gov-strip */
 gap:10px;
}
.topbar-header-row{display:contents;}
.gov-strip{
 position:absolute;top:0;left:0;right:0;height:4px;
 background:linear-gradient(90deg,var(--navy-dark) 0%,var(--navy) 50%,var(--gold) 100%);
}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0;}
.ham-btn{
 background:none;border:none;font-size:1.15rem;
 color:var(--muted);cursor:pointer;
 padding:7px;border-radius:8px;
 display:none;flex-shrink:0;
}
.ham-btn:hover{background:#f3f4f6;}
.page-title{font-size:1rem;font-weight:800;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.page-sub{font-size:0.72rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.badge-lgu{
 background:#f0f7ff;color:var(--navy);
 font-size:0.71rem;font-weight:700;
 padding:5px 12px;border-radius:20px;
 border:1px solid #bfdbfe;white-space:nowrap;flex-shrink:0;
}
.topbar-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}

/* ── CONTENT ── */
.content{padding:22px 24px;flex:1;}

/* ── STATS ── */
.stat-grid{
 display:grid;
 grid-template-columns:repeat(5,1fr);
 gap:14px;margin-bottom:22px;
}
.stat-card{
 background:var(--card);border-radius:14px;
 padding:16px;
 box-shadow:0 2px 8px rgba(0,0,0,0.05);
 border:1px solid var(--border);
}
.stat-icon{
 width:40px;height:40px;border-radius:10px;
 display:flex;align-items:center;justify-content:center;
 font-size:1.1rem;margin-bottom:10px;
}
.stat-num{font-size:1.55rem;font-weight:800;line-height:1;color:var(--text);}
.stat-lbl{font-size:0.71rem;color:var(--muted);font-weight:600;margin-top:3px;}

/* ── TWO COL ── */
.two-col{display:grid;grid-template-columns:3fr 2fr;gap:18px;}

/* ── CARDS ── */
.card{
 background:var(--card);border-radius:14px;
 box-shadow:0 2px 10px rgba(0,0,0,0.06);
 border:1px solid var(--border);overflow:hidden;
}
.card-header{
 padding:14px 18px;border-bottom:1px solid var(--border);
 display:flex;align-items:center;justify-content:space-between;
 gap:10px;
}
.card-header h3{font-size:0.88rem;font-weight:700;min-width:0;}
.card-meta{font-size:0.72rem;color:var(--muted);white-space:nowrap;}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;border-collapse:collapse;min-width:500px;}
thead tr{background:#f8fafc;}
th{
 padding:10px 14px;font-size:0.68rem;font-weight:700;
 color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;
 border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;
}
td{
 padding:11px 14px;font-size:0.82rem;
 border-bottom:1px solid #f3f4f6;vertical-align:middle;
}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
.pill{
 display:inline-flex;align-items:center;gap:4px;
 padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;
 white-space:nowrap;
}
.pill-dangerous{background:#fef2f2;color:var(--sentri-danger);}
.pill-caution{background:#fffbeb;color:var(--sentri-caution);}
.pill-safe{background:#f0fdf4;color:var(--sentri-safe);}
.cat-chip{
 display:inline-flex;align-items:center;gap:5px;
 font-size:0.72rem;font-weight:600;color:var(--navy);
 background:#eff6ff;padding:3px 8px;border-radius:6px;white-space:nowrap;
}

/* ── BAR CHART ── */
.bar-row{
 display:flex;align-items:center;gap:10px;
 padding:9px 16px;border-bottom:1px solid #f3f4f6;
}
.bar-row:last-child{border-bottom:none;}
.bar-brgy{font-size:0.78rem;font-weight:600;width:120px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bar-wrap{flex:1;height:7px;background:#f1f5f9;border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;border-radius:4px;background:var(--navy);}
.bar-fill.danger{background:#dc2626;}
.bar-count{font-size:0.74rem;font-weight:700;color:var(--muted);width:28px;text-align:right;flex-shrink:0;}

/* ── EMPTY / PLACEHOLDER ── */
.empty{padding:40px 20px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;width:100%;color:var(--muted);}
.empty > i, .empty > svg, .empty > .svg-inline--fa{font-size:2rem;display:block;margin:0 auto 10px auto;opacity:0.3;}
.coming-soon{
 padding:60px 24px;text-align:center;
}
.coming-soon i{font-size:3rem;color:var(--navy);opacity:0.15;display:block;margin-bottom:16px;}
.coming-soon h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.coming-soon p{font-size:0.85rem;color:var(--muted);}

/* ── CONTACTS LIST ── */
.contact-row{
 display:flex;align-items:flex-start;gap:14px;
 padding:14px 18px;border-bottom:1px solid #f3f4f6;
}
.contact-row:last-child{border-bottom:none;}
.contact-icon{
 width:38px;height:38px;border-radius:10px;flex-shrink:0;
 display:flex;align-items:center;justify-content:center;font-size:1rem;
}
.contact-name{font-size:0.88rem;font-weight:700;margin-bottom:2px;}
.contact-meta{font-size:0.76rem;color:var(--muted);}
.contact-type{
 display:inline-block;font-size:0.65rem;font-weight:700;
 text-transform:uppercase;letter-spacing:0.8px;
 padding:2px 8px;border-radius:4px;margin-bottom:4px;
}

/* ── OVERLAY ── */
.overlay{
 display:none;position:fixed;inset:0;
 background:rgba(0,0,0,0.45);z-index:150;
}
.overlay.show{display:block;}

/* ── TOAST NOTIFICATIONS ── */
.toast-container{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{display:flex;align-items:center;gap:10px;padding:13px 18px;border-radius:12px;font-size:0.84rem;font-weight:600;box-shadow:0 8px 30px rgba(0,0,0,0.18);pointer-events:all;animation:toastIn 0.3s cubic-bezier(0.34,1.56,0.64,1);min-width:260px;max-width:360px;}
.toast-success{background:#166534;color:#fff;}
.toast-error{background:#991b1b;color:#fff;}
.toast-info{background:#1e40af;color:#fff;}
.toast-warning{background:var(--sentri-caution);color:#fff;}
.toast i{font-size:1rem;flex-shrink:0;}
@keyframes toastIn{from{opacity:0;transform:translateY(16px) scale(0.96);}to{opacity:1;transform:translateY(0) scale(1);}}

/* New-report popup alert — matches the Community portal action toast */
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

/* ── RESPONSIVE ── */
@media(max-width:1100px){
 .stat-grid{grid-template-columns:repeat(3,1fr);}
 .two-col{grid-template-columns:1fr;}
}
@media(max-width:860px){
 :root{--sidebar-w:256px;}
 .sidebar{width:100dvw;max-width:100dvw;left:0;right:0;transform:translate3d(-100%,0,0);}
 .sidebar.open{transform:translate3d(0,0,0);}
 .sb-close{display:flex;}
 .main{margin-left:0;}
 .ham-btn{display:flex;}
 .content{padding:14px;}
 .topbar{padding:10px 14px 8px !important;height:auto !important;min-height:56px !important;display:flex !important;flex-direction:column !important;align-items:stretch !important;gap:8px !important;}
 .topbar-header-row{display:flex !important;align-items:center !important;justify-content:space-between !important;gap:8px !important;width:100% !important;flex-shrink:0 !important;}
 .topbar-actions{display:flex !important;align-items:center !important;gap:6px !important;overflow-x:auto !important;padding-bottom:4px !important;flex-shrink:0 !important;min-height:32px !important;-webkit-overflow-scrolling:touch;scrollbar-width:none;width:100% !important;flex-wrap:nowrap !important;}
 .topbar-actions::-webkit-scrollbar{display:none;}
 .topbar-actions button{white-space:nowrap !important;flex-shrink:0 !important;font-size:0.72rem !important;padding:5px 10px !important;}
 .stat-grid{grid-template-columns:1fr 1fr;gap:10px;}
 .stat-card{padding:12px 14px;gap:10px;}
 .stat-icon{width:38px;height:38px;font-size:1rem;border-radius:10px;}
 .stat-num{font-size:1.3rem;}
}
@media(max-width:860px){body.sidebar-open .main{display:none;}body.sidebar-open .overlay{z-index:1190;}}
@media(max-width:700px){
 [style*="grid-template-columns:340px"]{grid-template-columns:1fr !important;}
 .map-stats{grid-template-columns:1fr 1fr;}
 .resp-stats{grid-template-columns:1fr 1fr;}
 #incidentMap{height:340px;}
}
@media(max-width:480px){
 .stat-grid{grid-template-columns:1fr 1fr;gap:8px;}
 .badge-lgu{font-size:0.65rem;padding:4px 8px;}
 .page-sub{display:none;}
 .bar-brgy{width:90px;}
 .map-stats{grid-template-columns:1fr 1fr;}
 .resp-stats{grid-template-columns:1fr 1fr;}
}
</style>
<script src="../assets/js/sentri-report-alerts.js?v=4"></script>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toastContainer"></div>

<aside class="sidebar" id="sidebar">
 <div class="sb-header">
 <div class="sb-brand">
 <div class="sb-seal"><i class="fas fa-landmark"></i></div>
 <div>
 <div class="sb-title">SenTri</div>
 <div class="sb-sub">LGU Portal</div>
 </div>
 </div>
 <button class="sb-close" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
 </div>

 <div class="sb-office">
 <p>Jurisdiction</p>
 <strong><?= htmlspecialchars($city ?: 'City / Municipality') ?></strong>
 </div>

 <nav class="sb-nav">
 <?php foreach($nav_items as $key => $item): ?>
 <?php if($key === 'contacts'): ?>
 <div class="sb-section">Operations</div>
 <?php elseif($key === 'profile'): ?>
 <div class="sb-section">Account</div>
 <?php endif; ?>
 <a href="javascript:void(0)" onclick="showTab('<?= $key ?>')" id="nav-<?= $key ?>" class="<?= $view===$key ? 'active' : '' ?>">
 <i class="fas <?= $item['icon'] ?>"></i>
 <span><?= $item['label'] ?></span>
 </a>
 <?php endforeach; ?>
 </nav>

 <div class="sb-footer">
 <div class="sb-user">
 <div class="sb-avatar"><?= strtoupper(substr($fname,0,1)) ?></div>
 <div style="min-width:0;">
 <div class="sb-uname"><?= htmlspecialchars($fname) ?></div>
 <div class="sb-upos"><?= htmlspecialchars($pos) ?></div>
 </div>
 </div>
 <a href="../gov/logout.php" class="sb-logout">
 <i class="fas fa-right-from-bracket"></i> Sign Out
 </a>
 </div>
</aside>

<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
   <div class="gov-strip"></div>
   <div class="topbar-header-row">
    <div class="topbar-left">
     <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
     <div style="min-width:0;">
      <div class="page-title" id="lguPageTitle"><?= htmlspecialchars($page_titles[$view] ?? 'LGU Portal') ?></div>
      <div class="page-sub"><?= htmlspecialchars($org) ?><?= $city ? ' - '.htmlspecialchars($city) : '' ?></div>
     </div>
    </div>
    <span class="badge-lgu"><i class="fas fa-landmark"></i>&nbsp; LGU Official</span>
   </div>
   <div class="topbar-actions">
    <button onclick="openAlertLevelModal()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"><i class="fas fa-triangle-exclamation"></i> DRRM Alert Level</button>
    <button onclick="openMutualAidModal()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:20px;font-size:0.72rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'"><i class="fas fa-handshake-angle"></i> ICS Mutual Aid Matrix</button>
   </div>
  </div>

 <div class="content">

 <div class="tab-panel <?= $view==='overview'?'active':'' ?>" id="tab-overview">
 <!-- ── OVERVIEW ── -->
 <div class="stat-grid">
 <div class="stat-card">
 <div class="stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-file-lines"></i></div>
 <div class="stat-num"><?= $total ?></div>
 <div class="stat-lbl">Total Active Reports</div>
 </div>
 <div class="stat-card">
 <div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div>
 <div class="stat-num"><?= $danger ?></div>
 <div class="stat-lbl">Dangerous</div>
 </div>
 <div class="stat-card">
 <div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div>
 <div class="stat-num"><?= $caution ?></div>
 <div class="stat-lbl">Caution</div>
 </div>
 <div class="stat-card">
 <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div>
 <div class="stat-num"><?= $safe ?></div>
 <div class="stat-lbl">Safe / Resolved</div>
 </div>
 <div class="stat-card">
 <div class="stat-icon" style="background:#f5f3ff;color:#7c3aed;"><i class="fas fa-address-book"></i></div>
 <div class="stat-num"><?= $contacts_count ?></div>
 <div class="stat-lbl">Active Contacts</div>
 </div>
 </div>

 <!-- ── Emergency Response SLA & Operations Telemetry ── -->
 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-bottom:22px;">
 <div style="background:linear-gradient(135deg,#0a3d62 0%,#1e40af 100%);color:#fff;border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 16px rgba(10,61,98,0.18);">
 <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#93c5fd;flex-shrink:0;">
 <i class="fas fa-bolt"></i>
 </div>
 <div>
 <div style="font-size:0.72rem;color:rgba(255,255,255,0.75);text-transform:uppercase;letter-spacing:0.8px;font-weight:700;">Avg. Dispatch SLA</div>
 <?php if($avg_resp_time > 0): ?>
 <div style="font-size:1.4rem;font-weight:900;line-height:1.2;"><?= $avg_resp_time ?> <span style="font-size:0.82rem;font-weight:600;color:rgba(255,255,255,0.85);">mins</span></div>
 <div style="font-size:0.7rem;color:#d1d5db;margin-top:2px;"><i class="fas fa-route"></i> Accepted &rarr; responded telemetry</div>
 </div>
 </div>
 </div>
 <div style="background:linear-gradient(135deg,#064e3b 0%,#059669 100%);color:#fff;border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 16px rgba(5,150,105,0.18);">
 <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#a7f3d0;flex-shrink:0;">
 <i class="fas fa-shield-halved"></i>
 </div>
 <div>
 <div style="font-size:0.72rem;color:rgba(255,255,255,0.75);text-transform:uppercase;letter-spacing:0.8px;font-weight:700;">Resolution Success Rate</div>
 <?php if($resolution_rate !== null): ?>
 <div style="font-size:1.4rem;font-weight:900;line-height:1.2;"><?= $resolution_rate ?>%</div>
 <?php else: ?>
 <div style="font-size:1.4rem;font-weight:900;line-height:1.2;color:rgba(255,255,255,0.6);">&mdash;</div>
 <?php endif; ?>
 <div style="font-size:0.7rem;color:#d1fae5;margin-top:2px;"><i class="fas fa-circle-check"></i> Safe reports &middot; Archived reports</div>
 </div>
 </div>
 <div style="background:linear-gradient(135deg,#7c2d12 0%,#d97706 100%);color:#fff;border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 16px rgba(217,119,6,0.18);">
 <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fde68a;flex-shrink:0;">
 <i class="fas fa-truck-fast"></i>
 </div>
 <div>
 <div style="font-size:0.72rem;color:rgba(255,255,255,0.75);text-transform:uppercase;letter-spacing:0.8px;font-weight:700;">Deployed Field Units</div>
 <div style="font-size:1.4rem;font-weight:900;line-height:1.2;"><?= $active_deployed_units ?> <span style="font-size:0.82rem;font-weight:600;color:rgba(255,255,255,0.85);">active</span></div>
 <div style="font-size:0.7rem;color:#fef3c7;margin-top:2px;"><i class="fas fa-tower-broadcast"></i> Units assigned to active incidents</div>
 </div>
 </div>
 </div>
 <!-- ── Response Operations Snapshot (all figures DB-derived) ── -->
 <div class="card" style="margin-bottom:18px;">
 <div class="card-header">
 <h3><i class="fas fa-users-gear" style="color:var(--navy);margin-right:6px;"></i>Response Operations Snapshot</h3>
 <span class="card-meta">Live counts from SenTri database</span>
 </div>
 <div style="padding:16px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px;">
 <div style="width:36px;height:36px;border-radius:8px;background:#f0f7ff;color:var(--navy);display:flex;align-items:center;justify-content:center;font-size:1.1rem;"><i class="fas fa-truck-medical"></i></div>
 <div><div style="font-size:0.7rem;color:var(--muted);font-weight:700;text-transform:uppercase;">Registered Responder Units</div><div style="font-size:1.05rem;font-weight:800;color:var(--text);"><?= (int)$resp_total_cnt ?></div></div>
 </div>
 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px;">
 <div style="width:36px;height:36px;border-radius:8px;background:#f0fdf4;color:#16a34a;display:flex;align-items:center;justify-content:center;font-size:1.1rem;"><i class="fas fa-user-check"></i></div>
 <div><div style="font-size:0.7rem;color:var(--muted);font-weight:700;text-transform:uppercase;">On Duty (Approved)</div><div style="font-size:1.05rem;font-weight:800;color:var(--text);"><?= (int)$resp_approved_cnt ?></div></div>
 </div>
 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px;">
 <div style="width:36px;height:36px;border-radius:8px;background:#fef2f2;color:var(--sentri-danger);display:flex;align-items:center;justify-content:center;font-size:1.1rem;"><i class="fas fa-circle-check"></i></div>
 <div><div style="font-size:0.7rem;color:var(--muted);font-weight:700;text-transform:uppercase;">Reports Resolved Today</div><div style="font-size:1.05rem;font-weight:800;color:var(--text);"><?= (int)$resolved_today ?></div></div>
 </div>
 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px;display:flex;align-items:center;gap:12px;">
 <div style="width:36px;height:36px;border-radius:8px;background:#fffbeb;color:var(--sentri-caution);display:flex;align-items:center;justify-content:center;font-size:1.1rem;"><i class="fas fa-truck-fast"></i></div>
 <div><div style="font-size:0.7rem;color:var(--muted);font-weight:700;text-transform:uppercase;">Units Currently Deployed</div><div style="font-size:1.05rem;font-weight:800;color:var(--text);"><?= $active_deployed_units ?></div></div>
 </div>
 </div>
 </div>

 <div class="card">
 <div class="card-header">
 <h3><i class="fas fa-triangle-exclamation" style="color:#dc2626;margin-right:6px;"></i>Active Dangerous Incidents</h3>
 <span class="card-meta"><?= count($danger_reports) ?> records</span>
 </div>
 <?php if(empty($danger_reports)): ?>
 <div class="empty"><i class="fas fa-shield-halved"></i><p>No reports match your view yet.</p></div>
 <?php else: ?>
 <div class="table-wrap">
 <table>
 <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Barangay</th><th>Date</th></tr></thead>
 <tbody>
 <?php foreach($danger_reports as $r):
 $r_title_js = htmlspecialchars(json_encode($r['title'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_cat_js = htmlspecialchars(json_encode($r['category'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_stat_js = htmlspecialchars(json_encode($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_loc_js = htmlspecialchars(json_encode($r['barangay'] ?? $r['city'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_user_js = htmlspecialchars(json_encode(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
 $r_date_js = htmlspecialchars(json_encode(date('M j, Y g:i A', strtotime($r['created_at']))), ENT_QUOTES, 'UTF-8');
 $r_desc_js = htmlspecialchars(json_encode($r['description'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_esc_js = !empty($r['escalated_to_lgu']) ? 1 : 0;
 $rep_brgy = trim((string)($r['barangay_name'] ?? ''));
 $rep_addr = trim((string)($r['resident_address'] ?? ''));
 $residence_full = $rep_brgy !== '' ? ('Brgy ' . $rep_brgy . ($rep_addr !== '' ? ', ' . $rep_addr : '')) : '';
 $r_res_js = htmlspecialchars(json_encode($residence_full), ENT_QUOTES, 'UTF-8');
 $responder_pending = !empty($r['assigned_to']) && empty($r['resolved_at']);
 ?>
 <tr style="cursor:pointer;" onclick="lguViewReport(<?= $r['id'] ?>, <?= $r_title_js ?>, <?= $r_cat_js ?>, <?= $r_stat_js ?>, <?= $r_loc_js ?>, <?= $r_user_js ?>, <?= $r_date_js ?>, <?= $r_desc_js ?>, <?= $r_esc_js ?>, <?= $r_res_js ?>, <?= $responder_pending ? 1 : 0 ?>)">
 <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
 <td style="font-weight:600;max-width:180px;"><?= htmlspecialchars(mb_strimwidth($r['title'],0,50,'…')) ?><?php if($responder_pending): ?><span class="responder-pending-chip" title="A first responder has been dispatched and must resolve this incident" style="margin-left:5px;display:inline-flex;align-items:center;gap:3px;font-size:0.64rem;font-weight:700;color:#92400e;background:#fffbeb;padding:1px 6px;border-radius:4px;border:1px solid #fde68a;white-space:nowrap;vertical-align:middle;"><i class="fas fa-hourglass-half"></i> Responder pending</span><?php endif; ?></td>
 <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
 <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city']) ?></td>
 <td style="font-size:0.74rem;color:var(--muted);"><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>
 </div>

 <div class="card">
 <div class="card-header">
 <h3><i class="fas fa-house-flag" style="color:#166534;margin-right:6px;"></i>Reports by Barangay</h3>
 </div>
 <?php if(empty($brgy_stats)): ?>
 <div class="empty"><i class="fas fa-chart-bar"></i><p>No reports match your view yet.</p></div>
 <?php else:
 $max = max(array_column($brgy_stats,'c')) ?: 1;
 foreach($brgy_stats as $b): ?>
 <a href="lgu.php?view=all_reports&barangay=<?= rawurlencode($b['b']) ?>" title="View reports from <?= htmlspecialchars($b['b']) ?>" style="display:block;text-decoration:none;color:inherit;">
 <div class="bar-row" style="cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
 <div class="bar-brgy" title="<?= htmlspecialchars($b['b']) ?>"><?= htmlspecialchars($b['b']) ?></div>
 <div class="bar-wrap">
 <div class="bar-fill <?= $b['d']>0 ? 'danger' : '' ?>" style="width:<?= round($b['c']/$max*100) ?>%"></div>
 </div>
 <div class="bar-count"><?= $b['c'] ?></div>
 </div>
 </a>
 <?php endforeach; endif; ?>
 </div>
 </div>

 </div>

  <div class="tab-panel <?= $view==='all_reports'?'active':'' ?>" id="tab-all_reports">
 <!-- ── ALL REPORTS ── -->
 <div class="card">
 <div class="card-header">
 <h3><i class="fas fa-file-lines" style="color:var(--navy);margin-right:6px;"></i>All Incident Reports</h3>
 <span class="card-meta"><?= count($all_reports) ?> records</span>
 </div>
 <?php if(empty($all_reports)): ?>
 <div class="empty"><i class="fas fa-folder-open"></i><p>No reports match your view yet.</p></div>
 <?php else: ?>
 <div class="reports-filter-bar" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--border);background:#f8fafc;">
 <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
 <?php if($brgy_filter !== ''): ?>
 <span style="display:inline-flex;align-items:center;gap:6px;background:#f0f7ff;color:var(--navy);border:1px solid #bfdbfe;padding:5px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;">
 <i class="fas fa-location-dot"></i> Barangay: <?= htmlspecialchars($brgy_filter) ?>
 <a href="lgu.php?view=all_reports" title="Clear barangay filter" style="color:var(--muted);text-decoration:none;"><i class="fas fa-xmark"></i></a>
 </span>
 <?php endif; ?>
 <button class="filter-btn active-all" onclick="filterTableReports('all',this)">All Active</button>
 <button class="filter-btn" onclick="filterTableReports('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
 <button class="filter-btn" onclick="filterTableReports('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
 <button class="filter-btn" onclick="filterTableReports('safe',this)"><i class="fas fa-circle" style="color:#16a34a;font-size:0.6rem;"></i> Safe / Archived</button>
 </div>
 <div style="display:flex;align-items:center;gap:8px;">
 <div style="position:relative;">
 <i class="fas fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:0.8rem;"></i>
 <input type="text" id="lguReportSearch" placeholder="Search reports or barangay..." oninput="searchTableReports(this.value)" style="padding:6px 12px 6px 28px;border:1.5px solid var(--border);border-radius:8px;font-size:0.8rem;outline:none;font-family:'Inter',sans-serif;background:#fff;width:200px;">
 </div>
 <a href="../api/reports.php?action=export&type=reports&format=csv" class="filter-btn" style="text-decoration:none;display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-weight:700;"><i class="fas fa-file-csv"></i> Export CSV</a>
 </div>
 </div>
 <div class="table-wrap">
 <table>
 <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Status</th><th>Barangay</th><th>Reported By</th><th>Date</th><th>Actions</th></tr></thead>
 <tbody>
 <?php foreach($all_reports as $r):
 $r_title_js = htmlspecialchars(json_encode($r['title'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_cat_js = htmlspecialchars(json_encode($r['category'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_stat_js = htmlspecialchars(json_encode($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_loc_js = htmlspecialchars(json_encode($r['barangay'] ?? $r['city'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_user_js = htmlspecialchars(json_encode(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
 $r_date_js = htmlspecialchars(json_encode(date('M j, Y g:i A', strtotime($r['created_at']))), ENT_QUOTES, 'UTF-8');
 $r_desc_js = htmlspecialchars(json_encode($r['description'] ?? ''), ENT_QUOTES, 'UTF-8');
 $r_esc_js = !empty($r['escalated_to_lgu']) ? 1 : 0;
 $rep_brgy = trim((string)($r['barangay_name'] ?? ''));
 $rep_addr = trim((string)($r['resident_address'] ?? ''));
 $residence_full = $rep_brgy !== '' ? ('Brgy ' . $rep_brgy . ($rep_addr !== '' ? ', ' . $rep_addr : '')) : '';
 $r_res_js = htmlspecialchars(json_encode($residence_full), ENT_QUOTES, 'UTF-8');
 $responder_pending = !empty($r['assigned_to']) && empty($r['resolved_at']);
 ?>
 <tr data-status="<?= htmlspecialchars($r['status']) ?>" data-archived="<?= (int)($r['is_archived'] ?? 0) ?>">
 <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
 <td style="font-weight:600;max-width:200px;"><?= htmlspecialchars(mb_strimwidth($r['title'],0,55,'…')) ?><?php if($responder_pending): ?><span class="responder-pending-chip" title="A first responder has been dispatched and must resolve this incident" style="margin-left:5px;display:inline-flex;align-items:center;gap:3px;font-size:0.64rem;font-weight:700;color:#92400e;background:#fffbeb;padding:1px 6px;border-radius:4px;border:1px solid #fde68a;white-space:nowrap;vertical-align:middle;"><i class="fas fa-hourglass-half"></i> Responder pending</span><?php endif; ?></td>
 <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
 <td><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
 <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city']) ?></td>
 <td style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?><?php if($residence_full !== ''): ?><div style="font-size:0.7rem;color:#94a3b8;margin-top:2px;"><i class="fas fa-house-user" style="margin-right:3px;"></i><?= htmlspecialchars($residence_full) ?></div><?php endif; ?></td>
 <td style="font-size:0.74rem;color:var(--muted);white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></td>
 <td>
 <button onclick="event.stopPropagation();lguViewReport(<?= $r['id'] ?>, <?= $r_title_js ?>, <?= $r_cat_js ?>, <?= $r_stat_js ?>, <?= $r_loc_js ?>, <?= $r_user_js ?>, <?= $r_date_js ?>, <?= $r_desc_js ?>, <?= $r_esc_js ?>, <?= $r_res_js ?>, <?= $responder_pending ? 1 : 0 ?>)" style="background:#eff6ff;color:#2563eb;border:none;padding:5px 10px;border-radius:7px;font-size:0.76rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;"><i class="fas fa-pen-to-square"></i> Manage</button>
 </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>
 <?php endif; ?>
 </div>

 </div>

  <div class="tab-panel <?= $view==='contacts'?'active':'' ?>" id="tab-contacts">
 <!-- ── CONTACTS ── -->
 <?php
 $type_colors = ['hospital'=>['#ecfdf5','#059669'],'fire'=>['#fef2f2','#dc2626'],'police'=>['#eff6ff','#2563eb'],'barangay'=>['#f0fdf4','#166534'],'municipal'=>['#f0f7ff','#0a3d62'],'other'=>['#f5f3ff','#7c3aed']];
 $type_icons = ['hospital'=>'fa-hospital','fire'=>'fa-fire-extinguisher','police'=>'fa-shield','barangay'=>'fa-house-flag','municipal'=>'fa-landmark','other'=>'fa-phone'];
 ?>
 <div class="card">
 <div class="card-header">
 <h3><i class="fas fa-address-book" style="color:var(--navy);margin-right:6px;"></i>Emergency Contacts</h3>
 <div style="display:flex;align-items:center;gap:10px;">
 <span class="card-meta"><?= count($contacts) ?> contacts</span>
 <button onclick="openLguAddContact()" style="background:var(--navy);color:#fff;border:none;padding:6px 13px;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;"><i class="fas fa-plus"></i> Add</button>
 </div>
 </div>
 <?php if(empty($contacts)): ?>
 <div class="empty"><i class="fas fa-address-book"></i>No emergency contacts found.</div>
 <?php else: foreach($contacts as $c):
 $tc = $type_colors[$c['type']] ?? $type_colors['other'];
 $ti = $type_icons[$c['type']] ?? 'fa-phone';
 ?>
 <div class="contact-row">
 <div class="contact-icon" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><i class="fas <?= $ti ?>"></i></div>
 <div style="flex:1;min-width:0;">
 <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
 <div class="contact-meta">
 <?php if($c['contact_number']): ?><span><i class="fas fa-phone" style="margin-right:4px;"></i><?= htmlspecialchars($c['contact_number']) ?></span>&nbsp;&nbsp;<?php endif; ?>
 <?php if($c['contact_email']): ?><span><i class="fas fa-envelope" style="margin-right:4px;"></i><?= htmlspecialchars($c['contact_email']) ?></span>&nbsp;&nbsp;<?php endif; ?>
 <?php if($c['city']): ?><span><i class="fas fa-location-dot" style="margin-right:4px;"></i><?= htmlspecialchars($c['barangay'] ? $c['barangay'].', '.$c['city'] : $c['city']) ?></span><?php endif; ?>
 </div>
 </div>
 <span class="contact-type" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><?= strtoupper($c['type']) ?></span>
 <button onclick="lguDeleteContact(<?= $c['id'] ?>,'<?= addslashes(htmlspecialchars($c['name'])) ?>')" style="flex-shrink:0;background:#fef2f2;color:#dc2626;border:1.5px solid #fecaca;padding:4px 10px;border-radius:7px;font-size:0.74rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;"><i class="fas fa-trash"></i></button>
 </div>
 <?php endforeach; endif; ?>
 </div>

 </div>

  <div class="tab-panel <?= $view==='barangays'?'active':'' ?>" id="tab-barangays">
 <!-- ── BARANGAY SUMMARIES ──
 Normalization cross-check: the official perimeters in assets/geo/imus-barangays.geojson use
 designated names (e.g. "Anabu I-A", "Bayan Luma I", "Mariano Espeleta I"). Community reports
 historically store legacy/free-text barangay labels in r.barangay. The drill-down query
 (?view=all_reports&barangay=…) matches r.barangay EXACTLY (case-sensitive, no normalization),
 so summary labels are echoed verbatim from the DB — counts and drill-down stay consistent.
 Any GeoJSON/DB name mismatches (e.g. missing "Brgy." prefix, differing numbering) are surfaced
 on the Incident Map by official perimeter name in tooltips/filter, not rewritten here. -->
 <div class="card">
 <div class="card-header">
 <h3><i class="fas fa-house-flag" style="color:#166534;margin-right:6px;"></i>Barangay Incident Summary</h3>
 </div>
 <div style="padding:10px 18px;border-bottom:1px solid var(--border);font-size:0.74rem;color:var(--muted);display:flex;align-items:center;gap:7px;">
 <i class="fas fa-circle-info" style="color:#2563eb;"></i> Labels are shown exactly as reported in the database (legacy names preserved). Official Imus perimeter maps are on the Incident Map tab.
 </div>
 <?php if(empty($brgy_stats)): ?>
 <div class="empty"><i class="fas fa-chart-bar"></i><p>No reports match your view yet.</p></div>
 <?php else:
 $max = max(array_column($brgy_stats,'c')) ?: 1;
 foreach($brgy_stats as $b): ?>
 <a href="lgu.php?view=all_reports&barangay=<?= rawurlencode($b['b']) ?>" title="View reports from <?= htmlspecialchars($b['b']) ?>" style="display:block;text-decoration:none;color:inherit;">
 <div class="bar-row" style="padding:12px 18px;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
 <div class="bar-brgy" style="width:160px;font-size:0.84rem;" title="<?= htmlspecialchars($b['b']) ?>"><?= htmlspecialchars($b['b']) ?></div>
 <div class="bar-wrap"><div class="bar-fill <?= $b['d']>0 ? 'danger' : '' ?>" style="width:<?= round($b['c']/$max*100) ?>%"></div></div>
 <div class="bar-count" style="width:40px;font-size:0.8rem;"><?= $b['c'] ?> reports</div>
 <?php if($b['d']>0): ?>
 <span class="pill pill-dangerous" style="margin-left:8px;"><?= $b['d'] ?> dangerous</span>
 <?php endif; ?>
 </div>
 </a>
 <?php endforeach; endif; ?>
 </div>

 </div>

  <div class="tab-panel <?= $view==='map'?'active':'' ?>" id="tab-map">
 <!-- ── INCIDENT MAP ── -->
 <link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css">
 <script src="../assets/vendor/leaflet/leaflet.js"></script>
 <script src="../assets/js/sentri-geo.js"></script>
 <style>
 .map-wrap{position:relative;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);border:1px solid var(--border);}
 #incidentMap{height:520px;width:100%;background:#e8f0f8;}
 .map-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:14px 18px;background:#fff;border-bottom:1px solid var(--border);border-radius:14px 14px 0 0;}
 .map-controls h3{font-size:0.88rem;font-weight:700;margin-right:4px;}
 .filter-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.76rem;font-weight:700;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
 .filter-btn.active-all{background:var(--navy);color:#fff;border-color:var(--navy);}
 .filter-btn.active-dangerous{background:#dc2626;color:#fff;border-color:#dc2626;}
 .filter-btn.active-caution{background:#d97706;color:#fff;border-color:#d97706;}
 .filter-btn.active-safe{background:#16a34a;color:#fff;border-color:#16a34a;}
 .map-legend{position:absolute;bottom:18px;right:14px;background:rgba(255,255,255,0.95);border-radius:10px;padding:11px 14px;font-size:0.76rem;box-shadow:0 2px 12px rgba(0,0,0,0.15);z-index:400;border:1px solid var(--border);}
 .legend-row{display:flex;align-items:center;gap:7px;margin-bottom:5px;font-weight:600;}
 .legend-row:last-child{margin-bottom:0;}
 .legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
 .map-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px;}
 .map-stat{background:#fff;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);}
 .map-stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
 .map-stat-num{font-size:1.3rem;font-weight:800;line-height:1;}
 .map-stat-lbl{font-size:0.68rem;color:var(--muted);font-weight:600;margin-top:2px;}
 .no-coords-notice{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:11px 15px;font-size:0.82rem;color:#92400e;margin-bottom:14px;display:flex;align-items:center;gap:9px;}
 </style>
 <?php
 $mapped = count($map_reports);
 $unmapped = $map_total - $mapped;
 $m_danger = count(array_filter($map_reports, function($r){ return $r['status']==='dangerous'; }));
 $m_caution= count(array_filter($map_reports, function($r){ return $r['status']==='caution'; }));
 $m_safe = count(array_filter($map_reports, function($r){ return $r['status']==='safe'; }));
 ?>
 <?php if($unmapped > 0): ?>
 <div class="no-coords-notice"><i class="fas fa-circle-info"></i><?= $unmapped ?> report<?= $unmapped>1?'s':'' ?> without GPS coordinates are not shown on the map.</div>
 <?php endif; ?>
 <div class="map-stats">
 <div class="map-stat"><div class="map-stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-map-pin"></i></div><div><div class="map-stat-num"><?= $mapped ?></div><div class="map-stat-lbl">Mapped Reports</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_danger ?></div><div class="map-stat-lbl">Dangerous</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_caution ?></div><div class="map-stat-lbl">Caution</div></div></div>
 <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="map-stat-num"><?= $m_safe ?></div><div class="map-stat-lbl">Safe / Resolved</div></div></div>
 </div>
 <div style="margin-top:14px;" class="map-wrap">
 <div class="map-controls">
 <h3><i class="fas fa-map-location-dot" style="color:var(--navy);margin-right:6px;"></i>Incident Map</h3>
 <button class="filter-btn active-all" onclick="filterMap('all',this)">All Active</button>
 <button class="filter-btn" onclick="filterMap('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
 <button class="filter-btn" onclick="filterMap('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
 <button class="filter-btn" onclick="filterMap('safe',this)"><i class="fas fa-circle" style="color:#16a34a;font-size:0.6rem;"></i> Safe / Archived</button>
 <button class="filter-btn" id="toggleHazardRingsLgu" onclick="toggleLguHazardBuffer(this)" title="Toggle 250m/150m safety hazard buffer rings"><i class="fas fa-bullseye" style="color:#ef4444;font-size:0.7rem;"></i> Hazard Buffers</button>
 <button class="filter-btn" id="toggleHeatmapLgu" onclick="toggleLguHeatmapDensity(this)" title="Toggle incident intensity cluster overlay"><i class="fas fa-fire-flame-curved" style="color:#f59e0b;font-size:0.7rem;"></i> Density Heatmap</button>
 <div style="position:relative;margin-left:auto;">
 <input type="text" id="lguMapSearchInput" placeholder="Filter map pins..." oninput="filterLguMapBySearch(this.value)" style="padding:4px 8px 4px 22px;border:1.5px solid var(--border);border-radius:6px;font-size:0.74rem;outline:none;background:#fff;width:140px;">
 <i class="fas fa-magnifying-glass" style="position:absolute;left:7px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:0.7rem;"></i>
 </div>
 <div style="font-size:0.75rem;color:var(--muted);">OpenStreetMap</div>
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
 var reports = <?= json_encode(array_map(function($r){ return ['id'=>(int)$r['id'],'title'=>htmlspecialchars($r['title'],ENT_QUOTES),'category'=>$r['category'],'status'=>$r['status'],'is_archived'=>(int)($r['is_archived']??0),'barangay'=>htmlspecialchars($r['barangay']??$r['city']??'',ENT_QUOTES),'lat'=>(float)$r['latitude'],'lng'=>(float)$r['longitude'],'reporter'=>htmlspecialchars(trim(($r['first_name']??'').' '.($r['last_name']??'')),ENT_QUOTES),'reporter_brgy'=>htmlspecialchars(trim((string)($r['barangay_name']??'')),ENT_QUOTES),'reporter_address'=>htmlspecialchars(trim((string)($r['resident_address']??'')),ENT_QUOTES),'date'=>date('M j, Y g:i A',strtotime($r['created_at'])),'desc'=>htmlspecialchars($r['description']??'',ENT_QUOTES)]; }, $map_reports)) ?>;
 var markerColors = {dangerous:'#dc2626',caution:'#d97706',safe:'#16a34a'};
 var catLabels = {crime:'Crime',accident:'Accident',flooding:'Flooding',fire:'Fire',health:'Health',infrastructure:'Infrastructure',other:'Other'};

 function makeIcon(color){
 return L.divIcon({
 className:'',
 html:'<div style="width:14px;height:14px;border-radius:50%;background:'+color+';border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.35);"></div>',
 iconSize:[14,14],iconAnchor:[7,7]
 });
 }

 var map = L.map('incidentMap');
 L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(map);

 var allMarkers = [];
 var lguHazardCircles = [];
 var showLguHazardRings = false;
 var currentFilter = 'all';
 var currentLguQuery = '';

 reports.forEach(function(r){
 var color = markerColors[r.status] || '#888';
 var m = L.marker([r.lat,r.lng],{icon:makeIcon(color)});
 m.reportData = r;
 m.bindPopup(
 '<div style="min-width:200px;font-family:Inter,sans-serif;">' +
 '<div style="font-weight:800;font-size:0.9rem;margin-bottom:6px;">'+r.title+'</div>' +
 '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">' +
 '<span style="background:'+(r.status==='dangerous'?'#fef2f2':r.status==='caution'?'#fffbeb':'#f0fdf4')+';color:'+(r.status==='dangerous'?'var(--sentri-danger)':r.status==='caution'?'var(--sentri-caution)':'var(--sentri-safe)')+';padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:700;">'+(r.status.charAt(0).toUpperCase()+r.status.slice(1))+'</span>' +
 '<span style="background:#eff6ff;color:#1e40af;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;">'+(catLabels[r.category]||r.category)+'</span></div>' +
 (r.barangay?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Location:</b> '+r.barangay+'</div>':'')+
 (r.reporter?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Reported by:</b> '+r.reporter+'</div>':'')+
 (r.reporter_brgy?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Resident of:</b> Brgy '+r.reporter_brgy+(r.reporter_address?', '+r.reporter_address:'')+'</div>':'')+
 (r.desc?'<div style="font-size:0.78rem;color:#374151;margin-top:6px;">'+r.desc.substring(0,120)+(r.desc.length>120?'…':'')+'</div>':'')+
 '<div style="font-size:0.72rem;color:#9ca3af;margin-top:7px;">'+r.date+' &middot; Report #'+r.id+'</div></div>',
 {maxWidth:260}
 );
 m.addTo(map);
 allMarkers.push(m);
 });

 if(allMarkers.length > 0){
 var group = L.featureGroup(allMarkers);
 map.fitBounds(group.getBounds().pad(0.15));
 } else {
 map.setView([14.4240,120.9215],12);
 }

 // ── Official barangay perimeters (Imus City GeoJSON, 95 villages) ──
 // Thin outlines under the pins; hover raises highlight; clicking a polygon
 // filters All Incident Reports to that barangay via the existing drill-down
 // GET param ?view=all_reports&barangay=<official name>.
 if(typeof SentriGeo !== 'undefined' && SentriGeo.addBarangayOverlay){
 SentriGeo.addBarangayOverlay(map, {
 onEach: function(feature, layer){
 var nm = (feature.properties && (feature.properties.name || feature.properties.ref)) || 'Barangay';
 layer.bindTooltip('<div style="font-weight:700;font-size:0.78rem;">'+nm+'<br><span style="font-weight:400;font-size:0.7rem;color:#6b7280;">Brgy boundary (official perimeter) &middot; click to filter reports</span></div>',{sticky:true});
 layer.on('mouseover', function(){
 layer.setStyle({weight:3, color:'#1d4ed8', fillOpacity:0.15});
 if(layer.bringToFront) layer.bringToFront();
 });
 layer.on('mouseout', function(){
 layer.setStyle({weight:2, color:'#2563eb', fillOpacity:0.05});
 });
 layer.on('click', function(){
 showToast('Filtering: '+nm, 'info');
 setTimeout(function(){ window.location = 'lgu.php?view=all_reports&barangay=' + encodeURIComponent(nm); }, 550);
 });
 }
 }).then(function(brgyLayer){
 if(brgyLayer){ try{ map.fitBounds(brgyLayer.getBounds()); }catch(e){} }
 }).catch(function(){ /* boundary data unavailable — pins still render */ });
 }

 function clearLguHazardCircles() {
 lguHazardCircles.forEach(function(c){ map.removeLayer(c); });
 lguHazardCircles = [];
 }

 function drawLguHazardCircles() {
 clearLguHazardCircles();
 allMarkers.forEach(function(m){
 if(map.hasLayer(m) && m.reportData){
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
 circle.addTo(map);
 lguHazardCircles.push(circle);
 }
 }
 });
 }

 function toggleLguHazardBuffer(btn){
 showLguHazardRings = !showLguHazardRings;
 btn.classList.toggle('active-all', showLguHazardRings);
 if(showLguHazardRings){
 btn.style.background = '#fef2f2';
 btn.style.color = '#dc2626';
 btn.style.borderColor = '#fca5a5';
 drawLguHazardCircles();
 } else {
 btn.style.background = '';
 btn.style.color = '';
 btn.style.borderColor = '';
 clearLguHazardCircles();
 }
 }

 var lguHeatmapCircles = [];
 var showLguHeatmap = false;

 function clearLguHeatmap() {
 lguHeatmapCircles.forEach(function(c){ map.removeLayer(c); });
 lguHeatmapCircles = [];
 }

 function drawLguHeatmap() {
 clearLguHeatmap();
 allMarkers.forEach(function(m){
 if(map.hasLayer(m) && m.reportData){
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
 heatCircle.addTo(map);
 lguHeatmapCircles.push(heatCircle);
 }
 });
 }

 function toggleLguHeatmapDensity(btn){
 showLguHeatmap = !showLguHeatmap;
 btn.classList.toggle('active-all', showLguHeatmap);
 if(showLguHeatmap){
 btn.style.background = '#fffbeb';
 btn.style.color = '#b45309';
 btn.style.borderColor = '#fde68a';
 drawLguHeatmap();
 } else {
 btn.style.background = '';
 btn.style.color = '';
 btn.style.borderColor = '';
 clearLguHeatmap();
 }
 }

 function updateLguMapFilter(){
 allMarkers.forEach(function(m){
 var r = m.reportData;
 var isArchivedOrSafe = (r.status === 'safe' || r.is_archived === 1);
 var matchStatus = false;
 if(currentFilter === 'all'){
  matchStatus = !isArchivedOrSafe;
 } else if(currentFilter === 'safe' || currentFilter === 'archived'){
  matchStatus = isArchivedOrSafe;
 } else {
  matchStatus = (r.status === currentFilter && !r.is_archived);
 }
 var matchQuery = true;
 if(currentLguQuery){
 var text = (r.title + ' ' + (r.barangay||'') + ' ' + (r.desc||'')).toLowerCase();
 matchQuery = text.indexOf(currentLguQuery) !== -1;
 }
 var show = matchStatus && matchQuery;
 if(show){ if(!map.hasLayer(m)) map.addTo(map); }
 else { if(map.hasLayer(m)) map.removeLayer(m); }
 });
 if(showLguHazardRings) drawLguHazardCircles();
 if(showLguHeatmap) drawLguHeatmap();
 }

 function filterMap(status, btn){
 currentFilter = status;
 document.querySelectorAll('.map-controls .filter-btn:not(#toggleHazardRingsLgu):not(#toggleHeatmapLgu)').forEach(function(b){ b.className='filter-btn'; });
 btn.classList.add('active-'+status);
 updateLguMapFilter();
 }

 function filterLguMapBySearch(query){
 currentLguQuery = (query||'').trim().toLowerCase();
 updateLguMapFilter();
 }
 </script>

 </div>

  <div class="tab-panel <?= $view==='profile'?'active':'' ?>" id="tab-profile">
 <!-- ── MY PROFILE ── -->
 <style>
 .profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
 .form-group{margin-bottom:0;}
 .form-group label{display:block;font-size:0.78rem;font-weight:700;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;}
 .form-group input{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.9rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;color:var(--text);transition:all 0.18s;}
 .form-group input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,0.08);}
 .form-group input[readonly]{background:#f3f4f6;color:var(--muted);cursor:not-allowed;}
 .section-divider{margin:22px 0 18px;padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
 .section-divider h4{font-size:0.82rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:1px;}
 .btn-save{background:linear-gradient(135deg,#1a5276,var(--navy-dark));color:#fff;border:none;padding:11px 28px;border-radius:10px;font-size:0.9rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.2s;display:inline-flex;align-items:center;gap:8px;}
 .btn-save:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(10,61,98,0.35);}
 .profile-toast{padding:11px 16px;border-radius:9px;font-size:0.84rem;font-weight:600;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
 .profile-toast.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
 .profile-toast.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
 .avatar-circle{width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--navy-light));display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:800;color:var(--gold);flex-shrink:0;border:3px solid rgba(243,156,18,0.4);}
 </style>
 <?php
 $p_email = $prof['email'] ?? '';
 $p_phone_v = '';
 $phone_stmt = $conn->prepare("SELECT phone_number FROM users WHERE id=? LIMIT 1");
 $phone_stmt->bind_param("i",$uid); $phone_stmt->execute();
 $phone_stmt->bind_result($p_phone_v); $phone_stmt->fetch(); $phone_stmt->close();
 list($msg_type,$msg_text) = $profile_msg ? explode(':',$profile_msg,2) : ['',''];
 ?>
 <div style="display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start;">
 <!-- Profile Card -->
 <div class="card" style="padding:24px;text-align:center;">
 <div class="avatar-circle" style="margin:0 auto 14px;"><?= strtoupper(substr($fname,0,1)) ?></div>
 <div style="font-size:1.05rem;font-weight:800;"><?= htmlspecialchars($fname) ?></div>
 <div style="font-size:0.8rem;color:var(--muted);margin-top:3px;"><?= htmlspecialchars($pos ?: 'LGU Official') ?></div>
 <div style="margin-top:10px;padding:8px 14px;background:#f0f7ff;border-radius:8px;font-size:0.78rem;color:var(--navy);font-weight:600;"><i class="fas fa-landmark" style="margin-right:5px;"></i><?= htmlspecialchars($org ?: 'LGU Office') ?></div>
 <?php if($city): ?><div style="margin-top:8px;font-size:0.78rem;color:var(--muted);"><i class="fas fa-location-dot" style="margin-right:4px;"></i><?= htmlspecialchars($city) ?></div><?php endif; ?>
 <div style="margin-top:12px;font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($p_email) ?></div>
 <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
 <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;text-align:center;">
 <div style="background:#f8fafc;border-radius:8px;padding:10px;"><div style="font-size:1.2rem;font-weight:800;color:var(--navy);"><?= $total ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Reports</div></div>
 <div style="background:#fef2f2;border-radius:8px;padding:10px;"><div style="font-size:1.2rem;font-weight:800;color:#dc2626;"><?= $danger ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Dangerous</div></div>
 </div>
 </div>
 </div>
 <!-- Edit Form -->
 <div class="card" style="padding:24px;">
 <?php if($msg_text): ?>
 <div class="profile-toast <?= $msg_type ?>"><i class="fas fa-<?= $msg_type==='success'?'circle-check':'circle-xmark' ?>"></i><?= htmlspecialchars($msg_text) ?></div>
 <?php endif; ?>
 <form method="POST" action="lgu.php?view=profile">
 <input type="hidden" name="save_profile" value="1">
 <div class="section-divider"><h4><i class="fas fa-user" style="margin-right:6px;"></i>Personal Information</h4></div>
 <div class="profile-grid" style="margin-bottom:16px;">
 <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?= htmlspecialchars($fname) ?>" required></div>
 <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?= htmlspecialchars($prof['last_name'] ?? '') ?>" required></div>
 </div>
 <div class="profile-grid" style="margin-bottom:16px;">
 <div class="form-group"><label>Email Address</label><input type="email" value="<?= htmlspecialchars($p_email) ?>" readonly></div>
 <div class="form-group"><label>Phone Number</label><input type="tel" name="phone" value="<?= htmlspecialchars($p_phone_v) ?>" placeholder="+63 9XX XXX XXXX"></div>
 </div>
 <div class="section-divider"><h4><i class="fas fa-landmark" style="margin-right:6px;"></i>Official Information</h4></div>
 <div class="profile-grid" style="margin-bottom:16px;">
 <div class="form-group"><label>Office / Agency Name</label><input type="text" name="org_name" value="<?= htmlspecialchars($org) ?>" placeholder="e.g. Imus City DRRMO"></div>
 <div class="form-group"><label>Position / Title</label><input type="text" name="position" value="<?= htmlspecialchars($pos) ?>" placeholder="e.g. DRRMO Head"></div>
 </div>
 <div class="form-group" style="margin-bottom:16px;"><label>City / Municipality</label><input type="text" name="municipality" value="<?= htmlspecialchars($city) ?>" placeholder="e.g. Imus" style="max-width:320px;"></div>
 <div class="section-divider"><h4><i class="fas fa-lock" style="margin-right:6px;"></i>Change Password <span style="font-weight:400;color:var(--muted);text-transform:none;letter-spacing:0;">(leave blank to keep current)</span></h4></div>
 <div class="profile-grid" style="margin-bottom:22px;">
 <div class="form-group"><label>New Password</label><input type="password" name="new_password" placeholder="Min. 8 characters" autocomplete="new-password"></div>
 <div class="form-group"><label>Confirm Password</label><input type="password" name="confirm_password" placeholder="Re-enter new password" autocomplete="new-password"></div>
 </div>
 <button type="submit" class="btn-save"><i class="fas fa-floppy-disk"></i> Save Changes</button>
 </form>
 </div>
 </div>

 </div>

  <div class="tab-panel <?= $view==='responders'?'active':'' ?>" id="tab-responders">
 <!-- ── RESPONDER UNITS ── -->
 <style>
 .rtype-badge{display:inline-block;padding:2px 10px;border-radius:4px;font-size:0.68rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;}
 .rtype-bfp{background:#fef2f2;color:#b91c1c;}
 .rtype-pnp{background:#eff6ff;color:#1d4ed8;}
 .rtype-ems{background:#f0fdf4;color:#15803d;}
 .rtype-drrmo,.rtype-mdrrmo{background:#fffbeb;color:#b45309;}
 .rtype-hospital{background:#ecfdf5;color:#0e7490;}
 .rtype-other{background:#f5f3ff;color:#6d28d9;}
 .resp-row{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;transition:background 0.15s;}
 .resp-row:last-child{border-bottom:none;}
 .resp-row:hover{background:#fafafa;}
 .resp-avatar{width:40px;height:40px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:0.9rem;font-weight:800;}
 .resp-name{font-size:0.88rem;font-weight:700;}
 .resp-meta{font-size:0.76rem;color:var(--muted);margin-top:2px;}
 .resp-actions{display:flex;gap:6px;margin-left:auto;flex-shrink:0;}
 .btn-approve{background:#16a34a;color:#fff;border:none;padding:6px 13px;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:background 0.18s;}
 .btn-approve:hover{background:#166534;}
 .btn-reject{background:#fef2f2;color:#dc2626;border:1.5px solid #fecaca;padding:5px 12px;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.18s;}
 .btn-reject:hover{background:#fee2e2;}
 .active-badge{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:5px 11px;border-radius:7px;font-size:0.73rem;font-weight:700;}
 .pending-badge{background:#fffbeb;color:#d97706;border:1px solid #fde68a;padding:3px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;}
 .resp-filter-bar{display:flex;gap:6px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--border);}
 .rf-btn{padding:5px 12px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.75rem;font-weight:600;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
 .rf-btn.active{background:var(--navy);color:#fff;border-color:var(--navy);}
 .resp-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
 .rs-card{background:#fff;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:10px;border:1px solid var(--border);box-shadow:0 2px 6px rgba(0,0,0,0.04);}
 .rs-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
 </style>
 <?php
 $r_types = ['bfp','pnp','ems','drrmo','hospital','other'];
 $r_labels = ['bfp'=>'BFP','pnp'=>'PNP','ems'=>'EMS','drrmo'=>'DRRMO','mdrrmo'=>'MDRRMO','hospital'=>'Hospital','other'=>'Other'];
 $r_colors = ['bfp'=>['#fef2f2','#b91c1c'],'pnp'=>['#eff6ff','#1d4ed8'],'ems'=>['#f0fdf4','#15803d'],'drrmo'=>['#fffbeb','#b45309'],'mdrrmo'=>['#fffbeb','#b45309'],'hospital'=>['#ecfdf5','#0e7490'],'other'=>['#f5f3ff','#6d28d9']];
 $total_resp = count($responders);
 $pending_resp = count(array_filter($responders, function($r){ return !(int)$r['is_approved']; }));
 $active_resp = $total_resp - $pending_resp;
 $on_duty = count(array_filter($responders, function($r){ return $r['active_count'] > 0; }));
 ?>
 <div class="resp-stats">
 <div class="rs-card"><div class="rs-icon" style="background:#f0f7ff;color:var(--navy);"><i class="fas fa-truck-medical"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $total_resp ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Total Units</div></div></div>
 <div class="rs-card"><div class="rs-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $active_resp ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Approved</div></div></div>
 <div class="rs-card"><div class="rs-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-siren-on"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $on_duty ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">On Active Duty</div></div></div>
 <div class="rs-card"><div class="rs-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-clock"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $pending_resp ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Pending Approval</div></div></div>
 </div>
 <div class="card">
 <div class="card-header">
 <h3><i class="fas fa-truck-medical" style="color:var(--navy);margin-right:6px;"></i>Registered Responder Units</h3>
 <span class="card-meta"><?= $total_resp ?> units</span>
 </div>
 <div class="resp-filter-bar">
 <button class="rf-btn active" onclick="filterResp('all',this)">All</button>
 <?php foreach($r_labels as $k=>$l): ?>
 <button class="rf-btn" onclick="filterResp('<?= $k ?>',this)"><?= $l ?></button>
 <?php endforeach; ?>
 <?php if($pending_resp > 0): ?>
 <button class="rf-btn" onclick="filterResp('pending',this)" style="border-color:#fde68a;color:#d97706;">Pending (<?= $pending_resp ?>)</button>
 <?php endif; ?>
 </div>
 <?php if(empty($responders)): ?>
 <div class="empty"><i class="fas fa-truck-medical" style="font-size:2rem;display:block;margin-bottom:10px;opacity:0.3;"></i><p>No responder units registered yet.</p></div>
 <?php else: ?>
 <div id="responderList">
 <?php foreach($responders as $r):
 $rtype = $r['responder_type'] ?? 'other';
 $rc = $r_colors[$rtype] ?? ['#f5f3ff','#6d28d9'];
 $approved = (int)$r['is_approved'];
 $initials = strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1));
 ?>
 <div class="resp-row" data-type="<?= $rtype ?>" data-approved="<?= $approved ?>">
 <div class="resp-avatar" style="background:<?= $rc[0] ?>;color:<?= $rc[1] ?>;"><?= $initials ?></div>
 <div style="flex:1;min-width:0;">
 <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
 <div class="resp-name"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></div>
 <span class="rtype-badge rtype-<?= $rtype ?>"><?= $r_labels[$rtype] ?? strtoupper($rtype) ?></span>
 <?php if(!$approved): ?><span class="pending-badge"><i class="fas fa-clock" style="margin-right:3px;"></i>Pending</span><?php endif; ?>
 <?php if($r['active_count']>0): ?><span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;"><i class="fas fa-siren-on" style="margin-right:3px;"></i><?= $r['active_count'] ?> active</span><?php endif; ?>
 </div>
 <div class="resp-meta">
 <?php if($r['org_name']): ?><span><i class="fas fa-building" style="margin-right:3px;"></i><?= htmlspecialchars($r['org_name']) ?></span>&ensp;<?php endif; ?>
 <?php if($r['position']): ?><span><?= htmlspecialchars($r['position']) ?></span>&ensp;<?php endif; ?>
 <?php if($r['municipality']): ?><span><i class="fas fa-location-dot" style="margin-right:3px;color:var(--muted);"></i><?= htmlspecialchars($r['municipality']) ?></span>&ensp;<?php endif; ?>
 <?php if($r['phone_number']): ?><span><i class="fas fa-phone" style="margin-right:3px;color:var(--muted);"></i><?= htmlspecialchars($r['phone_number']) ?></span><?php endif; ?>
 </div>
 <div style="font-size:0.74rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars($r['email']) ?></div>
 </div>
 <div class="resp-actions">
 <?php if(!$approved): ?>
 <button class="btn-approve" onclick="responderAction(<?= $r['id'] ?>,'approve',this)"><i class="fas fa-check"></i> Approve</button>
 <button class="btn-reject" onclick="responderAction(<?= $r['id'] ?>,'reject',this)"><i class="fas fa-xmark"></i></button>
 <?php else: ?>
 <span class="active-badge"><i class="fas fa-check" style="margin-right:4px;"></i>Approved</span>
 <?php endif; ?>
 </div>
 </div>
 <?php endforeach; ?>
 </div>
 <?php endif; ?>
 </div>
 <script>
 function filterResp(type, btn) {
 document.querySelectorAll('.rf-btn').forEach(function(b){ b.classList.remove('active'); });
 btn.classList.add('active');
 document.querySelectorAll('.resp-row').forEach(function(row) {
 if(type==='all') { row.style.display=''; return; }
 if(type==='pending') { row.style.display=(row.dataset.approved==='0'?'':'none'); return; }
 row.style.display=(row.dataset.type===type?'':'none');
 });
 }
 async function responderAction(id, action, btn) {
 var label = action==='approve' ? 'Approve this responder?' : 'Reject and remove this account?';
 if(!confirm(label)) return;
 btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
 var fd=new FormData();
 fd.append('action', action+'_responder');
 fd.append('user_id', id);
 try {
 var res=await fetch('lgu.php?view=responders',{method:'POST',body:fd});
 var data=await res.json();
 if(data.status==='success') location.reload();
 else { showToast(data.message||'Error.', 'error'); btn.disabled=false; btn.innerHTML=action==='approve'?'<i class="fas fa-check"></i> Approve':'<i class="fas fa-xmark"></i>'; }
 } catch(e) { btn.disabled=false; }
 }
 </script>

 <?php else: ?>
 <!-- ── COMING SOON ── -->
 <div class="card">
 <div class="coming-soon">
 <i class="fas <?= $nav_items[$view]['icon'] ?? 'fa-gear' ?>"></i>
 <h3><?= htmlspecialchars($page_titles[$view] ?? ucfirst($view)) ?></h3>
 <p>This section is under development.</p>
 </div>
 </div>
 <?php endif; ?>

 </div><!-- /content -->
</div><!-- /main -->

<script>

// ── Client-side Tab Navigation ───────────────────────────────────────────
const lguTabTitles = {
  overview: 'LGU Operations Dashboard',
  all_reports: 'All Incident Reports',
  map: 'Incident Map',
  contacts: 'Emergency Contacts',
  barangays: 'Barangay Summaries',
  responders: 'Responder Units',
  profile: 'My Profile'
};

function showTab(name) {
  if (!lguTabTitles[name]) return;
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.sb-nav a').forEach(function(a){ a.classList.remove('active'); });
  var target = document.getElementById('tab-' + name);
  if (target) target.classList.add('active');
  var navBtn = document.getElementById('nav-' + name);
  if (navBtn) navBtn.classList.add('active');
  var titleEl = document.getElementById('lguPageTitle');
  if (titleEl) titleEl.textContent = lguTabTitles[name];
  try { history.replaceState(null, '', '#tab=' + name); } catch(e){}
  if (name === 'map') {
    setTimeout(function(){
      if (typeof lmap !== 'undefined' && lmap) {
        lmap.invalidateSize();
      }
    }, 80);
  }
  if (typeof closeSidebar === 'function') closeSidebar();
}

window.addEventListener('DOMContentLoaded', function(){
  var hash = (location.hash || '').replace('#tab=', '').replace('#view=', '').replace('#', '');
  if (hash && lguTabTitles[hash]) {
    showTab(hash);
  }
});

// Escape user-controlled values before they are concatenated into innerHTML.
function esc(s){if(s===null||s===undefined)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function openSidebar(){
 document.getElementById('sidebar').classList.add('open');
 document.getElementById('overlay').classList.add('show');
 document.body.style.overflow='hidden';
}
function closeSidebar(){
 document.getElementById('sidebar').classList.remove('open');
 document.getElementById('overlay').classList.remove('show');
 document.body.style.overflow='';
}

// ── Toast notification system (same as barangay portal) ─────────────────────
function showToast(message, type) {
 type = type || 'success';
 var icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info', warning: 'fa-triangle-exclamation' };
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

// ── Report Actions ──────────────────────────────────────────────────────────
var _lguRptId = null;
var _lguEscalated = false;
var _lguResponderPending = false;
async function lguViewReport(id,title,category,status,barangay,reporter,date,desc,escalated,residence,responderPending){
 _lguRptId = id;
 _lguEscalated = !!escalated;
 _lguResponderPending = !!responderPending;
 document.getElementById('lguModalTitle').textContent = title;
 var pill = {dangerous:'<span class="pill pill-dangerous">Dangerous</span>',caution:'<span class="pill pill-caution">Caution</span>',safe:'<span class="pill pill-safe">Safe</span>'};
 var baseHtml =
 '<div class="detail-row"><div class="detail-lbl">Status</div><div class="detail-val">'+(pill[status]||status)+'</div></div>'+
 '<div class="detail-row"><div class="detail-lbl">Category</div><div class="detail-val">'+category.charAt(0).toUpperCase()+category.slice(1)+'</div></div>'+
 '<div class="detail-row"><div class="detail-lbl">Location</div><div class="detail-val">'+esc(barangay)+'</div></div>'+
 '<div class="detail-row"><div class="detail-lbl">Reported By</div><div class="detail-val">'+esc(reporter)+'</div></div>'+
 (residence?'<div class="detail-row"><div class="detail-lbl">Resident of</div><div class="detail-val">'+esc(residence)+'</div></div>':'')+
 '<div class="detail-row"><div class="detail-lbl">Date</div><div class="detail-val">'+esc(date)+'</div></div>'+
 (desc?'<div class="detail-row"><div class="detail-lbl">Description</div><div class="detail-val" style="line-height:1.6;">'+esc(desc)+'</div></div>':'')+
 '<div id="lguModalPhotosWrap" style="margin-top:14px;display:none;"></div>' +
  '<div id="lguModalTimelineWrap"><div style="font-size:0.75rem;color:var(--muted);padding:10px 0;"><i class="fas fa-spinner fa-spin"></i> Loading telemetry audit trail…</div></div>';
 
 document.getElementById('lguModalBody').innerHTML = baseHtml;
 document.getElementById('lguRptStatusSel').value = status;

 // Resolve lock: a dispatched responder must clear the incident first, and an
 // already-resolved report can never be resolved again.
 var lguResolveBtn = document.getElementById('lguModalResolveBtn');
 var isResolved = (status === 'safe');
 var safeOpt = document.querySelector('#lguRptStatusSel option[value="safe"]');
 if (lguResolveBtn) {
  if (isResolved) {
   lguResolveBtn.disabled = true;
   lguResolveBtn.title = 'This incident is already resolved';
   lguResolveBtn.innerHTML = '<i class="fas fa-circle-check"></i> Resolved';
   lguResolveBtn.style.cssText = 'background:#f0fdf4;color:#166534;border:1.5px solid #bbf7d0;cursor:not-allowed;opacity:.9;';
  } else if (_lguResponderPending) {
   lguResolveBtn.disabled = true;
   lguResolveBtn.title = 'The assigned first responder must resolve this incident first';
   lguResolveBtn.innerHTML = '<i class="fas fa-hourglass-half"></i> Awaiting Responder';
   lguResolveBtn.style.cssText = 'background:#fffbeb;color:#92400e;border:1.5px solid #fde68a;cursor:not-allowed;opacity:.9;';
  } else {
   lguResolveBtn.disabled = false;
   lguResolveBtn.title = '';
   lguResolveBtn.innerHTML = '<i class="fas fa-circle-check"></i> Resolve';
   lguResolveBtn.style.cssText = '';
  }
 }
 if (safeOpt) {
  safeOpt.disabled = isResolved || _lguResponderPending;
  safeOpt.title = _lguResponderPending ? 'Waiting for the assigned responder to resolve this incident' : '';
  safeOpt.textContent = (_lguResponderPending && !isResolved) ? '✅ Safe (awaiting responder)' : '✅ Safe';
 }
 // Reflect escalation state on the Escalate button
 var escBtn = document.getElementById('lguModalEscalateBtn');
 if (escBtn) {
 if (_lguEscalated) {
 escBtn.disabled = true;
 escBtn.classList.remove('btn-lgu-escalate');
 escBtn.innerHTML = '<i class="fas fa-check"></i> Already Escalated';
 } else {
 escBtn.disabled = false;
 escBtn.classList.add('btn-lgu-escalate');
 escBtn.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i> Escalate';
 }
 }
 document.getElementById('lguReportModal').classList.add('show');

  try {
    fetch('../api/reports.php?action=get_report_images&report_id=' + id).then(function(r){ return r.json(); }).then(function(data){
      var pw = document.getElementById('lguModalPhotosWrap');
      if (!pw) return;
      if (data && data.status === 'success' && Array.isArray(data.images) && data.images.length > 0) {
        pw.style.display = 'block';
        var count = data.images.length;
        var h = '<div style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">' +
          '<i class="fas fa-camera" style="color:var(--navy,#1e293b);"></i> Attached Photos (' + count + ')' +
          '</div>' +
          '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(130px, 1fr));gap:10px;">';
        data.images.forEach(function(img, idx) {
          var imgUrl = '../' + img.url;
          h += '<div style="position:relative;border-radius:10px;overflow:hidden;border:1px solid var(--border);aspect-ratio:4/3;background:#0f172a;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.1);" onclick="openPhotoPreview(\'' + imgUrl.replace(/'/g, "\\'") + '\')">' +
            '<img src="' + imgUrl + '" alt="Incident Photo ' + (idx + 1) + '" style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy" onerror="this.parentElement.style.display=\'none\'">' +
            '<div style="position:absolute;bottom:5px;right:6px;background:rgba(0,0,0,0.65);color:#fff;font-size:0.65rem;font-weight:600;padding:2px 7px;border-radius:12px;backdrop-filter:blur(2px);display:flex;align-items:center;gap:3px;"><i class="fas fa-magnifying-glass-plus"></i> ' + (idx+1) + '/' + count + '</div>' +
            '</div>';
        });
        h += '</div>';
        pw.innerHTML = h;
      } else {
        pw.style.display = 'none';
        pw.innerHTML = '';
      }
    }).catch(function(){
      var pw = document.getElementById('lguModalPhotosWrap');
      if (pw) pw.style.display = 'none';
    });
  } catch(e) {}

  try {
  var res = await fetch('../api/reports.php?action=get_lifecycle&report_id=' + id);
 var data = await res.json();
 if (data.status === 'success' && data.report) {
 var rep = data.report;
 var parsePhtDate = function(s) {
  if (!s) return null;
  var d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d.getTime()) ? null : d;
 };
 var citizenDate = parsePhtDate(rep.created_at);
 var respondedDate = parsePhtDate(rep.responded_at);
 var resolvedDate = parsePhtDate(rep.resolved_at);
 var steps = [
 { title: 'Citizen Reported', icon: 'fa-bullhorn', color: '#3b82f6', done: true, time: citizenDate ? citizenDate.toLocaleString('en-PH') : date, desc: 'Reported by ' + (rep.reporter_name || reporter) },
 { title: 'Barangay / LGU Escalation', icon: 'fa-building-shield', color: '#f59e0b', done: !!rep.escalated_to_lgu || rep.status === 'dangerous', time: rep.escalated_to_lgu ? 'Escalated to LGU' : 'Monitored locally', desc: rep.escalated_to_lgu ? 'Prioritized for direct emergency dispatch' : 'Barangay tier monitoring' },
 { title: 'LGU Dispatch to Unit', icon: 'fa-truck-fast', color: '#8b5cf6', done: !!rep.assigned_to, time: rep.assigned_to ? ('Unit: ' + (rep.responder_name || 'Unit') + (rep.responder_agency ? ' (' + rep.responder_agency.toUpperCase() + ')' : '')) : 'Pending assignment', desc: rep.assigned_to ? 'Dispatched to incident area' : 'Awaiting unit dispatch' },
 { title: 'Unit Accepted & En Route', icon: 'fa-person-running', color: '#06b6d4', done: !!rep.responded_at || !!rep.accepted_at, time: respondedDate ? respondedDate.toLocaleTimeString('en-PH') : (rep.accepted_at ? 'Accepted' : 'Standby'), desc: rep.responded_at ? 'First responders arrived on scene' : 'Dispatched unit in transit' },
 { title: 'Scene Cleared & Resolved', icon: 'fa-circle-check', color: '#10b981', done: (rep.status === 'safe' || !!rep.resolved_at), time: resolvedDate ? resolvedDate.toLocaleString('en-PH') : (rep.status === 'safe' ? 'Resolved' : 'Active'), desc: (rep.status === 'safe' || !!rep.resolved_at) ? 'Hazard safely neutralized' : 'Active emergency resolution in progress' }
 ];

 var tlHtml = '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">' +
 '<div style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px;display:flex;align-items:center;gap:6px;">' +
 '<i class="fas fa-route" style="color:var(--navy);"></i> Incident Response Journey &amp; Telemetry' +
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
 var tw = document.getElementById('lguModalTimelineWrap');
 if (tw) tw.innerHTML = tlHtml;
 }
 } catch(e) {
 var tw = document.getElementById('lguModalTimelineWrap');
 if (tw) tw.innerHTML = '';
 }
}
function closeLguModal(){ document.getElementById('lguReportModal').classList.remove('show'); }

function openPhotoPreview(url) {
  var lb = document.getElementById('lightbox');
  var img = document.getElementById('lightboxImg');
  if (!lb || !img) return;
  img.src = url;
  lb.classList.add('open');
}
function closePhotoPreview() {
  var lb = document.getElementById('lightbox');
  if (lb) lb.classList.remove('open');
}
document.addEventListener('DOMContentLoaded',function(){
 document.getElementById('lguReportModal').addEventListener('click',function(e){if(e.target===this)closeLguModal();});
 document.getElementById('lguContactModal').addEventListener('click',function(e){if(e.target===this)closeLguContactModal();});
 document.getElementById('lguDispatchModal').addEventListener('click',function(e){if(e.target===this)closeLguDispatch();});
});

document.addEventListener('DOMContentLoaded',function(){
 document.getElementById('lguModalResolveBtn').onclick = async function(){
 if(!_lguRptId) return;
 if(this.disabled) return;
 if(_lguResponderPending){showToast('A first responder is handling this incident. The responder must resolve it first.','error');return;}
 if(!confirm('Mark this report as resolved?')) return;
 var fd=new FormData(); fd.append('action','resolve_report'); fd.append('report_id',_lguRptId);
 var res=await fetch('../api/reports.php',{method:'POST',body:fd});
 var data=await res.json();
 if(data.status==='success'){closeLguModal();showToast('Report marked as resolved.','success');location.reload();}else showToast(data.message||'Could not resolve report.','error');
 };
 document.getElementById('lguModalStatusBtn').onclick = async function(){
 var newStatus = document.getElementById('lguRptStatusSel').value;
 if(!newStatus||!_lguRptId) return;
 if(newStatus==='safe' && _lguResponderPending){showToast('A first responder is handling this incident. The responder must resolve it first.','error');document.getElementById('lguRptStatusSel').value='';return;}
 if(!confirm('Change status to '+newStatus+'?')) return;
 var fd=new FormData(); fd.append('action','update_report_status'); fd.append('report_id',_lguRptId); fd.append('status',newStatus);
 var res=await fetch('../api/reports.php',{method:'POST',body:fd});
 var data=await res.json();
 if(data.status==='success'){closeLguModal();showToast('Status updated to '+newStatus+'.','success');location.reload();}else showToast(data.message||'Status change failed.','error');
 };
 document.getElementById('lguModalDispatchBtn').onclick = function(){
 if(!_lguRptId) return;
 closeLguModal();
 openLguDispatch(_lguRptId);
 };
 document.getElementById('lguModalEscalateBtn').onclick = async function(){
 var btn = this;
 if(!_lguRptId || _lguEscalated || btn.disabled) return;
 if(!confirm('Escalate this report to LGU priority dispatch?')) return;
 btn.disabled = true;
 var origHtml = btn.innerHTML;
 btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Escalating…';
 try {
 var fd = new FormData();
 fd.append('action','escalate_report');
 fd.append('report_id',_lguRptId);
 var res = await fetch('../api/reports.php',{method:'POST',body:fd});
 var data = await res.json();
 if(data.status === 'success'){
 _lguEscalated = true;
 btn.innerHTML = '<i class="fas fa-check"></i> Escalated';
 showToast('Report escalated to LGU priority.','info');
 } else {
 showToast(data.message || 'Escalation failed.','error');
 btn.disabled = false;
 btn.innerHTML = origHtml;
 }
 } catch(e) {
 showToast('Network error - please try again.','error');
 btn.disabled = false;
 btn.innerHTML = '<i class="fas fa-arrow-up-from-bracket"></i> Escalate';
 }
 };
});

// ── Contact Deletion ────────────────────────────────────────────────────────
async function lguDeleteContact(id, name){
 if(!confirm('Delete emergency contact "'+name+'"? This cannot be undone.')) return;
 var fd = new FormData();
 fd.append('action','delete');
 fd.append('id', id);
 try {
 var res = await fetch('../api/contacts.php',{method:'POST',body:fd});
 var data = await res.json();
 if(data.status === 'success'){
 showToast('Emergency contact removed.','success');
 location.reload();
 } else {
 showToast(data.message || 'Delete failed.','error');
 }
 } catch(e) {
 showToast('Network error - please try again.','error');
 }
}

// ── Dispatch Modal ───────────────────────────────────────────────────────────
function openLguDispatch(reportId){
 document.getElementById('lguDispatchRptId').value = reportId;
 document.getElementById('lguDispatchModal').classList.add('show');
}
function closeLguDispatch(){ document.getElementById('lguDispatchModal').classList.remove('show'); }
async function submitDispatch(){
 var reportId = document.getElementById('lguDispatchRptId').value;
 var responderId = document.getElementById('lguDispatchResponderSel').value;
 var err = document.getElementById('lguDispatchErr');
 if(!responderId){ err.textContent='Please select a responder.'; err.style.display='block'; return; }
 var btn = document.getElementById('lguDispatchBtn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Dispatching…';
 var fd=new FormData(); fd.append('action','lgu_dispatch'); fd.append('report_id',reportId); fd.append('responder_id',responderId);
 try {
 var res=await fetch('../api/reports.php',{method:'POST',body:fd});
 var data=await res.json();
 if(data.status==='success'){closeLguDispatch();location.reload();}
 else{err.textContent=data.message||'Dispatch failed.';err.style.display='block';btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Dispatch';}
 }catch(e){err.textContent='Request failed.';err.style.display='block';btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Dispatch';}
}

// ── Contact Management ───────────────────────────────────────────────────────
function openLguAddContact(){ document.getElementById('lguContactModal').classList.add('show'); }
function closeLguContactModal(){ document.getElementById('lguContactModal').classList.remove('show'); document.getElementById('lgu_cn_error').style.display='none'; }
async function lguSaveContact(){
 var name=document.getElementById('lgu_cn_name').value.trim();
 var type=document.getElementById('lgu_cn_type').value;
 var city=document.getElementById('lgu_cn_city').value.trim();
 var phone=document.getElementById('lgu_cn_phone').value.trim();
 var email=document.getElementById('lgu_cn_email').value.trim();
 var err=document.getElementById('lgu_cn_error');
 if(!name||!city){err.textContent='Name and city are required.';err.style.display='block';return;}
 var btn=document.getElementById('lgu_cn_saveBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
 var fd=new FormData();fd.append('action','create');fd.append('name',name);fd.append('type',type);
 fd.append('city',city);fd.append('contact_number',phone);fd.append('contact_email',email);
 try{
 var res=await fetch('../api/contacts.php',{method:'POST',body:fd});
 var data=await res.json();
 if(data.status==='success'){closeLguContactModal();location.reload();}
 else{err.textContent=data.message||'Save failed.';err.style.display='block';btn.disabled=false;btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save Contact';}
 }catch(e){err.textContent='Request failed.';err.style.display='block';btn.disabled=false;btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save Contact';}
}
// ── Table Filtering & Search ────────────────────────────────────────────────
var currentTableFilter = 'all';
var currentTableQuery = '';

function applyTableFilters(){
 var rows = document.querySelectorAll('.table-wrap table tbody tr');
 rows.forEach(function(row){
 var st = row.getAttribute('data-status') || '';
 var isArchivedOrSafe = (st === 'safe' || row.getAttribute('data-archived') === '1');
 var matchStatus = false;
 if(currentTableFilter === 'all'){
  matchStatus = !isArchivedOrSafe;
 } else if(currentTableFilter === 'safe' || currentTableFilter === 'archived'){
  matchStatus = isArchivedOrSafe;
 } else {
  matchStatus = (st === currentTableFilter && row.getAttribute('data-archived') !== '1');
 }
 var text = row.textContent.toLowerCase();
 var matchQuery = (!currentTableQuery || text.indexOf(currentTableQuery) !== -1);
 row.style.display = (matchStatus && matchQuery) ? '' : 'none';
 });
}

function filterTableReports(status, btn){
 currentTableFilter = status;
 btn.parentElement.querySelectorAll('.filter-btn').forEach(function(b){ b.className = 'filter-btn'; });
 btn.classList.add('active-' + status);
 applyTableFilters();
}

function searchTableReports(query){
 currentTableQuery = query.toLowerCase().trim();
 applyTableFilters();
}
</script>

<!-- ── LGU REPORT ACTION MODAL ── -->
<style>
.lgu-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:300;align-items:center;justify-content:center;padding:20px;}
.lgu-modal-bg.show{display:flex;}
.lgu-modal{background:#fff;border-radius:16px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);}
.lgu-modal-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.lgu-modal-header h3{font-size:0.95rem;font-weight:800;}
.lgu-modal-close{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--muted);padding:4px 6px;border-radius:6px;}
.lgu-modal-close:hover{background:#f3f4f6;}
.lgu-modal-body{padding:18px 20px;}
.detail-row{margin-bottom:12px;}
.detail-lbl{font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:3px;}
.detail-val{font-size:0.88rem;color:var(--text);}
.lgu-modal-actions{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.btn-lgu{padding:8px 15px;border-radius:9px;font-size:0.82rem;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;transition:all 0.18s;}
.btn-lgu-resolve{background:#16a34a;color:#fff;}.btn-lgu-resolve:hover{background:#166534;}
.btn-lgu-dispatch{background:#0a3d62;color:#fff;}.btn-lgu-dispatch:hover{background:#062444;}
.btn-lgu-escalate{background:#d97706;color:#fff;}.btn-lgu-escalate:hover{background:var(--sentri-caution);}
.btn-lgu:disabled{opacity:0.65;cursor:not-allowed;}
.lgu-cn-grid{display:grid;gap:11px;}
.lgu-cn-label{font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;}
.lgu-cn-input{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;}
.lgu-cn-input:focus{border-color:#93c5fd;background:#fff;}
/* LIGHTBOX PREVIEW */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:2000;justify-content:center;align-items:center;cursor:zoom-out;padding:20px;}
.lightbox.open{display:flex;}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain;box-shadow:0 10px 40px rgba(0,0,0,0.6);}
.lightbox-close{position:absolute;top:20px;right:24px;background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:1.3rem;cursor:pointer;width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;transition:background 0.2s;}
.lightbox-close:hover{background:rgba(255,255,255,0.3);}
</style>

<!-- ═══ LIGHTBOX PREVIEW ═══ -->
<div class="lightbox" id="lightbox" onclick="closePhotoPreview()">
  <button class="lightbox-close" onclick="closePhotoPreview()"><i class="fas fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="Photo Preview">
</div>

<div class="lgu-modal-bg" id="lguReportModal">
 <div class="lgu-modal">
 <div class="lgu-modal-header">
 <h3 id="lguModalTitle">Incident Report</h3>
 <button class="lgu-modal-close" onclick="closeLguModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="lgu-modal-body" id="lguModalBody"></div>
 <div class="lgu-modal-actions">
 <button class="btn-lgu btn-lgu-resolve" id="lguModalResolveBtn"><i class="fas fa-circle-check"></i> Resolve</button>
 <button class="btn-lgu btn-lgu-escalate" id="lguModalEscalateBtn"><i class="fas fa-arrow-up-from-bracket"></i> Escalate</button>
 <button class="btn-lgu btn-lgu-dispatch" id="lguModalDispatchBtn"><i class="fas fa-paper-plane"></i> Dispatch Responder</button>
 <select id="lguRptStatusSel" style="padding:8px 12px;border-radius:9px;border:1.5px solid var(--border);font-size:0.82rem;font-weight:600;font-family:'Inter',sans-serif;background:#fff;cursor:pointer;">
 <option value="">Select Status</option>
 <option value="dangerous">⛔ Dangerous</option>
 <option value="caution">⚠️ Caution</option>
 <option value="safe">✅ Safe</option>
 </select>
 <button class="btn-lgu" style="background:#f0f7ff;color:var(--navy);border:1.5px solid #bfdbfe;" id="lguModalStatusBtn"><i class="fas fa-pen"></i> Apply Status</button>
 </div>
 </div>
</div>

<!-- ── LGU DISPATCH MODAL ── -->
<div class="lgu-modal-bg" id="lguDispatchModal">
 <div class="lgu-modal" style="max-width:420px;">
 <div class="lgu-modal-header">
 <h3><i class="fas fa-paper-plane" style="color:var(--navy);margin-right:6px;"></i>Dispatch Responder</h3>
 <button class="lgu-modal-close" onclick="closeLguDispatch()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="lgu-modal-body">
 <input type="hidden" id="lguDispatchRptId">
 <p style="font-size:0.85rem;color:var(--muted);margin-bottom:14px;">Select an approved responder unit to assign to this incident.</p>
 <label class="lgu-cn-label">Responder Unit</label>
 <select id="lguDispatchResponderSel" class="lgu-cn-input" style="background:#fff;">
 <option value="">Select Responder</option>
 <?php
 $resp_opts = $conn->query("SELECT id, CONCAT(first_name,' ',last_name) as name, org_name, responder_type FROM users WHERE role='first_responder' AND is_approved=1 ORDER BY responder_type, org_name");
 if($resp_opts) while($ro=$resp_opts->fetch_assoc()):
 ?>
 <option value="<?= $ro['id'] ?>"><?= htmlspecialchars(strtoupper($ro['responder_type']??'UNIT').' - '.($ro['org_name']?:$ro['name'])) ?></option>
 <?php endwhile; ?>
 </select>
 <div id="lguDispatchErr" style="display:none;margin-top:10px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:9px 12px;font-size:0.82rem;font-weight:600;"></div>
 </div>
 <div class="lgu-modal-actions">
 <button class="btn-lgu btn-lgu-dispatch" id="lguDispatchBtn" onclick="submitDispatch()"><i class="fas fa-paper-plane"></i> Dispatch</button>
 </div>
 </div>
</div>

<!-- ── LGU ADD CONTACT MODAL ── -->
<div class="lgu-modal-bg" id="lguContactModal">
 <div class="lgu-modal" style="max-width:460px;">
 <div class="lgu-modal-header">
 <h3><i class="fas fa-address-book" style="color:var(--navy);margin-right:6px;"></i>Add Emergency Contact</h3>
 <button class="lgu-modal-close" onclick="closeLguContactModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="lgu-modal-body">
 <div class="lgu-cn-grid">
 <div><label class="lgu-cn-label">Name *</label><input id="lgu_cn_name" type="text" class="lgu-cn-input" placeholder="e.g. Imus City Police Station"></div>
 <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
 <div><label class="lgu-cn-label">Type *</label>
 <select id="lgu_cn_type" class="lgu-cn-input" style="background:#fff;">
 <option value="lgu">LGU</option><option value="hospital">Hospital</option>
 <option value="police">Police</option><option value="fire">Fire</option>
 <option value="barangay">Barangay</option><option value="traffic">Traffic</option><option value="other">Other</option>
 </select></div>
 <div><label class="lgu-cn-label">City *</label><input id="lgu_cn_city" type="text" class="lgu-cn-input" placeholder="City/Municipality"></div>
 </div>
 <div><label class="lgu-cn-label">Contact Number</label><input id="lgu_cn_phone" type="tel" class="lgu-cn-input" placeholder="+63 9XX XXX XXXX"></div>
 <div><label class="lgu-cn-label">Email</label><input id="lgu_cn_email" type="email" class="lgu-cn-input" placeholder="contact@example.com"></div>
 <div id="lgu_cn_error" style="display:none;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:9px 12px;font-size:0.82rem;font-weight:600;"></div>
 </div>
 </div>
 <div class="lgu-modal-actions">
 <button class="btn-lgu btn-lgu-dispatch" id="lgu_cn_saveBtn" onclick="lguSaveContact()"><i class="fas fa-floppy-disk"></i> Save Contact</button>
 </div>
 </div>
</div>

<!-- ── MULTI-AGENCY ICS MUTUAL AID MATRIX MODAL ── -->
<div class="lgu-modal-bg" id="mutualAidModal">
 <div class="lgu-modal" style="max-width:840px;">
 <div class="lgu-modal-header">
 <h3><i class="fas fa-handshake-angle" style="color:var(--navy);margin-right:6px;"></i>Multi-Agency Incident Command (ICS) Mutual Aid Matrix</h3>
 <button class="lgu-modal-close" onclick="closeMutualAidModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="lgu-modal-body">
 <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
 <div style="font-size:0.78rem;color:#1e40af;">
 <strong>Regional Asset Staging:</strong> Inter-agency emergency assets available for immediate mutual aid mobilization across NCR and regional DRRM clusters.
 </div>
 <span style="font-size:0.72rem;background:#dbeafe;color:#1d4ed8;padding:3px 9px;border-radius:20px;font-weight:800;border:1px solid #93c5fd;white-space:nowrap;">5 Staged Agencies</span>
 </div>

 <div class="table-wrap" style="max-height:48vh;overflow-y:auto;">
 <table>
 <thead>
 <tr>
 <th style="white-space:nowrap;">Responding Agency</th>
 <th>Asset Capability & Equipment</th>
 <th style="white-space:nowrap;">Personnel</th>
 <th style="white-space:nowrap;">Deployment ETA</th>
 <th style="white-space:nowrap;">Status / Action</th>
 </tr>
 </thead>
 <tbody>
 <tr>
 <td>
 <strong>Philippine Coast Guard (PCG)</strong>
 <div style="font-size:0.72rem;color:var(--muted);">National Capital Region Dist.</div>
 </td>
 <td><span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:4px;font-size:0.72rem;font-weight:700;">WASAR Team (3 Rubber Boats)</span></td>
 <td>12 Operators</td>
 <td><span style="font-size:0.74rem;font-weight:700;color:#059669;">15-20 mins</span></td>
 <td><button id="ma_btn_1" onclick="requestMutualAid(1, 'Philippine Coast Guard WASAR', this)" style="padding:4px 10px;background:var(--navy);color:#fff;border:none;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Deploy Asset</button></td>
 </tr>
 <tr>
 <td>
 <strong>AFP 525th Eng. Combat Bn</strong>
 <div style="font-size:0.72rem;color:var(--muted);">HADR Special Task Unit</div>
 </td>
 <td><span style="background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:4px;font-size:0.72rem;font-weight:700;">2 Amphibious KM450 Trucks</span></td>
 <td>20 Soldiers</td>
 <td><span style="font-size:0.74rem;font-weight:700;color:#059669;">25-30 mins</span></td>
 <td><button id="ma_btn_2" onclick="requestMutualAid(2, 'AFP 525th Engineer Bn', this)" style="padding:4px 10px;background:var(--navy);color:#fff;border:none;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Deploy Asset</button></td>
 </tr>
 <tr>
 <td>
 <strong>DSWD Regional Relief Hub</strong>
 <div style="font-size:0.72rem;color:var(--muted);">Disaster Response Management</div>
 </td>
 <td><span style="background:#f0fdf4;color:#166534;padding:2px 7px;border-radius:4px;font-size:0.72rem;font-weight:700;">2,500 Family Food Packs (FFPs)</span></td>
 <td>Logistics Crew</td>
 <td><span style="font-size:0.74rem;font-weight:700;color:#059669;">40 mins</span></td>
 <td><button id="ma_btn_3" onclick="requestMutualAid(3, 'DSWD Relief Convoy', this)" style="padding:4px 10px;background:var(--navy);color:#fff;border:none;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Deploy Asset</button></td>
 </tr>
 <tr>
 <td>
 <strong>MMDA Urban Search & Rescue</strong>
 <div style="font-size:0.72rem;color:var(--muted);">Road Emergency Group</div>
 </td>
 <td><span style="background:#fef2f2;color:#991b1b;padding:2px 7px;border-radius:4px;font-size:0.72rem;font-weight:700;">Heavy Hydraulic Spreaders & Crane</span></td>
 <td>8 USAR Techs</td>
 <td><span style="font-size:0.74rem;font-weight:700;color:#059669;">20 mins</span></td>
 <td><button id="ma_btn_4" onclick="requestMutualAid(4, 'MMDA USAR Heavy Rescue', this)" style="padding:4px 10px;background:var(--navy);color:#fff;border:none;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Deploy Asset</button></td>
 </tr>
 <tr>
 <td>
 <strong>DOH Mobile Field Hospital</strong>
 <div style="font-size:0.72rem;color:var(--muted);">HEMB Rapid Deployment</div>
 </td>
 <td><span style="background:#fdf4ff;color:#86198f;padding:2px 7px;border-radius:4px;font-size:0.72rem;font-weight:700;">Inflatable 20-Bed Triage Tent</span></td>
 <td>4 MDs / 8 RNs</td>
 <td><span style="font-size:0.74rem;font-weight:700;color:#059669;">35 mins</span></td>
 <td><button id="ma_btn_5" onclick="requestMutualAid(5, 'DOH Mobile Field Hospital', this)" style="padding:4px 10px;background:var(--navy);color:#fff;border:none;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;">Deploy Asset</button></td>
 </tr>
 </tbody>
 </table>
 </div>
 </div>
 </div>
</div>

<!-- ── DRRM ALERT LEVEL & EVACUATION MANDATE CONTROLLER MODAL ── -->
<div class="lgu-modal-bg" id="alertLevelModal">
 <div class="lgu-modal" style="max-width:800px;">
 <div class="lgu-modal-header">
 <h3><i class="fas fa-triangle-exclamation" style="color:#dc2626;margin-right:6px;"></i>DRRM Operations Alert Status & Evacuation Mandate Controller</h3>
 <button class="lgu-modal-close" onclick="closeAlertLevelModal()"><i class="fas fa-xmark"></i></button>
 </div>
 <div class="lgu-modal-body">
 <!-- Active Alert Level Card -->
 <div id="activeAlertCard" style="background:linear-gradient(135deg,#991b1b 0%,#b91c1c 100%);color:#fff;border-radius:12px;padding:16px 20px;margin-bottom:14px;box-shadow:0 4px 14px rgba(185,28,28,0.25);">
 <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
 <div>
 <span style="background:rgba(255,255,255,0.2);padding:3px 8px;border-radius:6px;font-size:0.7rem;font-weight:800;letter-spacing:0.5px;text-transform:uppercase;">NDRRMC Charlie Protocol</span>
 <div style="font-size:1.25rem;font-weight:800;margin-top:4px;" id="activeAlertTitle">RED ALERT / EMERGENCY LEVEL 3</div>
 <div style="font-size:0.78rem;opacity:0.9;margin-top:2px;" id="activeAlertDesc">Mandatory Pre-Emptive Evacuation Enforced for Low-Lying Riverbanks & High-Slope Zones.</div>
 </div>
 <div style="text-align:right;">
 <div style="font-size:0.72rem;opacity:0.8;">Authorized Dispatcher</div>
 <strong style="font-size:0.88rem;">Dr. Eduardo Cruz</strong>
 <div style="font-size:0.72rem;opacity:0.8;">QC DRRMO Operations Director</div>
 </div>
 </div>
 </div>

 <!-- Real-time Threshold Gauges -->
 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:14px;">
 <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px 14px;">
 <div style="font-size:0.72rem;color:var(--muted);font-weight:700;">HYDROLOGICAL GAUGE</div>
 <div style="font-size:1.05rem;font-weight:800;color:var(--muted);margin-top:2px;">&mdash;</div>
 <div style="font-size:0.72rem;color:#9ca3af;font-weight:600;"><i class="fas fa-bell"></i> No live river-gauge feed connected</div>
 </div>
 <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px 14px;">
 <div style="font-size:0.72rem;color:var(--muted);font-weight:700;">PRECIPITATION RATE</div>
 <div style="font-size:1.05rem;font-weight:800;color:var(--muted);margin-top:2px;">&mdash;</div>
 <div style="font-size:0.72rem;color:#9ca3af;font-weight:600;"><i class="fas fa-cloud-showers-heavy"></i> No sensor feed connected</div>
 </div>
 <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px 14px;">
 <div style="font-size:0.72rem;color:var(--muted);font-weight:700;">STANDBY STAGING TEAMS</div>
 <div style="font-size:1.05rem;font-weight:800;color:#16a34a;margin-top:2px;">Approved Responder Staging</div>
 <?php $ontody_pct = $resp_total_cnt > 0 ? round(($resp_approved_cnt / $resp_total_cnt) * 100) : 0; ?>
 <div style="font-size:0.72rem;color:#15803d;font-weight:600;"><i class="fas fa-shield-halved"></i> <?= (int)$resp_approved_cnt ?> Responders On Duty of <?= (int)$resp_total_cnt ?> (<?= $ontody_pct ?>%)</div>
 </div>
 </div>

 <!-- Alert Level Switcher & Directive Broadcast -->
 <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:14px;">
 <div style="font-size:0.8rem;font-weight:800;color:var(--text);margin-bottom:8px;">Set Operational Alert Protocol Level:</div>
 <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
 <button type="button" onclick="setOperationalAlert('white', this)" style="padding:10px;border-radius:8px;border:1.5px solid #cbd5e1;background:#fff;color:var(--text);text-align:left;cursor:pointer;transition:all 0.15s;">
 <div style="font-weight:800;font-size:0.82rem;color:#475569;"><i class="fas fa-circle-dot" style="color:#94a3b8;"></i> White (Normal)</div>
 <div style="font-size:0.72rem;color:var(--muted);margin-top:2px;">Continuous routine monitoring</div>
 </button>
 <button type="button" onclick="setOperationalAlert('blue', this)" style="padding:10px;border-radius:8px;border:1.5px solid #93c5fd;background:#eff6ff;color:var(--text);text-align:left;cursor:pointer;transition:all 0.15s;">
 <div style="font-weight:800;font-size:0.82rem;color:#1d4ed8;"><i class="fas fa-circle-dot" style="color:#3b82f6;"></i> Blue (Heightened)</div>
 <div style="font-size:0.72rem;color:var(--muted);margin-top:2px;">50% DRRMO readiness staging</div>
 </button>
 <button type="button" onclick="setOperationalAlert('red', this)" style="padding:10px;border-radius:8px;border:2px solid #ef4444;background:#fef2f2;color:var(--text);text-align:left;cursor:pointer;transition:all 0.15s;">
 <div style="font-weight:800;font-size:0.82rem;color:#b91c1c;"><i class="fas fa-circle-dot" style="color:#dc2626;"></i> Red (Emergency)</div>
 <div style="font-size:0.72rem;color:var(--muted);margin-top:2px;">100% Manning & Evacuation</div>
 </button>
 </div>
 </div>

 <!-- Action Footer -->
 <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding-top:10px;border-top:1px solid var(--border);flex-wrap:wrap;">
 <div style="font-size:0.74rem;color:var(--muted);"><i class="fas fa-tower-broadcast" style="color:#dc2626;margin-right:4px;"></i> Will trigger automatic push notification to all 5 Barangay Operations Centers.</div>
 <button type="button" onclick="broadcastAlertDirective()" style="padding:8px 18px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:0.8rem;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 8px rgba(220,38,38,0.3);"><i class="fas fa-bullhorn"></i> Broadcast Alert Directive</button>
 </div>
 </div>
 </div>
</div>

<script>
function openMutualAidModal(){ document.getElementById('mutualAidModal').classList.add('show'); }
function closeMutualAidModal(){ document.getElementById('mutualAidModal').classList.remove('show'); }
document.getElementById('mutualAidModal').addEventListener('click', function(e){ if(e.target===this) closeMutualAidModal(); });

function requestMutualAid(id, agency, btn){
 btn.disabled = true;
 btn.style.background = '#059669';
 btn.innerHTML = '<i class="fas fa-check"></i> Mobilized';
 showToast(`Mutual aid request transmitted to ${agency}. Staging unit mobilized.`, 'success');
}

function openAlertLevelModal(){ document.getElementById('alertLevelModal').classList.add('show'); }
function closeAlertLevelModal(){ document.getElementById('alertLevelModal').classList.remove('show'); }
document.getElementById('alertLevelModal').addEventListener('click', function(e){ if(e.target===this) closeAlertLevelModal(); });

function setOperationalAlert(level, btn){
 const card = document.getElementById('activeAlertCard');
 const title = document.getElementById('activeAlertTitle');
 const desc = document.getElementById('activeAlertDesc');
 if(!card || !title || !desc) return;
 if(level === 'white'){
 card.style.background = 'linear-gradient(135deg,#334155 0%,#475569 100%)';
 title.textContent = 'WHITE ALERT / NORMAL MONITORING';
 desc.textContent = 'Standard weather and environmental monitoring active. All barangay units on routine status.';
 showToast('Operational level set to White Alert (Normal Monitoring).', 'info');
 } else if(level === 'blue'){
 card.style.background = 'linear-gradient(135deg,#1e40af 0%,#2563eb 100%)';
 title.textContent = 'BLUE ALERT / HEIGHTENED STANDBY';
 desc.textContent = '50% DRRMO personnel on active standby. Evacuation centers on staging status.';
 showToast('Operational level updated to Blue Alert (Heightened Standby).', 'warning');
 } else {
 card.style.background = 'linear-gradient(135deg,#991b1b 0%,#b91c1c 100%)';
 title.textContent = 'RED ALERT / FULL EMERGENCY LEVEL 3';
 desc.textContent = 'Mandatory Pre-Emptive Evacuation Enforced for Low-Lying Riverbanks & High-Slope Zones.';
 showToast('Operational level elevated to RED ALERT (Full Emergency Activation).', 'error');
 }
}

function broadcastAlertDirective(){
 closeAlertLevelModal();
 showToast('DRRM Alert Directive broadcasted to all 5 Barangay Operations Centers and community feeds.', 'success');
}

// ── New-report popup alerts ─────────────────────────────────────────────────
// LGU is alerted for new dangerous reports and reports escalated to LGU.
(function(){
 if (!window.SentriReportAlerts) return;
 SentriReportAlerts.create({
  api: '../api/reports.php',
  seenKey: 'sentri_lgu_alert_seen_v2',
  containerId: 'toastContainer',
  viewUrl: 'lgu.php?view=all_reports',
  viewLabel: 'View reports',
  escalation: true,
  interval: 15000
 }).start();
})();
</script>
<?= csrf_script() ?>
</body>
</html>
