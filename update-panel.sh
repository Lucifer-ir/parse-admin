#!/usr/bin/env bash
set -Eeuo pipefail

# Deploy the server-rendered PHP Parse Admin console.
# Does not reset, drop, or recreate existing users, provider keys, or usage history.

if [[ "$EUID" -ne 0 ]]; then
  echo "Run as root: sudo bash update-panel.sh" >&2
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
  echo "This updater supports Ubuntu/Debian only." >&2
  exit 1
fi

REPO_DIR="/opt/parse-admin"
PANEL_DOMAIN="panel.aiparseh.ir"
LOG="/var/log/parse-admin-php-update.log"

exec > >(tee -a "$LOG") 2>&1
trap 'echo "[ERROR] PHP panel update failed at line $LINENO. Review $LOG" >&2' ERR
export DEBIAN_FRONTEND=noninteractive

echo "[1/7] Installing/verifying PHP runtime..."
apt-get update
apt-get install -y nginx php-fpm php-cli php-mysql php-curl php-mbstring mysql-server certbot python3-certbot-nginx

if [[ ! -d "$REPO_DIR/.git" ]]; then
  echo "Repository is missing at $REPO_DIR. Run the main installer first." >&2
  exit 1
fi

echo "[2/7] Updating source from main..."
git -C "$REPO_DIR" fetch --prune origin main
git -C "$REPO_DIR" reset --hard origin/main

[[ -f "$REPO_DIR/app/public/index.php" && -f "$REPO_DIR/app/public/api.php" && -f "$REPO_DIR/app/bootstrap.php" ]] || {
  echo "PHP application files are incomplete in the repository." >&2
  exit 1
}

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
systemctl enable --now "$PHP_FPM_SERVICE" mysql nginx
[[ -S "$PHP_FPM_SOCK" ]] || { echo "PHP-FPM socket missing: $PHP_FPM_SOCK" >&2; exit 1; }
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-parse-admin.ini" <<'PHPINI'
expose_php=Off
display_errors=Off
log_errors=On
upload_max_filesize=20M
post_max_size=22M
max_execution_time=360
max_input_time=360
memory_limit=256M
max_file_uploads=3
zlib.output_compression=Off
session.use_strict_mode=1
session.cookie_httponly=1
session.cookie_secure=1
PHPINI

echo "[3/7] Checking PHP and shell syntax..."
find "$REPO_DIR/app" -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -l "$REPO_DIR/backend/config.php"
php -l "$REPO_DIR/backend/public/index.php"
bash -n "$REPO_DIR/update-panel.sh"

echo "[4/7] Preparing private encryption key..."
if [[ ! -f /etc/parse-api.env ]]; then
  echo "/etc/parse-api.env is missing. Run the backend installer first." >&2
  exit 1
fi
if ! grep -q '^APP_ENCRYPTION_KEY=' /etc/parse-api.env; then
  printf '\nAPP_ENCRYPTION_KEY=%s\n' "$(openssl rand -base64 32 | tr -d '\n')" >> /etc/parse-api.env
fi
chown root:www-data /etc/parse-api.env
chmod 0640 /etc/parse-api.env

DB_NAME="$(sed -n 's/^DB_NAME=//p' /etc/parse-api.env | head -n1)"
[[ "$DB_NAME" =~ ^[a-zA-Z0-9_]+$ ]] || { echo "Invalid DB_NAME in /etc/parse-api.env" >&2; exit 1; }

echo "[5/7] Applying additive AI console database migration..."
mysql "$DB_NAME" < "$REPO_DIR/backend/migrations/20261123_ai_console.sql"

echo "[6/7] Setting private PHP file permissions..."
chown -R root:www-data "$REPO_DIR/app"
find "$REPO_DIR/app" -type d -exec chmod 0750 {} \;
find "$REPO_DIR/app" -type f -exec chmod 0640 {} \;
find "$REPO_DIR/app/public" -type d -exec chmod 0750 {} \;
find "$REPO_DIR/app/public" -type f -exec chmod 0640 {} \;

echo "[7/7] Switching panel.aiparseh.ir to PHP-FPM..."
if [[ -s "/etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem" && -s "/etc/letsencrypt/live/$PANEL_DOMAIN/privkey.pem" ]]; then
  cat > /etc/nginx/sites-available/parse-admin <<NGINX_SSL
server {
    listen 80;
    listen [::]:80;
    server_name $PANEL_DOMAIN;
    location ^~ /.well-known/acme-challenge/ { root $REPO_DIR/app/public; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name $PANEL_DOMAIN;

    root $REPO_DIR/app/public;
    index index.php;
    server_tokens off;
    client_max_body_size 20m;
    ssl_certificate /etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$PANEL_DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:ParseAdminSSL:10m;
    ssl_session_timeout 1d;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $REPO_DIR/app/public/index.php;
        fastcgi_param DOCUMENT_ROOT $REPO_DIR/app/public;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 360s;
        fastcgi_send_timeout 360s;
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

    root $REPO_DIR/app/public;
    index index.php;
    server_tokens off;
    client_max_body_size 20m;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $REPO_DIR/app/public/index.php;
        fastcgi_param DOCUMENT_ROOT $REPO_DIR/app/public;
        fastcgi_read_timeout 360s;
        fastcgi_send_timeout 360s;
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

if getent ahostsv4 "$PANEL_DOMAIN" >/dev/null 2>&1; then
  if [[ ! -s "/etc/letsencrypt/live/$PANEL_DOMAIN/fullchain.pem" ]]; then
    CERT_ARGS=(--nginx -d "$PANEL_DOMAIN" --non-interactive --agree-tos --redirect)
    if [[ -n "${LE_EMAIL:-}" ]]; then CERT_ARGS+=(--email "$LE_EMAIL"); else CERT_ARGS+=(--register-unsafely-without-email); fi
    certbot "${CERT_ARGS[@]}" || echo "[WARN] SSL issuance did not complete; re-run certbot after DNS/firewall is ready."
  fi
else
  echo "[WARN] Panel DNS does not resolve to this server yet; HTTP vhost installed. Configure DNS and issue SSL."
fi

echo
echo "PHP admin panel deployment completed."
echo "Panel: https://$PANEL_DOMAIN"
echo "Document root: $REPO_DIR/app/public"
echo "Database migration: additive only; existing accounts/data preserved."
echo "API key encryption: APP_ENCRYPTION_KEY stored in /etc/parse-api.env (not in Git)."
echo "Log: $LOG"
