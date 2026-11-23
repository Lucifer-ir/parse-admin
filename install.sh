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

echo "[1/8] Installing Nginx, MySQL, PHP-FPM and required extensions..."
apt-get update
apt-get install -y ca-certificates curl git nginx rsync mysql-server openssl certbot python3-certbot-nginx php-fpm php-cli php-mysql php-curl php-mbstring

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [[ ! -S "$PHP_FPM_SOCK" ]]; then
  echo "PHP-FPM socket not found: $PHP_FPM_SOCK"
  exit 1
fi
systemctl enable --now mysql nginx "$PHP_FPM_SERVICE"

echo "[2/8] Updating repository..."
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

echo "[3/8] Publishing the static admin panel..."
install -d -m 0755 "$WEB_ROOT"
find "$WEB_ROOT" -mindepth 1 -maxdepth 1 ! -name index.html ! -name assets ! -name .well-known -exec rm -rf {} +
install -m 0644 "$INSTALL_DIR/index.html" "$WEB_ROOT/index.html"
install -d -m 0755 "$WEB_ROOT/assets"
rsync -a --delete "$INSTALL_DIR/assets/" "$WEB_ROOT/assets/"
chown -R www-data:www-data "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 755 {} \;
find "$WEB_ROOT" -type f -exec chmod 644 {} \;

echo "[4/8] Preparing MySQL database and restricted application user..."
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

mysql <<SQL
CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON `$DB_NAME`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql "$DB_NAME" < "$INSTALL_DIR/backend/schema.sql"

echo "[5/8] Installing private API configuration and PHP files..."
install -d -o root -g www-data -m 0750 /opt/parse-admin/backend
chown -R root:www-data "$INSTALL_DIR/backend"
find "$INSTALL_DIR/backend" -type d -exec chmod 0750 {} \;
find "$INSTALL_DIR/backend" -type f -exec chmod 0640 {} \;
install -o root -g www-data -m 0640 "$INSTALL_DIR/backend/config.php" "$INSTALL_DIR/backend/config.php"

cat > /etc/parse-api.env <<ENV
DB_HOST=localhost
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
CORS_ORIGIN=https://$PANEL_DOMAIN
ENV
chown root:www-data /etc/parse-api.env
chmod 0640 /etc/parse-api.env

echo "[6/8] Creating first administrator if one does not already exist..."
php "$INSTALL_DIR/backend/bin/create-admin.php"

echo "[7/8] Configuring PHP-FPM and Nginx API virtual host..."
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
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/backend/public/index.php;
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

echo "[8/8] Configuring HTTPS for API..."
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
