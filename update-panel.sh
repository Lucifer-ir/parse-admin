#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "Run as root: sudo bash update-panel.sh" >&2
  exit 1
fi

INSTALL_DIR="/opt/parse-admin"
WEB_ROOT="/var/www/parse-admin"
REPO_URL="https://github.com/Lucifer-ir/parse-admin.git"

if [[ ! -d "$INSTALL_DIR/.git" ]]; then
  echo "Repository not found at $INSTALL_DIR. Run the main installer first." >&2
  exit 1
fi

echo "[1/4] Fetching latest main branch..."
git -C "$INSTALL_DIR" fetch origin main
git -C "$INSTALL_DIR" reset --hard origin/main

echo "[2/4] Publishing updated HTML, JavaScript and CSS..."
install -d -m 0755 "$WEB_ROOT" "$WEB_ROOT/assets"
install -m 0644 "$INSTALL_DIR/index.html" "$WEB_ROOT/index.html"
rsync -a --delete "$INSTALL_DIR/assets/" "$WEB_ROOT/assets/"
chown -R www-data:www-data "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 0755 {} \;
find "$WEB_ROOT" -type f -exec chmod 0644 {} \;

echo "[3/4] Checking Nginx configuration..."
nginx -t

echo "[4/4] Reloading Nginx..."
systemctl reload nginx

echo
echo "Panel update completed."
echo "Commit: $(git -C "$INSTALL_DIR" rev-parse --short HEAD)"
echo "URL: https://panel.aiparseh.ir"
