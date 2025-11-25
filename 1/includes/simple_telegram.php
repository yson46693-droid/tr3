<?php
/**
 * نظام Telegram مبسط وموثوق
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';

// إعدادات Telegram Bot (يتم قراءتها من config.php)
// إذا لم تكن موجودة في config.php، استخدم القيم الافتراضية
if (!defined('TELEGRAM_BOT_TOKEN')) {
    define('TELEGRAM_BOT_TOKEN', '6286098014:AAGr6q-6mvUHYIa3elUkssoijFhY7OXBrew'); // ضع توكن البوت في config.php
}
if (!defined('TELEGRAM_CHAT_ID')) {
    define('TELEGRAM_CHAT_ID', '-1003293835035'); // ضع Chat ID في config.php
}

// استخدام IP بدلاً من Domain لحل مشكلة DNS
define('TELEGRAM_API_URL', 'https://149.154.167.220/bot' . TELEGRAM_BOT_TOKEN);

/**
 * التحقق من صحة إعدادات Telegram
 */
function isTelegramConfigured() {
    return !empty(TELEGRAM_BOT_TOKEN) && !empty(TELEGRAM_CHAT_ID) && 
           TELEGRAM_BOT_TOKEN !== 'YOUR_BOT_TOKEN' && 
           TELEGRAM_CHAT_ID !== 'YOUR_CHAT_ID';
}

/**
 * إرسال رسالة إلى Telegram (مبسط وموثوق)
 */
function sendTelegramMessage($message, $chatId = null) {
    if (!isTelegramConfigured()) {
        error_log("Telegram not configured");
        return false;
    }
    
    $chatId = $chatId ?? TELEGRAM_CHAT_ID;
    $url = TELEGRAM_API_URL . '/sendMessage';
    
    // تنظيف الرسالة من HTML غير المدعوم
    $message = strip_tags($message, '<b><strong><i><em><u><s><code><pre><a>');
    
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false
    ];
    
    // استبدال IP بـ Domain في URL
    $url = str_replace('149.154.167.220', 'api.telegram.org', $url);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_RESOLVE => ['api.telegram.org:443:149.154.167.220']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['ok']) && $result['ok']) {
            return $result;
        } else {
            $errorDesc = $result['description'] ?? 'Unknown error';
            error_log("Telegram API error: " . $errorDesc);
            return false;
        }
    } else {
        error_log("Telegram HTTP error: {$httpCode}. cURL Error: {$curlError}");
        return false;
    }
}

/**
 * إرسال رسالة مع أزرار Inline إلى Telegram
 *
 * @param string $message
 * @param array<int, array<int, array<string, string>>> $buttons مصفوفة من الصفوف، كل صف يحوي أزراراً (text, url)
 * @param string|null $chatId
 * @return array<string, mixed>|false
 */
function sendTelegramMessageWithButtons($message, array $buttons, $chatId = null) {
    if (!isTelegramConfigured()) {
        $error = 'Telegram bot token or chat id not configured';
        error_log("Telegram not configured (buttons)");
        return [
            'success' => false,
            'error' => $error,
        ];
    }

    $inlineKeyboard = [];
    foreach ($buttons as $row) {
        $rowButtons = [];
        foreach ($row as $button) {
            $text = trim($button['text'] ?? '');
            $url = trim($button['url'] ?? '');
            if ($text === '' || $url === '') {
                continue;
            }
            $rowButtons[] = [
                'text' => $text,
                'url'  => $url,
            ];
        }
        if (!empty($rowButtons)) {
            $inlineKeyboard[] = $rowButtons;
        }
    }

    $chatId = $chatId ?? TELEGRAM_CHAT_ID;
    $url = TELEGRAM_API_URL . '/sendMessage';

    $allowedTags = '<b><strong><i><em><u><s><code><pre><a>';
    $cleanMessage = strip_tags($message, $allowedTags);

    $data = [
        'chat_id' => $chatId,
        'text' => $cleanMessage,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false,
    ];

    if (!empty($inlineKeyboard)) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
    }

    $url = str_replace('149.154.167.220', 'api.telegram.org', $url);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_RESOLVE => ['api.telegram.org:443:149.154.167.220'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['ok']) && $result['ok']) {
            return [
                'success' => true,
                'response' => $result,
            ];
        }

        $errorDesc = $result['description'] ?? 'Unknown error';
        error_log("Telegram API error (buttons): " . $errorDesc);
        return [
            'success' => false,
            'error' => $errorDesc,
            'response' => $result,
        ];
    }

    $errorMessage = "HTTP {$httpCode}";
    if (!empty($curlError)) {
        $errorMessage .= ' - ' . $curlError;
    }
    error_log("Telegram HTTP error (buttons): {$errorMessage}");
    if (!empty($response)) {
        error_log("Telegram error response (buttons): " . substr($response, 0, 500));
    }

    return [
        'success' => false,
        'error' => $errorMessage,
    ];
}

