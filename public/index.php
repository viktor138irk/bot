<?php

declare(strict_types=1);

session_start();

const APP_VERSION = '0.1.0';
const ROOT = __DIR__ . '/..';
const CONFIG_FILE = ROOT . '/storage/config.php';

function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function path(): string { return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function installed(): bool { return is_file(CONFIG_FILE); }

function config(): array {
    if (!installed()) return [];
    return require CONFIG_FILE;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = config();
    $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array {
    $row = q($sql, $params)->fetch();
    return $row ?: null;
}

function allRows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function checkCsrf(): void { if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(419); exit('CSRF token mismatch'); } }
function user(): ?array { return $_SESSION['user'] ?? null; }
function requireAuth(): void { if (!user()) redirect('/login'); }

function layout(string $title, string $content): void {
    $u = user();
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · BotShop</title>';
    echo '<style>
    :root{--bg:#0b1020;--card:#121a31;--muted:#8ea0c5;--text:#eef3ff;--line:#263453;--brand:#6ee7ff;--danger:#ff6b6b;--ok:#55d187}
    *{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#182449,#0b1020 48%);color:var(--text);font-family:Inter,Arial,sans-serif}a{color:var(--brand);text-decoration:none}.wrap{display:grid;grid-template-columns:260px 1fr;min-height:100vh}.side{border-right:1px solid var(--line);background:rgba(11,16,32,.86);padding:22px;position:sticky;top:0;height:100vh}.brand{font-size:22px;font-weight:800;margin-bottom:22px}.nav a{display:block;padding:11px 12px;border-radius:12px;color:#dbe7ff;margin:5px 0}.nav a:hover,.nav .on{background:#1b294a}.main{padding:28px}.card{background:rgba(18,26,49,.92);border:1px solid var(--line);border-radius:20px;padding:20px;margin-bottom:18px;box-shadow:0 20px 70px rgba(0,0,0,.22)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.stat{font-size:30px;font-weight:800}.muted{color:var(--muted)}input,select,textarea{width:100%;background:#0d1428;color:var(--text);border:1px solid var(--line);border-radius:12px;padding:11px;margin:6px 0 12px}textarea{min-height:88px}.btn,button{display:inline-block;background:linear-gradient(135deg,#68e1fd,#7c5cff);border:0;color:#08111f;border-radius:12px;padding:10px 14px;font-weight:700;cursor:pointer}.btn.secondary{background:#243456;color:#e8f0ff}.btn.danger{background:var(--danger);color:white}.btn.ok{background:var(--ok);color:#06130c}.table{width:100%;border-collapse:collapse}.table th,.table td{border-bottom:1px solid var(--line);padding:10px;text-align:left;vertical-align:top}.pill{display:inline-block;padding:5px 9px;border-radius:999px;background:#243456;color:#dbe7ff;font-size:12px}.row-actions{display:flex;gap:8px;flex-wrap:wrap}.login{max-width:420px;margin:9vh auto}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:18px}.notice{padding:12px 14px;border:1px solid #38557e;background:#14223d;border-radius:14px;margin-bottom:16px}@media(max-width:820px){.wrap{display:block}.side{position:relative;height:auto}.main{padding:16px}}
    </style></head><body>';
    if ($u) {
        echo '<div class="wrap"><aside class="side"><div class="brand">BotShop <span class="muted">v' . APP_VERSION . '</span></div><nav class="nav">';
        $links = ['/'=>'Дашборд','/bots'=>'Боты','/cities'=>'Города','/districts'=>'Районы','/products'=>'Товары','/variants'=>'Варианты','/payments'=>'Реквизиты','/orders'=>'Заказы','/logs'=>'Логи'];
        foreach ($links as $href=>$name) echo '<a class="'.(path()===$href?'on':'').'" href="'.$href.'">'.e($name).'</a>';
        echo '<a href="/logout">Выйти</a></nav></aside><main class="main"><div class="top"><h1>'.e($title).'</h1><div class="muted">'.e($u['email']).'</div></div>'.$content.'</main></div>';
    } else {
        echo $content;
    }
    echo '</body></html>';
}

function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}
function notice(): string { $m = flash(); return $m ? '<div class="notice">'.e($m).'</div>' : ''; }

function botOptions(?int $selected = null): string {
    $html = '';
    foreach (allRows('SELECT id,name FROM bots ORDER BY id DESC') as $b) {
        $html .= '<option value="'.$b['id'].'" '.((int)$b['id']===$selected?'selected':'').'>'.e($b['name']).'</option>';
    }
    return $html;
}

function tgRequest(array $bot, string $method, array $payload = []): array {
    $url = 'https://api.telegram.org/bot' . $bot['token'] . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>10]);
    $raw = curl_exec($ch);
    if ($raw === false) return ['ok'=>false, 'description'=>curl_error($ch)];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['ok'=>false, 'description'=>'Bad Telegram response'];
}

