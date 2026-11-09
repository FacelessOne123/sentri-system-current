<?php
/**
 * tools/gps-bridge/status-page.php - status page template
 *
 * Included by bridge.php with these variables in scope:
 *   $page['paired'], $page['server'], $page['label'],
 *   $page['last_fix'], $page['bound'], $page['port']
 */
$paired = !empty($page['paired']);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SenTri GPS Bridge</title>
<style>
 body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f7fb;color:#16233a;margin:0;padding:28px;}
 .card{max-width:620px;margin:0 auto;background:#fff;border-radius:14px;padding:26px;box-shadow:0 10px 30px rgba(20,40,80,.08);}
 h1{margin:0 0 4px;font-size:1.2rem;} p.sub{margin:0 0 20px;color:#5b6b84;font-size:.9rem;}
 .row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid #eef2f7;font-size:.9rem;}
 .row:last-child{border-bottom:none;}
 .ok{color:#0f8a4f;font-weight:700;} .bad{color:#c0392b;font-weight:700;}
 code{background:#f1f4f9;padding:2px 6px;border-radius:5px;font-size:.82rem;word-break:break-all;}
 form{margin-top:20px;display:grid;gap:10px;} input{padding:10px;border:1px solid #ccd6e4;border-radius:8px;font-size:.9rem;}
 button{padding:10px 16px;border:none;border-radius:8px;background:#1c57b2;color:#fff;font-weight:700;font-size:.9rem;cursor:pointer;}
 .hint{font-size:.78rem;color:#6b7b93;line-height:1.6;}
</style></head><body><div class="card">
<h1>SenTri GPS Bridge</h1>
<p class="sub">Bridges this machine's GPS to the SenTri server.</p>
<div class="row"><span>Paired</span><span class="<?= $paired ? 'ok' : 'bad' ?>"><?= $paired ? 'Yes' : 'No' ?></span></div>
<div class="row"><span>SenTri server</span><span><code><?= $page['server'] ?></code></span></div>
<div class="row"><span>Device label</span><span><?= $page['label'] ?></span></div>
<div class="row"><span>Last fix sent</span><span><?= $page['last_fix'] ?></span></div>
<div class="row"><span>Listening on</span><span><code><?= $page['bound'] ?></code></span></div>
<form onsubmit="event.preventDefault();fetch('/pair',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({server:document.getElementById('server').value,code:document.getElementById('code').value,label:document.getElementById('label').value})}).then(r=>r.json()).then(d=>{document.getElementById('msg').textContent=d.message;});">
  <input id="server" placeholder="SenTri server URL (e.g. http://192.168.1.10/sentri-system)" value="<?= $page['server'] ?>">
  <input id="code" placeholder="8-digit pairing code from My Profile">
  <input id="label" placeholder="Device label" value="<?= $page['label'] ?>">
  <button type="submit">Pair device</button>
  <div id="msg" class="hint"></div>
</form>
<p class="hint">Generate the pairing code in SenTri &rarr; My Profile &rarr; GPS Bridge. The code is single-use and expires in 15 minutes.<br>
Send fixes with:<br><code>curl -X POST http://127.0.0.1:<?= (int)$page['port'] ?>/update -d '{"lat":14.4218,"lng":120.9160,"accuracy":12}'</code></p>
</div></body></html>
