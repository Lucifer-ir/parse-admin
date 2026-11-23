#!/usr/bin/env bash
set -Eeuo pipefail

# Upgrade the PHP-rendered control panel, additive DB schema, and AI console.
# Existing administrator accounts, provider credentials, and usage history are preserved.

if [[ "$EUID" -ne 0 ]]; then
  echo "Run as root: bash update-panel.sh" >&2
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
  echo "This updater supports Ubuntu/Debian only." >&2
  exit 1
fi

REPO_DIR="/opt/parse-admin"
WEB_ROOT="/var/www/parse-admin"
PANEL_DOMAIN="panel.aiparseh.ir"
LOG="/var/log/parse-admin-php-update.log"
LE_EMAIL="${LE_EMAIL:-}"

exec > >(tee -a "$LOG") 2>&1
trap 'echo "[ERROR] PHP panel update failed at line $LINENO. Review $LOG" >&2' ERR
export DEBIAN_FRONTEND=noninteractive

echo "[1/8] Verifying PHP, MySQL and Nginx runtime..."
apt-get update
apt-get install -y git nginx php-fpm php-cli php-mysql php-curl php-mbstring mysql-server openssl rsync certbot python3-certbot-nginx
[[ -d "$REPO_DIR/.git" ]] || { echo "Repository missing at $REPO_DIR. Run install.sh first." >&2; exit 1; }
[[ -f /etc/parse-api.env ]] || { echo "/etc/parse-api.env is missing. Run install.sh first." >&2; exit 1; }

echo "[2/8] Fetching the latest main branch..."
git -C "$REPO_DIR" fetch --prune origin main
git -C "$REPO_DIR" reset --hard origin/main
for required in admin/bootstrap.php admin/index.php admin/api.php backend/config.php backend/schema.sql backend/public/index.php backend/bin/create-admin.php backend/bin/backup-db.sh assets/admin.css assets/admin.js; do
  [[ -s "$REPO_DIR/$required" ]] || { echo "Required file missing: $required" >&2; exit 1; }
done

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
systemctl enable --now mysql nginx "$PHP_FPM_SERVICE"
[[ -S "$PHP_FPM_SOCK" ]] || { echo "PHP-FPM socket missing: $PHP_FPM_SOCK" >&2; exit 1; }
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-parse-admin.ini" <<'PHPINI'
expose_php=Off
display_errors=Off
log_errors=On
upload_max_filesize=12M
post_max_size=14M
max_execution_time=420
max_input_time=420
memory_limit=256M
max_file_uploads=3
session.use_strict_mode=1
session.cookie_httponly=1
zlib.output_compression=Off
PHPINI

echo "[3/8] Checking PHP and JavaScript syntax..."
find "$REPO_DIR/admin" -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -l "$REPO_DIR/backend/config.php"
php -l "$REPO_DIR/backend/public/index.php"
php -l "$REPO_DIR/backend/bin/create-admin.php"
node --check "$REPO_DIR/assets/admin.js"
bash -n "$REPO_DIR/install.sh"
bash -n "$REPO_DIR/update-panel.sh"
bash -n "$REPO_DIR/backend/bin/backup-db.sh"

echo "[4/8] Preserving server encryption and database credentials..."
DB_NAME="$(sed -n 's/^DB_NAME=//p' /etc/parse-api.env | head -n1)"
DB_USER="$(sed -n 's/^DB_USER=//p' /etc/parse-api.env | head -n1)"
DB_PASS="$(sed -n 's/^DB_PASS=//p' /etc/parse-api.env | head -n1)"
APP_ENCRYPTION_KEY="$(sed -n 's/^APP_ENCRYPTION_KEY=//p' /etc/parse-api.env | head -n1)"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ && -n "$DB_PASS" ]] || {
  echo "Database settings in /etc/parse-api.env are incomplete." >&2
  exit 1
}
if [[ -z "$APP_ENCRYPTION_KEY" ]]; then
  KEY_TABLE="$(mysql "$DB_NAME" -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='admin_ai_settings'" 2>/dev/null || echo 0)"
  STORED_KEYS=0
  if [[ "$KEY_TABLE" =~ ^[0-9]+$ ]] && (( KEY_TABLE > 0 )); then
    STORED_KEYS="$(mysql "$DB_NAME" -Nse "SELECT COUNT(*) FROM admin_ai_settings WHERE api_key_cipher IS NOT NULL AND api_key_cipher <> ''")"
  fi
  if [[ "$STORED_KEYS" =~ ^[0-9]+$ ]] && (( STORED_KEYS > 0 )); then
    echo "APP_ENCRYPTION_KEY is missing but encrypted AI credentials exist. Restore the original key from secure backup; refusing to rotate it." >&2
    exit 1
  fi
  APP_ENCRYPTION_KEY="$(openssl rand -hex 32)"
fi
[[ "$APP_ENCRYPTION_KEY" =~ ^[A-Fa-f0-9]{64}$ ]] || { echo "APP_ENCRYPTION_KEY must be exactly 64 hex characters." >&2; exit 1; }
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

echo "[5/8] Backing up database and applying additive schema..."
cat > /etc/parse-db-backup.cnf <<MYSQL_CNF
[client]
host=localhost
user=$DB_USER
password=$DB_PASS
MYSQL_CNF
chmod 0600 /etc/parse-db-backup.cnf
install -o root -g root -m 0700 "$REPO_DIR/backend/bin/backup-db.sh" /usr/local/sbin/parse-admin-db-backup
install -d -o root -g root -m 0700 /var/backups/parse-admin
/usr/local/sbin/parse-admin-db-backup
mysql "$DB_NAME" < "$REPO_DIR/backend/schema.sql"

