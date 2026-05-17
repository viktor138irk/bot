<?php

declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const CONFIG_FILE = ROOT . '/storage/config.php';
const OFFSET_FILE = ROOT . '/storage/polling_offsets.json';

if (!is_file(CONFIG_FILE)) {
    fwrite(STDERR, "BotShop is not installed: storage/config.php not found\n");
    exit(1);
}

$config = require CONFIG_FILE;

function db(): PDO
{
    global $config;
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row ?: null;
}

function allRows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function tgRequest(array $bot, string $method, array $payload = []): array
{
    $url = 'https://api.telegram.org/bot' . $bot['token'] . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 35,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['ok' => false, 'description' => curl_error($ch)];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['ok' => false, 'description' => 'Bad Telegram response'];
}

function sendMessage(array $bot, int $chatId, string $text, array $keyboard = []): void
{
    if ($chatId <= 0) {
        return;
    }
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    }
    tgRequest($bot, 'sendMessage', $payload);
}

function answerCallback(array $bot, string $callbackId): void
{
    tgRequest($bot, 'answerCallbackQuery', ['callback_query_id' => $callbackId]);
}

function buttons(array $rows, string $prefix, string $labelField = 'name'): array
{
    $kb = [];
    foreach ($rows as $r) {
        $kb[] = [[
            'text' => (string)$r[$labelField],
            'callback_data' => $prefix . ':' . $r['id'],
        ]];
    }
    return $kb;
}

function getBotUser(array $bot, array $from): array
{
    $tgId = (int)$from['id'];
    $row = one('SELECT * FROM bot_users WHERE bot_id=? AND telegram_id=?', [$bot['id'], $tgId]);
    if (!$row) {
        q(
            'INSERT INTO bot_users (bot_id,telegram_id,username,first_name,last_name,last_seen_at) VALUES (?,?,?,?,?,NOW())',
            [$bot['id'], $tgId, $from['username'] ?? null, $from['first_name'] ?? null, $from['last_name'] ?? null]
        );
        $row = one('SELECT * FROM bot_users WHERE bot_id=? AND telegram_id=?', [$bot['id'], $tgId]);
    } else {
        q(
            'UPDATE bot_users SET username=?, first_name=?, last_name=?, last_seen_at=NOW() WHERE id=?',
            [$from['username'] ?? null, $from['first_name'] ?? null, $from['last_name'] ?? null, $row['id']]
        );
    }
    return $row;
}

function setState(int $botUserId, string $state, array $data = []): void
{
    q('UPDATE bot_users SET state=?, state_data=? WHERE id=?', [
        $state,
        json_encode($data, JSON_UNESCAPED_UNICODE),
        $botUserId,
    ]);
}

function stateData(array $botUser): array
{
    $d = json_decode((string)($botUser['state_data'] ?? ''), true);
    return is_array($d) ? $d : [];
}

function showCities(array $bot, int $chatId, array $botUser): void
{
    $rows = allRows('SELECT * FROM shop_cities WHERE bot_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id']]);
    if (!$rows) {
        sendMessage($bot, $chatId, 'Пока нет доступных городов. Добавьте город в админ-панели.');
        return;
    }
    setState((int)$botUser['id'], 'choose_city');
    sendMessage($bot, $chatId, $bot['welcome_text'] ?: 'Выберите город:', buttons($rows, 'city'));
}

function generateAmount(int $botId, int $base, int $min, int $max): array
{
    $busy = allRows("SELECT final_amount FROM shop_orders WHERE bot_id=? AND status='waiting_pay' AND expires_at>NOW()", [$botId]);
    $busyMap = array_flip(array_map(fn($r) => (int)$r['final_amount'], $busy));

    for ($i = 0; $i < 100; $i++) {
        $add = random_int($min, $max);
        $final = $base + $add;
        if (!isset($busyMap[$final])) {
            return [$add, $final];
        }
    }

    throw new RuntimeException('Нет свободной уникальной суммы. Попробуйте позже.');
}

