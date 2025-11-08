<?php
/**
 * تنبيهات أدوات التعبئة اليومية عبر Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/simple_telegram.php';
require_once __DIR__ . '/pdf_helper.php';

const PACKAGING_ALERT_JOB_KEY = 'packaging_low_stock_alert';
const PACKAGING_ALERT_THRESHOLD = 20;

/**
 * تجهيز جدول تتبع المهام اليومية
 */
function packagingAlertEnsureJobTable(): void {
    static $tableReady = false;

    if ($tableReady) {
        return;
    }

    try {
        $db = db();
        $db->execute("
            CREATE TABLE IF NOT EXISTS `system_daily_jobs` (
              `job_key` varchar(120) NOT NULL,
              `last_sent_at` datetime DEFAULT NULL,
              `last_file_path` varchar(512) DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`job_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $tableError) {
        error_log('Packaging alert table error: ' . $tableError->getMessage());
        return;
    }

    $tableReady = true;
}

/**
 * معالجة التنبيه اليومي لأدوات التعبئة منخفضة الكمية
 */
function processDailyPackagingAlert(): void {
    static $processed = false;

    if ($processed) {
        return;
    }

    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return;
    }

    if (!isTelegramConfigured()) {
        return;
    }

    $processed = true;

    packagingAlertEnsureJobTable();

    $db = db();

    try {
        $jobState = $db->queryOne(
            "SELECT last_sent_at FROM system_daily_jobs WHERE job_key = ? LIMIT 1",
            [PACKAGING_ALERT_JOB_KEY]
        );
    } catch (Throwable $stateError) {
        error_log('Packaging alert state error: ' . $stateError->getMessage());
        return;
    }

    $today = date('Y-m-d');
    if (!empty($jobState['last_sent_at'])) {
        $lastSentDate = substr((string)$jobState['last_sent_at'], 0, 10);
        if ($lastSentDate === $today) {
            return;
        }
    }

    try {
        $lowStockItems = $db->query(
            "SELECT name, type, quantity, unit 
             FROM packaging_materials 
             WHERE status = 'active' 
               AND quantity IS NOT NULL 
               AND quantity < ? 
             ORDER BY quantity ASC, name ASC",
            [PACKAGING_ALERT_THRESHOLD]
        );
    } catch (Throwable $queryError) {
        error_log('Packaging alert query error: ' . $queryError->getMessage());
        return;
    }

    if (empty($lowStockItems)) {
        return;
    }

    $pdfPath = packagingAlertGeneratePdf($lowStockItems);
    if (!$pdfPath) {
        return;
    }

    $caption = "تقرير أدوات التعبئة منخفضة الكمية\nالتاريخ: " . date('Y-m-d H:i') . "\nالحد الأدنى: أقل من " . PACKAGING_ALERT_THRESHOLD . ' قطعة';

    $sent = sendTelegramFile($pdfPath, $caption);
    if ($sent && file_exists($pdfPath)) {
        @unlink($pdfPath);
        $pdfPath = null;
    }

    if (!$sent) {
        return;
    }

    try {
        if ($jobState) {
            $db->execute(
                "UPDATE system_daily_jobs SET last_sent_at = NOW(), last_file_path = NULL, updated_at = NOW() WHERE job_key = ?",
                [PACKAGING_ALERT_JOB_KEY]
            );
        } else {
            $db->execute(
                "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path) VALUES (?, NOW(), NULL)",
                [PACKAGING_ALERT_JOB_KEY]
            );
        }

        if (function_exists('createNotificationForRole')) {
            try {
                $relativeLink = null;
                if ($pdfPath && defined('BASE_PATH') && function_exists('getRelativeUrl')) {
                    $relative = ltrim(str_replace(BASE_PATH, '', $pdfPath), '/\\');
                    if ($relative !== '') {
                        $relativeLink = getRelativeUrl($relative);
                    }
                }

                createNotificationForRole(
                    'manager',
                    'تقرير المخازن اليومي',
                    'تم إرسال تقرير أدوات التعبئة منخفضة الكمية إلى قناة Telegram الخاصة بالمخازن.',
                    'info',
                    $relativeLink
                );
            } catch (Throwable $notificationError) {
                error_log('Packaging alert notification error: ' . $notificationError->getMessage());
            }
        }
    } catch (Throwable $updateError) {
        error_log('Packaging alert update error: ' . $updateError->getMessage());
    }
}

/**
 * إنشاء ملف PDF بسيط يحتوي على العناصر منخفضة الكمية
 *
 * @param array<int, array<string, mixed>> $items
 * @return string|null
 */
function packagingAlertGeneratePdf(array $items): ?string {
    $reportsDir = rtrim(REPORTS_PATH, '/\\') . '/alerts';
    if (!is_dir($reportsDir)) {
        @mkdir($reportsDir, 0755, true);
    }
    if (!is_dir($reportsDir) || !is_writable($reportsDir)) {
        error_log('Packaging alert reports directory not writable: ' . $reportsDir);
        return null;
    }

    $filename = sprintf('packaging-low-stock-%s.pdf', date('Ymd-His'));
    $filePath = $reportsDir . '/' . $filename;

    $title = 'تقرير أدوات التعبئة منخفضة الكمية';
    $timestamp = date('Y-m-d H:i');
    $thresholdLine = 'الحد الأدنى للتنبيه: أقل من ' . PACKAGING_ALERT_THRESHOLD . ' قطعة';

    // لتغيير البيانات المعروضة في التقرير، عدل بناء متغير $rowsHtml أدناه.
    $rowsHtml = '';
    foreach ($items as $item) {
        $name = htmlspecialchars(trim((string)($item['name'] ?? 'غير محدد')), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars(trim((string)($item['type'] ?? 'غير محدد')), ENT_QUOTES, 'UTF-8');
        $unit = htmlspecialchars(trim((string)($item['unit'] ?? 'قطعة')), ENT_QUOTES, 'UTF-8');
        $quantity = $item['quantity'];

        if (is_numeric($quantity)) {
            $quantity = rtrim(rtrim(number_format((float)$quantity, 3, '.', ''), '0'), '.');
        } else {
            $quantity = (string)$quantity;
        }

        $rowsHtml .= '<tr><td>' . $name . '</td><td>' . $type . '</td><td>' . htmlspecialchars($quantity, ENT_QUOTES, 'UTF-8') . ' ' . $unit . '</td></tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="3" class="empty">لا توجد عناصر منخفضة حالياً.</td></tr>';
    }

    $styles = '
        @page { margin: 18mm 15mm; }
        body { font-family: "Amiri", "Cairo", "Segoe UI", Tahoma, sans-serif; direction: rtl; text-align: right; margin:0; background:#f8fafc; color:#0f172a; }
        /* لتغيير الخط العربي، استبدل أسماء الخطوط في السطر أعلاه أو فعّل رابط Google Fonts داخل الوسم <head>. */
        .report { padding:32px; background:#ffffff; border-radius:16px; box-shadow:0 12px 40px rgba(15,23,42,0.08); }
        header { text-align:center; margin-bottom:24px; }
        header h1 { margin:0; font-size:26px; color:#1d4ed8; }
        header .meta { display:flex; justify-content:center; gap:16px; flex-wrap:wrap; margin-top:12px; color:#475569; font-size:14px; }
        header .meta span { background:#e2e8f0; padding:6px 14px; border-radius:999px; }
        .threshold { margin-bottom:24px; padding:16px; background:#f1f5f9; border-radius:14px; color:#1e293b; font-size:15px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; border:1px solid #e2e8f0; font-size:14px; }
        th { background:#1d4ed8; color:#ffffff; font-size:15px; }
        tr:nth-child(even) td { background:#f8fafc; }
        .empty { text-align:center; padding:36px 0; color:#64748b; font-style:italic; font-size:15px; }
    ';

    $fontHint = '<!-- لإضافة خط عربي من Google Fonts، يمكنك استخدام الرابط التالي (أزل التعليق إذا لزم الأمر):
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap">
-->';

    $body = '<div class="report"><header><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<div class="meta"><span>' . htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span>' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') . '</span></div></header>'
        . '<div class="threshold">' . htmlspecialchars($thresholdLine, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<table><thead><tr><th>اسم الأداة</th><th>النوع</th><th>الكمية الحالية</th></tr></thead><tbody>'
        . $rowsHtml . '</tbody></table></div>';

    $document = '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><meta name="viewport" content="width=device-width, initial-scale=1">'
        . $fontHint . '<style>' . $styles . '</style></head><body>' . $body . '</body></html>';

    try {
        apdfSavePdfToPath($document, $filePath, [
            'landscape' => false,
            'preferCSSPageSize' => true,
        ]);
    } catch (Throwable $e) {
        error_log('Packaging alert aPDF.io error: ' . $e->getMessage());
        return null;
    }

    return $filePath;
}


