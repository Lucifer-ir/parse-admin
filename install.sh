#!/usr/bin/env bash
set -Eeuo pipefail

# Fresh install / repair of Parse Admin:
# - PHP-FPM server-rendered panel on panel.aiparseh.ir
# - existing PHP API on api.aiparseh.ir
# - MySQL database and additive Paasta AI migrations
# Re-running this script does not reset an existing admin password or provider key.

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: bash install.sh" >&2
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
  echo "This installer supports Ubuntu/Debian only." >&2
  exit 1
fi

REPO_URL="https://github.com/Lucifer-ir/parse-admin.git"
INSTALL_DIR="/opt/parse-admin"
PANEL_DOMAIN="panel.aiparseh.ir"
API_DOMAIN="api.aiparseh.ir"
LOG_FILE="/var/log/parse-admin-install.log"
LE_EMAIL="${LE_EMAIL:-}"

exec > >(tee -a "$LOG_FILE") 2>&1
trap 'echo "[ERROR] Installation failed at line $LINENO. See $LOG_FILE" >&2' ERR
export DEBIAN_FRONTEND=noninteractive

echo "[1/8] Installing system packages..."
apt-get update
apt-get install -y ca-certificates curl git nginx rsync mysql-server openssl certbot cron python3-certbot-nginx php-fpm php-cli php-mysql php-curl php-mbstring

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
[[ -S "$PHP_FPM_SOCK" ]] || { echo "PHP-FPM socket missing: $PHP_FPM_SOCK" >&2; exit 1; }
systemctl enable --now mysql nginx "$PHP_FPM_SERVICE"

echo "[2/8] Fetching repository..."
if [[ -d "$INSTALL_DIR/.git" ]]; then
  git -C "$INSTALL_DIR" fetch --all --prune
  git -C "$INSTALL_DIR" reset --hard origin/main
else
  if [[ -e "$INSTALL_DIR" ]]; then
    mv "$INSTALL_DIR" "${INSTALL_DIR}.backup.$(date +%Y%m%d%H%M%S)"
  fi
  git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"
fi

for required in app/bootstrap.php app/public/index.php app/public/api.php app/public/assets/admin.css app/public/assets/admin.js backend/config.php backend/public/index.php backend/schema.sql backend/bin/create-admin.php backend/bin/backup-db.sh update-panel.sh; do
  [[ -s "$INSTALL_DIR/$required" ]] || { echo "Required file missing: $required" >&2; exit 1; }
done

echo "[3/8] Checking source syntax..."
find "$INSTALL_DIR/app" "$INSTALL_DIR/backend" -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check "$INSTALL_DIR/app/public/assets/admin.js"
bash -n "$INSTALL_DIR/install.sh"
bash -n "$INSTALL_DIR/update-panel.sh"
bash -n "$INSTALL_DIR/backend/bin/backup-db.sh"

echo "[4/8] Creating/preserving the MySQL database and restricted app user..."
DB_NAME="parse_admin"
DB_USER="parse_admin_app"
DB_PASS=""
APP_ENCRYPTION_KEY=""

if [[ -r /etc/parse-api.env ]]; then
  DB_NAME="$(sed -n 's/^DB_NAME=//p' /etc/parse-api.env | head -n1)"
  DB_USER="$(sed -n 's/^DB_USER=//p' /etc/parse-api.env | head -n1)"
  DB_PASS="$(sed -n 's/^DB_PASS=//p' /etc/parse-api.env | head -n1)"
  APP_ENCRYPTION_KEY="$(sed -n 's/^APP_ENCRYPTION_KEY=//p' /etc/parse-api.env | head -n1)"
fi

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || {
  echo "Invalid DB_NAME or DB_USER in /etc/parse-api.env." >&2
  exit 1
}
if [[ -z "$DB_PASS" ]]; then DB_PASS="$(openssl rand -hex 32)"; fi

mysql <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

mysql "$DB_NAME" < "$INSTALL_DIR/backend/schema.sql"

# The app uses libsodium Secretbox, which requires a base64-encoded 32-byte key.
KEY_VALID=0
if [[ -n "$APP_ENCRYPTION_KEY" ]] &&
   php -r '$v=base64_decode($argv[1], true); exit(is_string($v) && strlen($v) === 32 ? 0 : 1);' "$APP_ENCRYPTION_KEY"; then
  KEY_VALID=1
