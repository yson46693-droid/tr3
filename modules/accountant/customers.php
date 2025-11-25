<?php

declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/auth.php';
requireRole(['accountant', 'manager']);

include __DIR__ . '/../manager/customers.php';

