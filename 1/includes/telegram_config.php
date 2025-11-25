<?php
/**
 * إعدادات Telegram Bot - استخدام النظام المبسط
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// استخدام النظام المبسط
require_once __DIR__ . '/simple_telegram.php';

// للتوافق مع الكود القديم
if (!function_exists('sendTelegramMessage')) {
    // الدوال موجودة في simple_telegram.php
}

// جميع الدوال موجودة في simple_telegram.php