/**
 * إرسال ملف إلى Telegram (مبسط)
 */
function sendTelegramFile($filePath, $caption = '', $chatId = null) {
    if (!isTelegramConfigured()) {
        error_log("Telegram not configured");
        return false;
    }
    
    if (!file_exists($filePath)) {
        error_log("File not found: " . $filePath);
        return false;
    }
    
    $chatId = $chatId ?? TELEGRAM_CHAT_ID;
    $url = TELEGRAM_API_URL . '/sendDocument';
    
    // تحديد نوع الملف
    $mimeType = mime_content_type($filePath);
    if (!$mimeType) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'txt' => 'text/plain'
        ];
        $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    }
    
    $file = new CURLFile($filePath, $mimeType, basename($filePath));
    
    $data = [
        'chat_id' => $chatId,
        'document' => $file,
        'caption' => mb_substr($caption, 0, 1024) // Telegram limit
    ];
    
    // استبدال IP بـ Domain في URL
    $url = str_replace('149.154.167.220', 'api.telegram.org', $url);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RESOLVE => ['api.telegram.org:443:149.154.167.220']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['ok']) && $result['ok']) {
            return $result;
        } else {
            $errorDesc = $result['description'] ?? 'Unknown error';
            error_log("Telegram API error: " . $errorDesc);
            return false;
        }
    } else {
        error_log("Telegram HTTP error: {$httpCode}. cURL Error: {$curlError}");
        return false;
    }
}

/**
 * إرسال صورة إلى Telegram (مبسط)
 */
