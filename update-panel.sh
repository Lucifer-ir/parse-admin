#!/usr/bin/env bash
set -Eeuo pipefail

# Deploy the production PHP app in app/public.
# Additive DB migrations only; keeps existing users, usage, history and provider keys.

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: bash update-panel.sh" >&2
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
  echo "This updater supports Ubuntu/Debian only." >&2
  exit 1
fi

REPO_DIR="/opt/parse-admin"
WEB_ROOT="$REPO_DIR/app/public"
PANEL_DOMAIN="panel.aiparseh.ir"
LOG="/var/log/parse-admin-php-update.log"
LE_EMAIL="${LE_EMAIL:-}"

exec > >(tee -a "$LOG") 2>&1
trap 'echo "[ERROR] PHP panel update failed at line $LINENO. Review $LOG" >&2' ERR
export DEBIAN_FRONTEND=noninteractive

echo "[1/8] Installing/verifying PHP runtime and Nginx..."
apt-get update
apt-get install -y git nginx php-fpm php-cli php-mysql php-curl php-mbstring mysql-server openssl rsync certbot python3-certbot-nginx
[[ -d "$REPO_DIR/.git" ]] || { echo "Repository missing at $REPO_DIR. Run install.sh first." >&2; exit 1; }
[[ -r /etc/parse-api.env ]] || { echo "/etc/parse-api.env is missing. Run install.sh first." >&2; exit 1; }

echo "[2/8] Fetching latest production source..."
git -C "$REPO_DIR" fetch --prune origin main
git -C "$REPO_DIR" reset --hard origin/main

for required in \
  app/bootstrap.php app/public/index.php app/public/api.php app/public/assets/admin.css app/public/assets/admin.js \
  backend/config.php backend/schema.sql backend/public/index.php backend/platform_routes.php \
  backend/bin/backup-db.sh backend/migrations/20261123_ai_console.sql backend/migrations/20261123_learning_platform.sql backend/migrations/20261123_tracks_and_activities.sql; do
  [[ -s "$REPO_DIR/$required" ]] || { echo "Required application file missing: $required" >&2; exit 1; }
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
upload_max_filesize=20M
post_max_size=24M
max_execution_time=420
max_input_time=420
memory_limit=256M
max_file_uploads=4
session.use_strict_mode=1
session.use_only_cookies=1
session.cookie_httponly=1
session.cookie_samesite=Strict
zlib.output_compression=Off
PHPINI

echo "[3/8] Checking PHP, JavaScript and Bash syntax..."
find "$REPO_DIR/app" "$REPO_DIR/backend" -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check "$REPO_DIR/app/public/assets/admin.js"
bash -n "$REPO_DIR/install.sh"
bash -n "$REPO_DIR/update-panel.sh"
bash -n "$REPO_DIR/backend/bin/backup-db.sh"

echo "[4/8] Preserving DB credentials and validating the Sodium encryption key..."
DB_NAME="$(sed -n 's/^DB_NAME=//p' /etc/parse-api.env | head -n1)"
DB_USER="$(sed -n 's/^DB_USER=//p' /etc/parse-api.env | head -n1)"
DB_PASS="$(sed -n 's/^DB_PASS=//p' /etc/parse-api.env | head -n1)"
APP_ENCRYPTION_KEY="$(sed -n 's/^APP_ENCRYPTION_KEY=//p' /etc/parse-api.env | head -n1)"

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ && -n "$DB_PASS" ]] || {
  echo "Database settings in /etc/parse-api.env are incomplete." >&2
  exit 1
}

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
    echo "APP_ENCRYPTION_KEY is missing or invalid but an encrypted Paasta key exists. Restore the original base64 key from secure backup; refusing to rotate it." >&2
    exit 1
  fi
  APP_ENCRYPTION_KEY="$(openssl rand -base64 32 | tr -d '\n')"
fi

php -r '$v=base64_decode($argv[1], true); exit(is_string($v) && strlen($v) === 32 ? 0 : 1);' "$APP_ENCRYPTION_KEY" || {
  echo "Generated APP_ENCRYPTION_KEY is not a valid 32-byte base64 key." >&2
  exit 1
}
if grep -q '^APP_ENCRYPTION_KEY=' /etc/parse-api.env; then
  sed -i "s#^APP_ENCRYPTION_KEY=.*#APP_ENCRYPTION_KEY=$APP_ENCRYPTION_KEY#" /etc/parse-api.env
else
  printf '\nAPP_ENCRYPTION_KEY=%s\n' "$APP_ENCRYPTION_KEY" >> /etc/parse-api.env
