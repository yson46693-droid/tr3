<?php

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireAnyRole(['accountant', 'manager']);

$currentUser = getCurrentUser();

// التحويل إلى الصفحة الأساسية للموافقة على طلبات السلفة
$redirectUrl = getDashboardUrl($currentUser['role']) . '?page=salaries&view=advances&month=' . date('n') . '&year=' . date('Y');
header('Location: ' . $redirectUrl);
exit;