function sendMessage(array $bot, int $chatId, string $text, array $keyboard = []): void {
    $payload = ['chat_id'=>$chatId, 'text'=>$text, 'parse_mode'=>'HTML'];
    if ($keyboard) $payload['reply_markup'] = json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE);
    tgRequest($bot, 'sendMessage', $payload);
}

function answerCallback(array $bot, string $callbackId): void { tgRequest($bot, 'answerCallbackQuery', ['callback_query_id'=>$callbackId]); }

function buttons(array $rows, string $prefix, string $labelField = 'name'): array {
    $kb = [];
    foreach ($rows as $r) $kb[] = [['text'=>$r[$labelField], 'callback_data'=>$prefix . ':' . $r['id']]];
    return $kb;
}

function getBotUser(array $bot, array $from): array {
    $tgId = (int)$from['id'];
    $row = one('SELECT * FROM bot_users WHERE bot_id=? AND telegram_id=?', [$bot['id'], $tgId]);
    if (!$row) {
        q('INSERT INTO bot_users (bot_id,telegram_id,username,first_name,last_name,last_seen_at) VALUES (?,?,?,?,?,NOW())', [$bot['id'],$tgId,$from['username']??null,$from['first_name']??null,$from['last_name']??null]);
        $row = one('SELECT * FROM bot_users WHERE bot_id=? AND telegram_id=?', [$bot['id'], $tgId]);
    } else {
        q('UPDATE bot_users SET username=?, first_name=?, last_name=?, last_seen_at=NOW() WHERE id=?', [$from['username']??null,$from['first_name']??null,$from['last_name']??null,$row['id']]);
    }
    return $row;
}

function setState(int $botUserId, string $state, array $data = []): void { q('UPDATE bot_users SET state=?, state_data=? WHERE id=?', [$state, json_encode($data, JSON_UNESCAPED_UNICODE), $botUserId]); }
function stateData(array $botUser): array { $d = json_decode((string)($botUser['state_data'] ?? ''), true); return is_array($d) ? $d : []; }

function showCities(array $bot, int $chatId, array $botUser): void {
    $rows = allRows('SELECT * FROM shop_cities WHERE bot_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id']]);
    if (!$rows) { sendMessage($bot,$chatId,'Пока нет доступных городов.'); return; }
    setState((int)$botUser['id'], 'choose_city');
    sendMessage($bot, $chatId, $bot['welcome_text'] ?: 'Выберите город:', buttons($rows, 'city'));
}

function generateAmount(int $botId, int $base, int $min, int $max): array {
    $busy = allRows("SELECT final_amount FROM shop_orders WHERE bot_id=? AND status='waiting_pay' AND expires_at>NOW()", [$botId]);
    $busyMap = array_flip(array_map(fn($r)=>(int)$r['final_amount'], $busy));
    for ($i=0;$i<100;$i++) {
        $add = random_int($min, $max);
        $final = $base + $add;
        if (!isset($busyMap[$final])) return [$add, $final];
    }
    throw new RuntimeException('Нет свободной уникальной суммы.');
}

