<?php
// === НАСТРОЙКИ ===
$botToken = '8113379517:AAH3gvG94O5Efg5yR5B10BwuvKB7WeXG9nc'; // Замените на токен bot.php
$allowedGroupChats = ['-4966154005', '-4886687950']; // Разрешённые группы
$adminUsers = ['mol_25411', 'RUeSimSupport', 'simon_bred']; // Логины админов

$qrFile = __DIR__ . '/stored_qr.json';
$logFile = __DIR__ . '/bot_log.txt';
$statusFile = __DIR__ . '/bot_status.json';
$queueFile = __DIR__ . '/qr_queue.json';
$adminsFile = __DIR__ . '/admin_chats.json';

$maxQrPerRequest = 10;

define('STAT_GROUP_ID', '-4866505294');
define('FAIL_GROUP_ID', '-4910760629');
define('BOT2_API', 'https://aezakmi.pro/mtstest/bot2.php');

// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===
function makeRequest($method, $params = []) {
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/$method";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    if (isset($params['photo']) && $params['photo'] instanceof CURLFile) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}
function isAdmin($username) {
    global $adminUsers;
    return in_array($username, $adminUsers);
}
function saveAdminChatId($username, $chatId) {
    global $adminsFile;
    $adminChats = [];
    if (file_exists($adminsFile)) {
        $adminChats = json_decode(file_get_contents($adminsFile), true);
    }
    $adminChats[$username] = $chatId;
    file_put_contents($adminsFile, json_encode($adminChats, JSON_PRETTY_PRINT));
}
function getAdminChatIds() {
    global $adminsFile;
    if (!file_exists($adminsFile)) {
        return [];
    }
    return json_decode(file_get_contents($adminsFile), true);
}
function getBotStatus($chatId) {
    global $statusFile, $allowedGroupChats;
    if (!file_exists($statusFile)) {
        $initial_status = [];
        foreach ($allowedGroupChats as $groupId) {
            $initial_status[$groupId] = true;
        }
        file_put_contents($statusFile, json_encode($initial_status, JSON_PRETTY_PRINT));
        return true;
    }
    $status = json_decode(file_get_contents($statusFile), true);
    return isset($status[$chatId]) ? $status[$chatId] : true;
}
function setBotStatus($chatId, $enabled) {
    global $statusFile;
    $status = [];
    if (file_exists($statusFile)) {
        $status = json_decode(file_get_contents($statusFile), true);
    }
    $status[$chatId] = $enabled;
    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
    $message = $enabled 
        ? "🟢 Бот включен администратором.\nТеперь можно использовать команду МТС."
        : "🔴 Бот выключен администратором.\nКоманда МТС временно недоступна.";
    makeRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $message
    ]);
}
function getStoredQR() {
    global $qrFile;
    if (!file_exists($qrFile)) {
        $initial_data = [
            'qr_list' => [],
            'last_update' => date('Y-m-d H:i:s')
        ];
        file_put_contents($qrFile, json_encode($initial_data, JSON_PRETTY_PRINT));
        return $initial_data;
    }
    return json_decode(file_get_contents($qrFile), true);
}
function getQueueInfo() {
    global $queueFile;
    if (!file_exists($queueFile)) {
        $initial_queue = [
            'pending_count' => 0,
            'last_notified' => 0,
            'last_update' => date('Y-m-d H:i:s')
        ];
        file_put_contents($queueFile, json_encode($initial_queue, JSON_PRETTY_PRINT));
        return $initial_queue;
    }
    return json_decode(file_get_contents($queueFile), true);
}
function updateQueueInfo($newCount) {
    global $queueFile;
    $queue = getQueueInfo();
    $queue['pending_count'] = $newCount;
    $queue['last_update'] = date('Y-m-d H:i:s');
    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
}
function isValidQR($qr) {
    return (isset($qr['photo_path']) && file_exists($qr['photo_path'])) ||
           (isset($qr['photo']) && !empty($qr['photo'])) ||
           (isset($qr['text']) && !empty(trim($qr['text'])));
}
function isAllowedChat($chatId, $chatType) {
    global $allowedGroupChats;
    return ($chatType === 'group' || $chatType === 'supergroup') && in_array($chatId, $allowedGroupChats);
}
function storeQR($message) {
    global $qrFile, $adminUsers;
    $qr = [];
    // Сохраняем фото, если есть
    if (isset($message['photo'])) {
        $photoObj = end($message['photo']);
        $file_id = $photoObj['file_id'];
        // Сохраняем только file_id, а не скачиваем файл (можно доработать)
        $qr['photo'] = $message['photo'];
        // caption как текст
        if (isset($message['caption'])) {
            $qr['text'] = $message['caption'];
        }
    }
    // Если просто текст без фото
    if (isset($message['text']) && empty($message['photo'])) {
        $qr['text'] = $message['text'];
    }
    // Остальные поля
    $qr['from'] = $message['from'];
    $qr['created_at'] = date('Y-m-d H:i:s');
    $qr['message_id'] = $message['message_id'];
    // Проверка на валидность
    if (!isValidQR($qr)) return false;
    $data = getStoredQR();
    $qr['stored_at'] = date('Y-m-d H:i:s');
    $qr['is_forwarded'] = isset($message['forward_date']);
    $qr['stored_by'] = $message['from']['username'] ?? 'unknown';
    $data['qr_list'][] = $qr;
    $data['last_update'] = date('Y-m-d H:i:s');
    file_put_contents($qrFile, json_encode($data, JSON_PRETTY_PRINT));
    updateQueueInfo(count($data['qr_list']));
    return true;
}
function notifyAllGroups($count) {
    global $allowedGroupChats;
    $message = "📢 *Список QR пополнен!*\n".
              "📥 Добавлено новых QR: *{$count}*\n\n".
              "Используйте команду `мтс` чтобы получить QR.";
    foreach ($allowedGroupChats as $chatId) {
        if (getBotStatus($chatId)) {
            makeRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);
            usleep(500000);
        }
    }
    $queue = getQueueInfo();
    $queue['last_notified'] = $queue['pending_count'];
    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
}
function notifyAdmins($requestChatId) {
    $chatInfo = makeRequest('getChat', ['chat_id' => $requestChatId]);
    $chatTitle = $chatInfo['result']['title'] ?? 'Неизвестная группа';
    $message = "📨 *Запрос новых QR!*\n".
               "📱 Группа: *{$chatTitle}*\n".
               "🕒 Время запроса: " . date('Y-m-d H:i:s') . " UTC\n\n".
               "Клиенты ожидают новые QR!";
    $adminChats = getAdminChatIds();
    if (empty($adminChats)) return false;
    foreach ($adminChats as $chatId) {
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ]);
        usleep(500000);
    }
    return true;
}
function markQRAsSent($messageId) {
    global $qrFile;
    $data = getStoredQR();
    foreach ($data['qr_list'] as $key => $qr) {
        if ($qr['message_id'] == $messageId) {
            unset($data['qr_list'][$key]);
            break;
        }
    }
    $data['qr_list'] = array_values($data['qr_list']);
    $data['last_update'] = date('Y-m-d H:i:s');
    file_put_contents($qrFile, json_encode($data, JSON_PRETTY_PRINT));
    updateQueueInfo(count($data['qr_list']));
}
function isQRSent($messageId) {
    $data = getStoredQR();
    foreach ($data['qr_list'] as $qr) {
        if ($qr['message_id'] == $messageId) {
            return false;
        }
    }
    return true;
}
function logQrMessage($messageId, $qr, $groupId) {
    $logFile = __DIR__ . '/qr_status_log.json';
    $logArr = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
    $qr['group_msg_id'] = $messageId;
    $qr['group_id'] = $groupId;
    $logArr[$messageId] = $qr;
    file_put_contents($logFile, json_encode($logArr, JSON_PRETTY_PRINT));
}
function sendQrWithButtons($chatId, $qr) {
    $kb = [
        [
            ['text' => '✅ Встал', 'callback_data' => 'qr_ok_'.$qr['message_id']],
            ['text' => '❌ Сбой', 'callback_data' => 'qr_fail_'.$qr['message_id']]
        ]
    ];
    if (isset($qr['photo_path']) && file_exists($qr['photo_path'])) {
        $captionText = isset($qr['text']) ? $qr['text'] : '';
        $msg = makeRequest('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => new CURLFile($qr['photo_path']),
            'caption' => $captionText,
            'reply_markup' => json_encode(['inline_keyboard'=>$kb])
        ]);
    } elseif (isset($qr['photo']) && is_array($qr['photo'])) {
        $photoId = end($qr['photo'])['file_id'];
        $captionText = isset($qr['text']) ? $qr['text'] : '';
        $msg = makeRequest('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photoId,
            'caption' => $captionText,
            'reply_markup' => json_encode(['inline_keyboard'=>$kb])
        ]);
    } elseif (isset($qr['text'])) {
        $msg = makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $qr['text'],
            'reply_markup' => json_encode(['inline_keyboard'=>$kb])
        ]);
    }
    if (isset($msg['result']['message_id'])) {
        logQrMessage($msg['result']['message_id'], $qr, $chatId);
    }
}
function forwardStoredQR($chatId, $count) {
    if (!getBotStatus($chatId)) {
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "⚠️ Бот в данный момент отключен администратором."
        ]);
        return;
    }
    global $maxQrPerRequest;
    $count = min($count, $maxQrPerRequest);
    $data = getStoredQR();
    $forwardedCount = 0;
    $qrList = array_reverse($data['qr_list']);
    foreach ($qrList as $qr) {
        if (!isQRSent($qr['message_id'])) {
            sendQrWithButtons($chatId, $qr);
            markQRAsSent($qr['message_id']);
            $forwardedCount++;
            usleep(500000);
            if ($forwardedCount >= $count) break;
        }
    }
    if ($forwardedCount > 0) {
        $messageText = "✅ Переслано QR: $forwardedCount" . 
                      ($forwardedCount < $count ? "\n📝 Больше новых QR нет." : "");
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $messageText
        ]);
    } else {
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔔 Запросить новые QR',
                    'callback_data' => 'request_new_qr'
                ]
            ]]
        ];
        makeRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "ℹ️ Новых QR пока нет, но мы уже готовим их\n\nНажмите кнопку ниже, чтобы уведомить администраторов о запросе.",
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    updateQueueInfo(count($data['qr_list']));
}

