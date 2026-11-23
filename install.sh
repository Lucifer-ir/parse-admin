#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="panel.aiparseh.ir"
REPO_URL="https://github.com/Lucifer-ir/parse-admin.git"
INSTALL_DIR="/opt/parse-admin"
WEB_ROOT="/var/www/parse-admin"
NGINX_CONF="/etc/nginx/sites-available/parse-admin"
LOG_FILE="/var/log/parse-admin-install.log"
LE_EMAIL="${LE_EMAIL:-}"

exec > >(tee -a "$LOG_FILE") 2>&1
trap 'echo "[ERROR] Installation failed at line $LINENO. See $LOG_FILE" >&2' ERR

if [[ "$EUID" -ne 0 ]]; then
  echo "Run as root: sudo bash install.sh"
  exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
  echo "This installer supports Debian/Ubuntu only (apt-get)."
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "[1/6] Installing system packages..."
apt-get update
apt-get install -y ca-certificates curl git nginx rsync

echo "[2/6] Fetching repository..."
if [[ -d "$INSTALL_DIR/.git" ]]; then
  git -C "$INSTALL_DIR" fetch --all --prune
  git -C "$INSTALL_DIR" reset --hard origin/main
else
  if [[ -e "$INSTALL_DIR" ]]; then
    mv "$INSTALL_DIR" "${INSTALL_DIR}.backup.$(date +%Y%m%d%H%M%S)"
  fi
  git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"
fi

echo "[3/6] Installing static panel..."
install -d -m 0755 "$WEB_ROOT"
rsync -a --delete --exclude 'install.sh' "$INSTALL_DIR/" "$WEB_ROOT/"
chown -R www-data:www-data "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 755 {} \;
find "$WEB_ROOT" -type f -exec chmod 644 {} \;

echo "[4/6] Configuring Nginx..."
cat > "$NGINX_CONF" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $WEB_ROOT;
    index index.html;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header X-Frame-Options DENY always;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~* \.(?:css|js|png|jpg|jpeg|gif|svg|ico|webp)$ {
        expires 7d;
        add_header Cache-Control "public";
        try_files \$uri =404;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX

ln -sfn "$NGINX_CONF" /etc/nginx/sites-enabled/parse-admin
nginx -t
systemctl enable --now nginx
systemctl reload nginx

echo "[5/6] Configuring HTTPS..."
apt-get install -y certbot python3-certbot-nginx

if getent ahosts "$DOMAIN" >/dev/null 2>&1; then
  CERTBOT_ARGS=(--nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect)
  if [[ -n "$LE_EMAIL" ]]; then
    CERTBOT_ARGS+=(--email "$LE_EMAIL")
  else
    CERTBOT_ARGS+=(--register-unsafely-without-email)
  fi
  certbot "${CERTBOT_ARGS[@]}" || echo "[WARN] Certificate could not be issued. Check DNS and inbound ports 80/443, then retry certbot --nginx -d $DOMAIN."
else
  echo "[WARN] DNS for $DOMAIN does not resolve from this server yet. HTTPS skipped."
fi

echo "[6/6] Installation completed."
echo "Panel: https://$DOMAIN"
echo "Web root: $WEB_ROOT"
echo "Install directory: $INSTALL_DIR"
echo "Log: $LOG_FILE"
echo "NOTE: Set API Base URL in the panel, then add https://$DOMAIN to the API CORS_ORIGINS."
