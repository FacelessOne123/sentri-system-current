#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# SenTri — full-stack smoke test (the same battery run during the audit)
# Usage: BASE=http://localhost:8080 bash scripts/smoke-test.sh
# Requires: curl, mysql (only for ADB-root passwordless dev, or unset for skip)
# Safe to re-run — data it creates is [SMOKE]-tagged and wiped at the end.
# ═══════════════════════════════════════════════════════════════════════════
BASE="${BASE:-http://127.0.0.1:4446}"
MYSQL_CMD="${MYSQL_CMD:-mysql --skip-ssl -h 127.0.0.1 -u root sentri}"
PASS=0; FAIL=0; SKIP=0
JDIR=$(mktemp -d); trap 'rm -rf "$JDIR"' EXIT
html_file="tests-report-$$.html"

say()  { printf '\n\033[1;36m▸ %s\033[0m\n' "$1"; }
p()    { PASS=$((PASS+1)); printf '  \033[0;32mPASS\033[0m — %s\n' "$1"; }
f()    { FAIL=$((FAIL+1)); printf '  \033[0;31mFAIL\033[0m — %s\n' "$1"; }
s()    { SKIP=$((SKIP+1)); printf '  \033[0;33mSKIP\033[0m — %s\n' "$1"; }

# ── login helper: store realm cookies in a urlencoded curl jar ─────────────
login() { # jar role email password → echoes sid
  local jar="$JDIR/$1.jar" role="$2" email="$3" pass="$4"
  local page portal porturl
  case "$role" in
    community) page="$BASE/login.php";     portal="community";      porturl="$BASE/portal/community.php";;
    barangay)  page="$BASE/gov/login.php"; portal="barangay";       porturl="$BASE/portal/barangay.php";;
    lgu)       page="$BASE/gov/login.php"; portal="lgu";            porturl="$BASE/portal/lgu.php";;
    responder) page="$BASE/gov/login.php"; portal="first_responder";porturl="$BASE/portal/responder.php";;
    admin)     page="$BASE/admin.php";     portal="admin";          porturl="$BASE/admin.php";;
  esac
  rm -f "$jar"
  curl -s -c "$jar" "$page" -o /dev/null
  local out
  out=$(curl -s -b "$jar" -c "$jar" -X POST "$page" --data-urlencode "portal=$portal" --data-urlencode "email=$email" --data-urlencode "password=$pass")
  echo "$out" | grep -q '"status":"success"' || { echo "LOGIN FAILED for $role: $(echo "$out" | head -c 140)"; return 1; }
  local code
  code=$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$porturl")
  if [[ $code != 200 ]]; then
    echo "LANDING FAILED for $role (HTTP $code) — jar trace:" >&2
    cat "$jar" 2>/dev/null >&2
    return 1
  fi
  # sid used for browser shots/tests:
  grep -oiE '(sentri-(comm|gov|admin))\s.*' "$jar" | awk '{print $NF}' | head -1
}
csrf_for() { # jar url → csrf token meta
  curl -s -b "$1" "$2" | grep -oE 'meta name="csrf-token" content="[^"]+"' | head -1 | sed 's/.*content="//;s/"//'
}

say "0. Public surfaces reachable"
for p in index.php login.php signup.php admin.php verify_email.php forgot_password.php reset_password.php gov/index.php gov/login.php install.php debug.php; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -m 10 "$BASE/$p")
  # install.php may legitimately return 403 after a successful install (locked)
  [[ $code == 200 ]] || { [[ $p == install.php && $code == 403 && -f config/install.lock ]]; } && p "$p → $code" || f "$p → $code"
done

say "0b. GPS fallback chain (live → last known → registered address)"
GJJ="$JDIR/gps.jar"
curl -s -c "$GJJ" "$BASE/login.php" -o /dev/null
curl -s -b "$GJJ" -c "$GJJ" -X POST "$BASE/login.php" \
  --data-urlencode "portal=community" --data-urlencode "email=community@demo.ph" \
  --data-urlencode "password=Demo@1234" > /dev/null
