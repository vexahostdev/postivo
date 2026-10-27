#!/bin/sh
# Entrypoint smtp-engine:
#  1. Spool bersama dengan Laravel: path HARUS sama persis di kedua kontainer (engine mengirim path absolut).
#     Grup 82 = www-data di image php-alpine; setgid agar berkas baru bisa dibaca & dihapus Laravel.
#  2. Sertifikat TLS: dari certbot (/etc/letsencrypt) atau dari Caddy (/certs-root); atau TLS_CERT_PATH eksplisit.
#  3. Bila sertifikat diperbarui, proses dihentikan agar `restart: unless-stopped` memuat yang baru.
set -e
HOST="${POSTIVO_SMTP_HOST:-smtp.postivo.my.id}"
SPOOL="${POSTIVO_SPOOL_DIR:-/spool}"

mkdir -p "$SPOOL"
chgrp 82 "$SPOOL" 2>/dev/null || true
chmod 2770 "$SPOOL" 2>/dev/null || true

if [ -z "${TLS_CERT_PATH:-}" ]; then
    LE="/etc/letsencrypt/live/$HOST"
    n=0
    # certbot menerbitkan sertifikat pertama kali dalam ~1 menit setelah start; tunggu sampai 10 menit.
    while [ ! -f "$LE/fullchain.pem" ] && [ -d /etc/letsencrypt ] && [ "$n" -lt 120 ]; do
        echo "[smtp] menunggu sertifikat Let's Encrypt untuk $HOST ..." >&2
        n=$((n + 1)); sleep 5
    done
    if [ -f "$LE/fullchain.pem" ]; then
        export TLS_CERT_PATH="$LE/fullchain.pem" TLS_KEY_PATH="$LE/privkey.pem"
    elif [ -d /certs-root ]; then
        dir=$(find /certs-root/caddy/certificates -maxdepth 2 -type d -name "$HOST" 2>/dev/null | head -1)
        if [ -z "$dir" ]; then
            echo "[smtp] Sertifikat TLS untuk $HOST belum tersedia." >&2
            exit 1
        fi
        export TLS_CERT_PATH="$dir/$HOST.crt" TLS_KEY_PATH="$dir/$HOST.key"
    else
        echo "[smtp] Tidak ada sertifikat TLS (set TLS_CERT_PATH/TLS_KEY_PATH)." >&2
        exit 1
    fi
fi

stamp=$(stat -c %Y "$TLS_CERT_PATH")
node server.js &
pid=$!
trap 'kill "$pid" 2>/dev/null' TERM INT
while kill -0 "$pid" 2>/dev/null; do
    sleep 3600 &
    wait $! || true
    if [ "$(stat -c %Y "$TLS_CERT_PATH" 2>/dev/null)" != "$stamp" ]; then
        echo "[smtp] sertifikat diperbarui; restart untuk memuatnya" >&2
        kill "$pid" 2>/dev/null || true
    fi
done
wait "$pid"
