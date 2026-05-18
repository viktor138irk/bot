# BotShop

BotShop — стартовый каркас мультибот-магазина для Telegram с веб-админкой.

## Что заложено

- подключение нескольких Telegram-ботов через токен;
- локальный режим без домена через Telegram polling;
- webhook-режим для сервера с HTTPS-доменом;
- выбор города, района, товара и варианта в Telegram;
- создание заказа без отправки чека;
- выдача реквизитов и уникальной суммы `цена + случайная добавка`;
- ручное подтверждение/отклонение оплаты в админ-панели;
- управление городами, районами, товарами, вариантами и реквизитами;
- базовые логи входящих Telegram-событий и ошибок;
- автоустановщик `install.sh`.

## Важно

Проект предназначен только для легального магазина товаров и услуг. В коде нет механики тайников, скрытых адресов, автоматической выдачи запрещённых товаров или обхода платёжных правил.

## Требования

- Ubuntu/Debian сервер или локальная VM;
- PHP 8.1+;
- MySQL 5.7+/8+;
- Nginx;
- доступ в интернет для Telegram Bot API.

## Локальная установка без домена

Самый простой вариант:

```bash
sudo apt update
sudo apt install -y git curl
cd /root
git clone https://github.com/viktor138irk/bot.git botshop-installer
cd botshop-installer
sudo bash install.sh --local
```

После установки скрипт покажет:

```text
Админка: http://IP-СЕРВЕРА/login
Email: admin@local.test
Пароль: сгенерированный пароль
```

В локальном режиме домен и SSL не нужны. Telegram работает через polling-сервис:

```bash
systemctl status botshop-polling --no-pager
journalctl -u botshop-polling -f
```

## Установка с собственными данными

```bash
sudo bash install.sh --local \
  --admin-email admin@example.local \
  --admin-pass 'СЛОЖНЫЙ_ПАРОЛЬ' \
  --db-pass 'СЛОЖНЫЙ_ПАРОЛЬ_БД'
```

## После установки

1. Откройте админку: `http://IP-СЕРВЕРА/login`.
2. Войдите под админом.
3. Создайте Telegram-бота через `@BotFather`.
4. В панели откройте «Боты».
5. Добавьте токен.
6. Поставьте статус `active`.
7. В локальном режиме кнопку webhook нажимать не нужно.
8. Добавьте город, район, товар, вариант и реквизиты.
9. Напишите `/start` своему Telegram-боту.

## Обслуживание

Перезапуск polling:

```bash
sudo systemctl restart botshop-polling
```

Логи polling:

```bash
sudo journalctl -u botshop-polling -f
```

Логи Nginx:

```bash
sudo tail -n 100 /var/log/nginx/botshop_error.log
```

Файл локальной конфигурации:

```text
/var/www/botshop/storage/config.php
```

## Структура

```text
install.sh             # автоустановщик
public/index.php       # веб-панель и webhook endpoint
scripts/polling.php    # локальный Telegram polling worker
database/schema.sql    # схема базы
storage/config.php     # создаётся installer'ом, не хранится в git
storage/logs/          # логи
```

## Версия

`0.1.1`