function createOrder(array $bot, array $botUser, array $data): array {
    $variant = one('SELECT * FROM shop_product_variants WHERE id=? AND bot_id=? AND is_active=1', [$data['variant_id'], $bot['id']]);
    if (!$variant) throw new RuntimeException('Вариант товара не найден.');
    $method = one('SELECT * FROM shop_payment_methods WHERE bot_id=? AND is_active=1 AND is_online=1 ORDER BY id ASC LIMIT 1', [$bot['id']]);
    if (!$method) throw new RuntimeException('Нет активных реквизитов оплаты.');
    [$add,$final] = generateAmount((int)$bot['id'], (int)$variant['price'], (int)$bot['min_add'], (int)$bot['max_add']);
    $ttl = max(5, (int)$bot['order_ttl_minutes']);
    q('INSERT INTO shop_orders (bot_id,bot_user_id,telegram_id,city_id,district_id,product_id,variant_id,payment_method_id,base_amount,random_increment,final_amount,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL '.$ttl.' MINUTE))', [
        $bot['id'],$botUser['id'],$botUser['telegram_id'],$data['city_id'],$data['district_id'],$data['product_id'],$data['variant_id'],$method['id'],$variant['price'],$add,$final
    ]);
    return one('SELECT * FROM shop_orders WHERE id=?', [(int)db()->lastInsertId()]);
}

function orderText(array $order): string {
    $city = one('SELECT name FROM shop_cities WHERE id=?', [$order['city_id']]);
    $district = one('SELECT name FROM shop_districts WHERE id=?', [$order['district_id']]);
    $product = one('SELECT name FROM shop_products WHERE id=?', [$order['product_id']]);
    $variant = one('SELECT name FROM shop_product_variants WHERE id=?', [$order['variant_id']]);
    $method = one('SELECT * FROM shop_payment_methods WHERE id=?', [$order['payment_method_id']]);
    $pay = "\n\n<b>К оплате:</b> {$order['final_amount']} ₽\n";
    if ($method) {
        $pay .= "\n<b>Реквизиты:</b>\n";
        if ($method['bank_name']) $pay .= 'Банк: ' . e($method['bank_name']) . "\n";
        if ($method['card_number']) $pay .= 'Карта: ' . e($method['card_number']) . "\n";
        if ($method['sbp_phone']) $pay .= 'СБП: ' . e($method['sbp_phone']) . "\n";
        if ($method['recipient_name']) $pay .= 'Получатель: ' . e($method['recipient_name']) . "\n";
    }
    return "<b>Заказ №{$order['id']}</b>\nГород: ".e($city['name']??'')."\nРайон: ".e($district['name']??'')."\nТовар: ".e($product['name']??'')."\nВариант: ".e($variant['name']??'')."\nЦена: {$order['base_amount']} ₽\nУникальная добавка: +{$order['random_increment']} ₽" . $pay . "\nОплатите точную сумму. После проверки администратор подтвердит заказ.";
}

