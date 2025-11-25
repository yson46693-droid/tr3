<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/consumption_reports.php';

$targetDate = date('Y-m-d', strtotime('-1 day'));
$result = sendConsumptionReport($targetDate, $targetDate, 'التقرير اليومي الآلي');

$message = ($result['success'] ?? false) ? ($result['message'] ?? 'تم إرسال التقرير.') : ($result['message'] ?? 'تعذر إنشاء التقرير.');
error_log('[DailyConsumptionReport] ' . $message);

if (PHP_SAPI === 'cli') {
    echo $message . PHP_EOL;
} else {
    echo $message;
}