GTC=$(csrf_for "$GJJ" "$BASE/portal/community.php")

PORTAL=$(curl -s -b "$GJJ" "$BASE/portal/community.php")

# The report modal must never auto-place a pin from the registered address.
echo "$PORTAL" | grep -q 'reportRoutingHint' \
  && p "report modal shows which barangay the pin routes to" \
  || f "report modal is missing the routing hint"
echo "$PORTAL" | grep -q 'Use My Location.*only if the incident is happening where you are' \
  && p "report modal warns that Use My Location is only for the current spot" \
  || f "report modal is missing the Use My Location warning"

# The registered address must be exposed as identity context, clearly named.
echo "$PORTAL" | grep -q "Registered Address" \
  && p "GPS chip names the registered-address state honestly" \
  || f "GPS chip is missing the Registered Address state"

# The server-side last-known fix must be rendered for the community account.
if [[ -n $MYSQL_CMD ]]; then
  $MYSQL_CMD -e "UPDATE users SET gps_lat=14.4218810,gps_lng=120.9160660,gps_accuracy=12.50,gps_at=NOW(),gps_source='browser' WHERE email='community@demo.ph';" >/dev/null 2>&1 || true
  PORTAL2=$(curl -s -b "$GJJ" "$BASE/portal/community.php")
  echo "$PORTAL2" | grep -q '"lat":14.421881' \
    && p "last-known position is rendered into the portal" \
    || f "last-known position was not rendered"
  # A registered barangay must yield a home-area centre.
  echo "$PORTAL2" | grep -q '"barangay":"' \
    && p "registered barangay is available as home-area context" \
    || f "home-area context missing"
fi

# The last-known cache must be scoped per account, never shared between users.
echo "$PORTAL" | grep -q 'sentri-user' \
  && p "last-fix cache is scoped per account" \
  || f "portal is missing the sentri-user scoping meta tag"

# The bridge API must be gone entirely.
BCODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/gps_bridge.php")
[[ $BCODE == 404 ]] && p "removed GPS bridge API is no longer reachable" || f "gps_bridge.php still responds ($BCODE)"
say "0c. Barangay address-term suggestions"
AT=$(curl -s "$BASE/api/address_terms.php?barangay=Bucandala%20I")
echo "$AT" | grep -q '"status":"success"' && p "address API accepts a valid barangay" || f "address API valid-barangay failed: $(echo "$AT" | head -c 160)"
ATC=$(echo "$AT" | grep -oE '"count":[0-9]+' | head -1 | cut -d: -f2)
[[ ${ATC:-0} -gt 0 ]] && p "Bucandala I has $ATC address terms" || f "expected address terms for Bucandala I"
echo "$AT" | grep -q '"source":"database"\|"source":"snapshot"' && p "address API reports its data source" || f "address API source missing"
echo "$AT" | grep -q 'OpenStreetMap contributors' && p "address API returns OSM attribution" || f "address API attribution missing"
BADT=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/address_terms.php?barangay=Atlantis")
[[ $BADT == 400 ]] && p "address API rejects an unknown barangay (400)" || f "unknown barangay expected 400, got $BADT"
MISST=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/address_terms.php")
[[ $MISST == 400 ]] && p "address API rejects a missing barangay (400)" || f "missing barangay expected 400, got $MISST"
MAG=$(curl -s "$BASE/api/address_terms.php?barangay=Magdalo")
echo "$AT" | grep -qi 'Aguado Street' && f "address terms leaked across barangays" || p "no cross-barangay term leak"
echo "$MAG" | grep -q 'Aguado Street' && p "Magdalo returns its own streets" || f "Magdalo street lookup failed"
QF=$(curl -s "$BASE/api/address_terms.php?barangay=Bucandala%20I&q=street")
QFC=$(echo "$QF" | grep -oE '"count":[0-9]+' | head -1 | cut -d: -f2)
[[ ${QFC:-0} -gt 0 && ${QFC:-0} -le ${ATC:-0} ]] && p "address API text filter narrows results ($QFC/$ATC)" || f "address API filter failed ($QFC/$ATC)"
COV=$(php -r '$p=$argv[1]."/assets/geo/imus-address-terms.json";$d=json_decode(@file_get_contents($p),true);if(!is_array($d)||!is_array($d["barangays"]??null)){echo "0/0";exit;}$m=0;foreach($d["barangays"] as $b){if(!$b)$m++;}echo count($d["barangays"])."/".$m;' "$(dirname "$0")/.." 2>/dev/null)
[[ $COV == "95/0" ]] && p "address snapshot covers all 95 barangays" || f "address snapshot coverage: $COV"
curl -s "$BASE/signup.php" | grep -q 'sentri-address-autocomplete.js' && p "signup loads the address-autocomplete module" || f "signup is missing the autocomplete script"

