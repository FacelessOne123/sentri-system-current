#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# SenTri GPS Bridge launcher
#
# Starts the local bridge daemon that reports this machine's GPS position to
# the SenTri server. Use it when the browser cannot obtain a position (the
# app is opened over plain HTTP on the LAN, so the browser blocks geolocation).
#
# Usage
#   bash tools/gps-bridge/start.sh --server=http://192.168.1.10/sentri-system
#   bash tools/gps-bridge/start.sh --server=... --port=8787 --label="Tablet"
#
# The daemon listens on 127.0.0.1 only by default. Pass --allow-lan=1 to let
# other devices on the LAN post fixes to it as well.
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "ERROR: php not found. Install PHP or set PHP_BIN=/path/to/php" >&2
  exit 1
fi

SERVER=""
PORT="8787"
LABEL="GPS bridge ($(hostname 2>/dev/null || echo local))"
ALLOW_LAN="0"

for arg in "$@"; do
  case "$arg" in
    --server=*) SERVER="${arg#*=}" ;;
    --port=*)   PORT="${arg#*=}" ;;
    --label=*)  LABEL="${arg#*=}" ;;
    --allow-lan=*) ALLOW_LAN="${arg#*=}" ;;
    --help|-h)
      sed -n '2,18p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo "WARN: unknown option $arg" >&2 ;;
  esac
done

if [[ -z "$SERVER" ]]; then
  cat >&2 <<'EOF'
ERROR: --server is required.

  bash tools/gps-bridge/start.sh --server=http://192.168.1.10/sentri-system

The server URL is the address of the machine running SenTri.
EOF
  exit 1
fi

echo "SenTri GPS Bridge"
echo "  server : $SERVER"
echo "  port   : $PORT"
echo "  label  : $LABEL"
echo "  bind   : $([[ "$ALLOW_LAN" == "1" ]] && echo '0.0.0.0 (LAN reachable)' || echo '127.0.0.1 (this machine only)')"
echo
echo "Open http://127.0.0.1:$PORT/ to see the status page,"
echo "then generate a pairing code in SenTri -> My Profile -> GPS Bridge."
echo

exec "$PHP_BIN" -S "127.0.0.1:$PORT" "$HERE/router.php" --server="$SERVER" --port="$PORT" --label="$LABEL" ${ALLOW_LAN:+--allow-lan=$ALLOW_LAN}
