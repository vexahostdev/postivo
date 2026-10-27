#!/bin/sh
# Backup MySQL harian (jalankan dari cron di host): 15 3 * * * /opt/postivo/deploy/backup.sh
# Hasil: ./backups/postivo-YYYYmmdd-HHMMSS.sql.gz, simpan 14 hari. SALIN KELUAR SERVER (rclone/S3) juga.
# PENTING: cadangkan juga APP_KEY (.env.production) di tempat terpisah — tanpa APP_KEY,
# kunci privat DKIM & secret webhook di database tidak dapat didekripsi.
set -eu
cd "$(dirname "$0")/.."
mkdir -p backups
f="backups/postivo-$(date +%Y%m%d-%H%M%S).sql.gz"
docker compose --env-file .env.production exec -T mysql sh -c 'exec mysqldump --single-transaction --quick --routines -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip -9 > "$f"
[ -s "$f" ] || { echo "Backup kosong: $f" >&2; rm -f "$f"; exit 1; }
find backups -name 'postivo-*.sql.gz' -mtime +14 -delete
echo "OK $f ($(du -h "$f" | cut -f1))"
