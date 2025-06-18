<?php
// Настройки
$botToken = '7621469402:AAEmbuAJF1acw25qzJWpr4sSFI3ANRImLK0';
$admins = [7738271933];
$balanceFile = __DIR__ . '/balances.json';
$userFile    = __DIR__ . '/users.json';
$pendingFile = __DIR__ . '/pending_qr.json';
$approvedFile = __DIR__ . '/stored_qr.json';
$qrImageDir = __DIR__ . '/qr_images';
if (!is_dir($qrImageDir)) mkdir($qrImageDir, 0777, true);

// Главное меню (нижняя reply-кнопка)
function mainMenuKeyboard() {
    return [
        'keyboard' => [[['text' => '🏠 Главное меню']]],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];
}
function makeRequest($method, $params = []) {
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/$method";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    if (isset($params['photo']) && $params['photo'] instanceof CURLFile) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}
function getTelegramFile($file_id) {
    global $botToken;
    $fileInfo = file_get_contents("https://api.telegram.org/bot{$botToken}/getFile?file_id={$file_id}");
    $fileInfo = json_decode($fileInfo, true);
    if (!$fileInfo['ok']) return false;
    $file_path = $fileInfo['result']['file_path'];
    $url = "https://api.telegram.org/file/bot{$botToken}/{$file_path}";
    return file_get_contents($url);
}
function getUserBalance($userId) {
    global $balanceFile;
    if (!file_exists($balanceFile)) return 0;
    $data = json_decode(file_get_contents($balanceFile), true);
    return isset($data[$userId]) ? $data[$userId] : 0;
}
function addUserBalance($userId, $amount) {
    global $balanceFile;
    $data = file_exists($balanceFile) ? json_decode(file_get_contents($balanceFile), true) : [];
    $data[$userId] = (isset($data[$userId]) ? $data[$userId] : 0) + $amount;
    file_put_contents($balanceFile, json_encode($data, JSON_PRETTY_PRINT));
}
function setUserBalance($userId, $amount) {
    global $balanceFile;
    $data = file_exists($balanceFile) ? json_decode(file_get_contents($balanceFile), true) : [];
    $data[$userId] = $amount;
    file_put_contents($balanceFile, json_encode($data, JSON_PRETTY_PRINT));
}
function getUserInfo($userId) {
    global $userFile;
    if (!file_exists($userFile)) return [];
    $data = json_decode(file_get_contents($userFile), true);
    return isset($data[$userId]) ? $data[$userId] : [];
}
function saveUserInfo($userId, $info) {
    global $userFile;
    $data = file_exists($userFile) ? json_decode(file_get_contents($userFile), true) : [];
    $data[$userId] = $info;
    file_put_contents($userFile, json_encode($data, JSON_PRETTY_PRINT));
}

// --- API для bot.php
if (isset($_GET['add_balance']) && $_GET['add_balance'] == 1 && isset($_GET['user_id']) && isset($_GET['amount'])) {
    $userId = $_GET['user_id'];
    $amount = floatval($_GET['amount']);
    addUserBalance($userId, $amount);
    // Уведомление о пополнении баланса
    $userInfo = getUserInfo($userId);
    $chatId = $userInfo['chat_id'] ?? $userId;
    makeRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "💸 Ваш баланс пополнен на {$amount} USDT!",
        'reply_markup' => json_encode(mainMenuKeyboard())
    ]);
    exit('ok');
}
if (isset($_GET['get_user']) && $_GET['get_user'] == 1 && isset($_GET['user_id'])) {
    $userId = $_GET['user_id'];
    $info = getUserInfo($userId);
    exit(json_encode($info));
}

// Главное меню
function showMainMenu($chatId, $userId) {
    $balance = getUserBalance($userId);
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '➕ Отправить QR', 'callback_data' => 'start_send_qr']],
            [['text' => '💸 Запросить вывод средств', 'callback_data' => 'withdraw_request']]
        ]
    ];
    makeRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Ваш баланс: <b>$balance USDT</b>\n\nДля отправки QR-кода или вывода средств используйте меню ниже.",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard)
    ]);
}

