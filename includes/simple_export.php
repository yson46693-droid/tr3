<?php
/**
 * نظام تصدير مبسط - PDF, Excel, CSV
 * يعمل دائماً بدون مكتبات خارجية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pdf_helper.php';

// التأكد من وجود الدالة getCurrentLanguage
if (!function_exists('getCurrentLanguage')) {
    function getCurrentLanguage() {
        return $_SESSION['language'] ?? DEFAULT_LANGUAGE;
    }
}

/**
 * تصدير PDF باستخدام خدمة aPDF.io مع دعم كامل للعربية و RTL
 *
 * @param array<int, array<string, mixed>> $data
 * @param string $title
 * @param array<string, mixed> $filters
 * @return string مسار ملف PDF الناتج
 * @throws Exception
 */
function exportPDF($data, $title, $filters = [])
{
    $language = getCurrentLanguage();
    $dir = $language === 'ar' ? 'rtl' : 'ltr';

    $reportsDir = rtrim(REPORTS_PATH, '/\\');
    if (!file_exists($reportsDir) && !@mkdir($reportsDir, 0755, true)) {
        throw new Exception('فشل في إنشاء مجلد التقارير. يرجى التحقق من الصلاحيات.');
    }
    if (!is_writable($reportsDir)) {
        throw new Exception('مجلد التقارير غير قابل للكتابة. يرجى التحقق من الصلاحيات.');
    }

    $headers = [];
    if (!empty($data) && is_array($data)) {
        $firstRow = reset($data);
        if (is_array($firstRow)) {
            $headers = array_keys($firstRow);
        }
    }

    // لتغيير محتوى التقرير، عدل الأقسام المبنية داخل المتغير $html أدناه.
    $html = '<div class="report-wrapper">';
    $html .= '<header class="report-header">';
    $html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $html .= '<div class="meta"><span>' . htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<span>' . date('Y-m-d H:i:s') . '</span></div>';
    $html .= '</header>';

    if (!empty($filters)) {
        $html .= '<section class="filters"><h2>الفلاتر</h2><ul>';
        foreach ($filters as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $html .= '<li><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ':</strong> ' .
                htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul></section>';
    }

    if (!empty($headers)) {
        $html .= '<table class="report-table"><thead><tr>';
        foreach ($headers as $column) {
            $html .= '<th>' . htmlspecialchars((string)$column, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr>';
            foreach ($headers as $column) {
                $value = $row[$column] ?? '';
                $html .= '<td>' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<div class="empty">لا توجد بيانات متاحة</div>';
    }

    $html .= '</div>';

    $styles = '
        @page { margin: 15mm 12mm; }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: "Amiri", "Cairo", "DejaVu Sans", sans-serif; direction: ' . $dir . '; text-align: ' . ($dir === 'rtl' ? 'right' : 'left') . '; color:#1f2937; margin:0; background:#ffffff; }
        /* لتغيير الخط العربي، استبدل أسماء الخطوط في السطر أعلاه أو فعّل رابط Google Fonts داخل الوسم <head>. */
        .report-wrapper { padding: 24px; }
        .report-header { border-bottom: 3px solid #1d4ed8; margin-bottom: 20px; padding-bottom: 16px; text-align: center; }
        .report-header h1 { margin: 0 0 8px; font-size: 24px; color:#0f172a; }
        .report-header .meta { display:flex; gap:12px; justify-content:center; font-size:13px; color:#475569; }
        .report-header .meta span { background:#e2e8f0; padding:6px 12px; border-radius:999px; }
        .filters { margin: 24px 0; padding: 16px 20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; }
        .filters h2 { margin:0 0 10px; font-size:16px; color:#0f172a; }
        .filters ul { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; font-size:14px; }
        .report-table { width:100%; border-collapse:collapse; margin-top:16px; font-size:13px; }
        .report-table thead th { background:#1d4ed8; color:#fff; padding:12px; border:1px solid #1d4ed8; }
        .report-table tbody td { padding:10px 12px; border:1px solid #cbd5f5; background:#fff; }
        .report-table tbody tr:nth-child(even) td { background:#f1f5f9; }
        .empty { padding:24px; background:#f8fafc; border:1px dashed #cbd5f5; border-radius:12px; text-align:center; font-size:14px; color:#64748b; }
    ';

    $fileName = sanitizeFileName($title) . '_' . date('Y-m-d_His') . '.pdf';
    $filePath = $reportsDir . DIRECTORY_SEPARATOR . $fileName;
    $langAttr = $dir === 'rtl' ? 'ar' : 'en';
    $fontHint = '<!-- لإضافة خط عربي من Google Fonts، يمكنك استخدام الرابط التالي (أزل التعليق إذا لزم الأمر):
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap">
    -->';

    $document = '<!DOCTYPE html><html lang="' . $langAttr . '"><head><meta charset="utf-8">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . $fontHint
        . '<style>' . $styles . '</style></head><body>' . $html . '</body></html>';

    apdfSavePdfToPath($document, $filePath, [
        'landscape' => false,
        'preferCSSPageSize' => true,
    ]);

    return $filePath;
}

/**
 * تصدير Excel/CSV
 */
function exportCSV($data, $title, $filters = []) {
    // تغيير الامتداد إلى CSV
    $fileName = sanitizeFileName($title) . '_' . date('Y-m-d_His') . '.csv';
    $filePath = REPORTS_PATH . $fileName;
    
    // التأكد من وجود المجلد
    $reportsDir = rtrim(REPORTS_PATH, '/\\');
    if (!file_exists($reportsDir)) {
        if (!@mkdir($reportsDir, 0755, true)) {
            error_log("Failed to create reports directory for CSV: " . $reportsDir);
            error_log("Current working directory: " . getcwd());
            error_log("REPORTS_PATH: " . REPORTS_PATH);
            throw new Exception('فشل في إنشاء مجلد التقارير. يرجى التحقق من الصلاحيات.');
        }
    }
    
    // التحقق من صلاحيات الكتابة
    if (!is_writable($reportsDir)) {
        error_log("Reports directory is not writable for CSV: " . $reportsDir);
        throw new Exception('مجلد التقارير غير قابل للكتابة. يرجى التحقق من الصلاحيات.');
    }
    
    // فتح الملف للكتابة
    $output = @fopen($filePath, 'w');
    if ($output === false) {
        $error = error_get_last();
        error_log("Failed to open CSV file for writing: " . ($error['message'] ?? 'Unknown error'));
        error_log("File path: " . $filePath);
        throw new Exception('فشل في فتح ملف CSV للكتابة. يرجى التحقق من الصلاحيات.');
    }
    
    try {
        // إضافة BOM للUTF-8 (للعربية)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // العنوان
        fputcsv($output, [$title], ',');
        fputcsv($output, [COMPANY_NAME], ',');
        fputcsv($output, ['تاريخ التقرير: ' . date('Y-m-d H:i:s')], ',');
        fputcsv($output, [], ','); // سطر فارغ
        
        // الفلاتر
        if (!empty($filters)) {
            fputcsv($output, ['الفلاتر:'], ',');
            foreach ($filters as $key => $value) {
                if (!empty($value)) {
                    fputcsv($output, [$key . ': ' . $value], ',');
                }
            }
            fputcsv($output, [], ','); // سطر فارغ
        }
        
        // البيانات
        if (!empty($data) && is_array($data) && count($data) > 0) {
            $headers = array_keys($data[0]);
            fputcsv($output, $headers, ',');
            
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $values = [];
                foreach ($headers as $header) {
                    $values[] = $row[$header] ?? '';
                }
                fputcsv($output, $values, ',');
            }
        } else {
            fputcsv($output, ['الرسالة'], ',');
            fputcsv($output, ['لا توجد بيانات متاحة في الفترة المحددة'], ',');
        }
        
        fclose($output);
        
        // التحقق من أن الملف تم إنشاؤه
        if (!file_exists($filePath)) {
            error_log("CSV file was not created: " . $filePath);
            throw new Exception('فشل في إنشاء ملف CSV.');
        }
        
        if (filesize($filePath) === 0) {
            error_log("CSV file is empty: " . $filePath);
            throw new Exception('ملف CSV فارغ. لا توجد بيانات للتصدير.');
        }
        
        error_log("CSV report created successfully: " . $filePath . " (" . filesize($filePath) . " bytes)");
        
        return $filePath;
    } catch (Exception $e) {
        error_log("CSV export error: " . $e->getMessage());
        if (isset($output) && is_resource($output)) {
            @fclose($output);
        }
        if (isset($filePath) && file_exists($filePath)) {
            @unlink($filePath);
        }
        throw $e;
    }
}

/**
 * تنظيف اسم الملف
 */
function sanitizeFileName($fileName) {
    // إزالة الأحرف غير المسموحة
    $fileName = preg_replace('/[^a-zA-Z0-9_\x{0600}-\x{06FF}\s-]/u', '', $fileName);
    // استبدال المسافات بشرطة سفلية
    $fileName = preg_replace('/\s+/', '_', $fileName);
    return $fileName;
}

