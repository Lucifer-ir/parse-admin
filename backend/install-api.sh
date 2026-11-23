#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root"; exit 1; }
DOMAIN="api.aiparseh.ir"; PANEL="https://panel.aiparseh.ir"; DIR="/opt/parse-admin/backend"; LOG="/var/log/parse-api-install.log"
exec > >(tee -a "$LOG") 2>&1
apt-get update
apt-get install -y nginx mysql-server php-fpm php-mysql php-cli php-curl php-mbstring php-xml php-json certbot python3-certbot-nginx
systemctl enable --now mysql nginx
DB="parse"; DBUSER="parse_api"; DBPASS="$(openssl rand -hex 32)"
ADMIN_EMAIL=""; ADMIN_PASS=""
read -rp "Admin email: " ADMIN_EMAIL
read -rsp "Admin password (min 14 chars): " ADMIN_PASS; echo
[[ ${#ADMIN_PASS} -ge 14 ]] || { echo "Password too short"; exit 1; }
mysql <<SQL
CREATE DATABASE IF NOT EXISTS parse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'parse_api'@'localhost' IDENTIFIED BY '$DBPASS';
ALTER USER 'parse_api'@'localhost' IDENTIFIED BY '$DBPASS';
GRANT SELECT,INSERT,UPDATE,DELETE ON parse.* TO 'parse_api'@'localhost';
FLUSH PRIVILEGES;
SQL
mkdir -p "$DIR"
mysql "$DB" < "$DIR/schema.sql"
HASH="$(php -r 'echo password_hash($argv[1],PASSWORD_DEFAULT);' "$ADMIN_PASS")"
mysql "$DB" -e "INSERT INTO users(email,password_hash,role,active) VALUES('$ADMIN_EMAIL','$HASH','admin',1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),role='admin',active=1;"
unset ADMIN_PASS HASH
cat > /etc/parse-api.env <<ENV
DB_HOST=localhost
DB_NAME=parse
DB_USER=parse_api
DB_PASS=$DBPASS
CORS_ORIGIN=$PANEL
ENV
chmod 600 /etc/parse-api.env
cat > /etc/nginx/sites-available/parse-api <<NGINX
server {
 listen 80; listen [::]:80; server_name $DOMAIN;
 root $DIR/public; index index.php;
 client_max_body_size 1m;
 add_header X-Content-Type-Options nosniff always;
 add_header Referrer-Policy no-referrer always;
 add_header X-Frame-Options DENY always;
 add_header Content-Security-Policy "default-src 'none'; frame-ancestors 'none'" always;
 location / { try_files \$uri /index.php?\$query_string; }
 location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; fastcgi_param PHP_VALUE "auto_prepend_file=/opt/parse-admin/backend/config.php"; }
 location ~ /\. { deny all; }
}
NGINX
ln -sfn /etc/nginx/sites-available/parse-api /etc/nginx/sites-enabled/parse-api
nginx -t && systemctl reload nginx
if getent ahosts "$DOMAIN" >/dev/null; then certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect --register-unsafely-without-email || true; fi
echo "API install completed. Configure DNS A record $DOMAIN to server, then rerun certbot if needed."