fi
chown root:www-data /etc/parse-api.env
chmod 0640 /etc/parse-api.env

echo "[5/8] Backing up DB and applying additive AI migrations..."
cat > /etc/parse-db-backup.cnf <<MYSQL_CNF
[client]
host=localhost
user=$DB_USER
password=$DB_PASS
MYSQL_CNF
chown root:root /etc/parse-db-backup.cnf
chmod 0600 /etc/parse-db-backup.cnf

install -d -o root -g root -m 0700 /var/backups/parse-admin
install -o root -g root -m 0700 "$REPO_DIR/backend/bin/backup-db.sh" /usr/local/sbin/parse-admin-db-backup
cat > /etc/cron.d/parse-admin-db-backup <<'CRON'
17 2 * * * root /usr/local/sbin/parse-admin-db-backup
CRON
chmod 0644 /etc/cron.d/parse-admin-db-backup
systemctl enable --now cron

/usr/local/sbin/parse-admin-db-backup
shopt -s nullglob
MIGRATIONS=("$REPO_DIR"/backend/migrations/*.sql)
if (( ${#MIGRATIONS[@]} == 0 )); then
  echo "No database migrations found." >&2
  exit 1
fi
for migration in "${MIGRATIONS[@]}"; do
  echo "Applying additive migration: $(basename "$migration")"
  mysql "$DB_NAME" < "$migration"
done

echo "[6/8] Applying private PHP permissions..."
chown -R root:www-data "$REPO_DIR/app" "$REPO_DIR/backend"
find "$REPO_DIR/app" "$REPO_DIR/backend" -type d -exec chmod 0750 {} \;
find "$REPO_DIR/app" "$REPO_DIR/backend" -type f -exec chmod 0640 {} \;
# Keep the panel and its static assets readable by the Nginx/PHP-FPM group.
find "$WEB_ROOT" -type d -exec chmod 0750 {} \;
find "$WEB_ROOT" -type f -exec chmod 0640 {} \;
# Runtime-owned upload folders only; PHP-FPM cannot modify application source.
install -d -o www-data -g www-data -m 0750 "$WEB_ROOT/assets/fonts" /var/lib/parse-admin/resources

echo "[7/8] Configuring panel.aiparseh.ir to execute the PHP application..."
if [[ -s "/etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem" && -s "/etc/letsencrypt/live/$PANEL_DOMAIN/privkey.pem" ]]; then
  cat > /etc/nginx/sites-available/parse-admin <<NGINX_SSL
server {
    listen 80;
    listen [::]:80;
    server_name $PANEL_DOMAIN;
    location ^~ /.well-known/acme-challenge/ { root $WEB_ROOT; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $PANEL_DOMAIN;
    root $WEB_ROOT;
    index index.php;
    server_tokens off;
    client_max_body_size 20m;

    ssl_certificate /etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$PANEL_DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:ParseAdminSSL:10m;
    ssl_session_timeout 1d;

    add_header Strict-Transport-Security "max-age=15552000" always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;

    location ^~ /.well-known/acme-challenge/ { root $WEB_ROOT; }
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $WEB_ROOT/index.php;
        fastcgi_param DOCUMENT_ROOT $WEB_ROOT;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 420s;
        fastcgi_buffering off;
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
    client_max_body_size 20m;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;

    location ^~ /.well-known/acme-challenge/ { root $WEB_ROOT; }
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $WEB_ROOT/index.php;
        fastcgi_param DOCUMENT_ROOT $WEB_ROOT;
        fastcgi_read_timeout 420s;
        fastcgi_buffering off;
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
  certbot "${CERT_ARGS[@]}" || echo "[WARN] SSL issuance failed. Check DNS and TCP 80/443, then rerun certbot."
fi

echo "[8/8] Running final checks..."
nginx -t
systemctl reload "$PHP_FPM_SERVICE"
systemctl reload nginx

echo
echo "PHP Parse Admin deployment completed."
echo "Panel URL: https://$PANEL_DOMAIN"
echo "Document root: $WEB_ROOT"
echo "Entry point: $WEB_ROOT/index.php"
echo "AI API routes: same-origin /api/* via PHP"
echo "Encryption: Sodium Secretbox key in /etc/parse-api.env (base64, 32 bytes)"
echo "Database backups: /var/backups/parse-admin (daily at 02:17 UTC)"
echo "Commit: $(git -C "$REPO_DIR" rev-parse --short HEAD)"
echo "Log: $LOG"