function handleWebhook(string $secret): void {
    $bot = one('SELECT * FROM bots WHERE webhook_secret=? AND status="active"', [$secret]);
    if (!$bot) { http_response_code(404); echo 'bot not found'; return; }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    q('INSERT INTO telegram_updates (bot_id, update_id, payload) VALUES (?,?,?)', [$bot['id'], $payload['update_id'] ?? null, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $message = $payload['message'] ?? null;
    $callback = $payload['callback_query'] ?? null;
    try {
        if ($message) {
            $chatId = (int)$message['chat']['id'];
            $botUser = getBotUser($bot, $message['from'] ?? $message['chat']);
            showCities($bot, $chatId, $botUser);
        } elseif ($callback) {
            answerCallback($bot, $callback['id']);
            $chatId = (int)$callback['message']['chat']['id'];
            $botUser = getBotUser($bot, $callback['from']);
            [$action,$id] = array_pad(explode(':', (string)$callback['data'], 2), 2, null);
            $data = stateData($botUser);
            if ($action === 'city') {
                $data = ['city_id'=>(int)$id]; setState((int)$botUser['id'], 'choose_district', $data);
                $rows = allRows('SELECT * FROM shop_districts WHERE bot_id=? AND city_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id'],$id]);
                sendMessage($bot,$chatId,'Выберите район:', buttons($rows,'district'));
            } elseif ($action === 'district') {
                $data['district_id']=(int)$id; setState((int)$botUser['id'],'choose_product',$data);
                $rows = allRows('SELECT * FROM shop_products WHERE bot_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id']]);
                sendMessage($bot,$chatId,'Выберите товар:', buttons($rows,'product'));
            } elseif ($action === 'product') {
                $data['product_id']=(int)$id; setState((int)$botUser['id'],'choose_variant',$data);
                $rows = allRows('SELECT id, CONCAT(name, " — ", price, " ₽") AS name FROM shop_product_variants WHERE bot_id=? AND product_id=? AND is_active=1 ORDER BY sort_order,name', [$bot['id'],$id]);
                sendMessage($bot,$chatId,'Выберите вариант:', buttons($rows,'variant'));
            } elseif ($action === 'variant') {
                $data['variant_id']=(int)$id; setState((int)$botUser['id'],'confirm',$data);
                sendMessage($bot,$chatId,'Подтвердить заказ?', [[['text'=>'Подтвердить','callback_data'=>'confirm:1']],[['text'=>'Отмена','callback_data'=>'cancel:1']]]);
            } elseif ($action === 'confirm') {
                $order = createOrder($bot,$botUser,$data); setState((int)$botUser['id'],'ordered',['order_id'=>$order['id']]);
                sendMessage($bot,$chatId, orderText($order));
            } elseif ($action === 'cancel') {
                setState((int)$botUser['id'],'start'); showCities($bot,$chatId,$botUser);
            }
        }
    } catch (Throwable $e) {
        sendMessage($bot, (int)($chatId ?? 0), 'Ошибка: ' . $e->getMessage());
    }
    echo 'ok';
}

function installPage(): void {
    if (installed()) redirect('/login');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $host=$_POST['db_host']??'localhost'; $name=$_POST['db_name']??''; $user=$_POST['db_user']??''; $pass=$_POST['db_pass']??'';
        $pdo = new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents(ROOT.'/database/schema.sql'); $pdo->exec($schema);
        $adminName=$_POST['admin_name']?:'Admin'; $email=$_POST['admin_email']; $hash=password_hash($_POST['admin_pass'], PASSWORD_DEFAULT);
        $st=$pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,"admin")'); $st->execute([$adminName,$email,$hash]);
        if (!is_dir(ROOT.'/storage')) mkdir(ROOT.'/storage',0775,true); if (!is_dir(ROOT.'/storage/logs')) mkdir(ROOT.'/storage/logs',0775,true);
        $cfg = "<?php\nreturn " . var_export(['db_host'=>$host,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass], true) . ";\n";
        file_put_contents(CONFIG_FILE, $cfg); redirect('/login');
    }
    layout('Установка', '<div class="login card"><h1>Установка BotShop</h1><form method="post"><input name="db_host" placeholder="DB host" value="localhost"><input name="db_name" placeholder="DB name" required><input name="db_user" placeholder="DB user" required><input name="db_pass" placeholder="DB password" type="password"><hr><input name="admin_name" placeholder="Имя администратора" value="Admin"><input name="admin_email" placeholder="Email" required><input name="admin_pass" placeholder="Пароль" type="password" required><button>Установить</button></form></div>');
}

