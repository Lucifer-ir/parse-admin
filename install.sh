#!/usr/bin/env bash
set -Eeuo pipefail

# Full deployment for Parse Admin: static dashboard + private PHP API + MySQL.
# Safe to rerun: database contents and existing administrator credentials are preserved.

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: sudo bash install.sh"
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
  echo "This installer supports Ubuntu/Debian only."
  exit 1
fi

REPO_URL="https://github.com/Lucifer-ir/parse-admin.git"
INSTALL_DIR="/opt/parse-admin"
WEB_ROOT="/var/www/parse-admin"
PANEL_DOMAIN="panel.aiparseh.ir"
API_DOMAIN="api.aiparseh.ir"
LOG_FILE="/var/log/parse-admin-install.log"
LE_EMAIL="${LE_EMAIL:-}"

exec > >(tee -a "$LOG_FILE") 2>&1
trap 'echo "[ERROR] Installation failed at line $LINENO. See $LOG_FILE" >&2' ERR
export DEBIAN_FRONTEND=noninteractive

echo "[1/9] Installing Nginx, MySQL, PHP-FPM and required extensions..."
apt-get update
apt-get install -y ca-certificates curl git nginx rsync mysql-server openssl certbot cron python3-certbot-nginx php-fpm php-cli php-mysql php-curl php-mbstring

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [[ ! -S "$PHP_FPM_SOCK" ]]; then
  echo "PHP-FPM socket not found: $PHP_FPM_SOCK"
  exit 1
fi
systemctl enable --now mysql nginx "$PHP_FPM_SERVICE"

echo "[2/9] Updating repository..."
if [[ -d "$INSTALL_DIR/.git" ]]; then
  git -C "$INSTALL_DIR" fetch --all --prune
  git -C "$INSTALL_DIR" reset --hard origin/main
else
  if [[ -e "$INSTALL_DIR" ]]; then
    mv "$INSTALL_DIR" "${INSTALL_DIR}.backup.$(date +%Y%m%d%H%M%S)"
  fi
  git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"
fi

[[ -f "$INSTALL_DIR/backend/public/index.php" && -f "$INSTALL_DIR/backend/schema.sql" ]] || {
  echo "Backend files are missing from the repository checkout."
  exit 1
}
php -l "$INSTALL_DIR/backend/config.php"
php -l "$INSTALL_DIR/backend/public/index.php"
php -l "$INSTALL_DIR/backend/bin/create-admin.php"
find "$INSTALL_DIR/admin" -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check "$INSTALL_DIR/assets/admin.js"
bash -n "$INSTALL_DIR/install.sh"
bash -n "$INSTALL_DIR/update-panel.sh"
bash -n "$INSTALL_DIR/backend/bin/backup-db.sh"

echo "[3/9] Deferring panel deployment until the backend and database are ready..."
# The PHP-FPM panel is deployed by update-panel.sh after the DB, secrets and API host exist.

echo "[4/9] Preparing MySQL database and restricted application user..."
DB_NAME="parse_admin"
DB_USER="parse_admin_app"
if [[ -f /etc/parse-api.env ]]; then
  DB_PASS="$(sed -n 's/^DB_PASS=//p' /etc/parse-api.env | head -n1)"
else
  DB_PASS=""
fi
if [[ -z "$DB_PASS" ]]; then
  DB_PASS="$(openssl rand -hex 32)"
fi
APP_ENCRYPTION_KEY=""
if [[ -f /etc/parse-api.env ]]; then
  APP_ENCRYPTION_KEY="$(sed -n 's/^APP_ENCRYPTION_KEY=//p' /etc/parse-api.env | head -n1)"
fi
if [[ -z "$APP_ENCRYPTION_KEY" ]]; then
  EXISTING_AI_TABLE="$(mysql "$DB_NAME" -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='admin_ai_settings'" 2>/dev/null || echo 0)"
  EXISTING_AI_KEYS=0
  if [[ "$EXISTING_AI_TABLE" =~ ^[0-9]+$ ]] && (( EXISTING_AI_TABLE > 0 )); then
    EXISTING_AI_KEYS="$(mysql "$DB_NAME" -Nse "SELECT COUNT(*) FROM admin_ai_settings WHERE api_key_cipher IS NOT NULL AND api_key_cipher <> ''")"
  fi
  if [[ "$EXISTING_AI_KEYS" =~ ^[0-9]+$ ]] && (( EXISTING_AI_KEYS > 0 )); then
    echo "Encrypted AI API keys exist but APP_ENCRYPTION_KEY is missing. Restore its original value from backup; refusing to rotate it." >&2
    exit 1
  fi
  APP_ENCRYPTION_KEY="$(openssl rand -hex 32)"
