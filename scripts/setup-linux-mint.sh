#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# SenTri — Linux Mint / Ubuntu / Debian dev-environment bootstrap (capstone)
# Recreates the exact working stack used during development: PHP + MariaDB,
# fresh `sentri` database, offline-mail .env (signup works with NO internet),
# demo accounts, and the site on :8080 (+ debug.php console + smoke tests).
#
# Run:  sudo bash scripts/setup-linux-mint.sh
# Idempotent: safe to re-run; re-runs refresh .env flags + seed data.
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CREDS_FILE="$HOME/.sentry-db-creds"

say() { echo -e "\n\033[1;36m▸ $1\033[0m"; }
ok()  { echo -e "\033[1;32m✓\033[0m $1"; }
warn(){ echo -e "\033[1;33m!\033[0m $1"; }
die() { echo -e "\033[1;31m✗ $1\033[0m"; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo (needs apt + MySQL admin)."

# ── 1. System packages ───────────────────────────────────────────────────────
say "1/6 Installing PHP + MariaDB packages"
apt-get update -qq
apt-get install -yqq php-cli php-mysqli php-curl php-mbstring mariadb-server mariadb-client curl
ok "packages installed"

# ── 2. Start MariaDB ─────────────────────────────────────────────────────────
say "2/6 Starting MariaDB"
systemctl enable --now mariadb 2>/dev/null || service mariadb start || true
sleep 2
mysqladmin ping --silent && ok "mariadb is up" || die "mariadb did not start — check: systemctl status mariadb"

# ── 3. Database + dedicated dev user ─────────────────────────────────────────
say "3/6 Creating 'sentri' database + dev credentials"
if [[ -f $CREDS_FILE ]]; then
  # shellcheck disable=SC1090
  . "$CREDS_FILE"
else
  DB_PASS=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 20)
  printf 'DB_USER=sentri_dev\nDB_PASS=%s\n' "$DB_PASS" > "$CREDS_FILE"
  chmod 600 "$CREDS_FILE"
fi
# Try socket root (no password — Mint default when mariadb-autoconfigured):
if mysql --protocol=SOCKET -u root -e "CREATE DATABASE IF NOT EXISTS sentri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sentri_dev'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS 'sentri_dev'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON sentri.* TO 'sentri_dev'@'localhost' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON sentri.* TO 'sentri_dev'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;" 2>/dev/null; then
  ok "database + sentri_dev user ready (creds saved: $CREDS_FILE)"
else
  die "mysql socket auth as root failed. Run 'sudo mariadb-secure-installation' or launch mariadb with --skip-grant-tables once, then re-run this line manually (see README §3)."
fi

# ── 4. .env: offline mail outbox + env-auth ──────────────────────────────────
say "4/6 Writing .env (APP_DEBUG=1 → debug.php + offline mail outbox)"
cd "$REPO"
[[ -f .env ]] || cp .env.example .env
ensure() { grep -q "^$1=" .env && sed -i "s/^$1=.*/$2/" .env || echo "$2" >> .env; }
ensure APP_DEBUG "APP_DEBUG=1"
ensure MAIL_OUTBOX "MAIL_OUTBOX=1"
ensure DB_USER   "DB_USER=sentri_dev"
ensure DB_PASS   "DB_PASS=${DB_PASS}"
ensure DB_HOST   "DB_HOST=127.0.0.1"
ensure DB_NAME   "DB_NAME=sentri"
ensure APP_URL   "APP_URL=http://localhost:8080"
ok ".env ready"

# ── 5. Launch dev server, run installer, seed demo data ─────────────────────
say "5/6 Launching PHP dev server on :8080, running installer + seeder"
mkdir -p logs
if curl -s -o /dev/null -m 2 http://127.0.0.1:8080/login.php; then
  warn "something already listens on :8080 — assuming it is SenTri"
else
  nohup php -S 0.0.0.0:8080 -t "$REPO" > "$REPO/logs/dev-server.log" 2>&1 &
  sleep 3
fi
curl -s -o /dev/null -m 60 "http://127.0.0.1:8080/install.php" || warn "installer HTTP call failed"
curl -s -o /dev/null -w "install.php → %{http_code}\n" -m 60 "http://127.0.0.1:8080/install.php"
if [[ -f config/install.lock ]]; then ok "install.lock created (installer closed)"; else warn "install.lock missing — check install.php HTTP status above"; fi

php scripts/seed-demo.php && ok "demo users + sample incidents seeded"

# ── 6. Where to go next ──────────────────────────────────────────────────────
say "6/6 DONE"
ok "Live site:       http://localhost:8080"
ok "Debug console:   http://localhost:8080/debug.php  (offline verification links live here)"
ok "Full smoke test: BASE=http://localhost:8080 bash scripts/smoke-test.sh"
echo "  Demo accounts: community@ / barangay@ / responder@ / lgu@demo.ph → Demo@1234 · admin@sentri.ph → Admin@1234"
echo "  Signing up NEW accounts? No internet needed — APP_DEBUG diverts emails to logs/mail/ and"
echo "  debug.php lists them with clickable Verify links."
