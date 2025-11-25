<?php
/**
 * API لتوليد التقارير PDF/Excel
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/production_helper.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/telegram_config.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole(['accountant', 'sales', 'production', 'manager']);

$currentUser = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// معالجة طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // تفعيل تسجيل الأخطاء ولكن عدم عرضها للمستخدم
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // ضمان إرجاع JSON دائماً
    header('Content-Type: application/json; charset=utf-8');
    
    // معالجة الأخطاء بشكل صحيح
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
    
    try {
        if ($action === 'generate_pdf' || $action === 'generate_excel') {
            $reportType = $_POST['type'] ?? '';
            $format = $action === 'generate_pdf' ? 'pdf' : 'excel';
            $filtersJson = $_POST['filters'] ?? '{}';
            $filters = json_decode($filtersJson, true) ?: [];
            
            if (empty($reportType)) {
                echo json_encode(['success' => false, 'error' => 'نوع التقرير مطلوب']);
                exit;
            }
        } elseif ($action === 'send_telegram') {
        $reportType = $_POST['type'] ?? '';
        $reportName = $_POST['report_name'] ?? '';
        $payloadJson = $_POST['payload'] ?? '';
        $payload = [];
        if ($payloadJson !== '') {
            $decodedPayload = json_decode($payloadJson, true);
            if (is_array($decodedPayload)) {
                $payload = $decodedPayload;
            }
        }

        $reportArray = null;
        $relativePath = '';
        if (isset($payload['relative_path'])) {
            $relativePath = ltrim(str_replace('\\', '/', (string)$payload['relative_path']), '/');
            if ($relativePath !== '' && !preg_match('#\.\.[/\\\\]#', $relativePath)) {
                $fullPath = rtrim(
                    defined('REPORTS_PRIVATE_PATH')
                        ? REPORTS_PRIVATE_PATH
                        : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__) . '/reports')),
                    '/\\'
                ) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

                if (is_file($fullPath)) {
                    $viewerPath = (string)($payload['viewer_path'] ?? '');
                    $reportUrl = (string)($payload['file_path'] ?? $payload['report_url'] ?? $viewerPath);

                    $absoluteReportUrl = (string)($payload['absolute_report_url'] ?? '');
                    if ($absoluteReportUrl === '' && $viewerPath !== '') {
                        $absoluteReportUrl = getAbsoluteUrl(ltrim($viewerPath, '/'));
                    }
                    if ($absoluteReportUrl === '' && $reportUrl !== '') {
                        $absoluteReportUrl = getAbsoluteUrl(ltrim($reportUrl, '/'));
                    }

                    $printUrl = (string)($payload['print_url'] ?? '');
                    if ($printUrl === '' && $reportUrl !== '') {
                        $printUrl = $reportUrl . (strpos($reportUrl, '?') !== false ? '&' : '?') . 'print=1';
                    }
                    $absolutePrintUrl = (string)($payload['absolute_print_url'] ?? '');
                    if ($absolutePrintUrl === '' && $absoluteReportUrl !== '') {
                        $absolutePrintUrl = $absoluteReportUrl . (strpos($absoluteReportUrl, '?') !== false ? '&' : '?') . 'print=1';
                    }

                    $reportArray = [
                        'file_path' => $fullPath,
                        'relative_path' => $relativePath,
                        'report_url' => $reportUrl,
                        'absolute_report_url' => $absoluteReportUrl,
                        'print_url' => $printUrl ?: $reportUrl,
                        'absolute_print_url' => $absolutePrintUrl ?: $absoluteReportUrl,
                        'token' => $payload['token'] ?? '',
                        'total_rows' => $payload['total_rows'] ?? 0,
                        'generated_at' => $payload['generated_at'] ?? date('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        if ($reportArray !== null) {
            $sendResult = sendReportAndDelete($reportArray, $reportType, $reportName);
            echo json_encode($sendResult, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $filePath = $_POST['file_path'] ?? '';
        if (empty($filePath) || !file_exists($filePath)) {
            echo json_encode(['success' => false, 'error' => 'الملف غير موجود']);
            exit;
        }

        try {
            $result = sendReportAndDelete($filePath, $reportType, $reportName);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            error_log("Telegram send error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'فشل في إرسال التقرير إلى Telegram']);
        }
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'إجراء غير صحيح']);
        exit;
    }
    } catch (Exception $e) {
        // معالجة أي أخطاء في معالجة POST
        error_log("POST processing error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'حدث خطأ في معالجة الطلب'
        ]);
        exit;
    }
} else {
    // معالجة طلبات GET (للتوافق مع الكود القديم)
    $reportType = $_GET['type'] ?? '';
    $format = $_GET['format'] ?? 'pdf';
    
    if (empty($reportType)) {
        die('نوع التقرير مطلوب');
    }
    
    $filters = [
        'user_id' => $_GET['user_id'] ?? '',
        'product_id' => $_GET['product_id'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'customer_id' => $_GET['customer_id'] ?? '',
        'status' => $_GET['status'] ?? ''
    ];
}

// تنظيف الفلاتر (فقط إذا كان array)
if (is_array($filters)) {
    $filters = array_filter($filters, function($value) {
        return $value !== '' && $value !== null;
    });
} else {
    $filters = [];
}

try {
    $reportResult = null;
    $reportTitle = '';
    
    switch ($reportType) {
        case 'productivity':
            // تقرير الإنتاجية
            try {
                $productivityData = getProductivityReport(
                    !empty($filters['user_id']) ? intval($filters['user_id']) : null,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
                
                // تحويل البيانات للتقرير
                $reportData = [];
                if (!empty($productivityData) && is_array($productivityData)) {
                    foreach ($productivityData as $item) {
                        $reportData[] = [
                            'التاريخ' => formatDate($item['date'] ?? ''),
                            'المنتج' => $item['product_name'] ?? '-',
                            'العامل' => $item['user_name'] ?? '-',
                            'الكمية' => number_format(cleanFinancialValue($item['quantity'] ?? 0), 2),
                            'عدد المواد' => $item['materials_count'] ?? 0,
                            'التكلفة' => formatCurrency(cleanFinancialValue($item['total_cost'] ?? 0))
                        ];
                    }
                }
                
                // إذا لم تكن هناك بيانات، إضافة رسالة
                if (empty($reportData)) {
                    $reportData[] = [
                        'الرسالة' => 'لا توجد بيانات متاحة في الفترة المحددة'
                    ];
                }
                
                $reportTitle = 'تقرير الإنتاجية';
                
                if ($format === 'pdf') {
                    $reportResult = generatePDFReport('productivity', $reportData, $reportTitle, $filters);
                } else {
                    $reportResult = generateExcelReport('productivity', $reportData, $reportTitle, $filters);
                }
            } catch (Exception $e) {
                error_log("Productivity report error: " . $e->getMessage());
                throw new Exception('خطأ في توليد تقرير الإنتاجية: ' . $e->getMessage());
            }
            break;
            
        case 'invoices':
            // تقرير الفواتير
            try {
                $invoices = getInvoices($filters, 1000, 0);
                
                $reportData = [];
                if (!empty($invoices) && is_array($invoices)) {
                    foreach ($invoices as $invoice) {
                        $totalAmount = cleanFinancialValue($invoice['total_amount'] ?? 0);
                        $paidAmount = cleanFinancialValue($invoice['paid_amount'] ?? 0);
                        $reportData[] = [
                            'رقم الفاتورة' => $invoice['invoice_number'] ?? '-',
                            'العميل' => $invoice['customer_name'] ?? '-',
                            'التاريخ' => formatDate($invoice['date'] ?? ''),
                            'المبلغ الإجمالي' => formatCurrency($totalAmount),
                            'المدفوع' => formatCurrency($paidAmount),
                            'المتبقي' => formatCurrency($totalAmount - $paidAmount),
                            'الحالة' => $invoice['status'] ?? '-'
                        ];
                    }
                }
                
                // إذا لم تكن هناك بيانات، إضافة رسالة
                if (empty($reportData)) {
                    $reportData[] = [
                        'الرسالة' => 'لا توجد فواتير متاحة في الفترة المحددة'
                    ];
                }
                
                $reportTitle = 'تقرير الفواتير';
                
                if ($format === 'pdf') {
                    $reportResult = generatePDFReport('invoices', $reportData, $reportTitle, $filters);
                } else {
                    $reportResult = generateExcelReport('invoices', $reportData, $reportTitle, $filters);
                }
            } catch (Exception $e) {
                error_log("Invoices report error: " . $e->getMessage());
                throw new Exception('خطأ في توليد تقرير الفواتير: ' . $e->getMessage());
            }
            break;
            
        case 'sales':
            // تقرير المبيعات
            $db = db();
            $sales = $db->query(
                "SELECT s.*, c.name as customer_name, u.full_name as sales_rep_name
                 FROM sales s
                 LEFT JOIN customers c ON s.customer_id = c.id
                 LEFT JOIN users u ON s.salesperson_id = u.id
                 WHERE 1=1" . 
                 (!empty($filters['customer_id']) ? " AND s.customer_id = " . intval($filters['customer_id']) : '') .
                 (!empty($filters['salesperson_id']) ? " AND s.salesperson_id = " . intval($filters['salesperson_id']) : '') .
                 (!empty($filters['sales_rep_id']) ? " AND s.salesperson_id = " . intval($filters['sales_rep_id']) : '') .
                 (!empty($filters['date_from']) ? " AND DATE(s.date) >= '" . $db->escape($filters['date_from']) . "'" : '') .
                 (!empty($filters['date_to']) ? " AND DATE(s.date) <= '" . $db->escape($filters['date_to']) . "'" : '') .
                 " ORDER BY s.date DESC LIMIT 1000"
            );
            
            $reportData = [];
            foreach ($sales as $sale) {
                $reportData[] = [
                    'التاريخ' => formatDate($sale['date']),
                    'العميل' => $sale['customer_name'] ?? '-',
                    'مندوب المبيعات' => $sale['sales_rep_name'] ?? '-',
                    'المبلغ الإجمالي' => formatCurrency(cleanFinancialValue($sale['total'] ?? 0)),
                    'الحالة' => $sale['status']
                ];
            }
            
            $reportTitle = 'تقرير المبيعات';
            
            if ($format === 'pdf') {
                $reportResult = generatePDFReport('sales', $reportData, $reportTitle, $filters);
            } else {
                $reportResult = generateExcelReport('sales', $reportData, $reportTitle, $filters);
            }
            break;
            
        case 'financial':
            // تقرير مالي شامل
            $db = db();
            
            // الحصول على المعاملات المالية
            $whereConditions = [];
            $params = [];
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "DATE(created_at) >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "DATE(created_at) <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['status'])) {
                $whereConditions[] = "status = ?";
                $params[] = $filters['status'];
            }
            
            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
            
            $transactions = $db->query(
                "SELECT ft.*, u.username as created_by_name, u2.username as approved_by_name, s.name as supplier_name
                 FROM financial_transactions ft
                 LEFT JOIN users u ON ft.created_by = u.id
                 LEFT JOIN users u2 ON ft.approved_by = u2.id
                 LEFT JOIN suppliers s ON ft.supplier_id = s.id
                 $whereClause
                 ORDER BY ft.created_at DESC LIMIT 1000",
                $params
            );
            
            $reportData = [];
            foreach ($transactions as $trans) {
                $reportData[] = [
                    'التاريخ' => formatDate($trans['created_at']),
                    'النوع' => $trans['type'],
                    'المبلغ' => formatCurrency(cleanFinancialValue($trans['amount'] ?? 0)),
                    'المورد' => $trans['supplier_name'] ?? '-',
                    'الوصف' => $trans['description'],
                    'الحالة' => $trans['status'],
                    'أنشأ بواسطة' => $trans['created_by_name'] ?? '-',
                    'وافق بواسطة' => $trans['approved_by_name'] ?? '-'
                ];
            }
            
            $reportTitle = 'التقرير المالي';
            
            if ($format === 'pdf') {
                $reportResult = generatePDFReport('financial', $reportData, $reportTitle, $filters);
            } else {
                $reportResult = generateExcelReport('financial', $reportData, $reportTitle, $filters);
            }
            break;
            
        default:
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo json_encode(['success' => false, 'error' => 'نوع التقرير غير صحيح']);
                exit;
            }
            die('نوع التقرير غير صحيح');
    }
    
    $reportFilePath = null;
    $reportIsArray = is_array($reportResult);
    if ($format === 'pdf' && $reportIsArray) {
        $reportFilePath = $reportResult['file_path'] ?? null;
    } else {
        $reportFilePath = $reportResult;
    }

    if ($reportFilePath && file_exists($reportFilePath)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($format === 'pdf' && $reportIsArray) {
                echo json_encode([
                    'success' => true,
                    'file_path' => $reportResult['report_url'] ?? '',
                    'viewer_path' => $reportResult['viewer_path'] ?? '',
                    'absolute_report_url' => $reportResult['absolute_report_url'] ?? '',
                    'print_url' => $reportResult['print_url'] ?? ($reportResult['report_url'] ?? ''),
                    'absolute_print_url' => $reportResult['absolute_print_url'] ?? ($reportResult['absolute_report_url'] ?? ''),
                    'relative_path' => $reportResult['relative_path'] ?? '',
                    'report_name' => $reportTitle,
                    'token' => $reportResult['token'] ?? '',
                    'message' => 'تم توليد التقرير بنجاح (HTML قابل للعرض والطباعة)',
                    'file_type' => 'html',
                    'is_html' => true,
                    'is_csv' => false,
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $fileUrl = str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', $reportFilePath);
                $fileUrl = str_replace('\\', '/', $fileUrl);
                if (!str_starts_with($fileUrl, '/')) {
                    $fileUrl = '/' . $fileUrl;
                }
                $fileUrl = preg_replace('#/+#', '/', $fileUrl);
                $extension = strtolower(pathinfo($reportFilePath, PATHINFO_EXTENSION));
                $isCsv = ($extension === 'csv');

                echo json_encode([
                    'success' => true,
                    'file_path' => $fileUrl,
                    'report_name' => $reportTitle,
                    'message' => 'تم توليد التقرير بنجاح' . ($isCsv ? ' (ملف CSV)' : ''),
                    'file_type' => $extension,
                    'is_html' => false,
                    'is_csv' => $isCsv
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        if ($format === 'pdf' && $reportIsArray) {
            if (isTelegramConfigured() && isset($_GET['send_telegram']) && $_GET['send_telegram'] == '1') {
                sendReportAndDelete($reportResult, $reportType, $reportTitle);
            }

            header('Location: ' . ($reportResult['report_url'] ?? '/'));
            exit;
        }

        // تنزيل الملفات غير HTML (Excel/CSV)
        $fileName = basename($reportFilePath);
        $extension = strtolower(pathinfo($reportFilePath, PATHINFO_EXTENSION));
        $mimeType = $extension === 'csv'
            ? 'text/csv'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($reportFilePath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        readfile($reportFilePath);

        if (REPORTS_AUTO_DELETE && !isset($_GET['send_telegram'])) {
            @unlink($reportFilePath);
        }

        exit;
    } else {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo json_encode(['success' => false, 'error' => 'فشل في إنشاء التقرير']);
            exit;
        }
        die('فشل في إنشاء التقرير');
    }
    
} catch (Exception $e) {
    // تسجيل تفاصيل الخطأ
    error_log("Report Generation Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("Report Type: " . ($reportType ?? 'unknown'));
    error_log("Format: " . ($format ?? 'unknown'));
    error_log("Report Result: " . (is_array($reportResult ?? null) ? json_encode($reportResult) : ($reportResult ?? 'not generated')));
    
    // التحقق من وجود مجلد التقارير
    if (defined('REPORTS_PATH')) {
        error_log("Reports Path: " . REPORTS_PATH);
        error_log("Reports Path exists: " . (file_exists(REPORTS_PATH) ? 'yes' : 'no'));
        error_log("Reports Path writable: " . (is_writable(REPORTS_PATH) ? 'yes' : 'no'));
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // ضمان إرجاع JSON دائماً
        header('Content-Type: application/json; charset=utf-8');
        
        // إرجاع رسالة خطأ مع تفاصيل أكثر للتشخيص
        $errorMessage = 'حدث خطأ في إنشاء التقرير';
        
        // إضافة معلومات مفيدة للمستخدم
        $errorDetails = $e->getMessage();
        if (strpos($errorDetails, 'Permission denied') !== false || strpos($errorDetails, 'No such file') !== false || strpos($errorDetails, 'not writable') !== false) {
            $errorMessage = 'خطأ في إنشاء مجلد التقارير. يرجى التحقق من صلاحيات الملفات.';
        } elseif (strpos($errorDetails, 'Class') !== false && strpos($errorDetails, 'not found') !== false) {
            $errorMessage = 'خطأ في تحميل مكتبة التقارير. يرجى التحقق من تثبيت المكتبات المطلوبة.';
        } elseif (strpos($errorDetails, 'empty') !== false || strpos($errorDetails, 'فارغ') !== false) {
            $errorMessage = 'الملف المُنشأ فارغ. يرجى التحقق من البيانات والمسار.';
        } elseif (strpos($errorDetails, 'Failed to') !== false || strpos($errorDetails, 'فشل') !== false) {
            $errorMessage = 'فشل في إنشاء الملف. يرجى التحقق من المسار والصلاحيات.';
        } elseif (strpos($errorDetails, 'wkhtmltopdf') !== false || strpos($errorDetails, 'TCPDF') !== false) {
            $errorMessage = 'مكتبة توليد PDF غير متاحة. سيتم حفظ التقرير كملف HTML بدلاً من PDF.';
        } else {
            // في حالة التطوير، إضافة رسالة الخطأ
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                $errorMessage .= ': ' . $errorDetails;
            }
        }
        
        echo json_encode([
            'success' => false,
            'error' => $errorMessage,
            'debug' => defined('DEBUG_MODE') && DEBUG_MODE ? $errorDetails : null
        ]);
        exit;
    }
    
    // للطلبات GET، عرض رسالة خطأ بسيطة
    header('Content-Type: text/plain; charset=utf-8');
    die('حدث خطأ في إنشاء التقرير');
}