fi
[[ "$APP_ENCRYPTION_KEY" =~ ^[A-Fa-f0-9]{64}$ ]] || { echo "Invalid APP_ENCRYPTION_KEY format." >&2; exit 1; }

mysql <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql "$DB_NAME" < "$INSTALL_DIR/backend/schema.sql"

echo "[5/9] Installing private API configuration and PHP files..."
install -d -o root -g www-data -m 0750 /opt/parse-admin/backend
chown -R root:www-data "$INSTALL_DIR/backend"
find "$INSTALL_DIR/backend" -type d -exec chmod 0750 {} \;
find "$INSTALL_DIR/backend" -type f -exec chmod 0640 {} \;
# PHP config is readable only by root and the www-data group through restrictive directory/file modes.

cat > /etc/parse-api.env <<ENV
DB_HOST=localhost
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
CORS_ORIGIN=https://$PANEL_DOMAIN
APP_ENCRYPTION_KEY=$APP_ENCRYPTION_KEY
ENV
chown root:www-data /etc/parse-api.env
chmod 0640 /etc/parse-api.env

echo "[6/9] Configuring restricted-permission local database backups..."
cat > /etc/parse-db-backup.cnf <<MYSQL_CNF
[client]
host=localhost
user=$DB_USER
password=$DB_PASS
MYSQL_CNF
chmod 0600 /etc/parse-db-backup.cnf
install -o root -g root -m 0700 "$INSTALL_DIR/backend/bin/backup-db.sh" /usr/local/sbin/parse-admin-db-backup
install -d -o root -g root -m 0700 /var/backups/parse-admin
cat > /etc/cron.d/parse-admin-db-backup <<'CRON'
17 2 * * * root /usr/local/sbin/parse-admin-db-backup
CRON
chmod 0644 /etc/cron.d/parse-admin-db-backup
systemctl enable --now cron


echo "[7/9] Creating first administrator if one does not already exist..."
php "$INSTALL_DIR/backend/bin/create-admin.php"
/usr/local/sbin/parse-admin-db-backup

echo "[8/9] Configuring PHP-FPM and Nginx API virtual host..."
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-parse-api-security.ini" <<'PHPINI'
expose_php=Off
display_errors=Off
log_errors=On
session.use_strict_mode=1
session.cookie_httponly=1
session.cookie_secure=1
PHPINI
systemctl reload "$PHP_FPM_SERVICE"

cat > /etc/nginx/sites-available/parse-api <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $API_DOMAIN;
    root $INSTALL_DIR/backend/public;
    index index.php;

    client_max_body_size 1m;
    server_tokens off;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer always;
    add_header X-Frame-Options DENY always;

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/backend/public/index.php;
        fastcgi_param DOCUMENT_ROOT $INSTALL_DIR/backend/public;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(?:env|ini|log|sql|bak|dist)$ {
        deny all;
    }

    location ~ /\. {
        deny all;
    }

    location ~ \.php$ {
        return 404;
    }
}
NGINX

ln -sfn /etc/nginx/sites-available/parse-api /etc/nginx/sites-enabled/parse-api
nginx -t
systemctl reload nginx

echo "[9/9] Configuring HTTPS for API..."
if getent ahostsv4 "$API_DOMAIN" >/dev/null 2>&1; then
  CERTBOT_ARGS=(--nginx -d "$API_DOMAIN" --non-interactive --agree-tos --redirect)
  if [[ -n "$LE_EMAIL" ]]; then
    CERTBOT_ARGS+=(--email "$LE_EMAIL")
  else
    CERTBOT_ARGS+=(--register-unsafely-without-email)
  fi
  certbot "${CERTBOT_ARGS[@]}" || echo "[WARN] Certbot failed. Check api DNS and inbound TCP ports 80/443, then run: certbot --nginx -d $API_DOMAIN"
else
  echo "[WARN] $API_DOMAIN does not resolve yet. Add an A record to this server IP, then run: certbot --nginx -d $API_DOMAIN"
fi

echo
echo "Deployment completed."
echo "Admin panel: https://$PANEL_DOMAIN"
echo "API base URL: https://$API_DOMAIN"
echo "Health check: https://$API_DOMAIN/health"
echo "Database: $DB_NAME (localhost only; app user: $DB_USER)"
echo "Admin credentials are not printed or stored in this log."
echo "Log: $LOG_FILE"

echo "[FINAL] Deploying the PHP-rendered administration console..."
bash "$INSTALL_DIR/update-panel.sh"
