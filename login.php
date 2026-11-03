<?php
ini_set('display_errors', 0);
ob_start();
require_once __DIR__ . '/config/session.php';
sentri_boot_realm('community');
if (sentri_identity() !== null) {
 require_once __DIR__ . '/config/auth.php';
 redirect_to_portal();
}
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';

// Community portal page: only community accounts may sign in here.
// Government (barangay / LGU / first responder) accounts use gov/login.php,
// and administrators sign in directly at admin.php.
$SENTRI_PORTALS = ['community' => ['community', 'user']];
require __DIR__ . '/core/login_handler.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sign In - SenTri Community Portal</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/vendor/sentri-ui.css">
<style>
:root{--navy:#0a3d62;--navy-dark:#062444;--navy-light:#1a5276;--gold:#f39c12;--gold-dark:#d68910;--green:#166534;--green-light:#16a34a;--red:#b91c1c;--purple:#5b21b6;--text:#1a1a2e;--muted:#6b7280;--border:#e5e7eb;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
body{min-height:100vh;background:var(--navy-dark);display:flex;flex-direction:column;}
.gov-bar{background:#fff;border-bottom:4px solid var(--gold);padding:0 32px;display:flex;align-items:center;justify-content:space-between;height:60px;box-shadow:0 2px 12px rgba(0,0,0,0.15);}
.gov-brand{display:flex;align-items:center;gap:14px;text-decoration:none;}
.gov-seal{width:40px;height:40px;background:linear-gradient(135deg,var(--navy),var(--navy-light));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);border:2px solid var(--gold);}
.gov-text h1{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:800;color:var(--navy);}
.gov-text p{font-size:0.7rem;color:var(--muted);font-weight:500;}
.gov-links{display:flex;gap:20px;}
.gov-links a{font-size:0.82rem;color:var(--navy);text-decoration:none;font-weight:600;opacity:0.8;}
.gov-links a:hover{opacity:1;color:var(--gold-dark);}
.hero{background:linear-gradient(135deg,var(--navy-dark) 0%,var(--navy) 60%,#1a5276 100%);padding:18px 20px 0;position:relative;overflow:visible;}
.hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");}
.hero-inner{max-width:920px;margin:0 auto;display:flex;align-items:center;gap:20px;position:relative;z-index:1;}
.hero-eyebrow{font-size:0.68rem;font-weight:700;color:var(--gold);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;}
.hero-inner h2{font-family:'Poppins',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:3px;}
.hero-inner h2 span{color:var(--gold);}
.hero-inner p{font-size:0.8rem;color:rgba(255,255,255,0.7);line-height:1.45;max-width:420px;}
.hero-stats{display:flex;gap:18px;margin-top:10px;}
.stat-num{font-family:'Poppins',sans-serif;font-size:1.2rem;font-weight:800;color:var(--gold);}
.stat-lbl{font-size:0.65rem;color:rgba(255,255,255,0.55);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;}
.hero-badge{background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:10px 14px;flex-shrink:0;text-align:center;min-width:110px;margin-left:auto;}
.hero-badge i{font-size:1.5rem;color:var(--gold);display:block;margin-bottom:3px;}
.hero-badge span{font-size:0.68rem;color:rgba(255,255,255,0.65);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;}
.form-wrap{max-width:480px;width:100%;margin:0 auto;padding:0 16px 28px;position:relative;z-index:2;}
.form-card{background:#fff;border-radius:16px;padding:24px 26px 20px;margin-top:20px;box-shadow:0 8px 32px rgba(0,0,0,0.2);width:100%;}
.portal-header{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:12px;border-bottom:2px solid #e5e7eb;}
.ph-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:#eff6ff;color:#2563eb;}
.ph-title{font-size:0.98rem;font-weight:800;color:#1a1a2e;}
.ph-sub{font-size:0.75rem;color:#6b7280;margin-top:2px;}
.form-group{margin-bottom:11px;}
.form-group label{display:block;font-size:0.76rem;font-weight:600;color:#374151;margin-bottom:3px;}
.form-group input{width:100%;padding:9px 11px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.86rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;transition:border-color 0.15s,box-shadow 0.15s;color:#1a1a2e;}
.form-group input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,0.09);}
.pw-wrap{position:relative;}
.pw-wrap .toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6b7280;font-size:0.75rem;font-weight:700;font-family:'Inter',sans-serif;}
.forgot-row{display:flex;justify-content:flex-end;margin-top:-4px;margin-bottom:12px;}
.forgot-row a{font-size:0.75rem;color:#2563eb;text-decoration:none;font-weight:600;}
.btn-login{width:100%;min-height:42px;padding:10px 14px;border:none;border-radius:10px;font-size:0.9rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;color:#fff;background:var(--sentri-primary);display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);transition:filter 0.15s ease,box-shadow 0.15s ease;}
.btn-login:hover:not(:disabled){filter:brightness(1.05);box-shadow:0 4px 14px rgba(0,0,0,0.2);}
.btn-login:active:not(:disabled){transform:scale(0.99);}
.btn-login:disabled{opacity:0.6;cursor:not-allowed;background:#9ca3af!important;background-image:none!important;}
.tutorial-row{margin-top:12px;text-align:center;}
.btn-tutorial{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border:1px solid #cbd5e1;background:#fff;border-radius:10px;font-size:0.84rem;font-weight:700;font-family:'Inter',sans-serif;color:#1a1a2e;cursor:pointer;transition:border-color .15s,background .15s;}
.btn-tutorial:hover{border-color:#2563eb;background:#eff6ff;}
.video-modal{display:none;position:fixed;inset:0;z-index:1200;background:rgba(10,12,26,0.72);align-items:center;justify-content:center;padding:20px;}
.video-modal.open{display:flex;}
.video-card{background:#0f1220;border-radius:16px;padding:14px 14px 10px;max-width:720px;width:100%;box-shadow:0 18px 60px rgba(0,0,0,0.55);}
.video-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.video-title{color:#fff;font-size:0.95rem;font-weight:800;display:flex;align-items:center;gap:8px;}
.video-close{background:none;border:none;color:#94a3b8;font-size:1.1rem;cursor:pointer;padding:6px;}
.video-close:hover{color:#fff;}
.video-card video{width:100%;border-radius:10px;display:block;max-height:70vh;}
.msg{padding:8px 12px;border-radius:8px;font-size:0.8rem;font-weight:500;margin-bottom:11px;display:none;text-align:center;}
.msg.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.msg.success{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;}
.signup-row{text-align:center;margin-top:10px;font-size:0.8rem;color:#6b7280;}
.signup-row a{color:#2563eb;font-weight:700;text-decoration:none;}
.gov-portal-row{text-align:center;margin-top:6px;font-size:0.76rem;color:#6b7280;}
.gov-portal-row a{color:#0a3d62;font-weight:700;text-decoration:none;}
.resend-box{background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:11px;margin-top:9px;display:none;}
.resend-box p{font-size:0.78rem;color:#92400e;margin-bottom:8px;line-height:1.45;}
.resend-box input{width:100%;padding:8px 11px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:0.84rem;outline:none;font-family:'Inter',sans-serif;margin-bottom:6px;}
.btn-resend{width:100%;background:#d97706;color:#fff;border:none;padding:8px;border-radius:7px;font-size:0.81rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
.page-notice{max-width:920px;margin:12px auto 0;padding:0 24px;}
.notice{padding:10px 14px;border-radius:9px;font-size:0.81rem;font-weight:500;display:flex;align-items:flex-start;gap:8px;line-height:1.45;}
.notice.info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
.notice.success{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
.site-footer{background:var(--navy-dark);border-top:1px solid rgba(255,255,255,0.07);padding:16px 24px;text-align:center;margin-top:auto;}
.site-footer p{font-size:0.74rem;color:rgba(255,255,255,0.35);}
.site-footer a{color:rgba(255,255,255,0.55);text-decoration:none;}
.site-footer a:hover{color:var(--gold);}
@media(max-width:840px){.hero-badge{display:none;}.gov-links{display:none;}.form-card{padding:22px 20px 18px;margin-top:18px;}.form-wrap{max-width:480px;padding:0 16px 24px;}.hero-inner h2{font-size:1.3rem;}}
@media(max-width:580px){.form-card{padding:18px 14px 16px;margin-top:14px;}.form-wrap{max-width:100%;padding:0 12px 20px;}.hero-inner h2{font-size:1.2rem;}}

/* ── Guide video popup ── */
.guide-overlay{position:fixed;inset:0;background:rgba(6,36,68,.82);backdrop-filter:blur(4px);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;}
.guide-overlay.open{display:flex;animation:guideFade .25s ease;}
.guide-box{background:#fff;border-radius:16px;width:100%;max-width:880px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.5);animation:guidePop .3s cubic-bezier(.34,1.56,.64,1);}
.guide-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:3px solid var(--gold);}
.guide-title{font-family:'Poppins',sans-serif;font-size:.95rem;font-weight:800;color:var(--navy);display:flex;align-items:center;gap:8px;}
.guide-title i{color:var(--gold-dark);}
.guide-x{background:#f3f4f6;border:none;width:32px;height:32px;border-radius:8px;cursor:pointer;color:#374151;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.guide-x:hover{background:#e5e7eb;}
.guide-video{display:block;width:100%;max-height:70vh;background:#000;}
.guide-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;}
.guide-hint{font-size:.76rem;color:var(--muted);}
.guide-skip{background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;border:none;padding:9px 20px;border-radius:9px;font-size:.84rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
.guide-skip:hover{filter:brightness(1.1);}
@keyframes guideFade{from{opacity:0}to{opacity:1}}
@keyframes guidePop{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
@media(max-width:580px){.guide-hint{display:none;}.guide-foot{justify-content:flex-end;}}

/* ── Motion ── */
@keyframes msgError{0%{opacity:0;transform:translateY(-4px);}50%{opacity:1;transform:translateY(0);}100%{transform:translateY(0);}}
@keyframes msgSuccess{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}
.msg.error{animation:msgError .3s ease;}
.msg.success{animation:msgSuccess .25s ease;}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;}}
</style>
</head>
<body>
<header class="gov-bar">
 <a href="index.php" class="gov-brand">
 <div class="gov-seal"><i class="fas fa-shield-halved"></i></div>
 <div class="gov-text"><h1>SenTri</h1><p>Community Safety Incident Reporting System</p></div>
 </a>
 <nav class="gov-links">
 <a href="index.php">Home</a>
 <a href="signup.php">Register</a>
 <a href="gov/login.php">Government Portal</a>
 <a href="forgot_password.php">Forgot Password</a>
 </nav>
</header>

<section class="hero">
 <div class="hero-inner">
 <div>
 <p class="hero-eyebrow">Community Portal</p>
 <h2>Sign In to <span>SenTri</span></h2>
 <p>Access your citizen incident reporting dashboard - submit reports, view the community map, and follow active incidents.</p>
 <div class="hero-stats">
 <div><div class="stat-num">24/7</div><div class="stat-lbl">Monitoring</div></div>
 <div><div class="stat-num">100%</div><div class="stat-lbl">Community Driven</div></div>
 </div>
 </div>
 <div class="hero-badge"><i class="fas fa-users"></i><span>Community<br>Portal</span></div>
 </div>
</section>

<?php if(isset($_GET['verify_sent'])): ?><div class="page-notice"><div class="notice info"><i class="fas fa-envelope"></i><span>Verification link sent. Check your inbox and spam folder.</span></div></div>
<?php elseif(isset($_GET['reset'])): ?><div class="page-notice"><div class="notice success"><i class="fas fa-circle-check"></i><span>Password reset successfully. You may now sign in.</span></div></div>
<?php elseif(isset($_GET['pending'])): ?><div class="page-notice"><div class="notice info"><i class="fas fa-clock"></i><span>Your official account is awaiting administrator approval. Government portal users sign in at the <a href="gov/login.php" style="color:#1d4ed8;font-weight:700;">Government Portal</a>.</span></div></div>
<?php endif; ?>

<div class="form-wrap">
 <div class="form-card">
 <div class="portal-header">
 <div class="ph-icon"><i class="fas fa-users"></i></div>
 <div><div class="ph-title">Community Member Sign In</div><div class="ph-sub">Access the citizen incident reporting dashboard</div></div>
 </div>
 <div id="loginMsg" class="msg"></div>
 <form id="loginForm" novalidate>
 <input type="hidden" name="portal" value="community">
 <div class="form-group"><label>Email Address</label><input type="email" name="email" id="emailField" placeholder="you@example.com" required autocomplete="email"></div>
 <div class="form-group"><label>Password</label><div class="pw-wrap"><input type="password" name="password" id="pwField" placeholder="••••••••" required><button type="button" class="toggle-pw" onclick="togglePw()">SHOW</button></div></div>
 <div class="forgot-row"><a href="forgot_password.php">Forgot Password?</a></div>
 <button type="submit" class="btn-login sentri-btn-primary" id="loginBtn"><i class="fas fa-right-to-bracket"></i> <span id="loginBtnLabel">Sign In to Community Portal</span></button>
 </form>
 <div class="tutorial-row">
 <button type="button" class="btn-tutorial" onclick="openTutorial()"><i class="fas fa-circle-play"></i> Watch: How SenTri Works</button>
 </div>
 <div id="otpSection" style="display:none;">
 <form id="otpForm" novalidate>
 <input type="hidden" name="action" value="verify_otp">
 <input type="hidden" name="email" id="otpEmail" value="">
 <input type="hidden" name="portal" value="community">
 <div class="form-group"><label>One-Time Code</label><input type="text" name="otp" id="otpField" placeholder="123456" maxlength="6" pattern="[0-9]*" inputmode="numeric" required></div>
 <button type="submit" class="btn-login sentri-btn-primary" id="otpSubmitBtn"><i class="fas fa-key"></i> Verify OTP</button>
 <button type="button" class="btn-login" id="otpBackBtn" style="background:#f3f4f6;color:#1f2937;margin-top:10px;"><i class="fas fa-arrow-left"></i> Back to Password</button>
 </form>
 </div>
 <div id="resendBox" class="resend-box">
 <p><i class="fas fa-envelope-circle-check" style="color:#d97706;margin-right:5px;"></i>Your email is not verified. Enter your email to resend the verification link.</p>
 <input type="email" id="resendEmail" placeholder="you@example.com">
 <button class="btn-resend" id="resendBtn" onclick="resend()"><i class="fas fa-paper-plane"></i> Resend Verification Email</button>
 </div>
 <div class="signup-row" id="signupRow">Don't have an account? <a href="signup.php">Register here</a></div>
 <div class="gov-portal-row">Barangay official, LGU staff, or first responder? <a href="gov/login.php">Sign in at the Government Portal</a></div>
 </div>
</div>
<footer class="site-footer"><p>SenTri Community Safety Incident Reporting System - For official and community use only. <a href="gov/login.php">Government Portal</a></p></footer>

<!-- Guide video popup -->
<div class="guide-overlay" id="guideOverlay" role="dialog" aria-modal="true" aria-labelledby="guideTitle">
 <div class="guide-box">
  <div class="guide-head">
   <div class="guide-title" id="guideTitle"><i class="fas fa-circle-play"></i> Quick Guide: How to Use SenTri</div>
   <button type="button" class="guide-x" id="guideClose" aria-label="Close and continue"><i class="fas fa-xmark"></i></button>
  </div>
  <video class="guide-video" id="guideVideo" controls playsinline preload="none"></video>
  <div class="guide-foot">
   <span class="guide-hint">Press <b>Esc</b> to skip</span>
   <button type="button" class="guide-skip" id="guideSkip">Skip <i class="fas fa-forward"></i></button>
  </div>
 </div>
</div>

<script>
let timerInterval = null;

/* ── Guide video popup ── */
const GUIDE_SRC='assets/video/GuideVideoTutorial.mp4';
let guideRedirect=null;

function openGuide(redirectUrl){
 guideRedirect=redirectUrl;
 const ov=document.getElementById('guideOverlay'),v=document.getElementById('guideVideo');
 if(!v.getAttribute('src'))v.src=GUIDE_SRC;
 ov.classList.add('open');
 document.getElementById('guideSkip').focus();
 const p=v.play();
 if(p&&p.catch)p.catch(()=>{}); // if the browser blocks autoplay, the user can press play
}

async function afterLogin(redirectUrl){
 let show=false;
 try{
  const res=await fetch('api/guide.php',{method:'POST'});
  const d=await res.json();
  show=!!d.show;
 }catch{}
 if(show)openGuide(redirectUrl);
 else window.location.href=redirectUrl;
}

function finishGuide(){
 const ov=document.getElementById('guideOverlay');
 if(!ov.classList.contains('open'))return; // prevents a double redirect
 document.getElementById('guideVideo').pause();
 ov.classList.remove('open');
 const url=guideRedirect;guideRedirect=null;
 if(url)window.location.href=url;
}

document.getElementById('guideSkip').addEventListener('click',finishGuide);
document.getElementById('guideClose').addEventListener('click',finishGuide);
document.getElementById('guideVideo').addEventListener('ended',finishGuide);
document.getElementById('guideVideo').addEventListener('error',finishGuide); // video missing -> never block login
document.addEventListener('keydown',e=>{if(e.key==='Escape')finishGuide();});

function togglePw(){const f=document.getElementById('pwField');const b=f.nextElementSibling;const s=f.type==='password';f.type=s?'text':'password';b.textContent=s?'HIDE':'SHOW';}

function showOtpForm(email){
 const loginForm=document.getElementById('loginForm');
 const otpSection=document.getElementById('otpSection');
 const otpEmail=document.getElementById('otpEmail');
 const otpField=document.getElementById('otpField');
 loginForm.style.display='none';
 otpSection.style.display='block';
 otpEmail.value=email;
 otpField.value='';
 otpField.focus();
 document.getElementById('resendBox').style.display='none';
}

function hideOtpForm(){
 document.getElementById('otpSection').style.display='none';
 document.getElementById('loginForm').style.display='block';
 document.getElementById('loginMsg').style.display='none';
}

document.getElementById('otpBackBtn').addEventListener('click',()=>hideOtpForm());

document.getElementById('otpForm').addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('otpSubmitBtn');const msg=document.getElementById('loginMsg');const orig=btn.innerHTML;
 btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Verifying...';msg.style.display='none';
 try{
 const res=await fetch('login.php',{method:'POST',body:new FormData(e.target)});
 const data=await res.json();
 if(data.status==='success'){showMsg('success',data.message||'Signed in!');setTimeout(()=>afterLogin(data.redirect),500);}
 else{
 showMsg('error',data.message||'Invalid OTP.');
 btn.disabled=false;btn.innerHTML=orig;
 if(data.lockout){startLockTimer(30);}
 }
 }catch{showMsg('error','Connection error. Please try again.');btn.disabled=false;btn.innerHTML=orig;}
});

function startLockTimer(seconds) {
 const btn = document.getElementById('loginBtn');
 const msg = document.getElementById('loginMsg');
 btn.disabled = true;
 let timeLeft = seconds;

 if (timerInterval) clearInterval(timerInterval);

 showMsg('error', `Too many failed attempts. Please wait ${timeLeft} seconds before trying again.`);

 timerInterval = setInterval(() => {
 timeLeft--;
 btn.innerHTML = `<i class="fas fa-lock"></i> Wait ${timeLeft}s`;
 showMsg('error', `Too many failed attempts. Please wait ${timeLeft} seconds before trying again.`);
 if (timeLeft <= 0) {
 clearInterval(timerInterval);
 btn.disabled = false;
 btn.innerHTML = `<i class="fas fa-right-to-bracket"></i> <span id="loginBtnLabel">Sign In to Community Portal</span>`;
 msg.style.display = 'none';
 }
 }, 1000);
}

function resetLoginBtn(btn,orig){
 btn.disabled=false;
 const p = document.getElementById('loginBtnLabel');
 if(p){btn.innerHTML=`<i class="fas fa-right-to-bracket"></i> <span id="loginBtnLabel">Sign In to Community Portal</span>`;}
 else{btn.innerHTML=orig;}
}

document.getElementById('loginForm').addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('loginBtn');const msg=document.getElementById('loginMsg');const orig=btn.innerHTML;
 btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Signing in...';msg.style.display='none';
 try{
 const res=await fetch('login.php',{method:'POST',body:new FormData(e.target)});
 const data=await res.json();
 if(data.status==='success'){showMsg('success',data.message||'Signed in!');setTimeout(()=>afterLogin(data.redirect),500);}
 else if(data.status==='must_reset'){showMsg('success',data.message||'Redirecting to password setup...');setTimeout(()=>window.location.href=data.redirect,900);}
 else if(data.status==='otp_required'){
 showMsg('success',data.message||'Enter the 6-digit code from your email.');
 showOtpForm(data.email || document.getElementById('emailField').value);
 btn.disabled=false;
 btn.innerHTML=orig;
 }
 else{
 showMsg('error',data.message||'Sign in failed.');
 if(data.lockout) {
 startLockTimer(30);
 } else {
 resetLoginBtn(btn,orig);
 }
 if(data.unverified){document.getElementById('resendBox').style.display='block';document.getElementById('resendEmail').value=document.getElementById('emailField').value;}
 }
 }catch{showMsg('error','Connection error. Please try again.');resetLoginBtn(btn,orig);}
});

async function resend(){
 const email=document.getElementById('resendEmail').value.trim();const btn=document.getElementById('resendBtn');
 if(!email)return;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending...';
 const fd=new FormData();fd.append('portal','resend');fd.append('email',email);fd.append('password','__resend__');
 try{const res=await fetch('login.php',{method:'POST',body:fd});const data=await res.json();showMsg('success',data.message);btn.innerHTML='<i class="fas fa-check"></i> Sent!';}
 catch{btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Resend';}
}
function showMsg(type,text){const el=document.getElementById('loginMsg');el.className='msg '+type;el.textContent=text;el.style.display='block';}
function openTutorial(){document.getElementById('tutorialModal').classList.add('open');const v=document.getElementById('tutorialVideo');if(v)v.play().catch(()=>{});}
function closeTutorial(){const v=document.getElementById('tutorialVideo');if(v){v.pause();}document.getElementById('tutorialModal').classList.remove('open');}
document.addEventListener('click',function(e){const m=document.getElementById('tutorialModal');if(m&&e.target===m)closeTutorial();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeTutorial();});
</script>
<div class="video-modal" id="tutorialModal" role="dialog" aria-modal="true">
 <div class="video-card">
 <div class="video-head">
 <div class="video-title"><i class="fas fa-circle-play"></i> SenTri — How It Works (Community)</div>
 <button type="button" class="video-close" onclick="closeTutorial()" aria-label="Close"><i class="fas fa-xmark"></i></button>
 </div>
 <video id="tutorialVideo" src="assets/media/community-tutorial.mp4" controls preload="none" playsinline></video>
 </div>
</div>
</body>
</html>
