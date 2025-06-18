<?php
// Проверяем, не был ли файл уже подключен
if (!function_exists('saveMessage')) {
    // Функция сохранения сообщения в историю
    function saveMessage($chatId, $message, $type = 'text', $from = 'web') {
        $messages = [
            'messages' => [],
            'last_update' => date('Y-m-d H:i:s')
        ];
        
        if (file_exists('chat_messages.json')) {
            $content = file_get_contents('chat_messages.json');
            if ($content) {
                $messages = json_decode($content, true) ?: $messages;
            }
        }
        
        if (!isset($messages['messages'])) {
            $messages['messages'] = [];
        }
        
        $messages['messages'][] = [
            'chat_id' => $chatId,
            'message' => $message,
            'type' => $type,
            'from' => $from,
            'timestamp' => date('Y-m-d H:i:s'),
            'message_id' => time() . rand(1000, 9999)
        ];
        
        $messages['last_update'] = date('Y-m-d H:i:s');
        
        return file_put_contents('chat_messages.json', json_encode($messages, JSON_PRETTY_PRINT));
    }

    // Функция получения истории сообщений
    function getChatMessages($chatId = null, $limit = 50) {
        $messages = [
            'messages' => [],
            'last_update' => date('Y-m-d H:i:s')
        ];
        
        if (file_exists('chat_messages.json')) {
            $content = file_get_contents('chat_messages.json');
            if ($content) {
                $messages = json_decode($content, true) ?: $messages;
            }
        }
        
        if (!isset($messages['messages'])) {
            return [];
        }
        
        $filtered = $messages['messages'];
        
        if ($chatId !== null) {
            $filtered = array_filter($messages['messages'], function($msg) use ($chatId) {
                return $msg['chat_id'] == $chatId;
            });
        }
        
        $filtered = array_values($filtered);
        return array_slice($filtered, -$limit);
    }

    // Функция для получения информации о чате
    function getChatInfo($chatId) {
        global $allowedGroupChats;
        
        if (!in_array($chatId, $allowedGroupChats)) {
            return null;
        }
        
        $result = makeRequest('getChat', ['chat_id' => $chatId]);
        
        if ($result['ok']) {
            return $result['result'];
        }
        
        return null;
    }
}
?>