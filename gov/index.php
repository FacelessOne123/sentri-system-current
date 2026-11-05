<?php
require_once __DIR__ . '/../config/session.php';
sentri_boot_realm('gov');
if (sentri_identity() !== null) {
  require_once __DIR__ . '/../config/auth.php';
  redirect_to_portal();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SenTri - Government Portal | Republic of the Philippines</title>
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<link rel="stylesheet" href="../assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/vendor/sentri-ui.css">
<style>
:root{
  --navy:#0a3d62;
  --navy-dark:#062444;
  --navy-light:#1a5276;
  --gold:#f39c12;
  --gold-light:#f5b942;
  --gold-dark:#d68910;
  --green:#166534;
  --green-light:#16a34a;
  --red:#b91c1c;
  --text:#1a1a2e;
  --muted:#6b7280;
  --border:#e5e7eb;
}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html{scroll-behavior:smooth;}
body{background:#fff;min-height:100vh;display:flex;flex-direction:column;overflow-x:hidden;}

/* GOV TOPBAR */
.gov-bar{
  background:#fff;
  border-bottom:4px solid var(--gold);
  padding:0 32px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  height:60px;
  position:sticky;
  top:0;
  z-index:100;
  box-shadow:0 2px 12px rgba(0,0,0,0.08);
}
.gov-brand{display:flex;align-items:center;gap:14px;text-decoration:none;}
.gov-seal{
  width:40px;
  height:40px;
  background:linear-gradient(135deg,var(--navy),var(--navy-light));
  border-radius:50%;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:1.1rem;
  color:var(--gold);
  border:2px solid var(--gold);
  flex-shrink:0;
}
.gov-text h1{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:800;color:var(--navy);line-height:1.2;}
.gov-text p{font-size:0.7rem;color:var(--muted);font-weight:500;}
.gov-links{display:flex;gap:12px;align-items:center;}
.gov-links a{
  font-size:0.84rem;
  color:var(--navy);
  text-decoration:none;
  font-weight:600;
  padding:6px 12px;
  border-radius:8px;
  transition:all 0.2s;
}
.gov-links a:hover{color:var(--gold-dark);background:#f8fafc;}
.gov-links a.active{color:var(--navy);background:#f1f5f9;font-weight:700;}
.gov-links a.btn-solid-gov{
  background:linear-gradient(135deg,var(--navy-light),var(--navy-dark));
  color:#fff;
  padding:8px 18px;
  box-shadow:0 3px 10px rgba(10,61,98,0.25);
}
.gov-links a.btn-solid-gov:hover{
  transform:translateY(-1px);
  box-shadow:0 5px 16px rgba(10,61,98,0.35);
  color:#fff;
}
.ham-nav{display:none;background:none;border:none;font-size:1.3rem;color:var(--navy);cursor:pointer;}
.mobile-nav{
  display:none;
  flex-direction:column;
  gap:10px;
  position:absolute;
  top:60px;
  left:0;
  right:0;
  background:#fff;
  padding:18px;
  box-shadow:0 8px 24px rgba(0,0,0,0.1);
  z-index:200;
  border-top:2px solid var(--gold);
}
.mobile-nav.open{display:flex;}
.mobile-nav a{
  text-decoration:none;
  padding:11px 16px;
  border-radius:9px;
  font-weight:600;
  color:var(--navy);
  font-size:0.92rem;
  border:1.5px solid var(--border);
  text-align:center;
}
.mobile-nav a.solid{background:var(--navy);color:#fff;border-color:var(--navy);}

/* HERO */
.hero{
  background:linear-gradient(135deg,var(--navy-dark) 0%,var(--navy) 55%,var(--navy-light) 100%);
  padding:80px 40px 90px;
  display:flex;
  align-items:center;
  justify-content:center;
  min-height:75vh;
  position:relative;
  overflow:hidden;
}
.hero::before{
  content:'';
  position:absolute;
  inset:0;
  background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");
}
.hero-inner{position:relative;z-index:1;text-align:center;max-width:860px;}
.hero-eyebrow{
  display:inline-flex;
  align-items:center;
  gap:8px;
  background:rgba(243,156,18,0.15);
  border:1px solid rgba(243,156,18,0.35);
  border-radius:50px;
  padding:7px 18px;
  font-size:0.78rem;
  color:var(--gold);
  margin-bottom:24px;
  font-weight:600;
  letter-spacing:1px;
  text-transform:uppercase;
}
.hero-eyebrow .dot{width:7px;height:7px;background:var(--gold);border-radius:50%;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:0.4;}}
.hero h1{
  font-family:'Poppins',sans-serif;
  font-size:3.1rem;
  font-weight:800;
  color:#fff;
  line-height:1.18;
  margin-bottom:20px;
  letter-spacing:-1px;
}
.hero h1 span{color:var(--gold);}
.hero p{
  font-size:1.02rem;
  color:rgba(255,255,255,0.8);
  line-height:1.8;
  max-width:640px;
  margin:0 auto 36px;
}
.hero-ctas{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;}
.cta-primary{
  background:var(--gold);
  color:var(--navy-dark);
  padding:12px 24px;
  border-radius:10px;
  font-size:0.94rem;
  font-weight:800;
  text-decoration:none;
  transition:all 0.22s;
  box-shadow:0 6px 24px rgba(243,156,18,0.35);
  display:inline-flex;
  align-items:center;
  gap:9px;
}
.cta-primary:hover{
  background:var(--gold-light);
  transform:translateY(-2px);
  box-shadow:0 10px 32px rgba(243,156,18,0.45);
}
.cta-secondary{
  background:rgba(255,255,255,0.1);
  color:#fff;
  padding:12px 24px;
  border-radius:10px;
  text-decoration:none;
  font-weight:700;
  font-size:0.94rem;
  transition:all 0.22s;
  border:1.5px solid rgba(255,255,255,0.25);
  display:inline-flex;
  align-items:center;
  gap:9px;
}
.cta-secondary:hover{
  background:rgba(255,255,255,0.18);
  transform:translateY(-2px);
}
.hero-stats{
  display:flex;
  justify-content:center;
  gap:48px;
  margin-top:52px;
  padding-top:36px;
  border-top:1px solid rgba(255,255,255,0.12);
}
.stat-num{font-family:'Poppins',sans-serif;font-size:2rem;font-weight:800;color:var(--gold);}
.stat-lbl{font-size:0.72rem;color:rgba(255,255,255,0.6);font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-top:2px;}

/* PORTALS SECTION */
.portals-section{padding:84px 40px;background:#f8fafc;}
.section-header{text-align:center;margin-bottom:48px;}
.section-eyebrow{font-size:0.74rem;font-weight:700;color:var(--gold-dark);letter-spacing:2px;text-transform:uppercase;margin-bottom:10px;}
.section-header h2{font-family:'Poppins',sans-serif;font-size:2.2rem;font-weight:800;color:var(--navy);margin-bottom:12px;}
.section-header p{font-size:0.95rem;color:var(--muted);max-width:580px;margin:0 auto;line-height:1.6;}
.gov-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;max-width:1100px;margin:0 auto;}
.gov-card{
  background:#fff;
  border-radius:18px;
  padding:32px 26px;
  border:2px solid var(--border);
  transition:all 0.25s cubic-bezier(0.16,1,0.3,1);
  box-shadow:0 4px 14px rgba(0,0,0,0.04);
  display:flex;
  flex-direction:column;
  position:relative;
}
.gov-card:hover{
  transform:translateY(-6px);
  box-shadow:0 14px 36px rgba(0,0,0,0.1);
}
.gov-card.card-barangay:hover{border-color:var(--green-light);}
.gov-card.card-lgu:hover{border-color:var(--navy);}
.gov-card.card-responder:hover{border-color:var(--red);}

.card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.card-icon{
  width:60px;
  height:60px;
  border-radius:16px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:1.6rem;
}
.card-barangay .card-icon{background:#f0fdf4;color:var(--green);}
.card-lgu .card-icon{background:#f0f7ff;color:var(--navy);}
.card-responder .card-icon{background:#fef2f2;color:var(--red);}

.card-badge{
  font-size:0.68rem;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:1px;
  padding:5px 12px;
  border-radius:20px;
}
.card-barangay .card-badge{background:#e8f5e9;color:var(--green);}
.card-lgu .card-badge{background:#e0f2fe;color:var(--navy);}
.card-responder .card-badge{background:#fee2e2;color:var(--red);}

.gov-card h3{font-family:'Poppins',sans-serif;font-size:1.25rem;font-weight:800;color:var(--text);margin-bottom:6px;}
.card-sub{font-size:0.8rem;color:var(--muted);font-weight:600;margin-bottom:16px;}
.gov-card p{font-size:0.88rem;color:#4b5563;line-height:1.65;margin-bottom:20px;flex-grow:1;}

.feature-bullets{list-style:none;margin-bottom:26px;}
.feature-bullets li{
  font-size:0.82rem;
  color:#374151;
  margin-bottom:8px;
  display:flex;
  align-items:flex-start;
  gap:9px;
  line-height:1.45;
}
.card-barangay .feature-bullets li i{color:var(--green);margin-top:2px;font-size:0.8rem;}
.card-lgu .feature-bullets li i{color:var(--navy);margin-top:2px;font-size:0.8rem;}
.card-responder .feature-bullets li i{color:var(--red);margin-top:2px;font-size:0.8rem;}

.portal-cta{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:12px 20px;
  border-radius:10px;
  font-size:0.88rem;
  font-weight:700;
  text-decoration:none;
  transition:all 0.2s;
  color:#fff;
  width:100%;
}
.card-barangay .portal-cta{background:linear-gradient(135deg,var(--green-light),var(--green));box-shadow:0 3px 12px rgba(22,101,52,0.25);}
.card-barangay .portal-cta:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(22,101,52,0.38);}
.card-lgu .portal-cta{background:linear-gradient(135deg,var(--navy-light),var(--navy));box-shadow:0 3px 12px rgba(10,61,98,0.25);}
.card-lgu .portal-cta:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(10,61,98,0.38);}
.card-responder .portal-cta{background:linear-gradient(135deg,#ef4444,var(--red));box-shadow:0 3px 12px rgba(185,28,28,0.25);}
.card-responder .portal-cta:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(185,28,28,0.38);}

/* INFRASTRUCTURE FEATURES */
.infra-section{padding:84px 40px;background:#fff;}
.features-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;max-width:1100px;margin:0 auto;}
.feat-card{
  background:#f8fafc;
  border-radius:16px;
  padding:28px 24px;
  border:1px solid var(--border);
  transition:all 0.22s;
}
.feat-card:hover{
  transform:translateY(-4px);
  box-shadow:0 12px 28px rgba(0,0,0,0.06);
  border-color:rgba(10,61,98,0.25);
}
.feat-icon{
  width:48px;
  height:48px;
  border-radius:12px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:1.25rem;
  margin-bottom:16px;
}
.feat-card h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:8px;}
.feat-card p{font-size:0.84rem;color:var(--muted);line-height:1.65;}

/* NOTICE BAND */
.notice-section{padding:0 40px 60px;background:#fff;}
.notice-card{
  max-width:1100px;
  margin:0 auto;
  background:#fef9ec;
  border:1.5px solid #fde68a;
  border-radius:16px;
  padding:26px 32px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:24px;
}
.notice-left{display:flex;align-items:center;gap:18px;}
.notice-icon{
  width:50px;
  height:50px;
  background:#fef3c7;
  color:#d97706;
  border-radius:12px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:1.4rem;
  flex-shrink:0;
}
.notice-info h4{font-size:1rem;font-weight:700;color:#92400e;margin-bottom:4px;}
.notice-info p{font-size:0.84rem;color:#b45309;line-height:1.5;}
.notice-actions{display:flex;gap:10px;flex-shrink:0;}
.btn-notice{
  color:#92400e;
  border:1.5px solid #d97706;
  padding:9px 18px;
  border-radius:9px;
  text-decoration:none;
  font-size:0.84rem;
  font-weight:700;
  transition:all 0.2s;
  white-space:nowrap;
}
.btn-notice:hover{background:#fde68a;}
.btn-notice-primary{
  background:#d97706;
  color:#fff;
  border:1.5px solid #d97706;
  padding:9px 18px;
  border-radius:9px;
  text-decoration:none;
  font-size:0.84rem;
  font-weight:700;
  transition:all 0.2s;
  white-space:nowrap;
}
.btn-notice-primary:hover{background:#b45309;}

/* CTA BAND */
.cta-band{
  background:linear-gradient(135deg,var(--navy-dark),var(--navy),var(--navy-light));
  padding:72px 40px;
  text-align:center;
}
.cta-band h2{font-family:'Poppins',sans-serif;font-size:2.1rem;font-weight:800;color:#fff;margin-bottom:12px;}
.cta-band p{font-size:0.95rem;color:rgba(255,255,255,0.78);max-width:540px;margin:0 auto 28px;line-height:1.6;}
.cta-band a{
  display:inline-flex;
  align-items:center;
  gap:9px;
  background:var(--gold);
  color:var(--navy-dark);
  padding:13px 32px;
  border-radius:11px;
  text-decoration:none;
  font-weight:800;
  font-size:0.95rem;
  transition:all 0.2s;
  box-shadow:0 6px 24px rgba(243,156,18,0.35);
}
.cta-band a:hover{background:var(--gold-light);transform:translateY(-2px);}

/* FOOTER */
footer{
  background:var(--navy-dark);
  color:rgba(255,255,255,0.45);
  padding:32px 40px;
  text-align:center;
  font-size:0.8rem;
  margin-top:auto;
  border-top:1px solid rgba(255,255,255,0.08);
}
.footer-brand{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:10px;}
.footer-seal{
  width:32px;
  height:32px;
  background:rgba(243,156,18,0.15);
  border:1.5px solid rgba(243,156,18,0.3);
  border-radius:50%;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:0.85rem;
  color:var(--gold);
}
.footer-brand span{color:#fff;font-weight:700;font-size:0.95rem;}
.footer-desc{max-width:620px;margin:0 auto 8px;line-height:1.5;}

@media(max-width:960px){
  .gov-bar{padding:0 20px;}
  .ham-nav{display:block;}
  .gov-links{display:none;}
  .hero{padding:60px 24px;min-height:auto;}
  .hero h1{font-size:2.2rem;}
  .gov-grid{grid-template-columns:1fr;max-width:540px;}
  .features-grid{grid-template-columns:1fr 1fr;}
  .portals-section,.infra-section,.cta-band{padding:56px 24px;}
  .notice-card{flex-direction:column;align-items:flex-start;padding:22px;}
  .notice-actions{width:100%;justify-content:flex-start;}
}
@media(max-width:600px){
  .hero h1{font-size:1.8rem;}
  .hero-stats{gap:22px;}
  .features-grid{grid-template-columns:1fr;}
  footer{padding:24px 20px;}
}
</style>
</head>
<body>

<header class="gov-bar">
 <a href="index.php" class="gov-brand">
  <div class="gov-seal"><i class="fas fa-shield-halved"></i></div>
  <div class="gov-text">
   <h1>SenTri</h1>
   <p>Government Access &bull; City of Imus</p>
  </div>
 </a>
 <nav class="gov-links">
  <a href="index.php" class="active">Home</a>
  <a href="login.php" class="btn-solid-gov"><i class="fas fa-right-to-bracket"></i> Official Sign In</a>
  <a href="forgot_password.php">Forgot Password</a>
 </nav>
 <button class="ham-nav" onclick="toggleNav()" aria-label="Toggle navigation"><i class="fas fa-bars" id="hamIcon"></i></button>
 <div class="mobile-nav" id="mobileNav">
  <a href="index.php" class="solid">Home</a>
  <a href="login.php">Official Sign In</a>
  <a href="forgot_password.php">Forgot Password</a>
 </div>
</header>

<section class="hero">
 <div class="hero-inner">
  <div class="hero-eyebrow"><span class="dot"></span> Republic of the Philippines &bull; City of Imus</div>
  <h1>Incident Oversight &<br><span>Emergency Operations</span></h1>
  <p>Unified government operations console connecting Barangay Officials, Local Government Units, and First Responders for synchronized public safety and rapid incident response.</p>
  <div class="hero-ctas">
   <a href="login.php" class="cta-primary"><i class="fas fa-right-to-bracket"></i> Official Sign In</a>
   <a href="#portals" class="cta-secondary"><i class="fas fa-landmark"></i> Explore Portals</a>
  </div>
  <div class="hero-stats">
   <div><div class="stat-num">3</div><div class="stat-lbl">Official Portals</div></div>
   <div><div class="stat-num">95</div><div class="stat-lbl">Imus Barangays</div></div>
   <div><div class="stat-num">24/7</div><div class="stat-lbl">Monitoring</div></div>
  </div>
 </div>
</section>

<section class="portals-section" id="portals">
 <div class="section-header">
  <p class="section-eyebrow">Designated Roles</p>
  <h2>Three Government Portals</h2>
  <p>Role-restricted command interfaces built for local public safety, incident triage, and municipal disaster coordination.</p>
 </div>
 <div class="gov-grid">

  <!-- Barangay Portal -->
  <div class="gov-card card-barangay">
   <div class="card-top">
    <div class="card-icon"><i class="fas fa-house-flag"></i></div>
    <span class="card-badge">Barangay Level</span>
   </div>
   <h3>Barangay Operations</h3>
   <div class="card-sub">Barangay Captains, Kagawads &amp; Tanods</div>
   <p>Localized monitoring console for verified community incident reports. Coordinate neighborhood safety, conduct field verification, and escalate severe threats.</p>
   <ul class="feature-bullets">
    <li><i class="fas fa-circle-check"></i> Geotagged local incident feed</li>
    <li><i class="fas fa-circle-check"></i> Real-time status update &amp; verification</li>
    <li><i class="fas fa-circle-check"></i> Direct incident escalation to City LGU</li>
   </ul>
   <a href="login.php?portal=barangay" class="portal-cta"><i class="fas fa-right-to-bracket"></i> Sign In to Barangay Portal</a>
  </div>

  <!-- LGU Portal -->
  <div class="gov-card card-lgu">
   <div class="card-top">
    <div class="card-icon"><i class="fas fa-landmark"></i></div>
    <span class="card-badge">Executive Level</span>
   </div>
   <h3>City Government &amp; LGU</h3>
   <div class="card-sub">City Mayor, CDRRMO &amp; Department Heads</div>
   <p>City-wide executive dashboard providing high-level incident monitoring, cross-barangay risk analytics, and emergency responder dispatch management.</p>
   <ul class="feature-bullets">
    <li><i class="fas fa-circle-check"></i> City-wide interactive incident map</li>
    <li><i class="fas fa-circle-check"></i> Priority first responder dispatch queue</li>
    <li><i class="fas fa-circle-check"></i> Emergency contact directory &amp; alerts</li>
   </ul>
   <a href="login.php?portal=lgu" class="portal-cta"><i class="fas fa-right-to-bracket"></i> Sign In to LGU Portal</a>
  </div>

  <!-- First Responder Portal -->
  <div class="gov-card card-responder">
   <div class="card-top">
    <div class="card-icon"><i class="fas fa-truck-medical"></i></div>
    <span class="card-badge">Emergency Level</span>
   </div>
   <h3>First Responders</h3>
   <div class="card-sub">PNP Police, BFP Fire &amp; EMS Rescue Units</div>
   <p>Action-oriented dispatch interface for emergency units on the ground. Receive live incident alerts, view GPS navigation routes, and update response status.</p>
   <ul class="feature-bullets">
    <li><i class="fas fa-circle-check"></i> Live priority dispatch queue</li>
    <li><i class="fas fa-circle-check"></i> GPS coordinates &amp; turn-by-turn routing</li>
    <li><i class="fas fa-circle-check"></i> Response confirmation &amp; case resolution</li>
   </ul>
   <a href="login.php?portal=first_responder" class="portal-cta"><i class="fas fa-right-to-bracket"></i> Sign In to Responder Portal</a>
  </div>

 </div>
</section>

<section class="infra-section">
 <div class="section-header">
  <p class="section-eyebrow">System Capabilities</p>
  <h2>Engineered for Government Operations</h2>
  <p>Purpose-built infrastructure ensuring speed, accuracy, and compliance across all municipal offices.</p>
 </div>
 <div class="features-grid">
  <div class="feat-card">
   <div class="feat-icon" style="background:#f0fdf4;color:var(--green);"><i class="fas fa-map-location-dot"></i></div>
   <h3>Server Geofencing</h3>
   <p>Automated polygon boundary math routes incoming incident reports to the authoritative Imus barangay without manual sorting.</p>
  </div>
  <div class="feat-card">
   <div class="feat-icon" style="background:#f0f7ff;color:var(--navy);"><i class="fas fa-shield-halved"></i></div>
   <h3>Realm Isolation</h3>
   <p>Government sessions are strictly quarantined from public citizen sessions using dedicated encrypted realm cookies.</p>
  </div>
  <div class="feat-card">
   <div class="feat-icon" style="background:#fef2f2;color:var(--red);"><i class="fas fa-truck-fast"></i></div>
   <h3>Emergency Dispatch</h3>
   <p>City dispatchers assign response units instantly, giving field crews exact geolocation coordinates and citizen-provided photos.</p>
  </div>
  <div class="feat-card">
   <div class="feat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-clipboard-check"></i></div>
   <h3>Incident Audit Trail</h3>
   <p>Every status transition from reporting and verification to dispatch and resolution is permanently timestamped for accountability.</p>
  </div>
  <div class="feat-card">
   <div class="feat-icon" style="background:#f5f3ff;color:#7c3aed;"><i class="fas fa-address-book"></i></div>
   <h3>Agency Directory</h3>
   <p>Integrated notification pipeline linking barangay halls, police substations, and BFP fire stations for rapid emergency broadcast.</p>
  </div>
  <div class="feat-card">
   <div class="feat-icon" style="background:#ecfdf5;color:#059669;"><i class="fas fa-user-lock"></i></div>
   <h3>Provisioned Access</h3>
   <p>Government accounts are strictly provisioned by the system administrator with enforced password security on first login.</p>
  </div>
 </div>
</section>

<section class="notice-section">
 <div class="notice-card">
  <div class="notice-left">
   <div class="notice-icon"><i class="fas fa-id-badge"></i></div>
   <div class="notice-info">
    <h4>Authorized Personnel Notice</h4>
    <p>Official accounts are provisioned directly by the City Administrator. Self-registration is restricted. If your department requires credentials or account updates, please contact the System Administrator.</p>
   </div>
  </div>
  <div class="notice-actions">
   <a href="forgot_password.php" class="btn-notice"><i class="fas fa-key"></i> Forgot Password</a>
   <a href="login.php" class="btn-notice-primary"><i class="fas fa-right-to-bracket"></i> Official Sign In</a>
  </div>
 </div>
</section>

<section class="cta-band">
 <h2>Ready to Access Your Portal?</h2>
 <p>Sign in with your official ID or authorized government email to access your department portal.</p>
 <a href="login.php"><i class="fas fa-right-to-bracket"></i> Sign In to Government Portal</a>
</section>

<footer>
 <div class="footer-brand">
  <div class="footer-seal"><i class="fas fa-shield-halved"></i></div>
  <span>SenTri Government Portal</span>
 </div>
 <p class="footer-desc">Republic of the Philippines &bull; City of Imus Public Safety and Incident Reporting System. For authorized government personnel only.</p>
 <p>&copy; <?= date('Y') ?> SenTri Government Access. All rights reserved.</p>
</footer>

<script>
function toggleNav(){
  const nav = document.getElementById('mobileNav');
  const icon = document.getElementById('hamIcon');
  nav.classList.toggle('open');
  if(nav.classList.contains('open')){
    icon.classList.remove('fa-bars');
    icon.classList.add('fa-xmark');
  } else {
    icon.classList.remove('fa-xmark');
    icon.classList.add('fa-bars');
  }
}
</script>
</body>
</html>
