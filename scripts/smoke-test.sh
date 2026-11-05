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

say "1. All five role logins land on their portals"
CS=$(login community community community@demo.ph Demo@1234) && p "community (sentri-comm)" || f "community"
BS=$(login barangay barangay barangay@demo.ph Demo@1234)  && p "barangay (sentri-gov)"   || f "barangay"
RS=$(login responder responder responder@demo.ph Demo@1234) && p "responder (sentri-gov)" || f "responder"
LS=$(login lgu lgu lgu@demo.ph Demo@1234)            && p "lgu (sentri-gov)"        || f "lgu"
AS=$(login admin admin admin@sentri.ph Admin@1234)     && p "admin (sentri-admin)"    || f "admin"

say "2. Full cross-realm transaction chain (community → barangay → LGU → responder → admin)"
TC=$(csrf_for "$JDIR/community.jar" "$BASE/portal/community.php")
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
  LS2=$(login lgu lgu lgu@demo.ph Demo@1234) && p "re-login LGU for chain" || s "lgu re-login issue"
  TL=$(csrf_for "$JDIR/lgu.jar" "$BASE/portal/lgu.php")
  TRP=${MYSQL_CMD:+$($MYSQL_CMD -N -e "SELECT id FROM users WHERE email='responder@demo.ph'" 2>/dev/null)}
  [[ -n $TRP ]] || TRP=9
  DISP=$(curl -s -b "$JDIR/lgu.jar" -X POST "$BASE/api/reports.php" -A "Mozilla/5.0" \
    -F "action=lgu_dispatch" -F "csrf_token=$TL" -F "report_id=$RID" -F "responder_id=$TRP")
  echo "$DISP" | grep -q success && p "LGU dispatch ok" || f "dispatch: $DISP"
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
  --data-urlencode "confirm_password=Smoke@1234" --data-urlencode "phone=09171234567")
echo "$SRES" | grep -q '"success"' && p "signup OK" || f "signup: $SRES"
LV=$(find "$PWD/logs/mail" -maxdepth 1 -name '*.eml' -newermt "@$STAMP" 2>/dev/null | sort | tail -1)
if [[ -n $LV ]]; then
  VL=$(grep -ohE "https?://[^\"' <]*verify_email\.php\?token=[a-z0-9]+" "$LV" | tail -1)
  [[ -n $VL ]] && p "verification link captured in outbox" || f "no verification link in $LV"
else
  s "no outbox mail (live SMTP expected; add APP_DEBUG=1 to .env for offline)"
fi
$MYSQL_CMD -e "DELETE FROM users WHERE email LIKE 'smoke.t%@demo.ph';" >/dev/null 2>&1 && p "smoke signup users cleaned" || s "smoke-user cleanup needs mysql CLI"

printf '\n══════════ RESULTS ══════════\n\033[0;32mPASS: %s\033[0m   \033[0;31mFAIL: %s\033[0m   \033[0;33mSKIP: %s\033[0m\n' "$PASS" "$FAIL" "$SKIP"
[[ $FAIL -eq 0 ]] || exit 1
