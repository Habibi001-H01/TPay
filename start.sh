#!/bin/bash

# Cari dan jalankan server WA Node.js di latar belakang
WA_SCRIPT=$(find /var/www/html -maxdepth 2 \( -name "server.js" -o -name "index.js" -o -name "wa_server.js" -o -name "bot.js" \) ! -path "*/node_modules/*" | head -n 1)

if [ -n "$WA_SCRIPT" ]; then
    echo "Menjalankan mesin WA: $WA_SCRIPT"
    cd "$(dirname "$WA_SCRIPT")" && node "$(basename "$WA_SCRIPT")" &
    cd /var/www/html
fi

apache2-foreground