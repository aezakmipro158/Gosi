<?php
session_start();
require_once 'bot.php';

// Проверка авторизации
function checkAuth() {
    global $adminUsers;
    if (!isset($_SESSION['username']) || !in_array($_SESSION['username'], $adminUsers)) {
        header('Location: login.php');
        exit;
    }
}

checkAuth();

// Функция получения статистики по чатам
function getQRStats() {
    global $allowedGroupChats, $qrFile;
    
    $stats = [
        'total_qr' => 0,
        'active_qr' => 0,
        'sent_qr' => 0,
        'chats' => []
    ];
    
    // Получаем данные о QR
    $data = getStoredQR();
    $stats['active_qr'] = count($data['qr_list']);
    
    // Получаем информацию о чатах
    foreach ($allowedGroupChats as $chatId) {
        $chatInfo = makeRequest('getChat', ['chat_id' => $chatId]);
        if ($chatInfo['ok']) {
            $stats['chats'][] = [
                'id' => $chatId,
                'title' => $chatInfo['result']['title'] ?? 'Неизвестная группа',
                'status' => getBotStatus($chatId),
                'sent_count' => 0
            ];
        }
    }
    
    // Считаем отправленные QR
    $sent_log = [];
    if (file_exists('sent_log.json')) {
        $sent_log = json_decode(file_get_contents('sent_log.json'), true);
    }
    
    if (isset($sent_log['entries'])) {
        foreach ($sent_log['entries'] as $entry) {
            $stats['sent_qr']++;
        }
    }
    
    $stats['total_qr'] = $stats['active_qr'] + $stats['sent_qr'];
    
    return $stats;
}

