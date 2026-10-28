#!/bin/bash
# Backup MySQL harian (jalankan dari cron di host): 15 3 * * * /opt/postivo/deploy/backup.sh
# Hasil di POSTIVO_BACKUP_DIR (default ../postivo-backups), simpan 14 hari. Salin juga keluar server.
# PENTING: cadangkan juga APP_KEY (.env.production) di tempat terpisah — tanpa APP_KEY,
# kunci privat DKIM & secret webhook di database tidak dapat didekripsi.
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
project_dir=$(pwd -P)
backup_dir=${POSTIVO_BACKUP_DIR:-../postivo-backups}
mkdir -p "$backup_dir"
backup_dir=$(cd "$backup_dir" && pwd -P)
case "$backup_dir/" in
    "$project_dir/"*) echo "Lokasi backup harus di luar checkout aplikasi." >&2; exit 1 ;;
esac
f="$backup_dir/postivo-$(date +%Y%m%d-%H%M%S).sql.gz"
tmp=$(mktemp "$backup_dir/.postivo-XXXXXX.sql.gz")
trap 'rm -f "$tmp"' EXIT
trap 'exit 1' HUP INT TERM
docker compose --env-file .env.production exec -T mysql sh -c 'exec mysqldump --single-transaction --quick --routines -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip -9 > "$tmp"
[ -s "$tmp" ] || { echo "Backup kosong: $f" >&2; exit 1; }
mv "$tmp" "$f"
find "$backup_dir" -name 'postivo-*.sql.gz' -mtime +14 -delete
echo "OK $f ($(du -h "$f" | cut -f1))"
