<?php
/**
 * تنبيهات أدوات التعبئة اليومية عبر Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/simple_telegram.php';

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
    $processed = true;

    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return;
    }

    if (!isTelegramConfigured()) {
        return;
    }

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

    if (!$sent) {
        return;
    }

    try {
        if ($jobState) {
            $db->execute(
                "UPDATE system_daily_jobs SET last_sent_at = NOW(), last_file_path = ?, updated_at = NOW() WHERE job_key = ?",
                [$pdfPath, PACKAGING_ALERT_JOB_KEY]
            );
        } else {
            $db->execute(
                "INSERT INTO system_daily_jobs (job_key, last_sent_at, last_file_path) VALUES (?, NOW(), ?)",
                [PACKAGING_ALERT_JOB_KEY, $pdfPath]
            );
        }

        if (function_exists('createNotificationForRole')) {
            try {
                $relativeLink = null;
                if (defined('BASE_PATH') && function_exists('getRelativeUrl')) {
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

    $lines = [
        $title,
        'التاريخ: ' . $timestamp,
        $thresholdLine,
        str_repeat('-', 90),
        'القائمة:',
    ];

    foreach ($items as $item) {
        $name = trim((string)($item['name'] ?? ''));
        $type = trim((string)($item['type'] ?? ''));
        $unit = trim((string)($item['unit'] ?? 'قطعة'));
        $quantity = $item['quantity'];

        if (is_numeric($quantity)) {
            $quantity = (float)$quantity;
            $quantity = rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
        } else {
            $quantity = (string)$quantity;
        }

        $lines[] = sprintf('• %s — النوع: %s — الكمية الحالية: %s %s', $name, $type ?: 'غير محدد', $quantity, $unit ?: 'قطعة');
    }

    $pdfContent = packagingAlertBuildPdf($lines);
    if ($pdfContent === null) {
        return null;
    }

    $bytes = @file_put_contents($filePath, $pdfContent);
    if ($bytes === false || $bytes === 0) {
        error_log('Packaging alert unable to write PDF: ' . $filePath);
        return null;
    }

    return $filePath;
}

/**
 * توليد محتوى PDF بسيط لنصوص متعددة الأسطر
 *
 * @param array<int, string> $lines
 * @return string|null
 */
function packagingAlertBuildPdf(array $lines): ?string {
    if (empty($lines)) {
        return null;
    }

    $pageWidth = 595.0;
    $pageHeight = 842.0;
    $marginX = 40.0;
    $startY = $pageHeight - 60.0;
    $lineSpacing = 24.0;

    $contentParts = [];
    $yPos = $startY;
    foreach ($lines as $index => $line) {
        if ($yPos < 60.0) {
            break;
        }

        $fontSize = $index === 0 ? 20 : ($index <= 2 ? 14 : 12);
        $prepared = packagingAlertNormalizePdfText($line);
        $hex = strtoupper(bin2hex(packagingAlertUtf16beText($prepared, true)));

        $contentParts[] = "BT\n/F1 {$fontSize} Tf\n1 0 0 1 " . ($pageWidth - $marginX) . " {$yPos} Tm\n<{$hex}> Tj\nET\n";
        $yPos -= $lineSpacing;
    }

    $contentStream = implode('', $contentParts);
    $contentLength = strlen($contentStream);

    $toUnicode = packagingAlertBuildToUnicodeCMap();

    $objects = [];
    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
    $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
    $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents 7 0 R /Resources << /Font << /F1 4 0 R >> >> >> endobj\n";
    $objects[] = "4 0 obj << /Type /Font /Subtype /Type0 /BaseFont /TimesNewRomanPSMT /Encoding /Identity-H /DescendantFonts [5 0 R] /ToUnicode 6 0 R >> endobj\n";
    $objects[] = "5 0 obj << /Type /Font /Subtype /CIDFontType2 /BaseFont /TimesNewRomanPSMT /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 8 0 R /CIDToGIDMap /Identity /DW 1000 >> endobj\n";
    $objects[] = "6 0 obj << /Length " . strlen($toUnicode) . " >> stream\n{$toUnicode}endstream\nendobj\n";
    $objects[] = "7 0 obj << /Length {$contentLength} >> stream\n{$contentStream}endstream\nendobj\n";
    $objects[] = "8 0 obj << /Type /FontDescriptor /FontName /TimesNewRomanPSMT /Flags 32 /ItalicAngle 0 /Ascent 1024 /Descent -300 /CapHeight 800 /StemV 80 /FontBBox [-1024 -1024 3072 3072] >> endobj\n";

    $pdf = "%PDF-1.7\n";
    $offsets = [];
    $currentOffset = strlen($pdf);

    foreach ($objects as $object) {
        $offsets[] = $currentOffset;
        $pdf .= $object;
        $currentOffset += strlen($object);
    }

    $xrefPosition = $currentOffset;
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPosition}\n%%EOF";

    return $pdf;
}

function packagingAlertNormalizePdfText(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function packagingAlertUtf16beText(string $text, bool $withBom = false): string {
    $result = $withBom ? "\xFE\xFF" : '';
    $directional = "\u{202B}" . $text . "\u{202C}";
    $result .= mb_convert_encoding($directional, 'UTF-16BE', 'UTF-8');
    return $result;
}

function packagingAlertBuildToUnicodeCMap(): string {
    return <<<CMAP
/CIDInit /ProcSet findresource begin
12 dict begin
begincmap
/CIDSystemInfo
<< /Registry (Adobe)
   /Ordering (Identity)
   /Supplement 0
>> def
/CMapName /Adobe-Identity-UCS def
/CMapType 2 def
1 begincodespacerange
<0000> <FFFF>
endcodespacerange
1 beginbfrange
<0000> <FFFF> <0000>
endbfrange
endcmap
CMapName currentdict /CMap defineresource pop
end
end
CMAP;
}