// --- Обработка апдейта
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $first_name = $message['from']['first_name'] ?? '';
    $text = $message['text'] ?? '';
    $photo = $message['photo'] ?? null;

    // Главное меню по кнопке или /start
    if ($text === '/start' || trim($text) === '🏠 Главное меню') {
        saveUserInfo($userId, [
            'id' => $userId,
            'username' => $username,
            'first_name' => $first_name,
            'chat_id' => $chatId
        ]);
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Главное меню",
            'reply_markup' => json_encode(mainMenuKeyboard())
        ]);
        showMainMenu($chatId, $userId);
        exit;
    }

    $awaitFile = __DIR__ . "/await_qr_{$chatId}.txt";
    if (file_exists($awaitFile)) {
        unlink($awaitFile);
        saveUserInfo($userId, [
            'id' => $userId,
            'username' => $username,
            'first_name' => $first_name,
            'chat_id' => $chatId
        ]);
        $pending = file_exists($pendingFile) ? json_decode(file_get_contents($pendingFile), true) : [];
        $qr = [
            'from' => [
                'id' => $userId,
                'username' => $username,
                'first_name' => $first_name,
                'chat_id' => $chatId
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'message_id' => $message['message_id']
        ];
        if (!empty($photo)) {
            $photoObj = end($photo);
            $file_id = $photoObj['file_id'];
            $imgContent = getTelegramFile($file_id);
            $imgName = 'qr_' . time() . '_' . rand(10000,99999) . '.jpg';
            $imgPath = $qrImageDir . '/' . $imgName;
            file_put_contents($imgPath, $imgContent);
            $qr['photo_path'] = $imgPath;
        }
        if (!empty($message['caption'])) $qr['text'] = $message['caption'];
        if (!empty($text) && empty($photo)) $qr['text'] = $text;
        $pending[] = $qr;
        file_put_contents($pendingFile, json_encode($pending, JSON_PRETTY_PRINT));
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ Ваша заявка отправлена на модерацию. После проверки QR появится в целевых группах.",
            'reply_markup' => json_encode(mainMenuKeyboard())
        ]);
        // уведомление админам
        $msg = "Новая заявка на модерацию QR\nОт: @$username\n";
        $msg .= (!empty($message['caption']) ? "Текст: {$message['caption']}\n" : ($text && empty($photo) ? "Текст: $text\n" : ""));
        $msg .= ($photo ? "Фото QR" : "");
        $buttons = [
            'inline_keyboard' => [[
                ['text' => '✅ Принять', 'callback_data' => 'approve_' . $message['message_id']],
                ['text' => '❌ Отклонить', 'callback_data' => 'reject_' . $message['message_id']]
            ]]
        ];
        foreach ($admins as $adminId) {
            if (!empty($qr['photo_path']) && file_exists($qr['photo_path'])) {
                makeRequest('sendPhoto', [
                    'chat_id' => $adminId,
                    'photo' => new CURLFile($qr['photo_path']),
                    'caption' => $msg,
                    'reply_markup' => json_encode($buttons)
                ]);
            } else {
                makeRequest('sendMessage', [
                    'chat_id' => $adminId,
                    'text' => $msg,
                    'reply_markup' => json_encode($buttons)
                ]);
            }
        }
        exit;
    } else {
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❗️Чтобы отправить QR-код, используйте меню: /start",
            'reply_markup' => json_encode(mainMenuKeyboard())
        ]);
        exit;
    }
}

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'];
    $chatId = $cb['message']['chat']['id'];
    $userId = $cb['from']['id'];
    $username = $cb['from']['username'] ?? '';
    if ($data === 'start_send_qr') {
        file_put_contents(__DIR__ . "/await_qr_{$chatId}.txt", "1");
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Пожалуйста, отправьте ваш QR-код (фото или текст, либо фото с подписью) одним сообщением.",
            'reply_markup' => json_encode(mainMenuKeyboard())
        ]);
        exit;
    }
    if ($data === 'withdraw_request') {
        $balance = getUserBalance($userId);
        foreach ($admins as $admin) {
            makeRequest('sendMessage', [
                'chat_id' => $admin,
                'text' => "Пользователь @$username (ID $userId) запросил вывод: <b>$balance USDT</b>.",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '✅ Выплатил', 'callback_data' => 'admin_payout_'.$userId]]
                    ]
                ])
            ]);
        }
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "⏳ Запрос на вывод отправлен администратору.",
            'reply_markup' => json_encode(mainMenuKeyboard())
        ]);
        showMainMenu($chatId, $userId);
        exit;
    }
    if (strpos($data, 'admin_payout_') === 0) {
        $targetUserId = str_replace('admin_payout_', '', $data);
        setUserBalance($targetUserId, 0);
        $userInfo = getUserInfo($targetUserId);
        $userChatId = $userInfo['chat_id'] ?? $targetUserId;
        makeRequest('sendMessage', [
            'chat_id' => $userChatId,
            'text' => "✅ Ваш баланс был выплачен и обнулён администратором.",
            'reply_markup' => json_encode(mainMenuKeyboard())
        ]);
        makeRequest('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => "Баланс обнулён."
        ]);
        exit;
    }
    if (strpos($data, 'approve_') === 0 || strpos($data, 'reject_') === 0) {
        $pending = file_exists($pendingFile) ? json_decode(file_get_contents($pendingFile), true) : [];
        $id = intval(str_replace(['approve_', 'reject_'], '', $data));
        foreach ($pending as $k => $qr) {
            if ($qr['message_id'] == $id) {
                $userChatId = $qr['from']['chat_id'];
                if (strpos($data, 'approve_') === 0) {
                    $approved = file_exists($approvedFile) ? json_decode(file_get_contents($approvedFile), true) : ['qr_list'=>[], 'last_update'=>date('Y-m-d H:i:s')];
                    $qr['approved_by'] = $cb['from']['username'] ?? 'admin';
                    $approved['qr_list'][] = $qr;
                    $approved['last_update'] = date('Y-m-d H:i:s');
                    file_put_contents($approvedFile, json_encode($approved, JSON_PRETTY_PRINT));
                    makeRequest('sendMessage', [
                        'chat_id' => $userChatId,
                        'text' => "Ваш QR-код принят модератором и поставлен в очередь. Спасибо!",
                        'reply_markup' => json_encode(mainMenuKeyboard())
                    ]);
                    $text = "✅ QR поставлен в очередь!";
                } else {
                    makeRequest('sendMessage', [
                        'chat_id' => $userChatId,
                        'text' => "К сожалению, ваш QR-код не прошёл модерацию и был отклонён.",
                        'reply_markup' => json_encode(mainMenuKeyboard())
                    ]);
                    $text = "❌ QR отклонён.";
                }
                unset($pending[$k]);
                file_put_contents($pendingFile, json_encode(array_values($pending), JSON_PRETTY_PRINT));
                makeRequest('editMessageReplyMarkup', [
                    'chat_id' => $cb['message']['chat']['id'],
                    'message_id' => $cb['message']['message_id'],
                    'reply_markup' => json_encode(['inline_keyboard' => []])
                ]);
                makeRequest('answerCallbackQuery', [
                    'callback_query_id' => $cb['id'],
                    'text' => $text,
                    'show_alert' => true
                ]);
                exit;
            }
        }
        makeRequest('answerCallbackQuery', [
            'callback_query_id' => $cb['id'],
            'text' => "QR не найден или уже обработан.",
            'show_alert' => true
        ]);
        exit;
    }
}
?>