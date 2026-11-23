#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CONFIG="/etc/parse-api.env"
CREDENTIALS="/etc/parse-db-backup.cnf"
BACKUP_DIR="/var/backups/parse-admin"

[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ -r "$CONFIG" && -r "$CREDENTIALS" ]] || { echo "Backup configuration is missing." >&2; exit 1; }

# The configuration file is root-owned and contains only deployment-generated values.
source "$CONFIG"
install -d -o root -g root -m 0700 "$BACKUP_DIR"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TEMP_FILE="$BACKUP_DIR/.parse-admin-$STAMP.sql.gz.tmp"
FINAL_FILE="$BACKUP_DIR/parse-admin-$STAMP.sql.gz"

trap 'rm -f "$TEMP_FILE"' EXIT
mysqldump --defaults-extra-file="$CREDENTIALS" --single-transaction --quick --no-tablespaces --skip-triggers "$DB_NAME" | gzip -9 > "$TEMP_FILE"
gzip -t "$TEMP_FILE"
mv "$TEMP_FILE" "$FINAL_FILE"
chmod 0600 "$FINAL_FILE"

find "$BACKUP_DIR" -maxdepth 1 -type f -name 'parse-admin-*.sql.gz' -mtime +14 -delete
echo "Database backup created: $FINAL_FILE"
