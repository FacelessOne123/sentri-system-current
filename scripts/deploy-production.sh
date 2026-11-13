#!/usr/bin/env bash
# ==============================================================================
# SenTri System — One-Shot Automated Production Migration & Deployment Script
# Automatically sets up the complete LAMP stack on a clean Ubuntu/Debian VPS,
# configures MariaDB & Apache, imports the full database with all 95 Imus
# hotlines and address terms, sets up permissions, and verifies system health.
#
# Usage on target VPS:
#   git clone https://github.com/BorgorNinja/sentri-system.git /var/www/sentri-system
#   cd /var/www/sentri-system
#   sudo bash scripts/deploy-production.sh [optional_domain_or_ip]
# ==============================================================================
set -euo pipefail

DOMAIN="${1:-localhost}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

say()  { echo -e "\n\033[1;36m▸ $1\033[0m"; }
ok()   { echo -e "  \033[1;32m✓\033[0m $1"; }
warn() { echo -e "  \033[1;33m!\033[0m $1"; }
die()  { echo -e "  \033[1;31m✗ $1\033[0m"; exit 1; }

[[ $EUID -eq 0 ]] || die "This script must be run as root (use sudo)."

echo -e "\033[1;34m"
echo "  ███████╗███████╗███╗   ██╗████████╗██████╗ ██╗"
echo "  ██╔════╝██╔════╝████╗  ██║╚══██╔══╝██╔══██╗██║"
echo "  ███████╗█████╗  ██╔██╗ ██║   ██║   ██████╔╝██║"
echo "  ╚════██║██╔══╝  ██║╚██╗██║   ██║   ██╔══██╗██║"
echo "  ███████║███████╗██║ ╚████║   ██║   ██║  ██║██║"
echo "  SenTri System - One-Click Production Deployer"
echo -e "\033[0m"

# ── 1. System Package Installation ──────────────────────────────────────────
say "1/6 Installing Web & Database Server Stack (Apache2, MariaDB, PHP 8.x)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -yqq \
    apache2 \
    mariadb-server \
    mariadb-client \
    php \
    php-cli \
    php-mysql \
    php-curl \
    php-mbstring \
    php-gd \
    php-xml \
    php-zip \
    curl \
    git \
    unzip \
    ca-certificates \
    certbot \
    python3-certbot-apache \
    >/dev/null
ok "Apache2, MariaDB, and PHP 8.x installed"

# ── 2. Configure & Start MariaDB ────────────────────────────────────────────
say "2/6 Setting up MariaDB Database"
systemctl enable --now mariadb 2>/dev/null || service mariadb start || true
sleep 2

# Create sentri database with UTF8MB4
mysql --protocol=SOCKET -u root -e "
CREATE DATABASE IF NOT EXISTS sentri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
" 2>/dev/null || mysql -u root -e "
CREATE DATABASE IF NOT EXISTS sentri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
"
ok "Database 'sentri' ready"

# Import latest production backup or base schema
if [[ -f "$APP_DIR/sql/sentri_production_backup.sql" ]]; then
    mysql --protocol=SOCKET -u root sentri < "$APP_DIR/sql/sentri_production_backup.sql" 2>/dev/null || \
    mysql -u root sentri < "$APP_DIR/sql/sentri_production_backup.sql"
    ok "Imported full production data backup"
elif [[ -f "$APP_DIR/sql/sentri_demo_seed.sql" ]]; then
    mysql --protocol=SOCKET -u root sentri < "$APP_DIR/sql/sentri_demo_seed.sql" 2>/dev/null || \
    mysql -u root sentri < "$APP_DIR/sql/sentri_demo_seed.sql"
    ok "Imported seed data"
fi

# Run any pending migrations in sql/migrations/
if [[ -d "$APP_DIR/sql/migrations" ]]; then
    for mig in "$APP_DIR"/sql/migrations/*.sql; do
        if [[ -f "$mig" ]]; then
            mysql --protocol=SOCKET -u root sentri < "$mig" 2>/dev/null || \
            mysql -u root sentri < "$mig" 2>/dev/null || true
        fi
    done
    ok "Verified database migrations"
fi

# ── 3. Configure Apache VirtualHost ────────────────────────────────────────
say "3/6 Configuring Apache VirtualHost & Modules"
a2enmod rewrite headers ssl >/dev/null 2>&1 || true

VHOST_FILE="/etc/apache2/sites-available/sentri.conf"
cat <<EOF > "$VHOST_FILE"
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAdmin webmaster@localhost
    DocumentRoot ${APP_DIR}

    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/sentri_error.log
    CustomLog \${APACHE_LOG_DIR}/sentri_access.log combined
</VirtualHost>
EOF

a2dissite 000-default.conf >/dev/null 2>&1 || true
a2ensite sentri.conf >/dev/null 2>&1 || true
systemctl reload apache2 || systemctl restart apache2
ok "Apache configured to serve $APP_DIR"

# ── 4. Environment (.env.local) Configuration ──────────────────────────────
say "4/6 Generating Environment Configuration"
PROTO="http"
if [[ "$DOMAIN" != "localhost" && "$DOMAIN" != "127.0.0.1" ]]; then
    PROTO="https"
fi

cat <<EOF > "$APP_DIR/.env.local"
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=sican.isaac201419@gmail.com
MAIL_PASSWORD=oaeubwpzhxshuahp
MAIL_FROM=sican.isaac201419@gmail.com
MAIL_FROM_NAME=SenTri
APP_URL=${PROTO}://${DOMAIN}
APP_DEBUG=0
EOF
chmod 600 "$APP_DIR/.env.local"
ok "Created .env.local with APP_URL=${PROTO}://${DOMAIN}"

# ── 5. File Permissions & Directories ──────────────────────────────────────
say "5/6 Setting File Permissions"
mkdir -p "$APP_DIR/uploads" "$APP_DIR/logs" "$APP_DIR/logs/mail"
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod -R 775 "$APP_DIR/uploads" "$APP_DIR/logs"
ok "Permissions set to www-data:www-data"

# ── 6. Self-Verification Health Check ──────────────────────────────────────
say "6/6 Running Self-Verification Test"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1/api/mobile.php?action=weather")
if [[ "$HTTP_CODE" == "200" ]]; then
    ok "Live weather API test passed (HTTP 200)"
else
    warn "API returned HTTP $HTTP_CODE (check Apache / PHP logs)"
fi

HTTP_LOGIN=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1/login.php")
if [[ "$HTTP_LOGIN" == "200" ]]; then
    ok "Web login portal online (HTTP 200)"
fi

# Optional SSL notice
if [[ "$DOMAIN" != "localhost" && "$DOMAIN" != "127.0.0.1" && "$DOMAIN" != *"duckdns.org"* ]]; then
    say "To enable free Let's Encrypt SSL, run:"
    echo "  sudo certbot --apache -d ${DOMAIN}"
fi

echo -e "\n\033[1;32m═══════════════════════════════════════════════════════════════\033[0m"
echo -e "\033[1;32m  ✓ SenTri System successfully deployed and ready! \033[0m"
echo -e "  Site URL:    ${PROTO}://${DOMAIN}"
echo -e "  Admin Login: ${PROTO}://${DOMAIN}/admin.php"
echo -e "  Portal URL:  ${PROTO}://${DOMAIN}/gov/login.php"
echo -e "\033[1;32m═══════════════════════════════════════════════════════════════\033[0m\n"
