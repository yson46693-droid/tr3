<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

define('RAW_MATERIALS_CONTEXT', 'manager');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('manager');

include __DIR__ . '/../production/raw_materials_warehouse.php';

