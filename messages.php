<?php
session_start();
require_once 'bot.php';
require_once 'message_functions.php';

// Проверка авторизации
function checkAuth() {
    global $adminUsers;
    if (!isset($_SESSION['username']) || !in_array($_SESSION['username'], $adminUsers)) {
        header('Location: login.php');
        exit;
    }
}

checkAuth();

// Получаем информацию о доступных чатах
function getAvailableChats() {
    global $allowedGroupChats;
    $chats = [];
    
    foreach ($allowedGroupChats as $chatId) {
        $chatInfo = makeRequest('getChat', ['chat_id' => $chatId]);
        if ($chatInfo['ok']) {
            $chats[] = [
                'id' => $chatId,
                'title' => $chatInfo['result']['title'] ?? 'Чат ' . $chatId,
                'status' => getBotStatus($chatId)
            ];
        }
    }
    
    return $chats;
}

// Обработка AJAX запросов
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['action']) {
            case 'send_message':
                $chatIds = $_POST['chat_ids'] ?? [];
                $message = $_POST['message'] ?? '';
                
                if (empty($chatIds) || empty($message)) {
                    throw new Exception('Необходимо выбрать чат и ввести сообщение');
                }
                
                foreach ($chatIds as $chatId) {
                    $params = [
                        'chat_id' => $chatId,
                        'text' => $message
                    ];
                    
                    $result = makeRequest('sendMessage', $params);
                    
                    if ($result['ok']) {
                        saveMessage($chatId, $message, 'text', $_SESSION['username']);
                    } else {
                        throw new Exception('Ошибка отправки в чат ' . $chatId);
                    }
                }
                
                $response['success'] = true;
                $response['message'] = 'Сообщение отправлено';
                break;
                
            case 'get_messages':
                $chatId = isset($_POST['chat_id']) && !empty($_POST['chat_id']) ? $_POST['chat_id'] : null;
                $messages = getChatMessages($chatId);
                $response['success'] = true;
                $response['data'] = $messages;
                break;
                
            default:
                throw new Exception('Неизвестное действие');
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

$chats = getAvailableChats();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сообщения QR бота</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.0.7/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #198754;
            --info-color: #0dcaf0;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .send-message-form {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .message-list {
            height: 500px;
            overflow-y: auto;
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .message-item {
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
        }

        .message-item.outgoing {
            background: #e3f2fd;
            margin-left: 20%;
        }

        .message-item.incoming {
            background: #f5f5f5;
            margin-right: 20%;
        }

        .message-header {
            font-size: 0.8rem;
            color: var(--secondary-color);
            margin-bottom: 0.25rem;
        }

        .message-time {
            float: right;
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .toast {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: none;
        }

        .toast.success {
            border-left: 4px solid var(--success-color);
        }

        .toast.error {
            border-left: 4px solid var(--danger-color);
        }

        @media (max-width: 768px) {
            .message-item {
                margin-left: 0;
                margin-right: 0;
            }
        }
    </style>
</head>
<body>
    <div class="toast-container"></div>

    <div class="dashboard-header">
        <div class="container">
            <h1 class="display-4">Сообщения QR бота</h1>
            <p class="lead">
                <a href="stats.php" class="text-white">Статистика</a> |
                <a href="messages.php" class="text-white">Сообщения</a>
            </p>
        </div>
    </div>

    <div class="container">
        <!-- Форма отправки сообщения -->
        <div class="send-message-form">
            <h3 class="mb-3">Отправить сообщение</h3>
            <form id="messageForm">
                <div class="mb-3">
                    <label class="form-label">Выберите чаты</label>
                    <?php foreach ($chats as $chat): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" 
                               name="chat_ids[]" value="<?php echo $chat['id']; ?>" 
                               id="chat_<?php echo $chat['id']; ?>">
                        <label class="form-check-label" for="chat_<?php echo $chat['id']; ?>">
                            <?php echo htmlspecialchars($chat['title']); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mb-3">
                    <label for="messageText" class="form-label">Текст сообщения</label>
                    <textarea class="form-control" id="messageText" name="message" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bx bx-send"></i> Отправить
                </button>
            </form>
        </div>

        <!-- История сообщений -->
        <div class="message-history">
            <h3 class="mb-3">История сообщений</h3>
            <div class="mb-3">
                <select class="form-select" id="chatFilter">
                    <option value="">Все чаты</option>
                    <?php foreach ($chats as $chat): ?>
                    <option value="<?php echo $chat['id']; ?>">
                        <?php echo htmlspecialchars($chat['title']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="message-list" id="messageList">
                <!-- Сообщения будут добавлены через JavaScript -->
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            function showToast(message, type = 'success') {
                const toast = $(`<div class="toast ${type}">${message}</div>`);
                $('.toast-container').append(toast);
                toast.fadeIn();
                setTimeout(() => {
                    toast.fadeOut(() => toast.remove());
                }, 3000);
            }

            function loadMessages(chatId = '') {
                $.ajax({
                    url: 'messages.php',
                    method: 'POST',
                    data: {
                        action: 'get_messages',
                        chat_id: chatId
                    },
                    success: function(response) {
                        try {
                            const data = typeof response === 'string' ? JSON.parse(response) : response;
                            if (data.success) {
                                const messageList = $('#messageList');
                                messageList.empty();
                                
                                data.data.forEach(msg => {
                                    const isOutgoing = msg.from === '<?php echo $_SESSION['username']; ?>';
                                    const messageHtml = `
                                        <div class="message-item ${isOutgoing ? 'outgoing' : 'incoming'}">
                                            <div class="message-header">
                                                <span class="message-sender">${msg.from}</span>
                                                <span class="message-time">${msg.timestamp}</span>
                                            </div>
                                            <div class="message-content">
                                                ${msg.message}
                                            </div>
                                        </div>
                                    `;
                                    messageList.append(messageHtml);
                                });
                                
                                messageList.scrollTop(messageList[0].scrollHeight);
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            showToast('Ошибка загрузки сообщений', 'error');
                        }
                    },
                    error: function() {
                        showToast('Ошибка загрузки сообщений', 'error');
                    }
                });
            }

            $('#messageForm').submit(function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'send_message');
                
                $.ajax({
                    url: 'messages.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        try {
                            const data = typeof response === 'string' ? JSON.parse(response) : response;
                            showToast(data.message, data.success ? 'success' : 'error');
                            
                            if (data.success) {
                                $('#messageForm')[0].reset();
                                loadMessages($('#chatFilter').val());
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            showToast('Ошибка отправки сообщения', 'error');
                        }
                    },
                    error: function() {
                        showToast('Ошибка отправки сообщения', 'error');
                    }
                });
            });

            $('#chatFilter').change(function() {
                loadMessages($(this).val());
            });

            // Загружаем сообщения при загрузке страницы
            loadMessages();

            // Обновляем сообщения каждые 30 секунд
            setInterval(() => {
                loadMessages($('#chatFilter').val());
            }, 30000);
        });
    </script>
</body>
</html>