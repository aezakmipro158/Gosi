<?php
// Конфигурация бота по состоянию на 2025-05-19 18:03:45 UTC
$botToken = '7828567561:AAEc2dNQcaw2MNtsnGAGdf3XofiJXTMyoh4';

// ID разрешенных целевых групп
$allowedGroupChats = [
    '-4966154005',  // Первая целевая группа
    '-4774197633',   // Замените на ID второй группы
    '-4960955475',   // Замените на ID второй группы   
    '-1002639922721',   // Замените на ID второй группы
    '-4889030704',   // Замените на ID второй группы
    '-4657876510',   // Замените на ID второй группы
    '-4940650117',   // Замените на ID второй группы
    '-4831517390',   // Замените на ID второй группы
    '-4642408856'    // Замените на ID третьей группы
];

// Администраторы бота (username без @)
$adminUsers = [
    'mol_25411',    // Основной администратор
    'RUeSimSupport'  // Второй администратор
];

// Системные файлы
$qrFile = 'stored_qr.json';
$logFile = 'bot_log.txt';
$statusFile = 'bot_status.json';
$queueFile = 'qr_queue.json';
$adminsFile = 'admin_chats.json';

// Максимальное количество QR за один запрос
$maxQrPerRequest = 10;

// Функция для отправки запросов к API Telegram
function makeRequest($method, $params = []) {
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

// Функция проверки администратора
function isAdmin($username) {
    global $adminUsers;
    return in_array($username, $adminUsers);
}

// Функция сохранения chat_id администратора
function saveAdminChatId($username, $chatId) {
    global $adminsFile;
    
    $adminChats = [];
    if (file_exists($adminsFile)) {
        $adminChats = json_decode(file_get_contents($adminsFile), true);
    }
    
    $adminChats[$username] = $chatId;
    file_put_contents($adminsFile, json_encode($adminChats, JSON_PRETTY_PRINT));
}

// Функция получения chat_id администраторов
function getAdminChatIds() {
    global $adminsFile;
    
    if (!file_exists($adminsFile)) {
        return [];
    }
    
    return json_decode(file_get_contents($adminsFile), true);
}

// Функция получения статуса бота для конкретной группы
function getBotStatus($chatId) {
    global $statusFile;
    
    if (!file_exists($statusFile)) {
        $initial_status = [];
        foreach ($GLOBALS['allowedGroupChats'] as $groupId) {
            $initial_status[$groupId] = true;
        }
        file_put_contents($statusFile, json_encode($initial_status, JSON_PRETTY_PRINT));
        return true;
    }
    
    $status = json_decode(file_get_contents($statusFile), true);
    return isset($status[$chatId]) ? $status[$chatId] : true;
}

// Функция установки статуса бота для конкретной группы
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

// Функция получения сохраненных QR
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

// Функция получения информации об очереди
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

// Функция обновления информации об очереди
function updateQueueInfo($newCount) {
    global $queueFile;
    
    $queue = getQueueInfo();
    $queue['pending_count'] = $newCount;
    $queue['last_update'] = date('Y-m-d H:i:s');
    
    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
}

// Функция проверки допустимости QR
function isValidQR($qr) {
    return (isset($qr['photo']) && !empty($qr['photo'])) || 
           (isset($qr['text']) && !empty(trim($qr['text'])));
}

// Функция проверки, разрешен ли чат
function isAllowedChat($chatId, $chatType) {
    global $allowedGroupChats;
    return ($chatType === 'group' || $chatType === 'supergroup') && in_array($chatId, $allowedGroupChats);
}

// Функция сохранения нового QR
function storeQR($qr) {
    global $qrFile, $adminUsers;
    
    if (!isValidQR($qr)) {
        return false;
    }
    
    $data = getStoredQR();
    
    // Обрабатываем фото, загруженное через веб-интерфейс
    if (isset($qr['photo']) && isset($qr['photo'][0]['file_id']) && strpos($qr['photo'][0]['file_id'], 'uploads/') === 0) {
        $filePath = $qr['photo'][0]['file_id'];
        
        // Загружаем фото в Telegram
        $photo = new CURLFile($filePath);
        $response = makeRequest('sendPhoto', [
            'chat_id' => $adminUsers[0], // Отправляем первому админу
            'photo' => $photo
        ]);
        
        if ($response['ok']) {
            // Получаем file_id от Telegram
            $qr['photo'] = $response['result']['photo'];
            // Удаляем локальный файл
            unlink($filePath);
        } else {
            return false;
        }
    }
    
    $qr['stored_at'] = date('Y-m-d H:i:s');
    $qr['is_forwarded'] = isset($qr['forward_date']);
    $qr['stored_by'] = $qr['from']['username'] ?? 'unknown';
    
    $data['qr_list'][] = $qr;
    $data['last_update'] = date('Y-m-d H:i:s');
    
    file_put_contents($qrFile, json_encode($data, JSON_PRETTY_PRINT));
    
    updateQueueInfo(count($data['qr_list']));
    
    return true;
}

// Функция отправки уведомления во все группы
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

// Функция уведомления всех администраторов
function notifyAdmins($requestChatId) {
    $chatInfo = makeRequest('getChat', ['chat_id' => $requestChatId]);
    $chatTitle = $chatInfo['result']['title'] ?? 'Неизвестная группа';
    
    $message = "📨 *Запрос новых QR!*\n".
               "📱 Группа: *{$chatTitle}*\n".
               "🕒 Время запроса: " . date('Y-m-d H:i:s') . " UTC\n\n".
               "Клиенты ожидают новые QR!";
    
    $adminChats = getAdminChatIds();
    if (empty($adminChats)) {
        return false;
    }
    
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

// Функция отметки QR как отправленного и удаления его из базы
function markQRAsSent($messageId) {
    global $qrFile;
    
    $data = getStoredQR();
    
    // Находим и удаляем отправленный QR из списка
    foreach ($data['qr_list'] as $key => $qr) {
        if ($qr['message_id'] == $messageId) {
            unset($data['qr_list'][$key]);
            
            // Записываем в лог отправленных
            $sent_log = [];
            if (file_exists('sent_log.json')) {
                $sent_log = json_decode(file_get_contents('sent_log.json'), true);
            }
            
            if (!isset($sent_log['entries'])) {
                $sent_log['entries'] = [];
            }
            
            $sent_log['entries'][] = [
                'message_id' => $messageId,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            file_put_contents('sent_log.json', json_encode($sent_log, JSON_PRETTY_PRINT));
            break;
        }
    }
    
    // Переиндексируем массив
    $data['qr_list'] = array_values($data['qr_list']);
    
    // Обновляем время последнего обновления
    $data['last_update'] = date('Y-m-d H:i:s');
    
    // Сохраняем обновленные данные
    file_put_contents($qrFile, json_encode($data, JSON_PRETTY_PRINT));
    
    // Обновляем информацию об очереди
    updateQueueInfo(count($data['qr_list']));
}

// Функция проверки, был ли QR отправлен
function isQRSent($messageId) {
    $data = getStoredQR();
    
    // Проверяем, есть ли QR в списке
    foreach ($data['qr_list'] as $qr) {
        if ($qr['message_id'] == $messageId) {
            return false; // QR всё ещё в списке
        }
    }
    
    return true; // QR не найден в списке, значит уже был отправлен
}

// Функция для пересылки сохраненных QR
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
            if (isset($qr['text']) && !isset($qr['photo'])) {
                $params = [
                    'chat_id' => $chatId,
                    'text' => $qr['text']
                ];
                $result = makeRequest('sendMessage', $params);
            } else {
                $params = [
                    'chat_id' => $chatId,
                    'from_chat_id' => $qr['chat']['id'],
                    'message_id' => $qr['message_id']
                ];
                $result = makeRequest('forwardMessage', $params);
            }
            
            if ($result['ok']) {
                markQRAsSent($qr['message_id']);
                $forwardedCount++;
                
                usleep(500000);
                
                if ($forwardedCount >= $count) {
                    break;
                }
            }
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

// Получаем обновления от Telegram
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message']) || isset($update['callback_query'])) {
    // Обработка callback query (нажатие на кнопку)
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
                    'message_id' => $callback['message']['message_id'],
                    'text' => "ℹ️ Новых QR пока нет, но мы уже готовим их\n\n✅ Администраторы уведомлены о вашем запросе."
                ]);
            } else {
                makeRequest('answerCallbackQuery', [
                    'callback_query_id' => $callback['id'],
                    'text' => "⚠️ Не удалось уведомить администраторов. Попробуйте позже.",
                    'show_alert' => true
                ]);
            }
        }
        exit;
    }
    
    // Обработка обычных сообщений
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $chatType = $message['chat']['type'];
    $username = $message['from']['username'] ?? '';
    $text = $message['text'] ?? '';
    
    if (isAdmin($username)) {
        if ($chatType === 'private') {
            saveAdminChatId($username, $chatId);
            
            if (isset($message['forward_date']) || isset($message['photo']) || isset($message['text'])) {
                if (storeQR($message)) {
                    $keyboard = [
                        'inline_keyboard' => [[
                            [
                                'text' => '📢 Уведомить все группы о новых QR',
                                'callback_data' => 'notify_new_qr'
                            ]
                        ]]
                    ];
                    
                    makeRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "✅ QR добавлен в очередь на отправку.",
                        'reply_markup' => json_encode($keyboard)
                    ]);
                } else {
                    makeRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ QR должен содержать фото или текст."
                    ]);
                }
                exit;
            }
        }
        elseif (isAllowedChat($chatId, $chatType)) {
            if (preg_match('/^\/bot_(on|off)$/i', $text, $matches)) {
                $command = strtolower($matches[1]);
                setBotStatus($chatId, $command === 'on');
                exit;
            }
        }
    }
    
    if (!isAllowedChat($chatId, $chatType)) {
        if ($chatType === 'private' && !isAdmin($username)) {
            makeRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => '⚠️ Бот работает только в разрешенных групповых чатах.'
            ]);
        }
        exit;
    }
    
    // Обработка команды "мтс"
    if (preg_match('/^мтс(?:\s+(\d+))?$/iu', $text, $matches)) {
        $count = isset($matches[1]) ? (int)$matches[1] : 1;
        
        if ($count > $maxQrPerRequest) {
            makeRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⚠️ Максимальное количество QR за один запрос: $maxQrPerRequest"
            ]);
            $count = $maxQrPerRequest;
        }
        
        forwardStoredQR($chatId, $count);
    }
}

// Логирование
$logMessage = date('Y-m-d H:i:s') . " - Пользователь: $username, Чат: $chatId, Тип: $chatType\n";
file_put_contents($logFile, $logMessage, FILE_APPEND);
?>