// Обработка AJAX запросов
if (isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    switch ($_POST['action']) {
        case 'toggle_status':
            $chatId = $_POST['chat_id'];
            $newStatus = $_POST['status'] === 'true';
            setBotStatus($chatId, $newStatus);
            $response['success'] = true;
            break;
            
        case 'add_qr':
            try {
                $text = $_POST['text'] ?? '';
                $photo = $_FILES['photo'] ?? null;
                
                if (empty($text) && empty($photo)) {
                    throw new Exception('Необходимо добавить текст или фото');
                }
                
                // Создаем сообщение для сохранения
                $message = [
                    'message_id' => time() . rand(1000, 9999),
                    'from' => ['username' => $_SESSION['username']],
                    'chat' => ['id' => 'web_' . time()],
                    'date' => time()
                ];
                
                // Добавляем текст если есть
                if (!empty($text)) {
                    $message['text'] = $text;
                }
                
                // Обрабатываем фото если есть
                if (!empty($photo) && $photo['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = 'uploads/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileName = time() . '_' . basename($photo['name']);
                    $filePath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($photo['tmp_name'], $filePath)) {
                        $message['photo'] = [[
                            'file_id' => $filePath,
                            'file_unique_id' => $fileName,
                            'file_size' => $photo['size'],
                            'width' => 0,
                            'height' => 0
                        ]];
                    } else {
                        throw new Exception('Ошибка при загрузке файла');
                    }
                }
                
                if (storeQR($message)) {
                    $response['success'] = true;
                    $response['message'] = 'QR успешно добавлен в очередь';
                } else {
                    throw new Exception('Ошибка при сохранении QR');
                }
                
            } catch (Exception $e) {
                $response['message'] = $e->getMessage();
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

$stats = getQRStats();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика QR бота</title>
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

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 1rem 0;
            color: var(--primary-color);
        }

        .stat-label {
            color: var(--secondary-color);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .add-qr-form {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }

        .file-input-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .preview-image {
            max-width: 200px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 8px;
            display: none;
        }

        .remove-image {
            position: absolute;
            top: -10px;
            right: -10px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            display: none;
        }

        .preview-wrapper {
            position: relative;
            display: inline-block;
        }

        .chat-list {
            margin-top: 2rem;
        }

        .chat-item {
            background: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .chat-title {
            font-weight: 500;
            color: var(--secondary-color);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
            margin-bottom: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--success-color);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .status-active .status-indicator {
            background-color: var(--success-color);
        }

        .status-inactive .status-indicator {
            background-color: var(--danger-color);
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

        .refresh-button {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--primary-color);
            color: white;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .refresh-button:hover {
            transform: rotate(180deg);
        }

        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 1rem;
            }
            
            .chat-item {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="toast-container"></div>

    <div class="dashboard-header">
       
        <div class="container">
            <h1 class="display-4">Статистика QR бота</h1>
             <!-- В dashboard-header добавить: -->
<p class="lead">
    <a href="stats.php" class="text-white">Статистика</a> |
    <a href="messages.php" class="text-white">Сообщения</a>
</p>
            <p class="lead">Обновлено: <?php echo date('d.m.Y H:i:s'); ?></p>
        </div>
    </div>

    <div class="container">
        <!-- Форма добавления QR -->
        <div class="add-qr-form mb-4">
            <h3 class="mb-3">Добавить новый QR</h3>
            <form id="qrForm" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="qrText" class="form-label">Текст QR</label>
                    <textarea class="form-control" id="qrText" name="text" rows="3" placeholder="Введите текст для QR"></textarea>
                </div>
                <div class="mb-3">
                    <div class="file-input-wrapper">
                        <button type="button" class="btn btn-secondary">
                            <i class="bx bx-image-add"></i> Выбрать фото
                        </button>
                        <input type="file" id="qrPhoto" name="photo" accept="image/*">
                    </div>
                    <div class="preview-wrapper mt-2">
                        <img id="imagePreview" class="preview-image">
                        <div class="remove-image">&times;</div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bx bx-plus"></i> Добавить в очередь
                </button>
            </form>
        </div>

        <!-- Статистика -->
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label">Всего QR</div>
                    <div class="stat-value"><?php echo $stats['total_qr']; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label">Активных QR</div>
                    <div class="stat-value"><?php echo $stats['active_qr']; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label">Отправлено QR</div>
                    <div class="stat-value"><?php echo $stats['sent_qr']; ?></div>
                </div>
            </div>
        </div>

        <!-- Список чатов -->
        <div class="chat-list">
            <h2 class="mb-4">Статистика по чатам</h2>
            <?php foreach ($stats['chats'] as $chat): ?>
                <div class="chat-item">
                    <div class="chat-info">
                        <div class="chat-title"><?php echo htmlspecialchars($chat['title']); ?></div>
                        <div class="chat-id text-muted">ID: <?php echo $chat['id']; ?></div>
                    </div>
                    <div class="chat-stats">
                        <div class="d-flex align-items-center gap-2">
                            <label class="switch">
                                <input type="checkbox" 
                                       class="status-toggle" 
                                       data-chat-id="<?php echo $chat['id']; ?>"
                                       <?php echo $chat['status'] ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span class="status-text <?php echo $chat['status'] ? 'status-active' : 'status-inactive'; ?>">
                                <span class="status-indicator"></span>
                                <?php echo $chat['status'] ? 'Активен' : 'Отключен'; ?>
                            </span>
                        </div>
                        <div class="sent-count mt-2">
                            Отправлено QR: <?php echo $chat['sent_count']; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="refresh-button" onclick="location.reload()">
        <i class="bx bx-refresh" style="font-size: 1.5rem;"></i>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Функция показа оповещения
            function showToast(message, type = 'success') {
                const toast = $(`<div class="toast ${type}">${message}</div>`);
                $('.toast-container').append(toast);
                toast.fadeIn();
                setTimeout(() => {
                    toast.fadeOut(() => toast.remove());
                }, 3000);
            }

            // Предпросмотр изображения
            $('#qrPhoto').change(function(e) {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#imagePreview').attr('src', e.target.result).show();
                        $('.remove-image').show();
                    }
                    reader.readAsDataURL(file);
                }
            });

            // Удаление изображения
            $('.remove-image').click(function() {
                $('#qrPhoto').val('');
                $('#imagePreview').hide();
                $(this).hide();
            });

            // Обработчик переключения статуса
            $('.status-toggle').change(function() {
                const chatId = $(this).data('chat-id');
                const isChecked = $(this).prop('checked');
                const statusText = $(this).closest('.chat-stats').find('.status-text');
                
                $.ajax({
                    url: 'stats.php',
                    method: 'POST',
                    data: {
                        action: 'toggle_status',
                        chat_id: chatId,
                        status: isChecked
                    },
                    success: function(response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            statusText.removeClass('status-active status-inactive')
                                    .addClass(isChecked ? 'status-active' : 'status-inactive')
                                    .html(`<span class="status-indicator"></span>${isChecked ? 'Активен' : 'Отключен'}`);
                            
                            showToast(`Статус бота ${isChecked ? 'включен' : 'выключен'} для чата ${chatId}`);
                        } else {
                            showToast('Произошла ошибка при изменении статуса', 'error');
                            $(this).prop('checked', !isChecked);
                        }
                    },
                    error: function() {
                        showToast('Произошла ошибка при изменении статуса', 'error');
                        $(this).prop('checked', !isChecked);
                    }
                });
            });

            // Отправка формы добавления QR
            $('#qrForm').submit(function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'add_qr');
                
                $.ajax({
                    url: 'stats.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        const data = JSON.parse(response);
                        showToast(data.message, data.success ? 'success' : 'error');
                        
                        if (data.success) {
                            $('#qrForm')[0].reset();
                            $('#imagePreview').hide();
                            $('.remove-image').hide();
                            location.reload();
                        }
                    },
                    error: function() {
                        showToast('Произошла ошибка при добавлении QR', 'error');
                    }
                });
            });
        });
    </script>
</body>
</html>