echo "[6/8] Publishing PHP console and private application core..."
install -d -o root -g www-data -m 0750 "$REPO_DIR/admin" "$WEB_ROOT" "$WEB_ROOT/assets"
chown -R root:www-data "$REPO_DIR/admin" "$REPO_DIR/backend"
find "$REPO_DIR/admin" -type d -exec chmod 0750 {} \;
find "$REPO_DIR/admin" -type f -exec chmod 0640 {} \;
find "$REPO_DIR/backend" -type d -exec chmod 0750 {} \;
find "$REPO_DIR/backend" -type f -exec chmod 0640 {} \;
install -o root -g www-data -m 0640 "$REPO_DIR/admin/index.php" "$WEB_ROOT/index.php"
install -o root -g www-data -m 0640 "$REPO_DIR/admin/api.php" "$WEB_ROOT/api.php"
rm -f "$WEB_ROOT/index.html"
rsync -a --delete "$REPO_DIR/assets/" "$WEB_ROOT/assets/"
chown -R www-data:www-data "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 0755 {} \;
find "$WEB_ROOT" -type f -exec chmod 0644 {} \;

echo "[7/8] Configuring Nginx to execute PHP-FPM for the panel..."
if [[ -s "/etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem" && -s "/etc/letsencrypt/live/$PANEL_DOMAIN/privkey.pem" ]]; then
  cat > /etc/nginx/sites-available/parse-admin <<NGINX_SSL
server {
    listen 80;
    listen [::]:80;
    server_name $PANEL_DOMAIN;
    location ^~ /.well-known/acme-challenge/ { root $WEB_ROOT; }
    location / { return 301 https:\$host\$request_uri; }
}
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $PANEL_DOMAIN;
    root $WEB_ROOT;
    index index.php;
    server_tokens off;
    client_max_body_size 14m;
    ssl_certificate /etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$PANEL_DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:ParseAdminSSL:10m;
    ssl_session_timeout 1d;
    add_header Strict-Transport-Security "max-age=15552000" always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $WEB_ROOT/index.php;
        fastcgi_param DOCUMENT_ROOT $WEB_ROOT;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 420s;
        fastcgi_hide_header X-Powered-By;
    }
    location = /api.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $WEB_ROOT/api.php;
        fastcgi_param DOCUMENT_ROOT $WEB_ROOT;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 420s;
        fastcgi_hide_header X-Powered-By;
    }
    location ~* \.(?:env|ini|log|sql|bak|dist)$ { deny all; }
    location ~ /\. { deny all; }
    location ~ \.php$ { return 404; }
    location ~* \.(?:css|js|woff2|svg|png|jpg|jpeg|webp|ico)$ {
        expires 1h;
        add_header Cache-Control "public, max-age=3600";
        try_files \$uri =404;
    }
}
NGINX_SSL
else
  cat > /etc/nginx/sites-available/parse-admin <<NGINX_HTTP
server {
    listen 80;
    listen [::]:80;
    server_name $PANEL_DOMAIN;
    root $WEB_ROOT;
    index index.php;
    server_tokens off;
    client_max_body_size 14m;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $WEB_ROOT/index.php;
        fastcgi_param DOCUMENT_ROOT $WEB_ROOT;
        fastcgi_read_timeout 420s;
        fastcgi_hide_header X-Powered-By;
    }
    location = /api.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $WEB_ROOT/api.php;
        fastcgi_param DOCUMENT_ROOT $WEB_ROOT;
        fastcgi_read_timeout 420s;
        fastcgi_hide_header X-Powered-By;
    }
    location ~* \.(?:env|ini|log|sql|bak|dist)$ { deny all; }
    location ~ /\. { deny all; }
    location ~ \.php$ { return 404; }
    location ~* \.(?:css|js|woff2|svg|png|jpg|jpeg|webp|ico)$ {
        expires 1h;
        add_header Cache-Control "public, max-age=3600";
        try_files \$uri =404;
    }
}
NGINX_HTTP
fi

ln -sfn /etc/nginx/sites-available/parse-admin /etc/nginx/sites-enabled/parse-admin
nginx -t
systemctl reload "$PHP_FPM_SERVICE"
systemctl reload nginx

if [[ ! -s "/etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem" ]] && getent ahostsv4 "$PANEL_DOMAIN" >/dev/null 2>&1; then
  CERT_ARGS=(--nginx -d "$PANEL_DOMAIN" --non-interactive --agree-tos --redirect)
  if [[ -n "$LE_EMAIL" ]]; then CERT_ARGS+=(--email "$LE_EMAIL"); else CERT_ARGS+=(--register-unsafely-without-email); fi
  certbot "${CERT_ARGS[@]}" || echo "[WARN] SSL could not be issued. After DNS/firewall is ready run: certbot --nginx -d $PANEL_DOMAIN"
fi

echo "[8/8] Reloading services and reporting deployment..."
systemctl reload "$PHP_FPM_SERVICE"
systemctl reload nginx
echo "PHP admin panel is now installed."
echo "URL: https://$PANEL_DOMAIN"
echo "PHP entry point: $WEB_ROOT/index.php"
echo "Same-origin API: $WEB_ROOT/api.php"
echo "AI credentials are encrypted on the server; raw key is never returned to browser."
echo "Database backup: /var/backups/parse-admin"
echo "Commit: $(git -C "$REPO_DIR" rev-parse --short HEAD)"
echo "Log: $LOG"