say "0d. Consultation remarks (NOV 9)"
LOGIN_HTML=$(curl -s "$BASE/login.php")
echo "$LOGIN_HTML" | grep -q 'class="auth-split"' && p "login page uses the taller split layout" || f "login split layout missing"
echo "$LOGIN_HTML" | grep -q 'id="mediaSmaller"' && p "login tutorial video can be shrunk" || f "tutorial shrink control missing"
echo "$LOGIN_HTML" | grep -q 'id="mediaLarger"' && p "login tutorial video can be enlarged" || f "tutorial enlarge control missing"
echo "$LOGIN_HTML" | grep -q 'id="mediaFull"' && p "login tutorial video can go fullscreen" || f "tutorial fullscreen control missing"

PORTAL_HTML=$(curl -s -b "$GJJ" "$BASE/portal/community.php")
echo "$PORTAL_HTML" | grep -q 'id="timeFilter"' && p "feed has a time-range filter" || f "time-range filter missing"
echo "$PORTAL_HTML" | grep -q 'id="other_text"' && p "Other category has a free-text input" || f "Other free-text input missing"

# The free-text type must round-trip to the database.
if [[ -n $MYSQL_CMD && -n ${GTC:-} ]]; then
  CT_RESP=$(curl -s -b "$GJJ" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=post_report" -F "csrf_token=$GTC" -F "title=[CONSULT] Smoke other" \
    -F "description=consultation fixture" -F "location_name=site" -F "city=Imus" \
    -F "category=other" -F "other_text=Smoke custom type" -F "status=caution" \
    -F "latitude=14.421881" -F "longitude=120.916066")
  echo "$CT_RESP" | grep -q '"status":"success"' \
    && p "report with a custom 'Other' type is accepted" \
    || f "custom-type report failed: $CT_RESP"
  CT_DB=$($MYSQL_CMD -N -e "SELECT custom_type FROM reports WHERE title='[CONSULT] Smoke other' ORDER BY id DESC LIMIT 1" 2>/dev/null)
  [[ "$CT_DB" == "Smoke custom type" ]] \
    && p "custom 'Other' type is stored" \
    || f "custom type not stored (got: $CT_DB)"
  CT_API=$(curl -s -b "$GJJ" "$BASE/api/reports.php?action=get_reports&limit=5")
  echo "$CT_API" | grep -q '"custom_type":"Smoke custom type"' \
    && p "custom 'Other' type is returned by the API" \
    || f "custom type missing from get_reports"
  $MYSQL_CMD -e "DELETE FROM reports WHERE title='[CONSULT] Smoke other';" >/dev/null 2>&1 || true
else
  s "custom-type round-trip needs mysql CLI and a signed-in session"
fi

say "1. All five role logins land on their portals"
CS=$(login community community community@demo.ph Demo@1234) && p "community (sentri-comm)" || f "community"
BS=$(login barangay barangay barangay@demo.ph Demo@1234)  && p "barangay (sentri-gov)"   || f "barangay"
RS=$(login responder responder responder@demo.ph Demo@1234) && p "responder (sentri-gov)" || f "responder"
LS=$(login lgu lgu lgu@demo.ph Demo@1234)            && p "lgu (sentri-gov)"        || f "lgu"
AS=$(login admin admin admin@sentri.ph Admin@1234)     && p "admin (sentri-admin)"    || f "admin"

say "2. Full cross-realm transaction chain (community → barangay → LGU → responder → admin)"
# Residence is required for reporting now; seed the demo community fixture.
$MYSQL_CMD -e "UPDATE users SET barangay_name='Bucandala I', resident_address='123 Smoke Test St., Purok 1', barangay_changed_at=NULL WHERE email='community@demo.ph';" >/dev/null 2>&1 \
  && p "community residence fixture ensured" || s "residence fixture needs mysql CLI"
TC=$(csrf_for "$JDIR/community.jar" "$BASE/portal/community.php")

# Pins outside Imus must be rejected — the pin is the only location source
OUT_IMUS=$(curl -s -b "$JDIR/community.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
  -F "action=post_report" -F "csrf_token=$TC" -F "title=[SMOKE] Outside Imus" \
  -F "description=smoke-test fixture" -F "location_name=Manila site" \
  -F "city=Imus" -F "category=infrastructure" -F "status=dangerous" \
  -F "latitude=14.5995" -F "longitude=120.9842" -F "radius_m=120")
echo "$OUT_IMUS" | grep -q "Pin the incident inside an Imus barangay" \
  && p "outside-Imus report rejected by server rule" || f "outside-Imus expected reject: $OUT_IMUS"

CENTER=$(node -e 'const fs=require("fs");const fc=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));const b=fc.features.find(f=>f.properties.name==="Bucandala I");console.log(b.properties.center.join(" "))' "$PWD/assets/geo/imus-barangays.geojson" 2>/dev/null)
if [[ -z $CENTER ]]; then s "geojson/node not available for center lookup"
else
  set -- $CENTER; LNG=$1; LAT=$2
  RESP=$(curl -s -b "$JDIR/community.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=post_report" -F "csrf_token=$TC" -F "title=[SMOKE] Probe lamp" \
    -F "description=smoke-test fixture" -F "location_name=Bucandala I sample site" \
    -F "city=Imus" -F "category=infrastructure" -F "status=dangerous" \
    -F "latitude=$LAT" -F "longitude=$LNG" -F "radius_m=120")
  RID=$(echo "$RESP" | grep -oE '"id":[0-9]+' | grep -oE '[0-9]+')
  [[ -n $RID ]] && p "post_report created id=$RID" || f "post_report: $RESP"
  BRGY=${MYSQL_CMD:+$($MYSQL_CMD -N -e "SELECT barangay FROM reports WHERE id=$RID" 2>/dev/null)}
  [[ $BRGY == "Bucandala I" ]] && p "server tagged barangay=Bucandala I (geometry wins)" || f "barangay tag got '${BRGY:-?}' (expected Bucandala I)"
  FEED=$(curl -s -b "$JDIR/community.jar" "$BASE/api/reports.php?action=get_reports")
  if echo "$FEED" | grep -q "poster_address\|poster_barangay"; then
    f "community feed leaks reporter residence (poster_address/poster_barangay)"
  else
    p "community feed omits reporter residence (privacy)"
  fi
  TV=$(curl -s -b "$JDIR/community.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=vote" -F "csrf_token=$TC" -F "report_id=$RID" -F "vote=up")
  echo "$TV" | grep -q '"status":"success"' && p "vote counted server-side" || f "vote: $TV"
  TB=$(csrf_for "$JDIR/barangay.jar" "$BASE/portal/barangay.php")
  if [[ -z $TB ]]; then
    echo "  ⚠ DEBUG: barangay.php returned no token — jar contents:" >&2
    cat "$JDIR/barangay.jar" >&2
    echo "  ⚠ DEBUG: headers:" >&2
    curl -s -D - -o /dev/null -b "$JDIR/barangay.jar" "$BASE/portal/barangay.php" | head -12 >&2
  fi
  ST=$(curl -s -b "$JDIR/barangay.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=update_report_status" -F "csrf_token=$TB" -F "report_id=$RID" -F "status=dangerous")
  echo "$ST" | grep -q success && p "barangay status update ok" || f "status update: $ST"

  # Cross-barangay isolation test: official of Bucandala I cannot update a report in another barangay (e.g. Pasong Buaya II)
  X_ST=$(curl -s -b "$JDIR/barangay.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=update_report_status" -F "csrf_token=$TB" -F "report_id=72" -F "status=safe")
  echo "$X_ST" | grep -q "You can only update reports in your assigned barangay" \
    && p "cross-barangay report update rejected (official scoped to own barangay)" \
    || f "cross-barangay update expected rejection: $X_ST"
  LS2=$(login lgu lgu lgu@demo.ph Demo@1234) && p "re-login LGU for chain" || s "lgu re-login issue"
  TL=$(csrf_for "$JDIR/lgu.jar" "$BASE/portal/lgu.php")
  TRP=${MYSQL_CMD:+$($MYSQL_CMD -N -e "SELECT id FROM users WHERE email='responder@demo.ph'" 2>/dev/null)}
  [[ -n $TRP ]] || TRP=9
  DISP=$(curl -s -b "$JDIR/lgu.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=lgu_dispatch" -F "csrf_token=$TL" -F "report_id=$RID" -F "responder_id=$TRP")
  echo "$DISP" | grep -q success && p "LGU dispatch ok" || f "dispatch: $DISP"
  # Responder-first lock: once dispatched, Brgy/LGU cannot resolve it.
  LOCK_RES=$(curl -s -b "$JDIR/lgu.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=resolve_report" -F "csrf_token=$TL" -F "report_id=$RID")
  echo "$LOCK_RES" | grep -q "must resolve it first" \
    && p "LGU resolve blocked while responder is assigned" || f "LGU resolve should be blocked: $LOCK_RES"
  RS2=$(login responder responder responder@demo.ph Demo@1234) && p "re-login responder for chain" || s "responder re-login issue"
  TR=$(csrf_for "$JDIR/responder.jar" "$BASE/portal/responder.php")
  for ACT in accept_assignment report_responded resolve_report; do
    OUT=$(curl -s -b "$JDIR/responder.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
      -F "action=$ACT" -F "csrf_token=$TR" -F "report_id=$RID")
    echo "$OUT" | grep -q success && p "$ACT ok" || f "$ACT: $OUT"
  done
  TA=$(csrf_for "$JDIR/admin.jar" "$BASE/admin.php")
  AGR=$(curl -s -b "$JDIR/admin.jar" "$BASE/api/reports.php?action=admin_get_reports&csrf_token=$TA")
  [[ $(echo "$AGR" | grep -c "$RID") -ge 1 ]] && p "admin sees the incident" || f "admin_get_reports missing $RID"
  $MYSQL_CMD -e "DELETE r FROM reports r WHERE r.id='$RID'; DELETE l FROM report_audit_logs l LEFT JOIN reports r ON r.id=l.report_id WHERE r.id IS NULL;" >/dev/null 2>&1 && p "smoke fixture cleaned up" || s "cleanup needs mysql CLI"
fi

say "3. Dual session — community AND barangay alive on one browser"
DJ="$JDIR/dual2.jar"
cat "$JDIR/community.jar" "$JDIR/barangay.jar" > "$DJ"
C1=$(curl -s -b "$DJ" -o /dev/null -w '%{http_code}' "$BASE/portal/community.php")
C2=$(curl -s -b "$DJ" -o /dev/null -w '%{http_code}' "$BASE/portal/barangay.php")
[[ $C1 == 200 && $C2 == 200 ]] && p "both portals 200 with both cookies present" || f "community=$C1 barangay=$C2"

say "4. Realm-isolated logout (USE FRESH SESSION COPIES — logging out gov destroys that realm's server session for everyone logged in as it)"
DJ2="$JDIR/dual3.jar"
cat "$JDIR/community.jar" "$JDIR/barangay.jar" > "$DJ2"
curl -s -b "$DJ2" -c "$DJ2" -o /dev/null "$BASE/gov/logout.php"
C1=$(curl -s -b "$DJ2" -o /dev/null -w '%{http_code}' "$BASE/portal/community.php")
C2=$(curl -s -b "$DJ2" -o /dev/null -w '%{http_code}' "$BASE/portal/barangay.php")
[[ $C1 == 200 && $C2 != 200 ]] && p "gov logout leaves community signed in" || f "community=$C1 barangay=$C2 (expected 200 / non-200)"

say "5. Offline email outbox (APP_DEBUG=1 → logs/mail)"
[[ -f "$PWD/logs/mail" || $(ls "$PWD/logs/mail"/*.eml 2>/dev/null | wc -l) -ge 0 ]] && p "email outbox directory exists" || s "APP_DEBUG off — mail will try live SMTP"
STAMP=$(date +%s)
SRES=$(curl -s -X POST "$BASE/signup.php" -A "Mozilla/5.0" \
  --data-urlencode "first_name=SmokeT" --data-urlencode "last_name=User" \
  --data-urlencode "email=smoke.t$STAMP@demo.ph" --data-urlencode "password=Smoke@1234" \
  --data-urlencode "confirm_password=Smoke@1234" --data-urlencode "phone=09171234567" \
  --data-urlencode "barangay_name=Bucandala I" --data-urlencode "resident_address=123 Smoke Test St., Purok 1")
echo "$SRES" | grep -q '"success"' && p "signup OK" || f "signup: $SRES"
LV=$(find "$PWD/logs/mail" -maxdepth 1 -name '*.eml' -newermt "@$STAMP" 2>/dev/null | sort | tail -1)
if [[ -n $LV ]]; then
  VL=$(grep -ohE "https?://[^\"' <]*verify_email\.php\?token=[a-z0-9]+" "$LV" | tail -1)
  [[ -n $VL ]] && p "verification link captured in outbox" || f "no verification link in $LV"
  if [[ -n $VL ]]; then
    curl -s "$VL" > /dev/null
    UJ="$JDIR/smoke_unassigned.jar"
    curl -s -c "$UJ" "$BASE/login.php" -o /dev/null
    curl -s -b "$UJ" -c "$UJ" -X POST "$BASE/login.php" \
      --data-urlencode "email=smoke.t$STAMP@demo.ph" --data-urlencode "password=Smoke@1234" > /dev/null
    UN_REDIR=$(curl -s -b "$UJ" -o /dev/null -w '%{redirect_url}' "$BASE/portal/community.php?view=overview")
    [[ -z $UN_REDIR || $UN_REDIR != *"view=map&locked=1"* ]] && p "member reaches overview" || f "unexpected locked redirect: $UN_REDIR"
    UN_TC=$(csrf_for "$UJ" "$BASE/portal/community.php?view=overview")

    # Missing residence must block reporting even with a valid pin.
    if [[ -n $MYSQL_CMD ]]; then
      $MYSQL_CMD -e "UPDATE users SET resident_address=NULL WHERE email='smoke.t$STAMP@demo.ph';" >/dev/null 2>&1 || true
      CONTACTS_HTML=$(curl -s -b "$UJ" "$BASE/portal/community.php?view=contacts")
      echo "$CONTACTS_HTML" | grep -q 'id="residenceGate"' \
        && f "emergency contacts blocked by the residence gate" || p "emergency contacts stay usable without a residence"
      NO_RES=$(curl -s -b "$UJ" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
        -F "action=post_report" -F "csrf_token=$UN_TC" -F "title=[SMOKE] No residence" \
        -F "description=fixture" -F "location_name=site" -F "city=Imus" \
        -F "category=infrastructure" -F "status=dangerous" -F "latitude=14.421881" -F "longitude=120.916066")
      echo "$NO_RES" | grep -q "complete your resident profile" \
        && p "report blocked without a resident profile" || f "expected resident-profile block: $NO_RES"
      $MYSQL_CMD -e "UPDATE users SET resident_address='123 Smoke Test St., Purok 1' WHERE email='smoke.t$STAMP@demo.ph';" >/dev/null 2>&1 || true
    else
      s "resident-profile gate test needs mysql CLI"
    fi
    UN_POST=$(curl -s -b "$UJ" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
      -F "action=post_report" -F "csrf_token=$UN_TC" -F "title=[SMOKE] Pin-based" \
      -F "description=fixture" -F "location_name=site" -F "city=Imus" \
      -F "category=infrastructure" -F "status=dangerous" -F "latitude=14.421881" -F "longitude=120.916066")
    echo "$UN_POST" | grep -q '"status":"success"' \
      && p "member with residence can report from the pin" || f "pin-based report expected success: $UN_POST"
    $MYSQL_CMD -e "DELETE FROM reports WHERE title LIKE '[SMOKE] %';" >/dev/null 2>&1 || true

    # Barangay change lock (1 minute in testing): recent change is rejected,
    # backdating the timestamp unlocks it.
    if [[ -n $MYSQL_CMD ]]; then
      $MYSQL_CMD -e "UPDATE users SET barangay_changed_at=NOW() WHERE email='smoke.t$STAMP@demo.ph';" >/dev/null 2>&1 || true
      LCK=$(curl -s -b "$UJ" -X POST "$BASE/portal/community.php?view=profile" \
        --data-urlencode "save_profile=1" --data-urlencode "first_name=SmokeT" --data-urlencode "last_name=User" \
        --data-urlencode "phone=09171234567" --data-urlencode "barangay_name=Alapan I-A" \
        --data-urlencode "resident_address=123 Smoke Test St., Purok 1")
      echo "$LCK" | grep -q "Barangay was not changed" \
        && p "barangay change blocked by 1-minute lock" || f "expected barangay lock message"
      LCK_BRGY=$($MYSQL_CMD -N -e "SELECT barangay_name FROM users WHERE email='smoke.t$STAMP@demo.ph'" 2>/dev/null)
      [[ -z $LCK_BRGY || $LCK_BRGY == "Bucandala I" ]] && p "locked change kept the original barangay" || f "barangay changed despite lock: $LCK_BRGY"

      $MYSQL_CMD -e "UPDATE users SET barangay_changed_at=NOW() - INTERVAL 2 MINUTE WHERE email='smoke.t$STAMP@demo.ph';" >/dev/null 2>&1 || true
      UNLCK=$(curl -s -b "$UJ" -X POST "$BASE/portal/community.php?view=profile" \
        --data-urlencode "save_profile=1" --data-urlencode "first_name=SmokeT" --data-urlencode "last_name=User" \
        --data-urlencode "phone=09171234567" --data-urlencode "barangay_name=Alapan I-A" \
        --data-urlencode "resident_address=123 Smoke Test St., Purok 1")
      UNLCK_BRGY=$($MYSQL_CMD -N -e "SELECT barangay_name FROM users WHERE email='smoke.t$STAMP@demo.ph'" 2>/dev/null)
      [[ -z $UNLCK_BRGY || $UNLCK_BRGY == "Alapan I-A" ]] && p "barangay change allowed after lock expires" || f "unlock failed: $UNLCK_BRGY"
    else
      s "barangay lock tests need mysql CLI"
    fi
  fi
else
  s "no outbox mail (live SMTP expected; add APP_DEBUG=1 to .env for offline)"
fi
$MYSQL_CMD -e "DELETE FROM users WHERE email LIKE 'smoke.t%@demo.ph';" >/dev/null 2>&1 && p "smoke signup users cleaned" || s "smoke-user cleanup needs mysql CLI"

printf '\n══════════ RESULTS ══════════\n\033[0;32mPASS: %s\033[0m   \033[0;31mFAIL: %s\033[0m   \033[0;33mSKIP: %s\033[0m\n' "$PASS" "$FAIL" "$SKIP"
[[ $FAIL -eq 0 ]] || exit 1
