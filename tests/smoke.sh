#!/usr/bin/env bash
set -Eeuo pipefail

echo "[Parse Admin] Local validation"
find app backend -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check app/public/assets/admin.js
bash -n install.sh
bash -n update-panel.sh
bash -n backend/bin/backup-db.sh
git diff --check

for f in app/bootstrap.php app/public/index.php app/public/api.php app/public/assets/admin.js app/public/assets/admin.css backend/config.php backend/schema.sql backend/public/index.php backend/platform_routes.php install.sh update-panel.sh; do
  test -s "$f"
done

test -d app/public/pages
test -d backend/migrations
test -d backend/bin

echo "PASS: Parse Admin validation smoke tests"