function simpleCreate(string $table, array $fields, string $redirect): void {
    checkCsrf(); $cols=[];$vals=[];$ph=[]; foreach($fields as $f){$cols[]=$f;$vals[]=$_POST[$f]??null;$ph[]='?';}
    q('INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.implode(',',$ph).')',$vals); flash('Сохранено'); redirect($redirect);
}

function loginPage(): void {
    if ($_SERVER['REQUEST_METHOD']==='POST') { $u=one('SELECT * FROM users WHERE email=?',[$_POST['email']??'']); if($u && password_verify($_POST['password']??'', $u['password_hash'])){$_SESSION['user']=['id'=>$u['id'],'email'=>$u['email'],'name'=>$u['name']]; redirect('/');} flash('Неверный логин или пароль'); }
    layout('Вход', '<div class="login card"><h1>BotShop</h1>'.notice().'<form method="post"><input name="email" placeholder="Email"><input name="password" placeholder="Пароль" type="password"><button>Войти</button></form></div>');
}

function dashboard(): void { requireAuth();
    $stats = [
        'Боты'=>one('SELECT COUNT(*) c FROM bots')['c']??0,
        'Пользователи'=>one('SELECT COUNT(*) c FROM bot_users')['c']??0,
        'Ожидают оплату'=>one("SELECT COUNT(*) c FROM shop_orders WHERE status='waiting_pay'")['c']??0,
        'Оплачено'=>one("SELECT COUNT(*) c FROM shop_orders WHERE status='paid'")['c']??0,
    ];
    $html=notice().'<div class="grid">'; foreach($stats as $k=>$v)$html.='<div class="card"><div class="muted">'.e($k).'</div><div class="stat">'.e((string)$v).'</div></div>'; $html.='</div><div class="card"><b>BotShop v'.APP_VERSION.'</b><p class="muted">MVP мультибот-магазина без отправки чеков. Оплата подтверждается оператором в панели.</p></div>'; layout('Дашборд',$html);
}

function botsPage(): void { requireAuth();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        checkCsrf(); $token=trim($_POST['token']??''); $secret=bin2hex(random_bytes(16));
        $bot=['token'=>$token]; $me=tgRequest($bot,'getMe'); $username=$me['result']['username']??null;
        q('INSERT INTO bots (name,username,token,webhook_secret,status,welcome_text,pay_text,min_add,max_add,order_ttl_minutes) VALUES (?,?,?,?,?,?,?,?,?,?)', [$_POST['name'],$username,$token,$secret,$_POST['status']??'paused',$_POST['welcome_text']??null,$_POST['pay_text']??null,(int)$_POST['min_add'],(int)$_POST['max_add'],(int)$_POST['order_ttl_minutes']]);
        flash('Бот добавлен'); redirect('/bots');
    }
    if (isset($_GET['setwebhook'])) { $b=one('SELECT * FROM bots WHERE id=?',[(int)$_GET['setwebhook']]); if($b){$url=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].'/webhook/'.$b['webhook_secret']; $r=tgRequest($b,'setWebhook',['url'=>$url]); flash(($r['ok']??false)?'Webhook установлен: '.$url:'Ошибка webhook: '.($r['description']??'unknown'));} redirect('/bots'); }
    $rows=allRows('SELECT * FROM bots ORDER BY id DESC'); $html=notice().'<div class="card"><h2>Добавить бота</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><div class="grid"><div><input name="name" placeholder="Название" required></div><div><select name="status"><option value="active">active</option><option value="paused">paused</option></select></div></div><input name="token" placeholder="Telegram token" required><textarea name="welcome_text" placeholder="Приветственный текст">Выберите город:</textarea><div class="grid"><input name="min_add" type="number" value="1" placeholder="Мин. добавка"><input name="max_add" type="number" value="19" placeholder="Макс. добавка"><input name="order_ttl_minutes" type="number" value="30" placeholder="TTL заказа"></div><button>Добавить</button></form></div><div class="card"><h2>Боты</h2><table class="table"><tr><th>ID</th><th>Название</th><th>Username</th><th>Статус</th><th>Webhook</th></tr>'; foreach($rows as $r){$html.='<tr><td>'.$r['id'].'</td><td>'.e($r['name']).'</td><td>@'.e($r['username']).'</td><td><span class="pill">'.e($r['status']).'</span></td><td><a class="btn secondary" href="/bots?setwebhook='.$r['id'].'">Установить webhook</a></td></tr>'; } $html.='</table></div>'; layout('Боты',$html);
}

