#!/bin/sh
# Pulihkan backup: ./deploy/restore.sh backups/postivo-XXXX.sql.gz  (UJI di staging dulu secara berkala)
set -eu
cd "$(dirname "$0")/.."
[ -f "${1:-}" ] || { echo "Pemakaian: $0 <file.sql.gz>" >&2; exit 1; }
gunzip -c "$1" | docker compose --env-file .env.production exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
echo "Dipulihkan dari $1"