function createOrder(array $bot, array $botUser, array $data): array
{
    foreach (['city_id', 'district_id', 'product_id', 'variant_id'] as $key) {
        if (empty($data[$key])) {
            throw new RuntimeException('Неполные данные заказа. Начните заново через /start.');
        }
    }

    $variant = one('SELECT * FROM shop_product_variants WHERE id=? AND bot_id=? AND is_active=1', [$data['variant_id'], $bot['id']]);
    if (!$variant) {
        throw new RuntimeException('Вариант товара не найден.');
    }

    $method = one('SELECT * FROM shop_payment_methods WHERE bot_id=? AND is_active=1 AND is_online=1 ORDER BY id ASC LIMIT 1', [$bot['id']]);
    if (!$method) {
        throw new RuntimeException('Нет активных реквизитов оплаты.');
    }

    [$add, $final] = generateAmount((int)$bot['id'], (int)$variant['price'], (int)$bot['min_add'], (int)$bot['max_add']);
    $ttl = max(5, (int)$bot['order_ttl_minutes']);

    q(
        'INSERT INTO shop_orders (bot_id,bot_user_id,telegram_id,city_id,district_id,product_id,variant_id,payment_method_id,base_amount,random_increment,final_amount,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ' . $ttl . ' MINUTE))',
        [$bot['id'], $botUser['id'], $botUser['telegram_id'], $data['city_id'], $data['district_id'], $data['product_id'], $data['variant_id'], $method['id'], $variant['price'], $add, $final]
    );

    return one('SELECT * FROM shop_orders WHERE id=?', [(int)db()->lastInsertId()]);
}

function textSafe(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function orderText(array $order): string
{
    $city = one('SELECT name FROM shop_cities WHERE id=?', [$order['city_id']]);
    $district = one('SELECT name FROM shop_districts WHERE id=?', [$order['district_id']]);
    $product = one('SELECT name FROM shop_products WHERE id=?', [$order['product_id']]);
    $variant = one('SELECT name FROM shop_product_variants WHERE id=?', [$order['variant_id']]);
    $method = one('SELECT * FROM shop_payment_methods WHERE id=?', [$order['payment_method_id']]);

    $text = "<b>Заказ №{$order['id']}</b>\n";
    $text .= 'Город: ' . textSafe($city['name'] ?? '') . "\n";
    $text .= 'Район: ' . textSafe($district['name'] ?? '') . "\n";
    $text .= 'Товар: ' . textSafe($product['name'] ?? '') . "\n";
    $text .= 'Вариант: ' . textSafe($variant['name'] ?? '') . "\n";
    $text .= "Цена: {$order['base_amount']} ₽\n";
    $text .= "Уникальная добавка: +{$order['random_increment']} ₽\n\n";
    $text .= "<b>К оплате:</b> {$order['final_amount']} ₽\n\n";

    if ($method) {
        $text .= "<b>Реквизиты:</b>\n";
        if ($method['bank_name']) {
            $text .= 'Банк: ' . textSafe($method['bank_name']) . "\n";
        }
        if ($method['card_number']) {
            $text .= 'Карта: ' . textSafe($method['card_number']) . "\n";
        }
        if ($method['sbp_phone']) {
            $text .= 'СБП: ' . textSafe($method['sbp_phone']) . "\n";
        }
        if ($method['recipient_name']) {
            $text .= 'Получатель: ' . textSafe($method['recipient_name']) . "\n";
        }
    }

    $text .= "\nОплатите точную сумму. После проверки администратор подтвердит заказ.";
    return $text;
}

function processUpdate(array $bot, array $payload): void
{
    q('INSERT INTO telegram_updates (bot_id, update_id, payload) VALUES (?,?,?)', [
        $bot['id'],
        $payload['update_id'] ?? null,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $message = $payload['message'] ?? null;
    $callback = $payload['callback_query'] ?? null;
    $chatId = 0;

    try {
        if ($message) {
            $chatId = (int)$message['chat']['id'];
            $botUser = getBotUser($bot, $message['from'] ?? $message['chat']);
            showCities($bot, $chatId, $botUser);
            return;
        }

        if (!$callback) {
            return;
        }

        answerCallback($bot, $callback['id']);
        $chatId = (int)$callback['message']['chat']['id'];
        $botUser = getBotUser($bot, $callback['from']);
        [$action, $id] = array_pad(explode(':', (string)$callback['data'], 2), 2, null);
        $data = stateData($botUser);

        if ($action === 'city') {
            $data = ['city_id' => (int)$id];
            setState((int)$botUser['id'], 'choose_district', $data);
            $rows = allRows('SELECT * FROM shop_districts WHERE bot_id=? AND city_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id'], $id]);
            if (!$rows) {
                sendMessage($bot, $chatId, 'Для этого города пока нет районов.');
                return;
            }
            sendMessage($bot, $chatId, 'Выберите район:', buttons($rows, 'district'));
            return;
        }

        if ($action === 'district') {
            $data['district_id'] = (int)$id;
            setState((int)$botUser['id'], 'choose_product', $data);
            $rows = allRows('SELECT * FROM shop_products WHERE bot_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id']]);
            if (!$rows) {
                sendMessage($bot, $chatId, 'Пока нет доступных товаров.');
                return;
            }
            sendMessage($bot, $chatId, 'Выберите товар:', buttons($rows, 'product'));
            return;
        }

        if ($action === 'product') {
            $data['product_id'] = (int)$id;
            setState((int)$botUser['id'], 'choose_variant', $data);
            $rows = allRows('SELECT id, CONCAT(name, " — ", price, " ₽") AS name FROM shop_product_variants WHERE bot_id=? AND product_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id'], $id]);
            if (!$rows) {
                sendMessage($bot, $chatId, 'Для этого товара пока нет вариантов.');
                return;
            }
            sendMessage($bot, $chatId, 'Выберите вариант:', buttons($rows, 'variant'));
            return;
        }

        if ($action === 'variant') {
            $data['variant_id'] = (int)$id;
            setState((int)$botUser['id'], 'confirm', $data);
            sendMessage($bot, $chatId, 'Подтвердить заказ?', [
                [['text' => 'Подтвердить', 'callback_data' => 'confirm:1']],
                [['text' => 'Отмена', 'callback_data' => 'cancel:1']],
            ]);
            return;
        }

        if ($action === 'confirm') {
            $order = createOrder($bot, $botUser, $data);
            setState((int)$botUser['id'], 'ordered', ['order_id' => $order['id']]);
            sendMessage($bot, $chatId, orderText($order));
            return;
        }

        if ($action === 'cancel') {
            setState((int)$botUser['id'], 'start');
            showCities($bot, $chatId, $botUser);
        }
    } catch (Throwable $e) {
        sendMessage($bot, $chatId, 'Ошибка: ' . $e->getMessage());
        fwrite(STDERR, '[' . date('c') . '] Bot #' . $bot['id'] . ': ' . $e->getMessage() . PHP_EOL);
    }
}

