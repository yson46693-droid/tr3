<?php
/**
 * نظام تصدير مبسط - PDF, Excel, CSV
 * يعمل دائماً بدون مكتبات خارجية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/path_helper.php';

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
 * @return array<string, mixed> تفاصيل ملف التقرير (مسار HTML وروابط العرض)
 * @throws Exception
 */
function exportPDF($data, $title, $filters = [])
{
    $language = getCurrentLanguage();
    $dir = $language === 'ar' ? 'rtl' : 'ltr';

    $baseReportsDir = rtrim(
        defined('REPORTS_PRIVATE_PATH')
            ? REPORTS_PRIVATE_PATH
            : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__) . '/reports/')),
        '/\\'
    );

    ensurePrivateDirectory($baseReportsDir);

    $reportsDir = $baseReportsDir . DIRECTORY_SEPARATOR . 'exports';
    ensurePrivateDirectory($reportsDir);

    if (!is_dir($reportsDir) || !is_writable($reportsDir)) {
        throw new Exception('تعذر الوصول إلى مجلد التقارير. يرجى التحقق من الصلاحيات.');
    }

    $headers = [];
    if (!empty($data) && is_array($data)) {
        $firstRow = reset($data);
        if (is_array($firstRow)) {
            $headers = array_keys($firstRow);
        }
    }

    $totalRows = is_array($data) ? count($data) : 0;

    $safeTitle = trim(sanitizeFileName($title));
    $safeTitle = $safeTitle !== '' ? $safeTitle : 'report';
    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $tokenError) {
        $token = sha1($safeTitle . microtime(true) . mt_rand());
        error_log('exportPDF: random_bytes failed, fallback token used - ' . $tokenError->getMessage());
    }

    $fileName = $safeTitle . '-' . date('Ymd-His') . '-' . $token . '.html';
    $filePath = $reportsDir . DIRECTORY_SEPARATOR . $fileName;

    $langAttr = $dir === 'rtl' ? 'ar' : 'en';
    $fontHint = '<!-- لإضافة خط عربي من Google Fonts، يمكنك استخدام الرابط التالي (أزل التعليق إذا لزم الأمر):
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap">
-->';

    $summaryItems = '<li><span class="label">عدد السجلات</span><span class="value">' . intval($totalRows) . '</span></li>';

    $filtersHtml = '';
    if (!empty($filters) && is_array($filters)) {
        $items = '';
        foreach ($filters as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $items .= '<li><strong>' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        if ($items !== '') {
            $filtersHtml = '<section class="filters"><h2>الفلاتر المستخدمة</h2><ul>' . $items . '</ul></section>';
        }
    }

    $tableHtml = '';
    if (!empty($headers)) {
        $tableHtml .= '<div class="table-wrapper"><table class="report-table"><thead><tr>';
        foreach ($headers as $column) {
            $tableHtml .= '<th>' . htmlspecialchars((string)$column, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $tableHtml .= '</tr></thead><tbody>';
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tableHtml .= '<tr>';
            foreach ($headers as $column) {
                $value = $row[$column] ?? '';
                $tableHtml .= '<td>' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $tableHtml .= '</tr>';
        }
        $tableHtml .= '</tbody></table></div>';
    } else {
        $tableHtml = '<div class="empty">لا توجد بيانات متاحة لعرضها.</div>';
    }

    $body = '<div class="report-wrapper">'
        . '<div class="actions">'
        . '<button id="printReportButton" type="button">طباعة / حفظ كـ PDF</button>'
        . '<span class="hint">يمكنك استخدام زر الطباعة أو حفظ الصفحة كـ PDF من المتصفح.</span>'
        . '</div>'
        . '<header class="report-header">'
        . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<div class="meta">'
        . '<span>' . htmlspecialchars(COMPANY_NAME, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span>تاريخ الإنشاء: ' . date('Y-m-d H:i:s') . '</span>'
        . '</div>'
        . '</header>'
        . '<section class="summary"><h2>ملخص سريع</h2><ul>' . $summaryItems . '</ul></section>'
        . $filtersHtml
        . $tableHtml
        . '</div>';

    $styles = '
        @page { margin: 16mm 12mm; }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: "Amiri", "Cairo", "Segoe UI", sans-serif; direction: ' . $dir . '; text-align: ' . ($dir === 'rtl' ? 'right' : 'left') . '; color:#0f172a; margin:0; background:#f8fafc; }
        .report-wrapper { max-width: 1024px; margin: 0 auto; padding: 32px; background:#ffffff; border-radius:18px; box-shadow:0 20px 60px rgba(15,23,42,0.12); }
        .actions { display:flex; flex-direction:column; gap:8px; align-items:flex-start; margin-bottom:24px; }
        .actions button { background:#1d4ed8; color:#ffffff; border:none; padding:11px 20px; border-radius:12px; font-size:15px; cursor:pointer; transition:opacity 0.2s ease; }
        .actions button:hover { opacity:0.92; }
        .actions .hint { font-size:13px; color:#475569; }
        .report-header { margin-bottom:24px; text-align:center; }
        .report-header h1 { margin:0 0 12px; font-size:26px; font-weight:700; color:#0f172a; }
        .report-header .meta { display:flex; gap:14px; justify-content:center; flex-wrap:wrap; }
        .report-header .meta span { background:#e2e8f0; padding:7px 16px; border-radius:999px; font-size:13px; color:#334155; }
        .summary { background:#1d4ed8; color:#ffffff; padding:20px 24px; border-radius:16px; margin-bottom:28px; }
        .summary h2 { margin:0 0 14px; font-size:18px; }
        .summary ul { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; }
        .summary li { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.15); backdrop-filter: blur(3px); font-size:15px; }
        .summary .label { font-weight:600; }
        .summary .value { font-size:18px; font-weight:700; }
        .filters { margin-bottom:24px; padding:18px 22px; background:#f1f5f9; border-radius:16px; border:1px solid #e2e8f0; }
        .filters h2 { margin:0 0 12px; font-size:16px; color:#0f172a; }
        .filters ul { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; font-size:14px; color:#1f2937; }
        .filters li strong { color:#0f172a; }
        .table-wrapper { overflow-x:auto; border-radius:14px; border:1px solid #e2e8f0; background:#ffffff; }
        .report-table { width:100%; border-collapse:collapse; min-width:720px; }
        .report-table thead th { background:#1d4ed8; color:#ffffff; padding:12px 14px; font-size:14px; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.2); }
        .report-table tbody td { padding:12px 14px; border-bottom:1px solid #e2e8f0; font-size:13px; color:#1f2937; }
        .report-table tbody tr:nth-child(even) td { background:#f8fafc; }
        .empty { padding:32px; background:#f1f5f9; border:2px dashed #cbd5f5; border-radius:16px; text-align:center; font-size:15px; color:#64748b; }
        @media print { body { background:#ffffff; } .report-wrapper { box-shadow:none; } .actions { display:none !important; } }
    ';

    $printScript = '<script>(function(){function triggerPrint(){window.print();}'
        . 'document.addEventListener("DOMContentLoaded",function(){var btn=document.getElementById("printReportButton");'
        . 'if(btn){btn.addEventListener("click",function(e){e.preventDefault();triggerPrint();});}'
        . 'var params=new URLSearchParams(window.location.search);'
        . 'if(params.get("print")==="1"){setTimeout(triggerPrint,600);}'
        . '});})();</script>';

    $document = '<!DOCTYPE html><html lang="' . $langAttr . '"><head><meta charset="utf-8">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . $fontHint
        . '<style>' . $styles . '</style></head><body>' . $body . $printScript . '</body></html>';

    if (@file_put_contents($filePath, $document) === false) {
        throw new Exception('تعذر حفظ ملف التقرير. يرجى التحقق من الصلاحيات.');
    }

    $relativePath = 'exports/' . $fileName;
    $viewerQuery = http_build_query(
        [
            'type' => 'export',
            'file' => $relativePath,
            'token' => $token,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $viewerPath = 'reports/view.php?' . $viewerQuery;
    $reportUrl = getRelativeUrl($viewerPath);
    $absoluteReportUrl = getAbsoluteUrl($viewerPath);
    $printUrl = $reportUrl . (str_contains($reportUrl, '?') ? '&' : '?') . 'print=1';
    $absolutePrintUrl = $absoluteReportUrl . (str_contains($absoluteReportUrl, '?') ? '&' : '?') . 'print=1';

    return [
        'file_path' => $filePath,
        'relative_path' => $relativePath,
        'viewer_path' => $viewerPath,
        'report_url' => $reportUrl,
        'absolute_report_url' => $absoluteReportUrl,
        'print_url' => $printUrl,
        'absolute_print_url' => $absolutePrintUrl,
        'token' => $token,
        'title' => $title,
        'generated_at' => date('Y-m-d H:i:s'),
        'total_rows' => $totalRows,
        'filters' => $filters,
        'headers' => $headers,
    ];
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