function listPage(string $title, string $table, array $fields, array $labels): void { requireAuth(); if($_SERVER['REQUEST_METHOD']==='POST') simpleCreate($table,$fields,path());
    $rows=allRows('SELECT * FROM '.$table.' ORDER BY id DESC LIMIT 200'); $html=notice().'<div class="card"><h2>Добавить</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'">';
    foreach($fields as $f){ if($f==='bot_id')$html.='<label>Бот</label><select name="bot_id">'.botOptions().'</select>'; elseif($f==='city_id'){$cities=allRows('SELECT id, CONCAT(name," #",id) name FROM shop_cities ORDER BY id DESC');$html.='<label>Город</label><select name="city_id">';foreach($cities as $c)$html.='<option value="'.$c['id'].'">'.e($c['name']).'</option>'; $html.='</select>';} elseif($f==='product_id'){$ps=allRows('SELECT id, CONCAT(name," #",id) name FROM shop_products ORDER BY id DESC');$html.='<label>Товар</label><select name="product_id">';foreach($ps as $p)$html.='<option value="'.$p['id'].'">'.e($p['name']).'</option>'; $html.='</select>';} elseif(str_contains($f,'is_'))$html.='<label><input style="width:auto" type="checkbox" name="'.$f.'" value="1" checked> '.e($labels[$f]??$f).'</label><br>'; elseif($f==='description')$html.='<textarea name="'.$f.'" placeholder="'.e($labels[$f]??$f).'"></textarea>'; elseif(in_array($f,['price','sort_order','stock','min_amount','max_amount','daily_limit'],true))$html.='<input type="number" name="'.$f.'" placeholder="'.e($labels[$f]??$f).'">'; else $html.='<input name="'.$f.'" placeholder="'.e($labels[$f]??$f).'">'; }
    $html.='<button>Сохранить</button></form></div><div class="card"><h2>'.e($title).'</h2><table class="table"><tr>'; foreach($labels as $l)$html.='<th>'.e($l).'</th>'; $html.='</tr>'; foreach($rows as $r){$html.='<tr>'; foreach(array_keys($labels) as $k)$html.='<td>'.e((string)($r[$k]??'')).'</td>'; $html.='</tr>'; } $html.='</table></div>'; layout($title,$html);
}

function ordersPage(): void { requireAuth();
    if(isset($_GET['paid'])||isset($_GET['reject'])){ $id=(int)($_GET['paid']??$_GET['reject']); $status=isset($_GET['paid'])?'paid':'rejected'; q('UPDATE shop_orders SET status=?, paid_at=IF(?="paid",NOW(),paid_at) WHERE id=?',[$status,$status,$id]); $o=one('SELECT * FROM shop_orders WHERE id=?',[$id]); if($o){$bot=one('SELECT * FROM bots WHERE id=?',[$o['bot_id']]); if($bot) sendMessage($bot,(int)$o['telegram_id'],$status==='paid'?'Оплата подтверждена. Заказ №'.$id.' принят.':'Заказ №'.$id.' отклонён.');} flash('Статус обновлён'); redirect('/orders'); }
    q("UPDATE shop_orders SET status='expired' WHERE status='waiting_pay' AND expires_at<NOW()");
    $rows=allRows('SELECT o.*, b.name bot_name FROM shop_orders o LEFT JOIN bots b ON b.id=o.bot_id ORDER BY o.id DESC LIMIT 300'); $html=notice().'<div class="card"><table class="table"><tr><th>ID</th><th>Бот</th><th>TG</th><th>Сумма</th><th>Статус</th><th>Создан</th><th>Действия</th></tr>'; foreach($rows as $r){$html.='<tr><td>'.$r['id'].'</td><td>'.e($r['bot_name']).'</td><td>'.$r['telegram_id'].'</td><td><b>'.$r['final_amount'].' ₽</b><br><span class="muted">'.$r['base_amount'].' + '.$r['random_increment'].'</span></td><td><span class="pill">'.e($r['status']).'</span></td><td>'.$r['created_at'].'</td><td class="row-actions">'; if($r['status']==='waiting_pay'){$html.='<a class="btn ok" href="/orders?paid='.$r['id'].'">Оплачено</a><a class="btn danger" href="/orders?reject='.$r['id'].'">Отклонить</a>';} $html.='</td></tr>'; } $html.='</table></div>'; layout('Заказы',$html);
}