function sendTelegramPhoto($photoData, $caption = '', $chatId = null, $isBase64 = false) {
    if (!isTelegramConfigured()) {
        error_log("Telegram not configured - cannot send photo");
        return false;
    }
    
    error_log("sendTelegramPhoto called - isBase64: " . ($isBase64 ? 'yes' : 'no') . ", photoData type: " . gettype($photoData));
    
    $chatId = $chatId ?? TELEGRAM_CHAT_ID;
    $url = TELEGRAM_API_URL . '/sendPhoto';
    
    $tempFile = null;
    $deleteAfter = false;
    
    // معالجة base64
    if ($isBase64) {
        error_log("Processing base64 image, data length: " . strlen($photoData));
        
        if (empty($photoData)) {
            error_log("ERROR: Base64 photo data is empty!");
            return false;
        }
        
        // تنظيف البيانات من prefix إذا كان موجوداً
        $cleanData = preg_replace('#^data:image/\w+;base64,#i', '', $photoData);
        $cleanData = str_replace(' ', '+', trim($cleanData));
        
        // التأكد من أن الطول قابل للقسمة على 4 (متطلب base64)
        $mod = strlen($cleanData) % 4;
        if ($mod > 0) {
            $cleanData .= str_repeat('=', 4 - $mod);
        }
        
        error_log("Cleaned data length: " . strlen($cleanData));
        
        $imageData = base64_decode($cleanData, true);
        
        if ($imageData === false) {
            error_log("Failed to decode base64 image. Clean data preview: " . substr($cleanData, 0, 50));
            return false;
        }
        
        error_log("Decoded image data length: " . strlen($imageData) . " bytes");
        
        // استخدام مجلد مؤقت في نفس المجلد إذا كان sys_get_temp_dir() لا يعمل
        $tempDir = sys_get_temp_dir();
        if (!$tempDir || !is_writable($tempDir)) {
            $tempDir = __DIR__ . '/../uploads/temp';
        }
        
        if (!file_exists($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }
        
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            error_log("Temp directory not writable: " . $tempDir);
            return false;
        }
        
        $tempFile = $tempDir . '/' . uniqid('telegram_', true) . '.jpg';
        $bytesWritten = file_put_contents($tempFile, $imageData);
        
        if ($bytesWritten === false || $bytesWritten === 0) {
            error_log("Failed to write temp file: {$tempFile}, bytes written: {$bytesWritten}");
            return false;
        }
        
        error_log("Temp file created: {$tempFile}, size: {$bytesWritten} bytes");
        
        // التحقق من أن الملف موجود ويمكن قراءته
        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            error_log("Temp file verification failed: exists=" . (file_exists($tempFile) ? 'yes' : 'no') . ", size=" . filesize($tempFile));
            return false;
        }
        
        $deleteAfter = true;
        $photoPath = $tempFile;
    } else {
        if (!file_exists($photoData)) {
            error_log("Photo file not found: " . $photoData);
            return false;
        }
        $photoPath = $photoData;
    }
    
    // التحقق من وجود CURLFile class
    if (!class_exists('CURLFile')) {
        error_log("CURLFile class not available. PHP version may be too old.");
        return false;
    }
    
    // التحقق من حجم الملف (Telegram limit: 10MB)
    $fileSize = filesize($photoPath);
    if ($fileSize > 10 * 1024 * 1024) {
        error_log("Photo file too large: {$fileSize} bytes (max 10MB)");
        return false;
    }
    
    $photo = new CURLFile($photoPath, 'image/jpeg', 'attendance_photo.jpg');
    
    error_log("Preparing to send photo: file={$photoPath}, size={$fileSize} bytes, chat_id={$chatId}");
    
    $allowedCaptionTags = '<b><strong><i><em><u><s><code><pre><a>';
    $cleanCaption = strip_tags($caption, $allowedCaptionTags);

    $data = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => mb_substr($cleanCaption, 0, 1024),
        'parse_mode' => 'HTML'
    ];
    
    // استبدال IP بـ Domain في URL
    $url = str_replace('149.154.167.220', 'api.telegram.org', $url);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RESOLVE => ['api.telegram.org:443:149.154.167.220']
        // لا نضيف Content-Type header - curl يضيفه تلقائياً مع boundary عند استخدام CURLFile
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // حذف الملف المؤقت
    if ($deleteAfter && $tempFile && file_exists($tempFile)) {
        @unlink($tempFile);
    }
    
    // تسجيل تفاصيل الاستجابة للتشخيص
    error_log("Telegram Photo Send Response: HTTP {$httpCode}, Response length: " . strlen($response));
    if ($httpCode !== 200) {
        error_log("Telegram Photo Send Error: {$curlError}, Response: " . substr($response, 0, 500));
    }
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['ok']) && $result['ok']) {
            error_log("Telegram photo sent successfully to chat {$chatId}");
            return $result;
        } else {
            $errorDesc = $result['description'] ?? 'Unknown error';
            $errorCode = $result['error_code'] ?? 'N/A';
            error_log("Telegram API error: Code {$errorCode}, Description: {$errorDesc}");
            error_log("Full response: " . json_encode($result, JSON_UNESCAPED_UNICODE));
            return false;
        }
    } else {
        error_log("Telegram HTTP error: {$httpCode}. cURL Error: {$curlError}");
        if ($response) {
            $errorResponse = json_decode($response, true);
            if ($errorResponse) {
                error_log("Telegram error response: " . json_encode($errorResponse, JSON_UNESCAPED_UNICODE));
            }
        }
        return false;
    }
}

