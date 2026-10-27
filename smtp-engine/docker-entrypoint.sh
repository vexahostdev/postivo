#!/bin/sh
# Cari sertifikat TLS yang diterbitkan Caddy untuk host SMTP, lalu jalankan engine.
set -e
HOST="${POSTIVO_SMTP_HOST:-smtp.postivo.my.id}"
if [ -z "${TLS_CERT_PATH:-}" ] && [ -d /certs-root ]; then
    dir=$(find /certs-root/caddy/certificates -maxdepth 2 -type d -name "$HOST" 2>/dev/null | head -1)
    if [ -z "$dir" ]; then
        echo "[smtp] Sertifikat TLS untuk $HOST belum diterbitkan Caddy; coba lagi sebentar lagi." >&2
        exit 1
    fi
    export TLS_CERT_PATH="$dir/$HOST.crt" TLS_KEY_PATH="$dir/$HOST.key"
fi
exec node server.js