function logsPage(): void { requireAuth(); $rows=allRows('SELECT * FROM telegram_updates ORDER BY id DESC LIMIT 100'); $html='<div class="card"><table class="table"><tr><th>ID</th><th>Бот</th><th>Update</th><th>Дата</th></tr>'; foreach($rows as $r)$html.='<tr><td>'.$r['id'].'</td><td>'.$r['bot_id'].'</td><td>'.$r['update_id'].'</td><td>'.$r['created_at'].'</td></tr>'; $html.='</table></div>'; layout('Логи',$html); }

if (!installed() && path() !== '/install') redirect('/install');
$p = path();
if ($p === '/install') installPage();
elseif (preg_match('#^/webhook/([a-f0-9]+)$#', $p, $m)) handleWebhook($m[1]);
elseif ($p === '/login') loginPage();
elseif ($p === '/logout') { session_destroy(); redirect('/login'); }
elseif ($p === '/') dashboard();
elseif ($p === '/bots') botsPage();
elseif ($p === '/cities') listPage('Города','shop_cities',['bot_id','name','sort_order','is_active'],['id'=>'ID','bot_id'=>'Бот','name'=>'Название','sort_order'=>'Сортировка','is_active'=>'Активен']);
elseif ($p === '/districts') listPage('Районы','shop_districts',['bot_id','city_id','name','sort_order','is_active'],['id'=>'ID','bot_id'=>'Бот','city_id'=>'Город','name'=>'Название','sort_order'=>'Сортировка','is_active'=>'Активен']);
elseif ($p === '/products') listPage('Товары','shop_products',['bot_id','name','description','sort_order','is_active'],['id'=>'ID','bot_id'=>'Бот','name'=>'Название','description'=>'Описание','sort_order'=>'Сортировка','is_active'=>'Активен']);
elseif ($p === '/variants') listPage('Варианты','shop_product_variants',['bot_id','product_id','name','price','stock','sort_order','is_active'],['id'=>'ID','bot_id'=>'Бот','product_id'=>'Товар','name'=>'Название','price'=>'Цена','stock'=>'Остаток','sort_order'=>'Сортировка','is_active'=>'Активен']);
elseif ($p === '/payments') listPage('Реквизиты','shop_payment_methods',['bot_id','title','type','bank_name','card_number','recipient_name','sbp_phone','min_amount','max_amount','daily_limit','is_online','is_active'],['id'=>'ID','bot_id'=>'Бот','title'=>'Название','type'=>'Тип','bank_name'=>'Банк','card_number'=>'Карта','recipient_name'=>'Получатель','sbp_phone'=>'СБП','min_amount'=>'Мин','max_amount'=>'Макс','daily_limit'=>'Лимит','is_online'=>'Онлайн','is_active'=>'Активен']);
elseif ($p === '/orders') ordersPage();
elseif ($p === '/logs') logsPage();
else { http_response_code(404); echo '404'; }
