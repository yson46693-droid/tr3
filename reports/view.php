<?php
declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

// منع تشغيل المهام اليومية أثناء عرض التقرير
if (!defined('SKIP_LOW_STOCK_REPORT')) {
    define('SKIP_LOW_STOCK_REPORT', true);
}
if (!defined('SKIP_DAILY_CONSUMPTION_REPORT')) {
    define('SKIP_DAILY_CONSUMPTION_REPORT', true);
}
if (!defined('SKIP_DAILY_BACKUP')) {
    define('SKIP_DAILY_BACKUP', true);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$reportsBaseDir = null;
if (defined('REPORTS_PRIVATE_PATH')) {
    $reportsBaseDir = rtrim(str_replace('\\', '/', REPORTS_PRIVATE_PATH), '/');
} elseif (defined('REPORTS_PATH')) {
    $reportsBaseDir = rtrim(str_replace('\\', '/', REPORTS_PATH), '/');
} else {
    $reportsBaseDir = str_replace('\\', '/', BASE_PATH . '/reports');
}

/**
 * عرض رسالة خطأ للمستخدم
 *
 * @param int $code
 * @param string $message
 * @return void
 */
function renderReportError(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>خطأ في عرض التقرير</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>body{font-family:"Cairo","Segoe UI",Tahoma,sans-serif;direction:rtl;text-align:center;padding:40px;background:#f8fafc;color:#0f172a;}';
    echo '.card{max-width:480px;margin:0 auto;background:#fff;border-radius:16px;padding:32px;box-shadow:0 20px 45px rgba(15,23,42,0.1);}';
    echo '.code{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:#1d4ed8;color:#fff;font-weight:700;font-size:22px;margin-bottom:18px;}';
    echo 'h1{margin:0 0 12px;font-size:22px;}p{margin:0;font-size:15px;color:#475569;}</style></head><body>';
    echo '<div class="card"><div class="code">' . htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<h1>تعذّر عرض التقرير</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
    exit;
}

$type = $_GET['type'] ?? 'low_stock';
$token = trim((string)($_GET['token'] ?? ''));
$settingKey = null;
$usesSettingsTable = true;

switch ($type) {
    case 'low_stock':
        if (!defined('LOW_STOCK_REPORT_STATUS_SETTING_KEY')) {
            define('LOW_STOCK_REPORT_STATUS_SETTING_KEY', 'low_stock_report_status');
        }
        $settingKey = LOW_STOCK_REPORT_STATUS_SETTING_KEY;
        break;
    case 'packaging':
        if (!defined('PACKAGING_ALERT_STATUS_SETTING_KEY')) {
            define('PACKAGING_ALERT_STATUS_SETTING_KEY', 'packaging_alert_status');
        }
        $settingKey = PACKAGING_ALERT_STATUS_SETTING_KEY;
        break;
    case 'export':
    case 'consumption':
    case 'production':
        $usesSettingsTable = false;
        break;
    default:
        renderReportError(404, 'نوع التقرير غير مدعوم.');
}

if ($token === '') {
    renderReportError(400, 'المعرف الآمن للتقرير مفقود.');
}

$relativePath = '';

if ($usesSettingsTable) {
    try {
        $db = db();
    } catch (Throwable $connectionError) {
        error_log('Report viewer: DB connection failed - ' . $connectionError->getMessage());
        renderReportError(500, 'حدث خطأ في الاتصال بقاعدة البيانات.');
    }

    $row = null;
    try {
        $row = $db->queryOne(
            "SELECT value FROM system_settings WHERE `key` = ? LIMIT 1",
            [$settingKey]
        );
    } catch (Throwable $queryError) {
        error_log('Report viewer: status fetch failed - ' . $queryError->getMessage());
        renderReportError(500, 'تعذّر التحقق من حالة التقرير.');
    }

    if (empty($row) || empty($row['value'])) {
        renderReportError(404, 'لم يتم إنشاء تقرير لهذا اليوم بعد.');
    }

    $data = json_decode((string)$row['value'], true);
    if (!is_array($data)) {
        renderReportError(500, 'تنسيق بيانات التقرير غير صالح.');
    }

    $storedToken = (string)($data['access_token'] ?? '');
    if ($storedToken === '' || !hash_equals($storedToken, $token)) {
        renderReportError(403, 'المعرف الآمن غير صحيح أو انتهت صلاحيته.');
    }

    $relativePath = (string)($data['report_path'] ?? '');
    if ($relativePath === '') {
        renderReportError(404, 'مسار ملف التقرير غير متاح.');
    }

    if (preg_match('#\.\.[/\\\\]#', $relativePath)) {
        renderReportError(403, 'مسار الملف غير آمن.');
    }
} else {
    $fileParam = (string)($_GET['file'] ?? '');
    if ($fileParam === '') {
        renderReportError(400, 'مسار الملف غير محدد.');
    }

    $relativePath = str_replace('\\', '/', $fileParam);
    $relativePath = ltrim($relativePath, '/');

    if ($relativePath === '') {
        renderReportError(404, 'مسار الملف غير صالح.');
    }

    if (preg_match('#\.\.[/\\\\]#', $relativePath)) {
        renderReportError(403, 'مسار الملف غير آمن.');
    }

    $allowedPrefixes = ['exports/', 'consumption/', 'production/'];
    $isAllowed = false;
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            $isAllowed = true;
            break;
        }
    }
    if (!$isAllowed) {
        renderReportError(403, 'المسار المطلوب غير مسموح به.');
    }

    $fileName = basename($relativePath);
    if ($fileName === '' || strpos($fileName, $token) === false) {
        renderReportError(403, 'المعرف الآمن غير صالح لهذا الملف.');
    }
}

$normalized = str_replace('\\', '/', $relativePath);
$normalized = ltrim($normalized, '/');

$fullPath = $reportsBaseDir . '/' . $normalized;
if (!is_file($fullPath)) {
    renderReportError(404, 'ملف التقرير غير موجود.');
}

$contents = @file_get_contents($fullPath);
if ($contents === false) {
    renderReportError(500, 'تعذّر قراءة ملف التقرير.');
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$shouldPrint = isset($_GET['print']) && $_GET['print'] === '1';

if ($shouldPrint) {
    // إضافة سكريبت للطباعة التلقائية
    $printScript = '<script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>';
    
    // إدراج السكريبت قبل </body> أو في نهاية المحتوى
    if (stripos($contents, '</body>') !== false) {
        $contents = str_ireplace('</body>', $printScript . '</body>', $contents);
    } else {
        $contents .= $printScript;
    }
}

echo $contents;

