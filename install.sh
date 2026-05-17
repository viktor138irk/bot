#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/var/www/botshop"
REPO_URL="https://github.com/viktor138irk/bot.git"
DB_NAME="botshop"
DB_USER="botshop_user"
DB_PASS=""
ADMIN_NAME="Admin"
ADMIN_EMAIL="admin@local.test"
ADMIN_PASS=""
SERVER_NAME="_"
INSTALL_POLLING=1

log() { echo -e "\033[1;36m[BotShop]\033[0m $*"; }
err() { echo -e "\033[1;31m[Ошибка]\033[0m $*" >&2; }
need_root() { if [[ "${EUID}" -ne 0 ]]; then err "Запусти от root: sudo bash install.sh --local"; exit 1; fi; }
rand_pass() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24; }
local_ip() { hostname -I 2>/dev/null | awk '{print $1}'; }

usage() {
  cat <<'USAGE'
BotShop installer

Локальная установка без домена:
  sudo bash install.sh --local

Опции:
  --local                       Локальный режим без домена и SSL. Админка по http://IP/
  --dir /var/www/botshop        Папка установки
  --db-name botshop             Имя базы
  --db-user botshop_user        Пользователь базы
  --db-pass PASSWORD            Пароль базы. Если не указан — будет сгенерирован
  --admin-email EMAIL           Email администратора
  --admin-pass PASSWORD         Пароль администратора. Если не указан — будет сгенерирован
  --no-polling                  Не создавать systemd-сервис polling
  --help                        Справка
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --local) SERVER_NAME="_"; shift ;;
    --dir) APP_DIR="$2"; shift 2 ;;
    --db-name) DB_NAME="$2"; shift 2 ;;
    --db-user) DB_USER="$2"; shift 2 ;;
    --db-pass) DB_PASS="$2"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="$2"; shift 2 ;;
    --admin-pass) ADMIN_PASS="$2"; shift 2 ;;
    --no-polling) INSTALL_POLLING=0; shift ;;
    --help|-h) usage; exit 0 ;;
    *) err "Неизвестная опция: $1"; usage; exit 1 ;;
  esac
done

need_root

if [[ -z "$DB_PASS" ]]; then DB_PASS="$(rand_pass)"; fi
if [[ -z "$ADMIN_PASS" ]]; then ADMIN_PASS="$(rand_pass)"; fi

log "Установка пакетов"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mysql-server git unzip curl ca-certificates php-fpm php-cli php-mysql php-curl php-mbstring php-xml php-zip

log "Подготовка проекта в ${APP_DIR}"
mkdir -p "$(dirname "$APP_DIR")"
if [[ -d "$APP_DIR/.git" ]]; then
  git -C "$APP_DIR" pull --ff-only
else
  rm -rf "$APP_DIR"
  git clone "$REPO_URL" "$APP_DIR"
fi
mkdir -p "$APP_DIR/storage/logs"

log "Настройка MySQL"
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Импорт схемы базы"
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$APP_DIR/database/schema.sql"

log "Создание storage/config.php"
cat > "$APP_DIR/storage/config.php" <<PHP
<?php
return [
    'db_host' => 'localhost',
    'db_name' => '${DB_NAME}',
    'db_user' => '${DB_USER}',
    'db_pass' => '${DB_PASS}',
];
PHP

log "Создание администратора"
ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASS")"
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
INSERT INTO users (name, email, password_hash, role)
SELECT '${ADMIN_NAME}', '${ADMIN_EMAIL}', '${ADMIN_HASH}', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='${ADMIN_EMAIL}');
SQL

log "Настройка прав"
chown -R www-data:www-data "$APP_DIR"
chmod -R 775 "$APP_DIR/storage"

PHP_SOCK="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' | sort -V | tail -n 1)"
if [[ -z "$PHP_SOCK" ]]; then
  err "Не найден php-fpm.sock в /run/php"
  exit 1
fi

log "Настройка Nginx для локального доступа"
cat > /etc/nginx/sites-available/botshop <<NGINX
server {
    listen 80 default_server;
    server_name ${SERVER_NAME};

    root ${APP_DIR}/public;
    index index.php index.html;

    access_log /var/log/nginx/botshop_access.log;
    error_log /var/log/nginx/botshop_error.log;

    client_max_body_size 32m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    location ~ /\. {
        deny all;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/botshop /etc/nginx/sites-enabled/botshop
nginx -t
systemctl reload nginx

if [[ "$INSTALL_POLLING" -eq 1 ]]; then
  log "Настройка polling-сервиса для Telegram без домена"
  cat > /etc/systemd/system/botshop-polling.service <<SERVICE
[Unit]
Description=BotShop Telegram polling worker
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php ${APP_DIR}/scripts/polling.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE
  systemctl daemon-reload
  systemctl enable botshop-polling.service
  systemctl restart botshop-polling.service || true
fi

IP="$(local_ip)"
log "Готово"
echo ""
echo "Админка:       http://${IP:-SERVER_IP}/login"
echo "Email:         ${ADMIN_EMAIL}"
echo "Пароль:        ${ADMIN_PASS}"
echo "База:          ${DB_NAME}"
echo "Пользователь:  ${DB_USER}"
echo "Пароль БД:     ${DB_PASS}"
echo ""
echo "Локальный режим: домен и SSL не нужны. Для Telegram работает polling-сервис."
echo "Статус polling: systemctl status botshop-polling --no-pager"
echo "Логи polling:   journalctl -u botshop-polling -f"