function loadOffsets(): array
{
    if (!is_file(OFFSET_FILE)) {
        return [];
    }
    $json = json_decode((string)file_get_contents(OFFSET_FILE), true);
    return is_array($json) ? $json : [];
}

function saveOffsets(array $offsets): void
{
    file_put_contents(OFFSET_FILE, json_encode($offsets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

fwrite(STDOUT, '[' . date('c') . "] BotShop polling started\n");
$offsets = loadOffsets();
$webhookDeleted = [];

while (true) {
    try {
        q("UPDATE shop_orders SET status='expired' WHERE status='waiting_pay' AND expires_at<NOW()");
        $bots = allRows("SELECT * FROM bots WHERE status='active' ORDER BY id ASC");

        foreach ($bots as $bot) {
            $botId = (string)$bot['id'];
            if (empty($webhookDeleted[$botId])) {
                tgRequest($bot, 'deleteWebhook', ['drop_pending_updates' => 'false']);
                $webhookDeleted[$botId] = true;
            }

            $payload = [
                'timeout' => 10,
                'limit' => 30,
                'allowed_updates' => json_encode(['message', 'callback_query']),
            ];
            if (isset($offsets[$botId])) {
                $payload['offset'] = (int)$offsets[$botId];
            }

            $response = tgRequest($bot, 'getUpdates', $payload);
            if (!($response['ok'] ?? false)) {
                fwrite(STDERR, '[' . date('c') . '] getUpdates error for bot #' . $botId . ': ' . ($response['description'] ?? 'unknown') . PHP_EOL);
                continue;
            }

            foreach (($response['result'] ?? []) as $update) {
                processUpdate($bot, $update);
                if (isset($update['update_id'])) {
                    $offsets[$botId] = ((int)$update['update_id']) + 1;
                }
            }

            saveOffsets($offsets);
        }

        if (!$bots) {
            sleep(3);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] polling loop error: ' . $e->getMessage() . PHP_EOL);
        sleep(5);
    }
}
