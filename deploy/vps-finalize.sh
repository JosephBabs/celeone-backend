#!/usr/bin/env bash
set -euo pipefail

cd /var/www/celeone-backend
npm ci --omit=dev

sed -i 's|^SOCKET_PORT=.*|SOCKET_PORT=4200|' .env
sed -i 's|^SOCKET_PUBLIC_URL=.*|SOCKET_PUBLIC_URL=wss://api.celeonetv.com/socket|' .env
sed -i 's|^SOCKET_ALLOWED_ORIGINS=.*|SOCKET_ALLOWED_ORIGINS=https://app.celeonetv.com,https://api.celeonetv.com|' .env
cat >> .env <<EOF
API_INTERNAL_URL=http://127.0.0.1:8081/api/v1
SOCKET_JWT_SECRET=$(openssl rand -hex 64)
SOCKET_INTERNAL_SECRET=$(openssl rand -hex 64)
EOF

php artisan migrate --force

cat > /etc/nginx/sites-available/celeone-backend-internal <<'EOF'
server {
    listen 127.0.0.1:8081;
    server_name celeone-backend-internal;
    root /var/www/celeone-backend/public;
    index index.php;
    client_max_body_size 64m;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\. { deny all; }
}
EOF
ln -sfn /etc/nginx/sites-available/celeone-backend-internal /etc/nginx/sites-enabled/celeone-backend-internal

cp /etc/nginx/sites-enabled/celeone-api-router /etc/nginx/sites-enabled/celeone-api-router.before-laravel-$(date +%Y%m%d%H%M%S)
python3 - <<'PY'
from pathlib import Path
p = Path('/etc/nginx/sites-enabled/celeone-api-router')
s = p.read_text()
marker = '    location / {'
snippet = '''    location /api/v1/ {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Content-Type $content_type;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 120s;
    }

    location /admin/ {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }

    location /socket.io/ {
        proxy_pass http://127.0.0.1:4200/socket.io/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 75s;
        proxy_send_timeout 75s;
    }

'''
if 'location /api/v1/' not in s:
    s = s.replace(marker, snippet + marker, 1)
p.write_text(s)
PY

cat > /etc/systemd/system/celeone-socket.service <<'EOF'
[Unit]
Description=CeleOne Laravel API Socket.IO gateway
After=network.target redis-server.service nginx.service
[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/celeone-backend
EnvironmentFile=/var/www/celeone-backend/.env
ExecStart=/usr/bin/npm run socket:start
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/celeone-worker.service <<'EOF'
[Unit]
Description=CeleOne Laravel queue worker
After=network.target redis-server.service postgresql.service
[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/celeone-backend
EnvironmentFile=/var/www/celeone-backend/.env
ExecStart=/usr/bin/php artisan queue:work redis --sleep=1 --tries=3 --timeout=90
Restart=always
RestartSec=3
[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/celeone-outbox.service <<'EOF'
[Unit]
Description=CeleOne PostgreSQL outbox publisher
After=network.target redis-server.service postgresql.service
[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/celeone-backend
EnvironmentFile=/var/www/celeone-backend/.env
ExecStart=/usr/bin/php artisan celeone:outbox:publish
Restart=always
RestartSec=3
[Install]
WantedBy=multi-user.target
EOF

chown -R www-data:www-data /var/www/celeone-backend
chmod 640 /var/www/celeone-backend/.env
php artisan optimize:clear
php artisan config:cache
nginx -t
systemctl daemon-reload
systemctl enable --now celeone-socket.service celeone-worker.service celeone-outbox.service
systemctl reload nginx