fi
if (( KEY_VALID == 0 )); then
  TABLE_EXISTS="$(mysql "$DB_NAME" -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ai_provider_settings'")"
  STORED_KEYS=0
  if [[ "$TABLE_EXISTS" =~ ^[0-9]+$ ]] && (( TABLE_EXISTS > 0 )); then
    STORED_KEYS="$(mysql "$DB_NAME" -Nse "SELECT COUNT(*) FROM ai_provider_settings WHERE api_key_ciphertext IS NOT NULL AND api_key_ciphertext <> ''")"
  fi
  if [[ "$STORED_KEYS" =~ ^[0-9]+$ ]] && (( STORED_KEYS > 0 )); then
    echo "The encryption key is missing/invalid but a Paasta key is already encrypted in the database. Restore the original APP_ENCRYPTION_KEY from backup; refusing to rotate it." >&2
    exit 1
  fi
  APP_ENCRYPTION_KEY="$(openssl rand -base64 32 | tr -d '\n')"
fi
php -r '$v=base64_decode($argv[1], true); exit(is_string($v) && strlen($v) === 32 ? 0 : 1);' "$APP_ENCRYPTION_KEY" || {
  echo "APP_ENCRYPTION_KEY must decode to exactly 32 bytes." >&2
  exit 1
}

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

echo "[5/8] Preparing protected backup and retention..."
cat > /etc/parse-db-backup.cnf <<MYSQL_CNF
[client]
host=localhost
user=$DB_USER
password=$DB_PASS
MYSQL_CNF
chown root:root /etc/parse-db-backup.cnf
chmod 0600 /etc/parse-db-backup.cnf
install -d -o root -g root -m 0700 /var/backups/parse-admin
install -o root -g root -m 0700 "$INSTALL_DIR/backend/bin/backup-db.sh" /usr/local/sbin/parse-admin-db-backup
cat > /etc/cron.d/parse-admin-db-backup <<'CRON'
17 2 * * * root /usr/local/sbin/parse-admin-db-backup
CRON
chmod 0644 /etc/cron.d/parse-admin-db-backup
systemctl enable --now cron

echo "[6/8] Creating admin account if this is a fresh database..."
# The CLI tool does not alter a pre-existing account or reset its password.
php "$INSTALL_DIR/backend/bin/create-admin.php"

echo "[7/8] Installing the separate API virtual host..."
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-parse-api-security.ini" <<'PHPINI'
expose_php=Off
display_errors=Off
log_errors=On
session.use_strict_mode=1
session.cookie_httponly=1
PHPINI
systemctl reload "$PHP_FPM_SERVICE"

cat > /etc/nginx/sites-available/parse-api <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $API_DOMAIN;
    root $INSTALL_DIR/backend/public;
    index index.php;
    server_tokens off;
    client_max_body_size 20m;

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
    location ~* \.(?:env|ini|log|sql|bak|dist)$ { deny all; }
    location ~ /\. { deny all; }
    location ~ \.php$ { return 404; }
}
NGINX

ln -sfn /etc/nginx/sites-available/parse-api /etc/nginx/sites-enabled/parse-api
nginx -t
systemctl reload nginx

if getent ahostsv4 "$API_DOMAIN" >/dev/null 2>&1 &&
   [[ ! -s "/etc/letsencrypt/live/$API_DOMAIN/fullchain.pem" ]]; then
  CERT_ARGS=(--nginx -d "$API_DOMAIN" --non-interactive --agree-tos --redirect)
  if [[ -n "$LE_EMAIL" ]]; then CERT_ARGS+=(--email "$LE_EMAIL"); else CERT_ARGS+=(--register-unsafely-without-email); fi
  certbot "${CERT_ARGS[@]}" || echo "[WARN] API SSL issuance failed. Check DNS and TCP ports 80/443."
else
  echo "[INFO] API DNS/SSL already exists or DNS is not ready; existing certificate is preserved."
fi

echo "[8/8] Deploying PHP-rendered admin console and migrations..."
bash "$INSTALL_DIR/update-panel.sh"

echo
echo "Installation/repair completed."
echo "PHP Admin: https://$PANEL_DOMAIN"
echo "PHP API: https://$API_DOMAIN"
echo "DB: $DB_NAME (localhost only)"
echo "Logs: $LOG_FILE"
echo "Encryption secret is private in /etc/parse-api.env; it is not printed."