/**
 * اختبار إرسال رسالة (للتشخيص)
 */
function testTelegramConnection() {
    if (!isTelegramConfigured()) {
        return ['success' => false, 'message' => 'Telegram غير مُعد'];
    }
    
    $testMessage = "🧪 اختبار اتصال Telegram\nالتاريخ: " . date('Y-m-d H:i:s');
    $result = sendTelegramMessage($testMessage);
    
    if ($result) {
        return ['success' => true, 'message' => 'تم إرسال الرسالة بنجاح'];
    } else {
        return ['success' => false, 'message' => 'فشل إرسال الرسالة'];
    }
}

/**
 * إرسال فاتورة تسوية مرتب موظف إلى تليجرام
 */
function sendSalarySettlementToTelegram($settlementId, $salary, $settlementAmount, $previousAccumulated, $remainingAfter, $settlementType, $settlementDate, $invoicePath = null) {
    if (!isTelegramConfigured()) {
        error_log("Telegram not configured - cannot send salary settlement");
        return false;
    }
    
    try {
        require_once __DIR__ . '/path_helper.php';
        require_once __DIR__ . '/config.php';
        
        $settlementTypeLabel = $settlementType === 'full' ? 'تسوية كاملة' : 'تسوية جزئية';
        $employeeName = $salary['full_name'] ?? $salary['username'] ?? 'غير محدد';
        $formattedDate = formatDate($settlementDate);
        
        // بناء الرسالة
        $message = "💰 <b>عملية تسوية مستحقات موظف ({$settlementTypeLabel})</b>\n\n";
        $message .= "👤 <b>الموظف:</b> " . htmlspecialchars($employeeName) . "\n";
        $message .= "📅 <b>تاريخ التسوية:</b> " . htmlspecialchars($formattedDate) . "\n";
        $message .= "💵 <b>المبلغ التراكمي السابق:</b> " . number_format($previousAccumulated, 2) . " ج.م\n";
        $message .= "✅ <b>المبلغ المسدد:</b> " . number_format($settlementAmount, 2) . " ج.م\n";
        
        if ($remainingAfter > 0) {
            $message .= "⚠️ <b>المتبقي بعد التسوية:</b> " . number_format($remainingAfter, 2) . " ج.م\n";
        } else {
            $message .= "✅ <b>تم تسوية جميع المستحقات</b>\n";
        }
        
        $message .= "\n📄 تم إنشاء فاتورة تسوية المرتب بنجاح.";
        
        // بناء الأزرار
        $buttons = [];
        
        // إضافة زر للفاتورة إذا كان موجوداً
        if ($invoicePath) {
            $absoluteUrl = getAbsoluteUrl($invoicePath);
            $printUrl = $absoluteUrl . (strpos($absoluteUrl, '?') === false ? '?print=1' : '&print=1');
            
            $buttons[] = [
                ['text' => '📄 عرض الفاتورة', 'url' => $absoluteUrl],
                ['text' => '🖨️ طباعة / حفظ PDF', 'url' => $printUrl]
            ];
        }
        
        // إرسال الرسالة مع الأزرار
        if (!empty($buttons)) {
            $result = sendTelegramMessageWithButtons($message, $buttons);
        } else {
            $result = sendTelegramMessage($message);
        }
        
        return !empty($result) && (is_array($result) ? ($result['ok'] ?? false) : $result);
        
    } catch (Exception $e) {
        error_log('Error sending salary settlement to Telegram: ' . $e->getMessage());
        return false;
    }
}