// =============== ОБРАБОТКА КНОПОК QR ===============
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data_cb = $cb['data'];
    $cb_chat_id = $cb['message']['chat']['id'];
    $cb_msg_id = $cb['message']['message_id'];
    $logFile = __DIR__ . '/qr_status_log.json';
    $logArr = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];

    if (strpos($data_cb, 'qr_ok_') === 0 || strpos($data_cb, 'qr_fail_') === 0 || strpos($data_cb, 'qr_flew_') === 0) {
        if (strpos($data_cb, 'qr_ok_') === 0 || strpos($data_cb, 'qr_fail_') === 0) {
            if (isset($logArr[$cb_msg_id]['status'])) {
                makeRequest('answerCallbackQuery', [
                    'callback_query_id' => $cb['id'],
                    'text' => "Кнопка уже использована",
                    'show_alert' => true
                ]);
                exit;
            }
        }
        if (strpos($data_cb, 'qr_flew_') === 0) {
            if (!isset($logArr[$cb_msg_id]['status']) || $logArr[$cb_msg_id]['status'] !== 'ok') {
                makeRequest('answerCallbackQuery', [
                    'callback_query_id' => $cb['id'],
                    'text' => "Кнопка доступна только после 'Встал'.",
                    'show_alert' => true
                ]);
                exit;
            }
        }

        $qr = $logArr[$cb_msg_id];
        $userId = $qr['from']['id'] ?? null;
        $userName = $qr['from']['username'] ?? $userId;
        $userChatId = $qr['from']['chat_id'] ?? null;

        // === ВСТАЛ ===
        if (strpos($data_cb, 'qr_ok_') === 0) {
            if ($userId) {
                @file_get_contents(BOT2_API . "?add_balance=1&user_id=$userId&amount=13");
                // Уведомить автора QR
                if ($userChatId) {
                    makeRequest('sendMessage', [
                        'chat_id' => $userChatId,
                        'text' => "🎉 Ваш QR был успешно использован, баланс пополнен на 13 USDT!"
                    ]);
                }
            }
            $newKb = [ [ ['text' => '💥 Слетел', 'callback_data' => 'qr_flew_'.$cb_msg_id] ] ];
            makeRequest('editMessageReplyMarkup', [
                'chat_id' => $cb_chat_id,
                'message_id' => $cb_msg_id,
                'reply_markup' => json_encode(['inline_keyboard'=>$newKb])
            ]);
            $logArr[$cb_msg_id]['status'] = 'ok';
            $logArr[$cb_msg_id]['ts_ok'] = time();
            file_put_contents($logFile, json_encode($logArr, JSON_PRETTY_PRINT));
            $chatInfo = makeRequest('getChat', ['chat_id' => $cb_chat_id]);
            $groupTitle = $chatInfo['result']['title'] ?? $cb_chat_id;
            $info = "QR ВСТАЛ!\n"
                  . "Группа: $groupTitle ($cb_chat_id)\n"
                  . "Время: ".date('Y-m-d H:i:s');
            makeRequest('sendMessage', [
                'chat_id' => STAT_GROUP_ID,
                'text' => $info
            ]);
            exit;
        }
        // === СБОЙ ===
        if (strpos($data_cb, 'qr_fail_') === 0) {
            $userInfoRaw = @file_get_contents(BOT2_API . "?get_user=1&user_id=$userId");
            $userInfo = $userInfoRaw ? json_decode($userInfoRaw, true) : [];
            $chatInfo = makeRequest('getChat', ['chat_id' => $cb_chat_id]);
            $groupTitle = $chatInfo['result']['title'] ?? $cb_chat_id;
            $caption = "Сбой QR!\nГруппа: $groupTitle ($cb_chat_id)\nВремя: ".date('Y-m-d H:i:s')."\nПользователь: ".($userInfo['username'] ?? $userName);
            $qr_text = isset($qr['text']) ? "\n\n" . $qr['text'] : "";
            // В группу сбоев сам QR
            if (isset($qr['photo_path']) && file_exists($qr['photo_path'])) {
                makeRequest('sendPhoto', [
                    'chat_id' => FAIL_GROUP_ID,
                    'photo' => new CURLFile($qr['photo_path']),
                    'caption' => $caption . $qr_text
                ]);
            } elseif (isset($qr['photo']) && is_array($qr['photo'])) {
                $photoId = end($qr['photo'])['file_id'];
                makeRequest('sendPhoto', [
                    'chat_id' => FAIL_GROUP_ID,
                    'photo' => $photoId,
                    'caption' => $caption . $qr_text
                ]);
            } else {
                makeRequest('sendMessage', [
                    'chat_id' => FAIL_GROUP_ID,
                    'text' => $caption . $qr_text
                ]);
            }
            // Уведомить автора QR о неудаче
            if ($userChatId) {
                makeRequest('sendMessage', [
                    'chat_id' => $userChatId,
                    'text' => "❌ К сожалению, ваш QR не сработал (сбой). Баланс не зачислен."
                ]);
            }
            // Редактировать сообщение с QR в группе
            $originalText = $cb['message']['caption'] ?? $cb['message']['text'] ?? '';
            $editMethod = isset($cb['message']['caption']) ? 'editMessageCaption' : 'editMessageText';
            $editField = isset($cb['message']['caption']) ? 'caption' : 'text';
            $newText = $originalText . "\n\nСБОЙ. Требует внимания!";
            makeRequest($editMethod, [
                'chat_id' => $cb_chat_id,
                'message_id' => $cb_msg_id,
                $editField => $newText,
                'reply_markup' => json_encode(['inline_keyboard'=>[]])
            ]);
            $logArr[$cb_msg_id]['status'] = 'fail';
            $logArr[$cb_msg_id]['ts_fail'] = time();
            file_put_contents($logFile, json_encode($logArr, JSON_PRETTY_PRINT));
            exit;
        }
        // === СЛЕТЕЛ ===
        if (strpos($data_cb, 'qr_flew_') === 0) {
            $now = time();
            $ts_ok = isset($logArr[$cb_msg_id]['ts_ok']) ? $logArr[$cb_msg_id]['ts_ok'] : null;
            $flew_text = "\n\n";
            if ($ts_ok) {
                $flew_text .= "🟢 Встал: " . date("Y-m-d H:i:s", $ts_ok) . "\n";
                $flew_text .= "🔴 Слетел: " . date("Y-m-d H:i:s", $now) . "\n";
                $delta = $now - $ts_ok;
                if ($delta > 300) { // 5 минут
                    $flew_text .= "💸 QR требует оплаты\n";
                }
            } else {
                $flew_text .= "🔴 Слетел: " . date("Y-m-d H:i:s", $now) . "\n";
            }
            $originalText = "";
            if (isset($cb['message']['caption'])) {
                $originalText = $cb['message']['caption'];
                $editMethod = 'editMessageCaption';
                $editField = 'caption';
            } elseif (isset($cb['message']['text'])) {
                $originalText = $cb['message']['text'];
                $editMethod = 'editMessageText';
                $editField = 'text';
            } else {
                $editMethod = 'editMessageText';
                $editField = 'text';
            }
            $newText = $originalText . $flew_text;
            makeRequest($editMethod, [
                'chat_id' => $cb_chat_id,
                'message_id' => $cb_msg_id,
                $editField => $newText,
                'reply_markup' => json_encode(['inline_keyboard'=>[]])
            ]);
            $logArr[$cb_msg_id]['status'] = 'flew';
            $logArr[$cb_msg_id]['ts_flew'] = $now;
            file_put_contents($logFile, json_encode($logArr, JSON_PRETTY_PRINT));
            exit;
        }
    }
}

// =============== ОСТАЛЬНАЯ ЛОГИКА: команды, очереди, админские функции ===============
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $username = $callback['from']['username'] ?? '';
    $chatId = $callback['message']['chat']['id'];
    if ($callback['data'] === 'notify_new_qr' && isAdmin($username)) {
        $queue = getQueueInfo();
        $pendingCount = $queue['pending_count'] - $queue['last_notified'];
        if ($pendingCount > 0) {
            notifyAllGroups($pendingCount);
            makeRequest('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => "✅ Уведомления отправлены во все активные группы"
            ]);
        } else {
            makeRequest('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => "❌ Нет новых QR для уведомления"
            ]);
        }
    }
    elseif ($callback['data'] === 'request_new_qr') {
        if (notifyAdmins($chatId)) {
            makeRequest('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => "✅ Администраторы уведомлены о вашем запросе"
            ]);
            makeRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $callback['message']['message_i