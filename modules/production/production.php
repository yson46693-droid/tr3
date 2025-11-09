<?php
/**
 * ØµÙØ­Ø© Ø¥Ø¯Ø§Ø±Ø© Ø§Ù„Ø¥Ù†ØªØ§Ø¬
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/batch_numbers.php';
require_once __DIR__ . '/../../includes/simple_barcode.php';
require_once __DIR__ . '/../../includes/consumption_reports.php';
require_once __DIR__ . '/../../includes/production_helper.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

/**
 * Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØªÙˆÙØ± Ø§Ù„Ù…ÙƒÙˆÙ†Ø§Øª Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…Ø© ÙÙŠ ØµÙ†Ø§Ø¹Ø© Ø§Ù„Ù…Ù†ØªØ¬
 */
function checkMaterialsAvailability($db, $templateId, $productionQuantity) {
    $missingMaterials = [];
    $insufficientMaterials = [];
    
    // 1. Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ù…ÙˆØ§Ø¯ Ø§Ù„ØªØ¹Ø¨Ø¦Ø©
    $packagingNameExpression = getColumnSelectExpression('product_template_packaging', 'packaging_name');
    $packagingMaterials = $db->query(
        "SELECT packaging_material_id, quantity_per_unit, {$packagingNameExpression}
         FROM product_template_packaging 
         WHERE template_id = ?",
        [$templateId]
    );
    
    foreach ($packagingMaterials as $packaging) {
        $packagingId = $packaging['packaging_material_id'] ?? null;
        $requiredQuantity = floatval($packaging['quantity_per_unit']) * $productionQuantity;
        
        if ($packagingId) {
            // Ø§Ù„Ø¨Ø­Ø« ÙÙŠ Ø¬Ø¯ÙˆÙ„ products Ø£ÙˆÙ„Ø§Ù‹
            $product = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE id = ? AND status = 'active'",
                [$packagingId]
            );
            
            if ($product) {
                $availableQuantity = floatval($product['quantity'] ?? 0);
                if ($availableQuantity < $requiredQuantity) {
                    $insufficientMaterials[] = [
                        'name' => $product['name'],
                        'required' => $requiredQuantity,
                        'available' => $availableQuantity,
                        'type' => 'Ù…ÙˆØ§Ø¯ Ø§Ù„ØªØ¹Ø¨Ø¦Ø©'
                    ];
                }
            } else {
                // Ø§Ù„Ø¨Ø­Ø« ÙÙŠ Ø¬Ø¯ÙˆÙ„ packaging_materials Ø¥Ø°Ø§ ÙƒØ§Ù† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
                $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
                if ($packagingTableCheck) {
                    $packagingMaterial = $db->queryOne(
                        "SELECT id, name, quantity FROM packaging_materials WHERE id = ?",
                        [$packagingId]
                    );
                    if ($packagingMaterial) {
                        $availableQuantity = floatval($packagingMaterial['quantity'] ?? 0);
                        if ($availableQuantity < $requiredQuantity) {
                            $insufficientMaterials[] = [
                                'name' => $packagingMaterial['name'],
                                'required' => $requiredQuantity,
                                'available' => $availableQuantity,
                                'type' => 'Ù…ÙˆØ§Ø¯ Ø§Ù„ØªØ¹Ø¨Ø¦Ø©'
                            ];
                        }
                    } else {
                        $missingMaterials[] = [
                            'name' => $packaging['packaging_name'] ?? 'Ù…Ø§Ø¯Ø© ØªØ¹Ø¨Ø¦Ø© ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙØ©',
                            'type' => 'Ù…ÙˆØ§Ø¯ Ø§Ù„ØªØ¹Ø¨Ø¦Ø©'
                        ];
                    }
                } else {
                    $missingMaterials[] = [
                        'name' => $packaging['packaging_name'] ?? 'Ù…Ø§Ø¯Ø© ØªØ¹Ø¨Ø¦Ø© ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙØ©',
                        'type' => 'Ù…ÙˆØ§Ø¯ Ø§Ù„ØªØ¹Ø¨Ø¦Ø©'
                    ];
                }
            }
        }
    }
    
    // 2. Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù…
    $rawMaterials = $db->query(
        "SELECT material_name, quantity_per_unit, unit 
         FROM product_template_raw_materials 
         WHERE template_id = ?",
        [$templateId]
    );
    
    foreach ($rawMaterials as $raw) {
        $materialName = $raw['material_name'];
        $requiredQuantity = floatval($raw['quantity_per_unit']) * $productionQuantity;
        
        // Ø§Ù„Ø¨Ø­Ø« Ø¹Ù† Ø§Ù„Ù…Ø§Ø¯Ø© ÙÙŠ Ø¬Ø¯ÙˆÙ„ products
        $product = $db->queryOne(
            "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
            [$materialName]
        );
        
        if ($product) {
            $availableQuantity = floatval($product['quantity'] ?? 0);
            if ($availableQuantity < $requiredQuantity) {
                $insufficientMaterials[] = [
                    'name' => $materialName,
                    'required' => $requiredQuantity,
                    'available' => $availableQuantity,
                    'type' => 'Ù…ÙˆØ§Ø¯ Ø®Ø§Ù…',
                    'unit' => $raw['unit'] ?? 'ÙƒØ¬Ù…'
                ];
            }
        } else {
            $missingMaterials[] = [
                'name' => $materialName,
                'type' => 'Ù…ÙˆØ§Ø¯ Ø®Ø§Ù…',
                'unit' => $raw['unit'] ?? 'ÙƒØ¬Ù…'
            ];
        }
    }
    
    // 3. Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø¹Ø³Ù„ (Ù…Ù† Ø§Ù„Ù‚Ø§Ù„Ø¨)
    $template = $db->queryOne("SELECT honey_quantity FROM product_templates WHERE id = ?", [$templateId]);
    $honeyQuantity = floatval($template['honey_quantity'] ?? 0);
    if ($honeyQuantity > 0) {
        $requiredHoney = $honeyQuantity * $productionQuantity;
        
        // Ø§Ù„Ø¨Ø­Ø« Ø¹Ù† Ø§Ù„Ø¹Ø³Ù„ ÙÙŠ Ø¬Ø¯ÙˆÙ„ honey_stock (Ø§Ù„Ù…ØµÙÙ‰ ÙÙ‚Ø·)
        $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
        if ($honeyStockTableCheck) {
            $honeyStock = $db->query(
                "SELECT hs.*, s.name as supplier_name 
                 FROM honey_stock hs
                 LEFT JOIN suppliers s ON hs.supplier_id = s.id
                 WHERE hs.filtered_honey_quantity > 0
                 ORDER BY hs.filtered_honey_quantity DESC"
            );
            
            $totalHoneyAvailable = 0;
            $honeyDetails = [];
            foreach ($honeyStock as $honey) {
                $available = floatval($honey['filtered_honey_quantity'] ?? 0);
                $totalHoneyAvailable += $available;
                if ($available > 0) {
                    $honeyDetails[] = $honey['honey_variety'] . ' (' . $honey['supplier_name'] . '): ' . number_format($available, 2) . ' ÙƒØ¬Ù…';
                }
            }
            
            if ($totalHoneyAvailable < $requiredHoney) {
                $insufficientMaterials[] = [
                    'name' => 'Ø¹Ø³Ù„ Ù…ØµÙÙ‰',
                    'required' => $requiredHoney,
                    'available' => $totalHoneyAvailable,
                    'type' => 'Ø¹Ø³Ù„',
                    'unit' => 'ÙƒØ¬Ù…',
                    'details' => implode(' | ', $honeyDetails)
                ];
            }
        } else {
            // Ø§Ù„Ø¨Ø­Ø« ÙÙŠ Ø¬Ø¯ÙˆÙ„ products ÙƒØ¨Ø¯ÙŠÙ„
            $honeyProducts = $db->query(
                "SELECT id, name, quantity FROM products 
                 WHERE (name LIKE '%Ø¹Ø³Ù„%' OR category = 'honey' OR category = 'raw_material') 
                 AND status = 'active'
                 ORDER BY quantity DESC"
            );
            
            $totalHoneyAvailable = 0;
            foreach ($honeyProducts as $honey) {
                $totalHoneyAvailable += floatval($honey['quantity'] ?? 0);
            }
            
            if ($totalHoneyAvailable < $requiredHoney) {
                $insufficientMaterials[] = [
                    'name' => 'Ø¹Ø³Ù„',
                    'required' => $requiredHoney,
                    'available' => $totalHoneyAvailable,
                    'type' => 'Ø¹Ø³Ù„',
                    'unit' => 'ÙƒØ¬Ù…'
                ];
            }
        }
    }
    
    // Ø¨Ù†Ø§Ø¡ Ø±Ø³Ø§Ù„Ø© Ø§Ù„Ø®Ø·Ø£
    $errorMessages = [];
    
    if (!empty($missingMaterials)) {
        $missingNames = array_map(function($m) {
            return $m['name'] . ' (' . $m['type'] . ')';
        }, $missingMaterials);
        $errorMessages[] = 'Ù…ÙˆØ§Ø¯ ØºÙŠØ± Ù…ÙˆØ¬ÙˆØ¯Ø© ÙÙŠ Ø§Ù„Ù…Ø®Ø²ÙˆÙ†: ' . implode(', ', $missingNames);
    }
    
    if (!empty($insufficientMaterials)) {
        $insufficientDetails = [];
        foreach ($insufficientMaterials as $mat) {
            $unit = $mat['unit'] ?? '';
            $insufficientDetails[] = sprintf(
                '%s (%s): Ù…Ø·Ù„ÙˆØ¨ %s %sØŒ Ù…ØªÙˆÙØ± %s %s',
                $mat['name'],
                $mat['type'],
                number_format($mat['required'], 2),
                $unit,
                number_format($mat['available'], 2),
                $unit
            );
        }
        $errorMessages[] = 'Ù…ÙˆØ§Ø¯ ØºÙŠØ± ÙƒØ§ÙÙŠØ©: ' . implode(' | ', $insufficientDetails);
    }
    
    if (!empty($errorMessages)) {
        return [
            'available' => false,
            'message' => implode(' | ', $errorMessages),
            'missing' => $missingMaterials,
            'insufficient' => $insufficientMaterials
        ];
    }
    
    return [
        'available' => true,
        'message' => 'Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙƒÙˆÙ†Ø§Øª Ù…ØªÙˆÙØ±Ø©'
    ];
}

// Ø¥Ù†Ø´Ø§Ø¡ Ø¬Ø¯ÙˆÙ„ batch_numbers Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
$hasBatchNumbersTable = false;
try {
    $batchTableCheck = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
    if (empty($batchTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `batch_numbers` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `batch_number` varchar(100) NOT NULL,
              `product_id` int(11) NOT NULL,
              `production_id` int(11) DEFAULT NULL,
              `production_date` date NOT NULL,
              `honey_supplier_id` int(11) DEFAULT NULL,
              `packaging_materials` text DEFAULT NULL COMMENT 'JSON array of packaging material IDs',
              `packaging_supplier_id` int(11) DEFAULT NULL,
              `workers` text DEFAULT NULL COMMENT 'JSON array of worker IDs',
              `quantity` int(11) NOT NULL DEFAULT 1,
              `status` enum('in_production','completed','in_stock','sold','expired') DEFAULT 'in_production',
              `expiry_date` date DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `batch_number` (`batch_number`),
              KEY `product_id` (`product_id`),
              KEY `production_id` (`production_id`),
              KEY `production_date` (`production_date`),
              KEY `status` (`status`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // Ø¥Ø¶Ø§ÙØ© Ø­Ù‚Ù„ all_suppliers Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
    $allSuppliersColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'all_suppliers'");
    if (empty($allSuppliersColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `batch_numbers` 
                ADD COLUMN `all_suppliers` TEXT DEFAULT NULL COMMENT 'JSON array of all suppliers with their materials' 
                AFTER `packaging_supplier_id`
            ");
        } catch (Exception $e) {
            error_log("Error adding all_suppliers column: " . $e->getMessage());
        }
    }
    
    // Ø¥Ø¶Ø§ÙØ© Ø­Ù‚Ù„ honey_variety Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
    $honeyVarietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'honey_variety'");
    if (empty($honeyVarietyColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `batch_numbers` 
                ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…' 
                AFTER `honey_supplier_id`
            ");
        } catch (Exception $e) {
            error_log("Error adding honey_variety column: " . $e->getMessage());
        }
    }
    $hasBatchNumbersTable = true;
} catch (Exception $e) {
    error_log("Batch numbers table creation error: " . $e->getMessage());
    $hasBatchNumbersTable = false;
}

// Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø±Ø³Ø§Ù„Ø© Ø§Ù„Ù†Ø¬Ø§Ø­ Ù…Ù† session (Ø¨Ø¹Ø¯ redirect)
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

// Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ÙˆØ¬ÙˆØ¯ Ø¹Ù…ÙˆØ¯ date Ø£Ùˆ production_date
$dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$hasDateColumn = !empty($dateColumnCheck);
$hasProductionDateColumn = !empty($productionDateColumnCheck);
$dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

// Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ÙˆØ¬ÙˆØ¯ Ø¹Ù…ÙˆØ¯ user_id Ø£Ùˆ worker_id
$userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
$workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
$hasUserIdColumn = !empty($userIdColumnCheck);
$hasWorkerIdColumn = !empty($workerIdColumnCheck);
$userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

// Ù…Ø¹Ø§Ù„Ø¬Ø© Ø§Ù„Ø¹Ù…Ù„ÙŠØ§Øª
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Ù…Ù†Ø¹ ØªÙƒØ±Ø§Ø± Ø§Ù„Ø¥Ø±Ø³Ø§Ù„ Ø¨Ø§Ø³ØªØ®Ø¯Ø§Ù… CSRF Token
    $submitToken = $_POST['submit_token'] ?? '';
    $sessionToken = $_SESSION['last_submit_token'] ?? '';
    
    if ($submitToken && $submitToken === $sessionToken) {
        // ØªÙ… Ø¥Ø±Ø³Ø§Ù„ Ù‡Ø°Ø§ Ø§Ù„Ù†Ù…ÙˆØ°Ø¬ Ù…Ù† Ù‚Ø¨Ù„ - ØªØ¬Ø§Ù‡Ù„Ù‡
        $error = 'ØªÙ… Ù…Ø¹Ø§Ù„Ø¬Ø© Ù‡Ø°Ø§ Ø§Ù„Ø·Ù„Ø¨ Ù…Ù† Ù‚Ø¨Ù„. ÙŠØ±Ø¬Ù‰ Ø¹Ø¯Ù… Ø¥Ø¹Ø§Ø¯Ø© ØªØ­Ù…ÙŠÙ„ Ø§Ù„ØµÙØ­Ø© Ø¨Ø¹Ø¯ Ø§Ù„Ø¥Ø±Ø³Ø§Ù„.';
        error_log("Duplicate form submission detected: token={$submitToken}, action={$action}");
    } elseif ($action === 'add_production') {
        // Ø­ÙØ¸ Ø§Ù„ØªÙˆÙƒÙ† Ù„Ù…Ù†Ø¹ Ø§Ù„ØªÙƒØ±Ø§Ø±
        $_SESSION['last_submit_token'] = $submitToken;
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = $_POST['unit'] ?? 'kg'; // ÙƒØ¬Ù… Ø£Ùˆ Ø¬Ø±Ø§Ù…
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        
        // ØªØ­ÙˆÙŠÙ„ Ø§Ù„Ø¬Ø±Ø§Ù… Ø¥Ù„Ù‰ ÙƒÙŠÙ„ÙˆØ¬Ø±Ø§Ù… Ø¥Ø°Ø§ Ù„Ø²Ù… Ø§Ù„Ø£Ù…Ø±
        if ($unit === 'g' || $unit === 'gram') {
            $quantity = $quantity / 1000; // ØªØ­ÙˆÙŠÙ„ Ù…Ù† Ø¬Ø±Ø§Ù… Ø¥Ù„Ù‰ ÙƒØ¬Ù…
        }
        
        // ØªØ­Ø¯ÙŠØ¯ user_id - Ø¥Ø°Ø§ ÙƒØ§Ù† Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù… Ø¹Ø§Ù…Ù„ Ø¥Ù†ØªØ§Ø¬ØŒ Ø§Ø³ØªØ®Ø¯Ù… id Ø§Ù„Ø®Ø§Øµ Ø¨Ù‡ØŒ ÙˆØ¥Ù„Ø§ Ø§Ø³ØªØ®Ø¯Ù… Ø§Ù„Ù…Ø­Ø¯Ø¯
        $selectedUserId = intval($_POST['user_id'] ?? 0);
        if ($currentUser['role'] === 'production' && $selectedUserId <= 0) {
            $selectedUserId = $currentUser['id'];
        } elseif ($selectedUserId <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ø¹Ø§Ù…Ù„';
        }
        
        if (empty($productId) || $productId <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ù…Ù†ØªØ¬';
        } elseif ($quantity <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø¥Ø¯Ø®Ø§Ù„ ÙƒÙ…ÙŠØ© ØµØ­ÙŠØ­Ø©';
        } elseif ($selectedUserId <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ø¹Ø§Ù…Ù„';
        } else {
            // Ø¨Ù†Ø§Ø¡ Ø§Ù„Ø§Ø³ØªØ¹Ù„Ø§Ù… Ø¨Ø´ÙƒÙ„ Ø¯ÙŠÙ†Ø§Ù…ÙŠÙƒÙŠ
            $columns = ['product_id', 'quantity'];
            $values = [$productId, $quantity];
            $placeholders = ['?', '?'];
            
            // Ø¥Ø¶Ø§ÙØ© Ø¹Ù…ÙˆØ¯ Ø§Ù„ØªØ§Ø±ÙŠØ®
            $columns[] = $dateColumn;
            $values[] = $productionDate;
            $placeholders[] = '?';
            
            // Ø¥Ø¶Ø§ÙØ© Ø¹Ù…ÙˆØ¯ user_id/worker_id
            if ($userIdColumn) {
                $columns[] = $userIdColumn;
                $values[] = $selectedUserId;
                $placeholders[] = '?';
            }
            
            // Ø¥Ø¶Ø§ÙØ© Ù…ÙˆØ§Ø¯ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø¥Ù† ÙˆØ¬Ø¯Øª
            if ($materialsUsed) {
                $columns[] = 'materials_used';
                $values[] = $materialsUsed;
                $placeholders[] = '?';
            }
            
            // Ø¥Ø¶Ø§ÙØ© Ø§Ù„Ù…Ù„Ø§Ø­Ø¸Ø§Øª
            if ($notes) {
                $columns[] = 'notes';
                $values[] = $notes;
                $placeholders[] = '?';
            }
            
            // Ø¥Ø¶Ø§ÙØ© Ø§Ù„Ø­Ø§Ù„Ø© (Ø§ÙØªØ±Ø§Ø¶ÙŠØ§Ù‹ pending)
            $columns[] = 'status';
            $values[] = 'pending';
            $placeholders[] = '?';
            
            $sql = "INSERT INTO production (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            try {
                $result = $db->execute($sql, $values);
                
                logAudit($currentUser['id'], 'add_production', 'production', $result['insert_id'], null, [
                    'product_id' => $productId,
                    'quantity' => $quantity
                ]);
                
                // Ù…Ù†Ø¹ Ø§Ù„ØªÙƒØ±Ø§Ø± Ø¨Ø§Ø³ØªØ®Ø¯Ø§Ù… redirect
                $successMessage = 'ØªÙ… Ø¥Ø¶Ø§ÙØ© Ø³Ø¬Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø¨Ù†Ø¬Ø§Ø­';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'Ø­Ø¯Ø« Ø®Ø·Ø£ ÙÙŠ Ø¥Ø¶Ø§ÙØ© Ø³Ø¬Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_production') {
        $_SESSION['last_submit_token'] = $submitToken;
        $productionId = intval($_POST['production_id'] ?? 0);
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = $_POST['unit'] ?? 'kg'; // ÙƒØ¬Ù… Ø£Ùˆ Ø¬Ø±Ø§Ù…
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        
        // ØªØ­ÙˆÙŠÙ„ Ø§Ù„Ø¬Ø±Ø§Ù… Ø¥Ù„Ù‰ ÙƒÙŠÙ„ÙˆØ¬Ø±Ø§Ù… Ø¥Ø°Ø§ Ù„Ø²Ù… Ø§Ù„Ø£Ù…Ø±
        if ($unit === 'g' || $unit === 'gram') {
            $quantity = $quantity / 1000; // ØªØ­ÙˆÙŠÙ„ Ù…Ù† Ø¬Ø±Ø§Ù… Ø¥Ù„Ù‰ ÙƒØ¬Ù…
        }
        
        if ($productionId <= 0) {
            $error = 'Ù…Ø¹Ø±Ù Ø§Ù„Ø¥Ù†ØªØ§Ø¬ ØºÙŠØ± ØµØ­ÙŠØ­';
        } elseif ($productId <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ù…Ù†ØªØ¬';
        } elseif ($quantity <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø¥Ø¯Ø®Ø§Ù„ ÙƒÙ…ÙŠØ© ØµØ­ÙŠØ­Ø©';
        } else {
            // Ø¨Ù†Ø§Ø¡ Ø§Ù„Ø§Ø³ØªØ¹Ù„Ø§Ù… Ø¨Ø´ÙƒÙ„ Ø¯ÙŠÙ†Ø§Ù…ÙŠÙƒÙŠ
            $setParts = ['product_id = ?', 'quantity = ?'];
            $values = [$productId, $quantity];
            
            // ØªØ­Ø¯ÙŠØ« Ø¹Ù…ÙˆØ¯ Ø§Ù„ØªØ§Ø±ÙŠØ®
            $setParts[] = "$dateColumn = ?";
            $values[] = $productionDate;
            
            // ØªØ­Ø¯ÙŠØ« Ù…ÙˆØ§Ø¯ Ø§Ù„Ø¥Ù†ØªØ§Ø¬
            if ($materialsUsed !== '') {
                $setParts[] = 'materials_used = ?';
                $values[] = $materialsUsed;
            }
            
            // ØªØ­Ø¯ÙŠØ« Ø§Ù„Ù…Ù„Ø§Ø­Ø¸Ø§Øª
            if ($notes !== '') {
                $setParts[] = 'notes = ?';
                $values[] = $notes;
            }
            
            // ØªØ­Ø¯ÙŠØ« Ø§Ù„Ø­Ø§Ù„Ø©
            $setParts[] = 'status = ?';
            $values[] = $status;
            
            $values[] = $productionId;
            
            $sql = "UPDATE production SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            try {
                $db->execute($sql, $values);
                
                logAudit($currentUser['id'], 'update_production', 'production', $productionId, null, [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'status' => $status
                ]);
                
                // Ù…Ù†Ø¹ Ø§Ù„ØªÙƒØ±Ø§Ø± Ø¨Ø§Ø³ØªØ®Ø¯Ø§Ù… redirect
                $successMessage = 'ØªÙ… ØªØ­Ø¯ÙŠØ« Ø³Ø¬Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø¨Ù†Ø¬Ø§Ø­';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'Ø­Ø¯Ø« Ø®Ø·Ø£ ÙÙŠ ØªØ­Ø¯ÙŠØ« Ø³Ø¬Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_production') {
        $_SESSION['last_submit_token'] = $submitToken;
        $productionId = intval($_POST['production_id'] ?? 0);
        
        if ($productionId <= 0) {
            $error = 'Ù…Ø¹Ø±Ù Ø§Ù„Ø¥Ù†ØªØ§Ø¬ ØºÙŠØ± ØµØ­ÙŠØ­';
        } else {
            try {
                // Ø­Ø°Ù Ù…ÙˆØ§Ø¯ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø§Ù„Ù…Ø±ØªØ¨Ø·Ø© Ø£ÙˆÙ„Ø§Ù‹
                $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);
                
                // Ø­Ø°Ù Ø³Ø¬Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬
                $db->execute("DELETE FROM production WHERE id = ?", [$productionId]);
                
                logAudit($currentUser['id'], 'delete_production', 'production', $productionId, null, null);
                
                // Ù…Ù†Ø¹ Ø§Ù„ØªÙƒØ±Ø§Ø± Ø¨Ø§Ø³ØªØ®Ø¯Ø§Ù… redirect
                $successMessage = 'ØªÙ… Ø­Ø°Ù Ø³Ø¬Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø¨Ù†Ø¬Ø§Ø­';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'Ø­Ø¯Ø« Ø®Ø·Ø£ ÙÙŠ Ø­Ø°Ù Ø³Ø¬Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'create_from_template') {
        $_SESSION['last_submit_token'] = $submitToken;
        $templateId = intval($_POST['template_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        
        if ($templateId <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø§Ø®ØªÙŠØ§Ø± Ù‚Ø§Ù„Ø¨ Ù…Ù†ØªØ¬';
        } elseif ($quantity <= 0) {
            $error = 'ÙŠØ¬Ø¨ Ø¥Ø¯Ø®Ø§Ù„ ÙƒÙ…ÙŠØ© ØµØ­ÙŠØ­Ø© (Ø£ÙƒØ¨Ø± Ù…Ù† 0)';
        } else {
            try {
                $db->beginTransaction();
                
                $materialSuppliersInput = $_POST['material_suppliers'] ?? [];
                $materialSuppliers = [];
                if (is_array($materialSuppliersInput)) {
                    foreach ($materialSuppliersInput as $key => $value) {
                        $materialSuppliers[$key] = intval($value);
                    }
                }

                $materialHoneyVarietiesInput = $_POST['material_honey_varieties'] ?? [];
                $materialHoneyVarieties = [];
                if (is_array($materialHoneyVarietiesInput)) {
                    foreach ($materialHoneyVarietiesInput as $key => $value) {
                        $cleanValue = trim((string)$value);
                        if ($cleanValue !== '') {
                            $cleanValue = mb_substr($cleanValue, 0, 120, 'UTF-8');
                        }
                        $materialHoneyVarieties[$key] = $cleanValue;
                    }
                }

                if (empty($materialSuppliers)) {
                    throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ù†Ø§Ø³Ø¨ Ù„ÙƒÙ„ Ù…Ø§Ø¯Ø© Ù‚Ø¨Ù„ Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„ØªØ´ØºÙŠÙ„Ø©.');
                }

                $templateMode = $_POST['template_mode'] ?? 'advanced';
                if ($templateMode !== 'advanced') {
                    $templateMode = 'advanced';
                }
                $templateType = trim($_POST['template_type'] ?? 'legacy');

                // Ù…Ø­Ø§ÙˆÙ„Ø© Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø§Ù„Ù‚Ø§Ù„Ø¨ Ø§Ù„Ù…ÙˆØ­Ø¯ Ø£ÙˆÙ„Ø§Ù‹
                $unifiedTemplate = $db->queryOne(
                    "SELECT * FROM unified_product_templates WHERE id = ? AND status = 'active'",
                    [$templateId]
                );
                
                $isUnifiedTemplate = !empty($unifiedTemplate);
                
                if ($isUnifiedTemplate) {
                    // Ù‚Ø§Ù„Ø¨ Ù…ÙˆØ­Ø¯ Ø¬Ø¯ÙŠØ¯
                    $template = $unifiedTemplate;
                    
                    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØªÙˆÙØ± Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù…
                    $rawMaterials = $db->query(
                        "SELECT * FROM template_raw_materials WHERE template_id = ?",
                        [$templateId]
                    );
                    
                    if (empty($rawMaterials)) {
                        throw new Exception('Ø§Ù„Ù‚Ø§Ù„Ø¨ Ù„Ø§ ÙŠØ­ØªÙˆÙŠ Ø¹Ù„Ù‰ Ù…ÙˆØ§Ø¯ Ø®Ø§Ù…');
                    }
                    
                    $insufficientMaterials = [];
                    foreach ($rawMaterials as $material) {
                        $quantityColumnValue = isset($material['quantity']) ? $material['quantity'] : ($material['quantity_per_unit'] ?? 0);
                        $requiredQty = floatval($quantityColumnValue) * $quantity;
                        $supplierIdForCheck = $material['supplier_id'] ?? null;
                        
                        // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØªÙˆÙØ± Ø§Ù„Ù…Ø§Ø¯Ø© Ø­Ø³Ø¨ Ù†ÙˆØ¹Ù‡Ø§
                        switch ($material['material_type']) {
                            case 'honey_raw':
                            case 'honey_filtered':
                                $stockColumn = $material['material_type'] === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM($stockColumn) as total FROM honey_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM($stockColumn) as total FROM honey_stock"
                                    );
                                }
                                break;
                            case 'olive_oil':
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM(quantity) as total FROM olive_oil_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM(quantity) as total FROM olive_oil_stock"
                                    );
                                }
                                break;
                            case 'beeswax':
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM beeswax_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM beeswax_stock"
                                    );
                                }
                                break;
                            case 'derivatives':
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM derivatives_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM derivatives_stock"
                                    );
                                }
                                break;
                            default:
                                $available = ['total' => PHP_FLOAT_MAX]; // Ù„Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø£Ø®Ø±Ù‰ Ù„Ø§ Ù†ØªØ­Ù‚Ù‚
                        }
                        
                        $availableQty = floatval($available['total'] ?? 0);
                        if ($availableQty < $requiredQty) {
                            $insufficientMaterials[] = [
                                'name' => $material['material_name'],
                                'required' => $requiredQty,
                                'available' => $availableQty,
                                'unit' => $material['unit']
                            ];
                        }
                    }
                    
                    if (!empty($insufficientMaterials)) {
                        $errorMsg = 'Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù… ØºÙŠØ± ÙƒØ§ÙÙŠØ©: ';
                        $errors = [];
                        foreach ($insufficientMaterials as $mat) {
                            $errors[] = $mat['name'] . ' (Ù…Ø·Ù„ÙˆØ¨: ' . $mat['required'] . ' ' . $mat['unit'] . ', Ù…ØªÙˆÙØ±: ' . $mat['available'] . ' ' . $mat['unit'] . ')';
                        }
                        throw new Exception($errorMsg . implode(', ', $errors));
                    }
                    
                    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø©
                    $packagingItems = $db->query(
                        "SELECT * FROM template_packaging WHERE template_id = ?",
                        [$templateId]
                    );
                    
                    foreach ($packagingItems as $pkg) {
                        $requiredQty = floatval($pkg['quantity_per_unit']) * $quantity;
                        $available = $db->queryOne(
                            "SELECT quantity FROM packaging_materials WHERE id = ?",
                            [$pkg['packaging_material_id']]
                        );
                        
                        $availableQty = floatval($available['quantity'] ?? 0);
                        if ($availableQty < $requiredQty) {
                            $pkgInfo = $db->queryOne("SELECT name FROM packaging_materials WHERE id = ?", [$pkg['packaging_material_id']]);
                            throw new Exception('Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø© ØºÙŠØ± ÙƒØ§ÙÙŠØ©: ' . ($pkgInfo['name'] ?? 'Ù…Ø§Ø¯Ø© ØªØ¹Ø¨Ø¦Ø©') . ' (Ù…Ø·Ù„ÙˆØ¨: ' . $requiredQty . ', Ù…ØªÙˆÙØ±: ' . $availableQty . ')');
                        }
                    }
                    
                } else {
                    // Ù‚Ø§Ù„Ø¨ Ù‚Ø¯ÙŠÙ…
                    $template = $db->queryOne(
                        "SELECT pt.*, pr.id as product_id, pr.name as product_name
                         FROM product_templates pt
                         LEFT JOIN products pr ON pt.product_name = pr.name
                         WHERE pt.id = ?",
                        [$templateId]
                    );
                    
                    if (!$template) {
                        throw new Exception('Ø§Ù„Ù‚Ø§Ù„Ø¨ ØºÙŠØ± Ù…ÙˆØ¬ÙˆØ¯');
                    }
                    
                    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØªÙˆÙØ± Ø§Ù„Ù…ÙƒÙˆÙ†Ø§Øª Ù‚Ø¨Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬
                    $materialsCheck = checkMaterialsAvailability($db, $templateId, $quantity);
                    if (!$materialsCheck['available']) {
                        throw new Exception('Ø§Ù„Ù…ÙƒÙˆÙ†Ø§Øª ØºÙŠØ± Ù…ØªÙˆÙØ±Ø©: ' . $materialsCheck['message']);
                    }
                }
                
                // Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„Ù…Ù†ØªØ¬ Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
                $productId = $template['product_id'] ?? 0;
                if ($productId <= 0) {
                    // Ø§Ù„Ø¨Ø­Ø« Ø¹Ù† Ù…Ù†ØªØ¬ Ø¨Ù†ÙØ³ Ø§Ù„Ø§Ø³Ù…
                    $existingProduct = $db->queryOne("SELECT id FROM products WHERE name = ? LIMIT 1", [$template['product_name']]);
                    if ($existingProduct) {
                        $productId = $existingProduct['id'];
                    } else {
                        // Ø¥Ù†Ø´Ø§Ø¡ Ù…Ù†ØªØ¬ Ø¬Ø¯ÙŠØ¯
                        $result = $db->execute(
                            "INSERT INTO products (name, category, status, unit) VALUES (?, 'finished', 'active', 'Ù‚Ø·Ø¹Ø©')",
                            [$template['product_name']]
                        );
                        $productId = $result['insert_id'];
                    }
                }
                
                // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø© ÙˆØ§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ† Ù…Ù† Ø§Ù„Ù‚Ø§Ù„Ø¨
                $packagingIds = [];
                $allSuppliers = []; // Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ† Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…ÙŠÙ† ÙÙŠ Ø§Ù„Ù…Ù†ØªØ¬
                $materialsConsumption = [
                    'raw' => [],
                    'packaging' => []
                ];
                
                if ($isUnifiedTemplate) {
                    // Ù‚Ø§Ù„Ø¨ Ù…ÙˆØ­Ø¯ - Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø©
                    $packagingNameExpression = getColumnSelectExpression('template_packaging', 'packaging_name', 'packaging_name', 'tp');
                    $packagingItems = $db->query(
                        "SELECT tp.id, tp.packaging_material_id, {$packagingNameExpression}, tp.quantity_per_unit,
                                pm.name as packaging_db_name, pm.unit as packaging_unit, pm.product_id as packaging_product_id
                         FROM template_packaging tp 
                         LEFT JOIN packaging_materials pm ON pm.id = tp.packaging_material_id
                         WHERE tp.template_id = ?",
                        [$templateId]
                    );
                    $packagingIds = array_filter(array_map(function($p) { return $p['packaging_material_id'] ?? null; }, $packagingItems));
                    
                    $honeySupplierId = null;
                    $packagingSupplierId = null;
                    $honeyVariety = null;
                    $usingSubmittedSuppliers = !empty($materialSuppliers);
                    
                    if ($usingSubmittedSuppliers) {
                        // Ø§Ù„Ù…ÙˆØ±Ø¯ÙˆÙ† Ø§Ù„Ù…Ø­Ø¯Ø¯ÙˆÙ† Ù…Ù† Ø§Ù„Ù†Ù…ÙˆØ°Ø¬ Ø§Ù„Ù…ØªÙ‚Ø¯Ù…
                        foreach ($packagingItems as $pkg) {
                            $pkgKey = 'pack_' . ($pkg['packaging_material_id'] ?? $pkg['id']);
                            $selectedSupplierId = $materialSuppliers[$pkgKey] ?? 0;
                            if (empty($selectedSupplierId)) {
                                throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ù„ÙƒÙ„ Ø£Ø¯Ø§Ø© ØªØ¹Ø¨Ø¦Ø© Ù‚Ø¨Ù„ Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„ØªØ´ØºÙŠÙ„Ø©.');
                            }
                            
                            $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                            if (!$supplierInfo) {
                                throw new Exception('Ù…ÙˆØ±Ø¯ ØºÙŠØ± ØµØ§Ù„Ø­ Ù„Ø£Ø¯Ø§Ø© Ø§Ù„ØªØ¹Ø¨Ø¦Ø©: ' . ($pkg['packaging_name'] ?? 'ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙ'));
                            }
                            
                            $allSuppliers[] = [
                                'id' => $supplierInfo['id'],
                                'name' => $supplierInfo['name'],
                                'type' => $supplierInfo['type'],
                                'material' => $pkg['packaging_name'] ?? 'Ø£Ø¯Ø§Ø© ØªØ¹Ø¨Ø¦Ø©'
                            ];
                            
                            if (!$packagingSupplierId) {
                                $packagingSupplierId = $supplierInfo['id'];
                            }

                            $packagingQuantityPerUnit = isset($pkg['quantity_per_unit']) ? (float)$pkg['quantity_per_unit'] : 1.0;
                            $packagingName = $pkg['packaging_name'] ?? $pkg['packaging_db_name'] ?? 'Ù…Ø§Ø¯Ø© ØªØ¹Ø¨Ø¦Ø©';
                            $packagingUnit = $pkg['packaging_unit'] ?? 'Ù‚Ø·Ø¹Ø©';
                            $packagingProductId = isset($pkg['packaging_product_id']) ? (int)$pkg['packaging_product_id'] : null;
                            if (!empty($pkg['packaging_material_id'])) {
                                $materialsConsumption['packaging'][] = [
                                    'material_id' => (int)$pkg['packaging_material_id'],
                                    'quantity' => $packagingQuantityPerUnit * $quantity,
                                    'name' => $packagingName,
                                    'unit' => $packagingUnit,
                                    'product_id' => $packagingProductId,
                                    'supplier_id' => $selectedSupplierId
                                ];
                            }
                        }
                        
                        $rawSuppliers = $db->query(
                            "SELECT id, material_name, material_type, honey_variety, quantity FROM template_raw_materials WHERE template_id = ?",
                            [$templateId]
                        );
                        
                        foreach ($rawSuppliers as $materialRow) {
                            $rawKey = 'raw_' . $materialRow['id'];
                            $selectedSupplierId = $materialSuppliers[$rawKey] ?? 0;
                            if (empty($selectedSupplierId)) {
                                throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ù„ÙƒÙ„ Ù…Ø§Ø¯Ø© Ø®Ø§Ù… Ù‚Ø¨Ù„ Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„ØªØ´ØºÙŠÙ„Ø©.');
                            }
                            
                            $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                            if (!$supplierInfo) {
                                throw new Exception('Ù…ÙˆØ±Ø¯ ØºÙŠØ± ØµØ§Ù„Ø­ Ù„Ù„Ù…Ø§Ø¯Ø© Ø§Ù„Ø®Ø§Ù…: ' . ($materialRow['material_name'] ?? 'ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙ'));
                            }
                            
                            $materialType = $materialRow['material_type'] ?? '';
                            $selectedHoneyVariety = $materialHoneyVarieties[$rawKey] ?? '';
                            if (in_array($materialType, ['honey_raw', 'honey_filtered'], true) && $selectedHoneyVariety === '') {
                                throw new Exception('ÙŠØ±Ø¬Ù‰ ØªØ­Ø¯ÙŠØ¯ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ù„Ù„Ù…Ø§Ø¯Ø© Ø§Ù„Ø®Ø§Ù…: ' . ($materialRow['material_name'] ?? 'Ø¹Ø³Ù„'));
                            }

                            $detectedHoneyVariety = $selectedHoneyVariety !== '' ? $selectedHoneyVariety : ($materialRow['honey_variety'] ?? null);
                            
                            $materialDisplay = $materialRow['material_name'] ?? 'Ù…Ø§Ø¯Ø© Ø®Ø§Ù…';
                            if (!empty($detectedHoneyVariety)) {
                                $materialDisplay .= ' (' . $detectedHoneyVariety . ')';
                                if (!$honeyVariety) {
                                    $honeyVariety = $detectedHoneyVariety;
                                }
                            }
                            
                            $allSuppliers[] = [
                                'id' => $supplierInfo['id'],
                                'name' => $supplierInfo['name'],
                                'type' => $supplierInfo['type'],
                                'material' => $materialDisplay,
                                'honey_variety' => $detectedHoneyVariety
                            ];
                            
                            if (!$honeySupplierId && in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                                $honeySupplierId = $supplierInfo['id'];
                            }

                            $rawQuantityPerUnit = isset($materialRow['quantity']) ? (float)$materialRow['quantity'] : (isset($materialRow['quantity_per_unit']) ? (float)$materialRow['quantity_per_unit'] : 0.0);
                            $materialUnit = $materialRow['unit'] ?? 'ÙƒØ¬Ù…';
                            $materialsConsumption['raw'][] = [
                                'template_material_id' => (int)$materialRow['id'],
                                'supplier_id' => $selectedSupplierId,
                                'material_type' => $materialType,
                                'material_name' => $materialRow['material_name'] ?? '',
                                'honey_variety' => $detectedHoneyVariety,
                                'unit' => $materialUnit,
                                'display_name' => $materialDisplay,
                                'quantity' => $rawQuantityPerUnit * $quantity
                            ];
                        }

                        if (!$packagingSupplierId) {
                            foreach ($materialSuppliers as $key => $value) {
                                if (strpos($key, 'pack_') === 0 && $value > 0) {
                                    $packagingSupplierId = $value;
                                    break;
                                }
                            }
                        }
                    } else {
                        // fallback Ø¥Ù„Ù‰ Ø§Ù„Ù†Ø¸Ø§Ù… Ø§Ù„Ù‚Ø¯ÙŠÙ… (Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ† Ù…Ù† Ø§Ù„Ù‚Ø§Ù„Ø¨ Ù†ÙØ³Ù‡)
                        $hasHoneyVarietyColumn = false;
                        $templateRawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
                        if (!empty($templateRawMaterialsTableCheck)) {
                            $honeyVarietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'honey_variety'");
                            $hasHoneyVarietyColumn = !empty($honeyVarietyColumnCheck);
                        }
                        
                        $selectColumns = "DISTINCT supplier_id, material_type, material_name, quantity, unit";
                        if ($hasHoneyVarietyColumn) {
                            $selectColumns .= ", honey_variety";
                        }
                        
                        $rawMaterialsWithSuppliers = $db->query(
                            "SELECT {$selectColumns} FROM template_raw_materials WHERE template_id = ? AND supplier_id IS NOT NULL",
                            [$templateId]
                        );
                        
                        $honeyVariety = null;
                        
                        foreach ($rawMaterialsWithSuppliers as $material) {
                            $supplierInfo = $db->queryOne(
                                "SELECT id, name, type FROM suppliers WHERE id = ?",
                                [$material['supplier_id']]
                            );
                            if ($supplierInfo) {
                                $materialDisplay = $material['material_name'];
                                
                                if ($hasHoneyVarietyColumn 
                                    && ($material['material_type'] === 'honey_raw' || $material['material_type'] === 'honey_filtered') 
                                    && !empty($material['honey_variety'])) {
                                    $materialDisplay .= ' (' . $material['honey_variety'] . ')';
                                    if (!$honeyVariety) {
                                        $honeyVariety = $material['honey_variety'];
                                    }
                                }
                                
                                $allSuppliers[] = [
                                    'id' => $supplierInfo['id'],
                                    'name' => $supplierInfo['name'],
                                    'type' => $supplierInfo['type'],
                                    'material' => $materialDisplay,
                                    'honey_variety' => ($hasHoneyVarietyColumn && isset($material['honey_variety'])) ? $material['honey_variety'] : null
                                ];
                                
                                if (!$honeySupplierId && in_array($supplierInfo['type'], ['honey'])) {
                                    $honeySupplierId = $supplierInfo['id'];
                                }

                                $rawQuantityPerUnit = isset($material['quantity']) ? (float)$material['quantity'] : 0.0;
                                if ($rawQuantityPerUnit > 0) {
                                    $materialsConsumption['raw'][] = [
                                        'template_material_id' => null,
                                        'supplier_id' => $material['supplier_id'] ?? null,
                                        'material_type' => $material['material_type'] ?? '',
                                        'material_name' => $material['material_name'] ?? '',
                                        'honey_variety' => ($hasHoneyVarietyColumn && isset($material['honey_variety'])) ? $material['honey_variety'] : null,
                                        'unit' => $material['unit'] ?? 'ÙƒØ¬Ù…',
                                        'display_name' => $materialDisplay,
                                        'quantity' => $rawQuantityPerUnit * $quantity
                                    ];
                                }
                            }
                        }
                        
                        if (!empty($allSuppliers)) {
                            foreach ($allSuppliers as $sup) {
                                if (!$packagingSupplierId && $sup['type'] === 'packaging') {
                                    $packagingSupplierId = $sup['id'];
                                }
                            }
                        }

                        if (!$packagingSupplierId && !empty($materialSuppliers)) {
                            foreach ($materialSuppliers as $key => $value) {
                                if (strpos($key, 'pack_') === 0 && $value > 0) {
                                    $packagingSupplierId = $value;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    // Ù‚Ø§Ù„Ø¨ Ù‚Ø¯ÙŠÙ… ÙˆØ£Ù†ÙˆØ§Ø¹ Ø§Ù„Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù…Ø¨Ø³Ø·Ø© (Ø¹Ø³Ù„ØŒ Ø²ÙŠØªØŒ Ø´Ù…Ø¹ØŒ Ù…Ø´ØªÙ‚Ø§Øª)
                    $packagingMaterials = $db->query(
                        "SELECT ptp.id, ptp.packaging_material_id, ptp.packaging_name, ptp.quantity_per_unit,
                                pm.name as packaging_db_name, pm.unit as packaging_unit, pm.product_id as packaging_product_id
                         FROM product_template_packaging ptp
                         LEFT JOIN packaging_materials pm ON pm.id = ptp.packaging_material_id
                         WHERE ptp.template_id = ?",
                        [$templateId]
                    );
                    $packagingIds = array_filter(array_map(function($p) { return $p['packaging_material_id'] ?? null; }, $packagingMaterials));
                    
                    $packagingSupplierId = null;

                    foreach ($packagingMaterials as $legacyPkg) {
                        $pkgKey = 'pack_' . ($legacyPkg['packaging_material_id'] ?? $legacyPkg['id']);
                        $selectedSupplierId = $materialSuppliers[$pkgKey] ?? 0;
                        if (empty($selectedSupplierId)) {
                            throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ù„ÙƒÙ„ Ø£Ø¯Ø§Ø© ØªØ¹Ø¨Ø¦Ø© Ù…Ø³ØªØ®Ø¯Ù…Ø© ÙÙŠ Ø§Ù„Ù‚Ø§Ù„Ø¨.');
                        }

                        $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                        if (!$supplierInfo) {
                            throw new Exception('Ù…ÙˆØ±Ø¯ ØºÙŠØ± ØµØ§Ù„Ø­ Ù„Ø£Ø¯Ø§Ø© Ø§Ù„ØªØ¹Ø¨Ø¦Ø©: ' . ($legacyPkg['packaging_name'] ?? 'ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙ'));
                        }

                        $materialLabel = $legacyPkg['packaging_name'] ?? 'Ø£Ø¯Ø§Ø© ØªØ¹Ø¨Ø¦Ø©';
                        $allSuppliers[] = [
                            'id' => $supplierInfo['id'],
                            'name' => $supplierInfo['name'],
                            'type' => $supplierInfo['type'],
                            'material' => $materialLabel
                        ];

                    if (!$packagingSupplierId) {
                            $packagingSupplierId = $supplierInfo['id'];
                        }

                        if (!empty($legacyPkg['packaging_material_id'])) {
                            $legacyPackagingName = $legacyPkg['packaging_name'] ?? $legacyPkg['packaging_db_name'] ?? 'Ù…Ø§Ø¯Ø© ØªØ¹Ø¨Ø¦Ø©';
                            $legacyPackagingUnit = $legacyPkg['packaging_unit'] ?? 'Ù‚Ø·Ø¹Ø©';
                            $legacyPackagingProductId = isset($legacyPkg['packaging_product_id']) ? (int)$legacyPkg['packaging_product_id'] : null;
                            $materialsConsumption['packaging'][] = [
                                'material_id' => (int)$legacyPkg['packaging_material_id'],
                                'quantity' => (float)($legacyPkg['quantity_per_unit'] ?? 1.0) * $quantity,
                                'name' => $legacyPackagingName,
                                'unit' => $legacyPackagingUnit,
                                'product_id' => $legacyPackagingProductId,
                                'supplier_id' => $selectedSupplierId
                            ];
                        }
                    }
                    
                    // Ù…Ø¹Ø§Ù„Ø¬Ø© Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø±Ø¦ÙŠØ³ÙŠØ© Ø­Ø³Ø¨ Ù†ÙˆØ¹ Ø§Ù„Ù‚Ø§Ù„Ø¨
                    switch ($templateType) {
                        case 'olive_oil':
                            $oliveSupplierId = $materialSuppliers['olive_main'] ?? 0;
                            if (empty($oliveSupplierId)) {
                                throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ø²ÙŠØª Ø§Ù„Ø²ÙŠØªÙˆÙ†.');
                            }
                            $oliveSupplier = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$oliveSupplierId]);
                            if (!$oliveSupplier) {
                                throw new Exception('Ù…ÙˆØ±Ø¯ Ø²ÙŠØª Ø§Ù„Ø²ÙŠØªÙˆÙ† ØºÙŠØ± ØµØ§Ù„Ø­.');
                            }
                            $allSuppliers[] = [
                                'id' => $oliveSupplier['id'],
                                'name' => $oliveSupplier['name'],
                                'type' => $oliveSupplier['type'],
                                'material' => 'Ø²ÙŠØª Ø²ÙŠØªÙˆÙ†'
                            ];
                            break;

                        case 'beeswax':
                            $beeswaxSupplierId = $materialSuppliers['beeswax_main'] ?? 0;
                            if (empty($beeswaxSupplierId)) {
                                throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ø´Ù…Ø¹ Ø§Ù„Ø¹Ø³Ù„.');
                            }
                            $beeswaxSupplier = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$beeswaxSupplierId]);
                            if (!$beeswaxSupplier) {
                                throw new Exception('Ù…ÙˆØ±Ø¯ Ø´Ù…Ø¹ Ø§Ù„Ø¹Ø³Ù„ ØºÙŠØ± ØµØ§Ù„Ø­.');
                            }
                            $allSuppliers[] = [
                                'id' => $beeswaxSupplier['id'],
                                'name' => $beeswaxSupplier['name'],
                                'type' => $beeswaxSupplier['type'],
                                'material' => 'Ø´Ù…Ø¹ Ø¹Ø³Ù„'
                            ];
                            break;

                        case 'derivatives':
                            $derivativeSupplierId = $materialSuppliers['derivative_main'] ?? 0;
                            if (empty($derivativeSupplierId)) {
                                throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ø´ØªÙ‚.');
                            }
                            $derivativeSupplier = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$derivativeSupplierId]);
                            if (!$derivativeSupplier) {
                                throw new Exception('Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ø´ØªÙ‚ ØºÙŠØ± ØµØ§Ù„Ø­.');
                            }
                            $allSuppliers[] = [
                                'id' => $derivativeSupplier['id'],
                                'name' => $derivativeSupplier['name'],
                                'type' => $derivativeSupplier['type'],
                                'material' => 'Ù…Ø´ØªÙ‚'
                            ];
                            break;

                        case 'honey':
                        case 'legacy':
                        default:
                            $honeySupplierIdSelected = $materialSuppliers['honey_main'] ?? 0;
                            if ((float)($template['honey_quantity'] ?? 0) > 0) {
                                if (empty($honeySupplierIdSelected)) {
                                    throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ø§Ù„Ø¹Ø³Ù„.');
                                }
                                $honeySupp = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$honeySupplierIdSelected]);
                                if (!$honeySupp) {
                                    throw new Exception('Ù…ÙˆØ±Ø¯ Ø§Ù„Ø¹Ø³Ù„ ØºÙŠØ± ØµØ§Ù„Ø­.');
                                }
                            $selectedHoneyVariety = $materialHoneyVarieties['honey_main'] ?? '';
                            if ($selectedHoneyVariety === '') {
                                throw new Exception('ÙŠØ±Ø¬Ù‰ ØªØ­Ø¯ÙŠØ¯ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù… ÙÙŠ Ø§Ù„ØªØ´ØºÙŠÙ„Ø©.');
                            }
                            $honeySupplierId = $honeySupp['id'];
                            $honeyVariety = $selectedHoneyVariety;
                                $allSuppliers[] = [
                                    'id' => $honeySupp['id'],
                                    'name' => $honeySupp['name'],
                                    'type' => $honeySupp['type'],
                                'material' => 'Ø¹Ø³Ù„ (' . $selectedHoneyVariety . ')',
                                'honey_variety' => $selectedHoneyVariety
                                ];

                            $materialsConsumption['raw'][] = [
                                'template_material_id' => null,
                                'supplier_id' => $honeySupplierId,
                                'material_type' => 'honey_filtered',
                                'material_name' => 'Ø¹Ø³Ù„',
                                'honey_variety' => $selectedHoneyVariety,
                                'unit' => 'ÙƒØ¬Ù…',
                                'display_name' => 'Ø¹Ø³Ù„ (' . $selectedHoneyVariety . ')',
                                'quantity' => (float)($template['honey_quantity'] ?? 0) * $quantity
                            ];
                            }
                            break;
                    }

                    // Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù… Ø§Ù„Ø¥Ø¶Ø§ÙÙŠØ© Ø§Ù„Ù…Ø±ØªØ¨Ø·Ø© Ø¨Ø§Ù„Ù‚Ø§Ù„Ø¨ Ø§Ù„Ù‚Ø¯ÙŠÙ…
                    $legacyRawMaterials = $db->query(
                        "SELECT id, material_name, quantity_per_unit, unit 
                         FROM product_template_raw_materials 
                         WHERE template_id = ?",
                        [$templateId]
                    );

                    foreach ($legacyRawMaterials as $legacyRaw) {
                        $rawKey = 'raw_' . $legacyRaw['id'];
                        $selectedSupplierId = $materialSuppliers[$rawKey] ?? 0;
                        if (empty($selectedSupplierId)) {
                            throw new Exception('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ù„Ù„Ù…Ø§Ø¯Ø© Ø§Ù„Ø®Ø§Ù…: ' . ($legacyRaw['material_name'] ?? 'Ù…Ø§Ø¯Ø© Ø®Ø§Ù…'));
                        }

                        $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                        if (!$supplierInfo) {
                            throw new Exception('Ù…ÙˆØ±Ø¯ ØºÙŠØ± ØµØ§Ù„Ø­ Ù„Ù„Ù…Ø§Ø¯Ø© Ø§Ù„Ø®Ø§Ù…: ' . ($legacyRaw['material_name'] ?? 'ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙ'));
                        }

                        $allSuppliers[] = [
                            'id' => $supplierInfo['id'],
                            'name' => $supplierInfo['name'],
                            'type' => $supplierInfo['type'],
                            'material' => $legacyRaw['material_name'] ?? 'Ù…Ø§Ø¯Ø© Ø®Ø§Ù…'
                        ];
                    }
                }
                
                // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØªÙˆÙØ± Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ù„Ø¯Ù‰ Ø§Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ø­Ø¯Ø¯
                $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
                foreach ($materialsConsumption['raw'] as $rawItem) {
                    $materialType = $rawItem['material_type'] ?? '';
                    if (!in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                        continue;
                    }
                    
                    $supplierForHoney = $rawItem['supplier_id'] ?? null;
                    $requiredHoneyQuantity = (float)($rawItem['quantity'] ?? 0);
                    if (!$supplierForHoney || $requiredHoneyQuantity <= 0) {
                        continue;
                    }
                    
                    if ($honeyStockTableCheck) {
                        $stockColumn = $materialType === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                        $params = [$supplierForHoney];
                        $honeySql = "SELECT {$stockColumn} AS available_quantity, honey_variety 
                                     FROM honey_stock 
                                     WHERE supplier_id = ?";
                        
                        if (!empty($rawItem['honey_variety'])) {
                            $honeySql .= " AND honey_variety = ?";
                            $params[] = $rawItem['honey_variety'];
                        }
                        
                        $honeySql .= " ORDER BY {$stockColumn} DESC LIMIT 1";
                        $supplierHoney = $db->queryOne($honeySql, $params);
                        
                        if (!$supplierHoney) {
                            $varietyLabel = $rawItem['honey_variety'] ?: ($rawItem['material_name'] ?: 'Ø§Ù„Ø¹Ø³Ù„ Ø§Ù„Ù…Ø·Ù„ÙˆØ¨');
                            throw new Exception('Ø§Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ø­Ø¯Ø¯ Ù„Ø§ ÙŠÙ…ØªÙ„Ùƒ Ù…Ø®Ø²ÙˆÙ†Ø§Ù‹ Ù…Ù† Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„: ' . $varietyLabel);
                        }
                        
                        $availableHoney = (float)($supplierHoney['available_quantity'] ?? 0);
                        if ($availableHoney < $requiredHoneyQuantity) {
                            $varietyLabel = $supplierHoney['honey_variety'] ?? $rawItem['honey_variety'] ?? ($rawItem['material_name'] ?: 'Ø§Ù„Ø¹Ø³Ù„ Ø§Ù„Ù…Ø·Ù„ÙˆØ¨');
                            throw new Exception(sprintf(
                                'Ø§Ù„ÙƒÙ…ÙŠØ© Ø§Ù„Ù…ØªØ§Ø­Ø© Ù…Ù† %s Ù„Ø¯Ù‰ Ø§Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ø®ØªØ§Ø± ØºÙŠØ± ÙƒØ§ÙÙŠØ©. Ù…Ø·Ù„ÙˆØ¨ %.2f ÙƒØ¬Ù…ØŒ Ù…ØªÙˆÙØ± %.2f ÙƒØ¬Ù….',
                                $varietyLabel,
                                $requiredHoneyQuantity,
                                $availableHoney
                            ));
                        }
                    }
                }
                
                // ØªØ­Ø¯ÙŠØ¯ user_id
                $selectedUserId = $currentUser['role'] === 'production' ? $currentUser['id'] : intval($_POST['user_id'] ?? $currentUser['id']);
                
                // 3. Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø¹Ù…Ø§Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø§Ù„Ø­Ø§Ø¶Ø±ÙŠÙ† Ø®Ù„Ø§Ù„ Ø§Ù„ÙŠÙˆÙ…
                $workersList = [];
                $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                if (!empty($attendanceTableCheck)) {
                    // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø¹Ù…Ø§Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø§Ù„Ø°ÙŠÙ† Ø³Ø¬Ù„ÙˆØ§ Ø­Ø¶ÙˆØ± Ø§Ù„ÙŠÙˆÙ…
                    $presentWorkers = $db->query(
                        "SELECT DISTINCT user_id 
                         FROM attendance_records 
                         WHERE date = ? 
                         AND check_in_time IS NOT NULL 
                         AND user_id IN (SELECT id FROM users WHERE role = 'production' AND status = 'active')
                         ORDER BY check_in_time DESC",
                        [$productionDate]
                    );
                    
                    foreach ($presentWorkers as $worker) {
                        $workersList[] = intval($worker['user_id']);
                    }
                }
                
                // Ø¥Ø°Ø§ Ù„Ù… ÙŠÙˆØ¬Ø¯ Ø¹Ù…Ø§Ù„ Ø­Ø§Ø¶Ø±ÙŠÙ†ØŒ Ø¥Ø¶Ø§ÙØ© Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù… Ø§Ù„Ø­Ø§Ù„ÙŠ ÙÙ‚Ø·
                if (empty($workersList)) {
                    $workersList = [$selectedUserId];
                }
                
                // 4. Ø§Ù„Ù…Ù„Ø§Ø­Ø¸Ø§Øª: Ø¥Ù†Ø´Ø§Ø¡ Ù…Ù„Ø§Ø­Ø¸Ø§Øª ØªÙ„Ù‚Ø§Ø¦ÙŠØ© ØªØ´Ù…Ù„ Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ†
                $batchNotes = trim($_POST['batch_notes'] ?? '');
                if (empty($batchNotes)) {
                    $notesParts = ['ØªÙ… Ø¥Ù†Ø´Ø§Ø¡Ù‡ Ù…Ù† Ù‚Ø§Ù„Ø¨: ' . $template['product_name']];
                    
                    // Ø¥Ø¶Ø§ÙØ© Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ† Ø¥Ù„Ù‰ Ø§Ù„Ù…Ù„Ø§Ø­Ø¸Ø§Øª
                    if (!empty($allSuppliers)) {
                        $supplierNames = [];
                        foreach ($allSuppliers as $supplier) {
                            $supplierNames[] = $supplier['name'] . ' (' . $supplier['material'] . ')';
                        }
                        $notesParts[] = 'Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ†: ' . implode(', ', $supplierNames);
                    }
                    
                    $batchNotes = implode(' | ', $notesParts);
                }
                
                // Ø¥Ù†Ø´Ø§Ø¡ Ø³Ø¬Ù„ Ø¥Ù†ØªØ§Ø¬ ÙˆØ§Ø­Ø¯ Ù„Ù„ØªØ´ØºÙŠÙ„Ø©
                $columns = ['product_id', 'quantity'];
                $values = [$productId, $quantity]; // Ø§Ù„ÙƒÙ…ÙŠØ© Ø§Ù„ÙƒØ§Ù…Ù„Ø©
                $placeholders = ['?', '?'];
                
                $columns[] = $dateColumn;
                $values[] = $productionDate;
                $placeholders[] = '?';
                
                if ($userIdColumn) {
                    $columns[] = $userIdColumn;
                    $values[] = $selectedUserId;
                    $placeholders[] = '?';
                }
                
                $columns[] = 'status';
                $values[] = 'completed';
                $placeholders[] = '?';
                
                $sql = "INSERT INTO production (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $result = $db->execute($sql, $values);
                $productionId = $result['insert_id'];
                
                // Ø¥Ù†Ø´Ø§Ø¡ Ø±Ù‚Ù… ØªØ´ØºÙŠÙ„Ø© ÙˆØ§Ø­Ø¯ Ù„Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…Ù†ØªØ¬Ø§Øª
                $batchResult = createBatchNumber(
                    $productId,
                    $productionId,
                    $productionDate,
                    $honeySupplierId, // Ù…ÙˆØ±Ø¯ Ø§Ù„Ø¹Ø³Ù„ (Ù„Ù„ØªÙˆØ§ÙÙ‚)
                    $packagingIds,
                    $packagingSupplierId, 
                    $workersList, // Ø§Ù„Ø¹Ù…Ø§Ù„ Ø§Ù„Ø­Ø§Ø¶Ø±ÙŠÙ† (ØªÙ„Ù‚Ø§Ø¦ÙŠ)
                    $quantity, // Ø§Ù„ÙƒÙ…ÙŠØ© Ø§Ù„ÙƒØ§Ù…Ù„Ø©
                    null, // expiry_date
                    $batchNotes, // Ø§Ù„Ù…Ù„Ø§Ø­Ø¸Ø§Øª (ØªÙ„Ù‚Ø§Ø¦ÙŠØ©)
                    $currentUser['id'],
                    $allSuppliers, // Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ† Ù…Ø¹ Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù…
                    $honeyVariety ?? null // Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…
                );
                
                if (!$batchResult['success']) {
                    throw new Exception('ÙØ´Ù„ ÙÙŠ Ø¥Ù†Ø´Ø§Ø¡ Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø©: ' . $batchResult['message']);
                }
                
                $batchNumber = $batchResult['batch_number'];

                storeProductionMaterialsUsage($productionId, $materialsConsumption['raw'], $materialsConsumption['packaging']);

                try {
                    foreach ($materialsConsumption['raw'] as $rawItem) {
                        $deductQuantity = (float)($rawItem['quantity'] ?? 0);
                        if ($deductQuantity <= 0) {
                            continue;
                        }

                        $materialType = $rawItem['material_type'] ?? '';
                        $supplierForDeduction = $rawItem['supplier_id'] ?? null;
                        $materialName = $rawItem['material_name'] ?? '';

                        switch ($materialType) {
                            case 'honey_raw':
                            case 'honey_filtered':
                                if ($supplierForDeduction) {
                                    $stockColumn = $materialType === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                                    $db->execute(
                                        "UPDATE honey_stock 
                                         SET {$stockColumn} = GREATEST({$stockColumn} - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'olive_oil':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE olive_oil_stock 
                                         SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'beeswax':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE beeswax_stock 
                                         SET weight = GREATEST(weight - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'derivatives':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE derivatives_stock 
                                         SET weight = GREATEST(weight - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'nuts':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE nuts_stock 
                                         SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'legacy':
                                // Ù„Ø§ ÙŠÙˆØ¬Ø¯ ØªØ¹Ø±ÙŠÙ ÙˆØ§Ø¶Ø­ Ù„Ù„Ù…ÙˆØ±Ø¯ ÙÙŠ Ø§Ù„Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù‚Ø¯ÙŠÙ…Ø©ØŒ ÙŠØªÙ… ØªØ¬Ø§Ù‡Ù„ Ø§Ù„Ø®ØµÙ… ØªÙ„Ù‚Ø§Ø¦ÙŠØ§Ù‹
                                break;
                            default:
                                if ($materialName !== '') {
                                    $matchedProduct = $db->queryOne(
                                        "SELECT id FROM products WHERE name = ? LIMIT 1",
                                        [$materialName]
                                    );
                                    if ($matchedProduct) {
                                        $db->execute(
                                            "UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?",
                                            [$deductQuantity, $matchedProduct['id']]
                                        );
                                    }
                                }
                                break;
                        }
                    }

                    foreach ($materialsConsumption['packaging'] as $packItem) {
                        $packMaterialId = $packItem['material_id'] ?? null;
                        $packQuantity = (float)($packItem['quantity'] ?? 0);
                        if ($packMaterialId && $packQuantity > 0) {
                            $db->execute(
                                "UPDATE packaging_materials 
                                 SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                 WHERE id = ?",
                                [$packQuantity, $packMaterialId]
                            );
                        }
                    }
                } catch (Exception $stockWarning) {
                    error_log('Production stock deduction warning: ' . $stockWarning->getMessage());
                }
                
                // Ø¥Ù†Ø´Ø§Ø¡ Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª Ù…ØªØ¹Ø¯Ø¯Ø© Ø­Ø³Ø¨ Ø§Ù„ÙƒÙ…ÙŠØ©
                $batchNumbersToPrint = [];
                for ($i = 0; $i < $quantity; $i++) {
                    $batchNumbersToPrint[] = $batchNumber;
                }
                
                $db->commit();
                
                logAudit($currentUser['id'], 'create_from_template', 'production', $productionId, null, [
                    'template_id' => $templateId,
                    'quantity' => $quantity,
                    'batch_number' => $batchNumber,
                    'honey_supplier_id' => $honeySupplierId,
                    'packaging_supplier_id' => $packagingSupplierId
                ]);
                
                // Ø­ÙØ¸ Ø£Ø±Ù‚Ø§Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø© ÙÙŠ session Ù„Ø¹Ø±Ø¶Ù‡Ø§ ÙÙŠ modal Ø§Ù„Ø·Ø¨Ø§Ø¹Ø©
                $_SESSION['created_batch_numbers'] = $batchNumbersToPrint; // Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª Ù…ØªØ¹Ø¯Ø¯Ø© Ø­Ø³Ø¨ Ø§Ù„ÙƒÙ…ÙŠØ©
                $_SESSION['created_batch_product_name'] = $template['product_name'];
                $_SESSION['created_batch_quantity'] = $quantity;
                
                // Ù…Ù†Ø¹ Ø§Ù„ØªÙƒØ±Ø§Ø± Ø¨Ø§Ø³ØªØ®Ø¯Ø§Ù… redirect
                $successMessage = 'ØªÙ… Ø¥Ù†Ø´Ø§Ø¡ ØªØ´ØºÙŠÙ„Ø© Ø¥Ù†ØªØ§Ø¬ Ø¨Ù†Ø¬Ø§Ø­! Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø©: ' . $batchNumber . ' (Ø§Ù„ÙƒÙ…ÙŠØ©: ' . $quantity . ' Ù‚Ø·Ø¹Ø©)';
                $redirectParams = ['page' => 'production', 'show_barcode_modal' => '1'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Ø­Ø¯Ø« Ø®Ø·Ø£ ÙÙŠ Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„Ø¥Ù†ØªØ§Ø¬: ' . $e->getMessage();
                error_log("Create from template error: " . $e->getMessage());
            }
        }
    }
    // ØªÙ… Ù†Ù‚Ù„ Ø¥Ù†Ø´Ø§Ø¡ Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù…Ù†ØªØ¬Ø§Øª Ø¥Ù„Ù‰ ØµÙØ­Ø© Ù…Ø®Ø²Ù† Ø§Ù„Ø®Ø§Ù…Ø§Øª
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 5; // 5 Ø¹Ù†Ø§ØµØ± Ù„ÙƒÙ„ ØµÙØ­Ø©
$offset = ($pageNum - 1) * $perPage;

// Ø§Ù„Ø¨Ø­Ø« ÙˆØ§Ù„ÙÙ„ØªØ±Ø©
$search = $_GET['search'] ?? '';
$productId = $_GET['product_id'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Ø¨Ù†Ø§Ø¡ Ø§Ø³ØªØ¹Ù„Ø§Ù… Ø§Ù„Ø¨Ø­Ø«
$whereConditions = ['1=1'];
$params = [];

if ($search) {
    if ($userIdColumn) {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ? OR u.full_name LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    } else {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
}

if ($productId) {
    $whereConditions[] = "p.product_id = ?";
    $params[] = intval($productId);
}

if ($status) {
    $whereConditions[] = "p.status = ?";
    $params[] = $status;
}

if ($dateFrom) {
    $whereConditions[] = "DATE(p.$dateColumn) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(p.$dateColumn) <= ?";
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// Ø­Ø³Ø§Ø¨ Ø§Ù„Ø¹Ø¯Ø¯ Ø§Ù„Ø¥Ø¬Ù…Ø§Ù„ÙŠ
if ($userIdColumn) {
    $countSql = "SELECT COUNT(*) as total 
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 LEFT JOIN users u ON p.{$userIdColumn} = u.id
                 WHERE $whereClause";
} else {
    $countSql = "SELECT COUNT(*) as total 
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 WHERE $whereClause";
}

$totalResult = $db->queryOne($countSql, $params);
$totalProduction = $totalResult['total'] ?? 0;
$totalPages = ceil($totalProduction / $perPage);

// Ø­Ø³Ø§Ø¨ Ø¥Ø¬Ù…Ø§Ù„ÙŠ Ø§Ù„ÙƒÙ…ÙŠØ© Ø§Ù„Ù…Ù†ØªØ¬Ø©
$totalQuantitySql = str_replace('COUNT(*) as total', 'COALESCE(SUM(p.quantity), 0) as total', $countSql);
$totalQuantityResult = $db->queryOne($totalQuantitySql, $params);
$totalQuantity = floatval($totalQuantityResult['total'] ?? 0);

// Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
$batchJoinSelect = $hasBatchNumbersTable ? ', bn.batch_number as batch_number' : ', NULL as batch_number';
$batchJoinClause = '';

if ($hasBatchNumbersTable) {
    $batchJoinClause = "
            LEFT JOIN (
                SELECT b1.production_id, b1.batch_number
                FROM batch_numbers b1
                WHERE b1.production_id IS NOT NULL
                  AND b1.id = (
                      SELECT MAX(b2.id)
                      FROM batch_numbers b2
                      WHERE b2.production_id = b1.production_id
                  )
            ) bn ON bn.production_id = p.id";
}

if ($userIdColumn) {
    $sql = "SELECT p.*, 
                   pr.name as product_name, 
                   pr.category as product_category,
                   u.full_name as worker_name,
                   u.username as worker_username
                   $batchJoinSelect
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id
            LEFT JOIN users u ON p.{$userIdColumn} = u.id
            $batchJoinClause
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
} else {
    $sql = "SELECT p.*, 
                   pr.name as product_name, 
                   pr.category as product_category,
                   'ØºÙŠØ± Ù…Ø­Ø¯Ø¯' as worker_name,
                   'ØºÙŠØ± Ù…Ø­Ø¯Ø¯' as worker_username
                   $batchJoinSelect
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id
            $batchJoinClause
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
}

$params[] = $perPage;
$params[] = $offset;

$productions = $db->query($sql, $params);

// Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø§Ù„Ù…Ù†ØªØ¬Ø§Øª ÙˆØ§Ù„Ø¹Ù…Ø§Ù„
$products = $db->query("SELECT id, name, category FROM products WHERE status = 'active' ORDER BY name");
$workers = $db->query("SELECT id, username, full_name FROM users WHERE role = 'production' AND status = 'active' ORDER BY username");

// Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ†
$suppliers = [];
$suppliersTableCheck = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
if (!empty($suppliersTableCheck)) {
    $suppliers = $db->query("SELECT id, name, type FROM suppliers WHERE status = 'active' ORDER BY name");
}

// Ø¥Ù†Ø´Ø§Ø¡ Ø¬Ø¯Ø§ÙˆÙ„ Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù…Ù†ØªØ¬Ø§Øª Ø¥Ø°Ø§ Ù„Ù… ØªÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø©
try {
    $templatesTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
    if (empty($templatesTableCheck)) {
        // Ø¥Ù†Ø´Ø§Ø¡ Ø¬Ø¯ÙˆÙ„ product_templates
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL COMMENT 'Ø§Ø³Ù… Ø§Ù„Ù…Ù†ØªØ¬',
              `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'ÙƒÙ…ÙŠØ© Ø§Ù„Ø¹Ø³Ù„ Ø¨Ø§Ù„Ø¬Ø±Ø§Ù…',
              `status` enum('active','inactive') DEFAULT 'active',
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ÙˆØ¬ÙˆØ¯ Ø¹Ù…ÙˆØ¯ honey_quantity ÙˆØ¥Ø¶Ø§ÙØªÙ‡ Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
        $honeyColumnCheck = $db->queryOne("SHOW COLUMNS FROM product_templates LIKE 'honey_quantity'");
        if (empty($honeyColumnCheck)) {
            try {
                $db->execute("
                    ALTER TABLE `product_templates` 
                    ADD COLUMN `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'ÙƒÙ…ÙŠØ© Ø§Ù„Ø¹Ø³Ù„ Ø¨Ø§Ù„Ø¬Ø±Ø§Ù…' 
                    AFTER `product_name`
                ");
                error_log("Added honey_quantity column to product_templates table");
            } catch (Exception $e) {
                error_log("Error adding honey_quantity column: " . $e->getMessage());
            }
        }
    }
    
    // Ø¥Ù†Ø´Ø§Ø¡ Ø¬Ø¯ÙˆÙ„ product_template_packaging
    $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_packaging'");
    if (empty($packagingTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_template_packaging` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `packaging_material_id` int(11) DEFAULT NULL,
              `packaging_name` varchar(255) NOT NULL,
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`),
              KEY `packaging_material_id` (`packaging_material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // Ø¥Ù†Ø´Ø§Ø¡ Ø¬Ø¯ÙˆÙ„ product_template_raw_materials
    $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
    if (empty($rawMaterialsTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `material_name` varchar(255) NOT NULL COMMENT 'Ø§Ø³Ù… Ø§Ù„Ù…Ø§Ø¯Ø© (Ù…Ø«Ù„: Ù…ÙƒØ³Ø±Ø§ØªØŒ Ù„ÙˆØ²ØŒ Ø¥Ù„Ø®)',
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Ø§Ù„ÙƒÙ…ÙŠØ© Ø¨Ø§Ù„Ø¬Ø±Ø§Ù…',
              `unit` varchar(50) DEFAULT 'Ø¬Ø±Ø§Ù…',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        
        $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_packaging'");
        if (empty($packagingTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_packaging` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `packaging_material_id` int(11) DEFAULT NULL,
                  `packaging_name` varchar(255) NOT NULL,
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`),
                  KEY `packaging_material_id` (`packaging_material_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
        if (empty($rawMaterialsTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `material_name` varchar(255) NOT NULL COMMENT 'Ø§Ø³Ù… Ø§Ù„Ù…Ø§Ø¯Ø© (Ù…Ø«Ù„: Ù…ÙƒØ³Ø±Ø§ØªØŒ Ù„ÙˆØ²ØŒ Ø¥Ù„Ø®)',
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Ø§Ù„ÙƒÙ…ÙŠØ© Ø¨Ø§Ù„Ø¬Ø±Ø§Ù…',
                  `unit` varchar(50) DEFAULT 'Ø¬Ø±Ø§Ù…',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
} catch (Exception $e) {
    error_log("Product templates tables creation error: " . $e->getMessage());
}

// Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù…Ù†ØªØ¬Ø§Øª Ù…Ù† Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø£Ù‚Ø³Ø§Ù…
$templates = [];

// 0. Ø§Ù„Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù…ÙˆØ­Ø¯Ø© Ø§Ù„Ø¬Ø¯ÙŠØ¯Ø© (Ù…ØªØ¹Ø¯Ø¯Ø© Ø§Ù„Ù…ÙˆØ§Ø¯)
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (!empty($unifiedTemplatesCheck)) {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ÙˆØ¬ÙˆØ¯ Ø¬Ø¯ÙˆÙ„ template_raw_materials ÙˆØ¹Ù…ÙˆØ¯ honey_variety
    $templateRawMaterialsCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
    $hasHoneyVariety = false;
    
    if (!empty($templateRawMaterialsCheck)) {
        $honeyVarietyCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'honey_variety'");
        $hasHoneyVariety = !empty($honeyVarietyCheck);
        
        // Ø¥Ø¶Ø§ÙØ© Ø§Ù„Ø¹Ù…ÙˆØ¯ Ø¥Ø°Ø§ Ù„Ù… ÙŠÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹
        if (!$hasHoneyVariety) {
            try {
                $db->execute("
                    ALTER TABLE `template_raw_materials` 
                    ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ (Ø³Ø¯Ø±ØŒ Ø¬Ø¨Ù„ÙŠØŒ Ø¥Ù„Ø®)' 
                    AFTER `supplier_id`
                ");
                $hasHoneyVariety = true;
            } catch (Exception $e) {
                error_log("Error adding honey_variety column: " . $e->getMessage());
            }
        }
    }
    
    $unifiedTemplates = $db->query(
        "SELECT upt.*, 
                'unified' as template_type,
                u.full_name as creator_name
         FROM unified_product_templates upt
         LEFT JOIN users u ON upt.created_by = u.id
         WHERE upt.status = 'active'
         ORDER BY upt.created_at DESC"
    );
    
    foreach ($unifiedTemplates as &$template) {
        // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù… Ù„Ù„Ù‚Ø§Ù„Ø¨
        if (!empty($templateRawMaterialsCheck)) {
            $selectColumns = "material_type, material_name, quantity, unit";
            if ($hasHoneyVariety) {
                $selectColumns = "material_type, material_name, honey_variety, quantity, unit";
            }
            
            $rawMaterials = $db->query(
                "SELECT {$selectColumns}
                 FROM template_raw_materials 
                 WHERE template_id = ?",
                [$template['id']]
            );
        } else {
            $rawMaterials = [];
        }
        
        $template['material_details'] = [];
        foreach ($rawMaterials as $material) {
            // ØªØ±Ø¬Ù…Ø© Ø§Ø³Ù… Ø§Ù„Ù…Ø§Ø¯Ø© Ø¥Ù„Ù‰ Ø§Ù„Ø¹Ø±Ø¨ÙŠØ©
            $materialNameArabic = $material['material_name'];
            
            // Ù‚Ø§Ù…ÙˆØ³ Ø§Ù„ØªØ±Ø¬Ù…Ø© Ù„Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø´Ø§Ø¦Ø¹Ø©
            $materialTranslations = [
                ':honey_filtered' => 'Ø¹Ø³Ù„ Ù…ØµÙÙ‰',
                ':honey_raw' => 'Ø¹Ø³Ù„ Ø®Ø§Ù…',
                'honey_filtered' => 'Ø¹Ø³Ù„ Ù…ØµÙÙ‰',
                'honey_raw' => 'Ø¹Ø³Ù„ Ø®Ø§Ù…',
                ':olive_oil' => 'Ø²ÙŠØª Ø²ÙŠØªÙˆÙ†',
                'olive_oil' => 'Ø²ÙŠØª Ø²ÙŠØªÙˆÙ†',
                ':beeswax' => 'Ø´Ù…Ø¹ Ø¹Ø³Ù„',
                'beeswax' => 'Ø´Ù…Ø¹ Ø¹Ø³Ù„',
                ':derivatives' => 'Ù…Ø´ØªÙ‚Ø§Øª',
                'derivatives' => 'Ù…Ø´ØªÙ‚Ø§Øª',
                ':nuts' => 'Ù…ÙƒØ³Ø±Ø§Øª',
                'nuts' => 'Ù…ÙƒØ³Ø±Ø§Øª',
                ':other' => 'Ù…ÙˆØ§Ø¯ Ø£Ø®Ø±Ù‰',
                'other' => 'Ù…ÙˆØ§Ø¯ Ø£Ø®Ø±Ù‰'
            ];
            
            // ØªØ·Ø¨ÙŠÙ‚ Ø§Ù„ØªØ±Ø¬Ù…Ø© Ø¥Ø°Ø§ ÙˆÙØ¬Ø¯Øª
            if (isset($materialTranslations[$materialNameArabic])) {
                $materialNameArabic = $materialTranslations[$materialNameArabic];
            }
            
            $materialDisplay = $materialNameArabic;
            
            // Ø¥Ø¶Ø§ÙØ© Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ø¥Ù† ÙˆÙØ¬Ø¯ (ÙÙ‚Ø· Ø¥Ø°Ø§ ÙƒØ§Ù† Ø§Ù„Ø¹Ù…ÙˆØ¯ Ù…ÙˆØ¬ÙˆØ¯Ø§Ù‹)
            if ($hasHoneyVariety 
                && ($material['material_type'] === 'honey_raw' || $material['material_type'] === 'honey_filtered') 
                && !empty($material['honey_variety'])) {
                $materialDisplay .= ' (' . $material['honey_variety'] . ')';
            }
            
            $template['material_details'][] = [
                'type' => $materialDisplay,
                'quantity' => $material['quantity'],
                'unit' => $material['unit']
            ];
        }
    }
    
    // ØªØµÙÙŠØ© Ø§Ù„Ù‚ÙˆØ§Ù„Ø¨: Ø¥Ø²Ø§Ù„Ø© Ø§Ù„Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„ÙØ§Ø±ØºØ© (Ø§Ù„ØªÙŠ Ù„ÙŠØ³ Ù„Ù‡Ø§ Ù…ÙˆØ§Ø¯ Ø®Ø§Ù…)
    $unifiedTemplates = array_filter($unifiedTemplates, function($template) {
        return !empty($template['material_details']);
    });
    
    $templates = array_merge($templates, $unifiedTemplates);
}

// 1. Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ø¹Ø³Ù„ (Ø§Ù„Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù‚Ø¯ÙŠÙ…Ø©)
$honeyTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
if (!empty($honeyTemplatesCheck)) {
    $honeyTemplates = $db->query(
        "SELECT pt.*, 
                'honey' as template_type,
                u.full_name as creator_name
         FROM product_templates pt
         LEFT JOIN users u ON pt.created_by = u.id
         WHERE pt.status = 'active'
         ORDER BY pt.created_at DESC"
    );
    foreach ($honeyTemplates as &$template) {
        $template['material_details'] = [
            ['type' => 'Ø¹Ø³Ù„', 'quantity' => $template['honey_quantity'], 'unit' => 'Ø¬Ø±Ø§Ù…']
        ];
    }
    $templates = array_merge($templates, $honeyTemplates);
}

// 2. Ù‚ÙˆØ§Ù„Ø¨ Ø²ÙŠØª Ø§Ù„Ø²ÙŠØªÙˆÙ†
$oliveOilTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_product_templates'");
if (!empty($oliveOilTemplatesCheck)) {
    $oliveOilTemplates = $db->query(
        "SELECT id, product_name, olive_oil_quantity, created_at,
                'olive_oil' as template_type
         FROM olive_oil_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($oliveOilTemplates as &$template) {
        $template['material_details'] = [
            ['type' => 'Ø²ÙŠØª Ø²ÙŠØªÙˆÙ†', 'quantity' => $template['olive_oil_quantity'], 'unit' => 'Ù„ØªØ±']
        ];
        $template['creator_name'] = '';
    }
    $templates = array_merge($templates, $oliveOilTemplates);
}

// 3. Ù‚ÙˆØ§Ù„Ø¨ Ø´Ù…Ø¹ Ø§Ù„Ø¹Ø³Ù„
$beeswaxTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'beeswax_product_templates'");
if (!empty($beeswaxTemplatesCheck)) {
    $beeswaxTemplates = $db->query(
        "SELECT id, product_name, beeswax_weight, created_at,
                'beeswax' as template_type
         FROM beeswax_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($beeswaxTemplates as &$template) {
        $template['material_details'] = [
            ['type' => 'Ø´Ù…Ø¹ Ø¹Ø³Ù„', 'quantity' => $template['beeswax_weight'], 'unit' => 'ÙƒØ¬Ù…']
        ];
        $template['creator_name'] = '';
    }
    $templates = array_merge($templates, $beeswaxTemplates);
}

// 4. Ù‚ÙˆØ§Ù„Ø¨ Ø§Ù„Ù…Ø´ØªÙ‚Ø§Øª
$derivativesTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'derivatives_product_templates'");
if (!empty($derivativesTemplatesCheck)) {
    $derivativesTemplates = $db->query(
        "SELECT id, product_name, derivative_type, derivative_weight, created_at,
                'derivatives' as template_type
         FROM derivatives_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($derivativesTemplates as &$template) {
        // ØªØ±Ø¬Ù…Ø© Ù†ÙˆØ¹ Ø§Ù„Ù…Ø´ØªÙ‚ Ø¥Ù„Ù‰ Ø§Ù„Ø¹Ø±Ø¨ÙŠØ©
        $derivativeTypeArabic = $template['derivative_type'];
        $derivativeTranslations = [
            'royal_jelly' => 'ØºØ°Ø§Ø¡ Ù…Ù„ÙƒØ§Øª Ø§Ù„Ù†Ø­Ù„',
            'propolis' => 'Ø§Ù„Ø¨Ø±ÙˆØ¨ÙˆÙ„ÙŠØ³',
            'pollen' => 'Ø­Ø¨ÙˆØ¨ Ø§Ù„Ù„Ù‚Ø§Ø­',
            'other' => 'Ù…Ø´ØªÙ‚ Ø¢Ø®Ø±'
        ];
        if (isset($derivativeTranslations[$derivativeTypeArabic])) {
            $derivativeTypeArabic = $derivativeTranslations[$derivativeTypeArabic];
        }
        
        $template['material_details'] = [
            ['type' => $derivativeTypeArabic, 'quantity' => $template['derivative_weight'], 'unit' => 'ÙƒØ¬Ù…']
        ];
        $template['creator_name'] = '';
    }
    $templates = array_merge($templates, $derivativesTemplates);
}

// Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø© Ù„Ù„Ø§Ø³ØªØ®Ø¯Ø§Ù… ÙÙŠ modal Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„Ù‚Ø§Ù„Ø¨
$packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
$packagingMaterials = [];
if (!empty($packagingTableCheck)) {
    $packagingMaterials = $db->query(
        "SELECT id, name, type, quantity, unit FROM packaging_materials WHERE status = 'active' ORDER BY name"
    );
}

$productionReportsTodayDate = date('Y-m-d');
$productionReportsMonthStart = date('Y-m-01');
$productionReportsToday = getConsumptionSummary($productionReportsTodayDate, $productionReportsTodayDate);
$productionReportsMonth = getConsumptionSummary($productionReportsMonthStart, $productionReportsTodayDate);

if (!function_exists('productionPageRenderConsumptionTable')) {
    function productionPageRenderConsumptionTable(array $items, bool $includeCategory = false): void
    {
        if (empty($items)) {
            echo '<div class="text-center text-muted py-4">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¨ÙŠØ§Ù†Ø§Øª Ù…ØªØ§Ø­Ø© Ù„Ù„ÙØªØ±Ø© Ø§Ù„Ù…Ø­Ø¯Ø¯Ø©.</div>';
            return;
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-hover align-middle">';
        echo '<thead class="table-light"><tr>';
        echo '<th>Ø§Ù„Ù…Ø§Ø¯Ø©</th>';
        if ($includeCategory) {
            echo '<th>Ø§Ù„ÙØ¦Ø©</th>';
        }
        echo '<th>Ø§Ù„Ø§Ø³ØªÙ‡Ù„Ø§Ùƒ</th><th>Ø§Ù„ÙˆØ§Ø±Ø¯</th><th>Ø§Ù„ØµØ§ÙÙŠ</th><th>Ø§Ù„Ø­Ø±ÙƒØ§Øª</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'] ?? 'ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙ', ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($item['sub_category'] ?? '-', ENT_QUOTES, 'UTF-8');
            $totalOut = number_format((float)($item['total_out'] ?? 0), 3);
            $totalIn = number_format((float)($item['total_in'] ?? 0), 3);
            $net = number_format((float)($item['net'] ?? 0), 3);
            $movements = (int)($item['movements'] ?? 0);

            echo '<tr>';
            echo '<td>' . $name . '</td>';
            if ($includeCategory) {
                echo '<td><span class="badge bg-secondary text-white">' . $category . '</span></td>';
            }
            echo '<td>' . $totalOut . '</td>';
            echo '<td>' . $totalIn . '</td>';
            echo '<td>' . $net . '</td>';
            echo '<td>' . $movements . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}

if (!function_exists('productionPageSumMovements')) {
    function productionPageSumMovements(array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        return array_sum(array_map(static function ($row) {
            return (int)($row['movements'] ?? 0);
        }, $items));
    }
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam me-2"></i><?php echo isset($lang['production']) ? $lang['production'] : 'Ø§Ù„Ø¥Ù†ØªØ§Ø¬'; ?></h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="mb-4">
    <div class="production-tab-toggle" role="tablist" aria-label="التنقل بين أقسام صفحة الإنتاج">
        <button type="button"
                class="btn btn-outline-primary production-tab-btn active"
                data-production-tab="records"
                aria-pressed="true"
                aria-controls="productionRecordsSection">
            <i class="bi bi-list-task me-1"></i>
            سجلات الإنتاج
        </button>
        <button type="button"
                class="btn btn-outline-primary production-tab-btn"
                data-production-tab="reports"
                aria-pressed="false"
                aria-controls="productionReportsSection">
            <i class="bi bi-graph-up-arrow me-1"></i>
            تقارير الإنتاج
        </button>
    </div>
</div>

<div id="productionRecordsSection">

<!-- قسم قوالب المنتجات -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>قوالب المنتجات - إنشاء إنتاج من قالب</h5>
        <a href="<?php echo getDashboardUrl('production'); ?>?page=raw_materials_warehouse" class="btn btn-light btn-sm">
            <i class="bi bi-plus-circle me-2"></i>إدارة القوالب في مخزن الخامات
        </a>
    </div>
    <?php if (!empty($templates)): ?>
    <div class="card-body template-grid">
        <?php foreach ($templates as $template): ?>
            <?php 
            $templateTypeLabels = [
                'unified' => 'متعدد المواد',
                'honey' => 'عسل',
                'olive_oil' => 'زيت زيتون',
                'beeswax' => 'شمع عسل',
                'derivatives' => 'مشتقات'
            ];
            $typeLabel = $templateTypeLabels[$template['template_type']] ?? 'غير محدد';
            $typeAccents = [
                'unified' => ['#0f172a', '#0f172a22'],
                'honey' => ['#f59e0b', '#f59e0b22'],
                'olive_oil' => ['#16a34a', '#16a34a22'],
                'beeswax' => ['#2563eb', '#2563eb22'],
                'derivatives' => ['#7c3aed', '#7c3aed22']
            ];
            $accentPair = $typeAccents[$template['template_type']] ?? ['#334155', '#33415522'];
            $materialDetails = $template['material_details'] ?? [];
            $materialsPreview = array_slice($materialDetails, 0, 3);
            $hasMoreMaterials = count($materialDetails) > 3;
            $packagingCount = (int)($template['packaging_count'] ?? 0);
            $rawCount = count($materialDetails);
            $productsCount = (int)($template['products_count'] ?? 1);
            ?>
            <div class="template-card-modern"
                 style="--template-accent: <?php echo $accentPair[0]; ?>; --template-accent-light: <?php echo $accentPair[1]; ?>;"
                 data-template-id="<?php echo $template['id']; ?>"
                 data-template-name="<?php echo htmlspecialchars($template['product_name']); ?>"
                 data-template-type="<?php echo htmlspecialchars($template['template_type'] ?? 'legacy'); ?>"
                 onclick="openCreateFromTemplateModal(this)">

                <div class="template-card-header">
                    <div>
                        <span class="template-type-pill">
                            <i class="bi bi-droplet-half me-1"></i><?php echo $typeLabel; ?>
                        </span>
                        <h6 class="template-title mt-2"><?php echo htmlspecialchars($template['product_name']); ?></h6>
                    </div>
                    <div class="template-products-count">
                        <i class="bi bi-box me-1"></i>
                        <?php echo $productsCount; ?>
                    <span>منتج</span>
                    </div>
                </div>

                <div class="template-metrics">
                    <div class="metric-item">
                    <span class="metric-label">أدوات التعبئة</span>
                        <span class="metric-value"><?php echo $packagingCount; ?></span>
                    </div>
                    <div class="metric-separator"></div>
                    <div class="metric-item">
                    <span class="metric-label">مواد خام</span>
                        <span class="metric-value"><?php echo $rawCount; ?></span>
                    </div>
                    <div class="metric-separator"></div>
                    <div class="metric-item">
                    <span class="metric-label">آخر تعديل</span>
                        <span class="metric-value">
                            <?php echo htmlspecialchars(date('Y/m/d', strtotime($template['updated_at'] ?? $template['created_at'] ?? 'now'))); ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($materialsPreview)): ?>
                <div class="template-materials">
                    <?php foreach ($materialsPreview as $material): ?>
                        <div class="material-row">
                            <div class="material-icon">
                                <i class="bi bi-drop"></i>
                            </div>
                            <div class="material-info">
                                <div class="material-name"><?php echo htmlspecialchars($material['type']); ?></div>
                                <div class="material-quantity">
                                    <?php echo number_format($material['quantity'], 2); ?>
                                    <span><?php echo htmlspecialchars($material['unit']); ?></span>
                                </div>
                            </div>
                            <?php if (!empty($material['honey_variety'])): ?>
                                <span class="material-tag"><?php echo htmlspecialchars($material['honey_variety']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($hasMoreMaterials): ?>
                        <div class="materials-more">+ <?php echo $rawCount - count($materialsPreview); ?> مواد إضافية</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="template-actions">
                    <span class="template-action-badge">
                        <i class="bi bi-lightning-charge me-2"></i>ابدأ الإنتاج الآن
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
            <h5 class="text-muted">لا توجد قوالب منتجات</h5>
            <p class="text-muted">قم بإنشاء قوالب المنتجات من صفحة مخزن الخامات</p>
            <a href="<?php echo getDashboardUrl('production'); ?>?page=raw_materials_warehouse" class="btn btn-primary">
                <i class="bi bi-box-seam me-2"></i>الذهاب إلى مخزن الخامات
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- جدول الإنتاج -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i><?php echo isset($lang['production_list']) ? $lang['production_list'] : 'Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ø¥Ù†ØªØ§Ø¬'; ?> (<?php echo $totalProduction; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><?php echo isset($lang['id']) ? $lang['id'] : 'Ø±Ù‚Ù…'; ?></th>
                        <th><?php echo isset($lang['product']) ? $lang['product'] : 'Ø§Ù„Ù…Ù†ØªØ¬'; ?></th>
                        <th><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'Ø§Ù„ÙƒÙ…ÙŠØ©'; ?></th>
                        <th><?php echo isset($lang['worker']) ? $lang['worker'] : 'Ø§Ù„Ø¹Ø§Ù…Ù„'; ?></th>
                        <th><?php echo isset($lang['date']) ? $lang['date'] : 'Ø§Ù„ØªØ§Ø±ÙŠØ®'; ?></th>
                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'Ø§Ù„Ø­Ø§Ù„Ø©'; ?></th>
                        <th><?php echo isset($lang['batch_number']) ? $lang['batch_number'] : 'Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø©'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productions)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                <?php echo isset($lang['no_production']) ? $lang['no_production'] : 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ø³Ø¬Ù„Ø§Øª Ø¥Ù†ØªØ§Ø¬'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productions as $prod): ?>
                            <tr>
                                <td>#<?php echo $prod['id']; ?></td>
                                <td><?php echo htmlspecialchars($prod['product_name'] ?? 'ØºÙŠØ± Ù…Ø­Ø¯Ø¯'); ?></td>
                                <td><?php echo number_format($prod['quantity'] ?? 0, 2); ?></td>
                                <td><?php echo htmlspecialchars($prod['worker_name'] ?? $prod['worker_username'] ?? 'ØºÙŠØ± Ù…Ø­Ø¯Ø¯'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($prod[$dateColumn] ?? $prod['created_at'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        $status = $prod['status'] ?? 'pending';
                                        echo $status === 'approved' ? 'success' : 
                                            ($status === 'rejected' ? 'danger' : 
                                            ($status === 'completed' ? 'info' : 'warning')); 
                                    ?>">
                                        <?php echo isset($lang[$status]) ? $lang[$status] : ucfirst($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($prod['batch_number'])): ?>
                                        <span class="badge bg-secondary text-wrap" style="white-space: normal;"><?php echo htmlspecialchars($prod['batch_number']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">ØºÙŠØ± Ù…ØªÙˆÙØ±</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=production&p=<?php echo $pageNum - 1; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=production&p=1">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=production&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=production&p=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=production&p=<?php echo $pageNum + 1; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
            <div class="text-center mt-2 text-muted">
                <small>Ø¹Ø±Ø¶ <?php echo min($offset + 1, $totalProduction); ?> - <?php echo min($offset + $perPage, $totalProduction); ?> Ù…Ù† <?php echo $totalProduction; ?> Ø³Ø¬Ù„</small>
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>

</div>

</div>

<div id="productionReportsSection" class="d-none">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-calendar-day me-2"></i>Ù…Ù„Ø®Øµ Ø§Ù„ÙŠÙˆÙ…</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($productionReportsToday['date_from'] ?? $productionReportsTodayDate); ?>
                        â€”
                        <?php echo htmlspecialchars($productionReportsToday['date_to'] ?? $productionReportsTodayDate); ?>
                    </p>
                </div>
                <span class="badge bg-light text-primary border border-primary-subtle">
                    Ø¢Ø®Ø± ØªØ­Ø¯ÙŠØ«: <?php echo htmlspecialchars($productionReportsToday['generated_at'] ?? date('Y-m-d H:i:s')); ?>
                </span>
            </div>
            <div class="production-summary-grid mt-3">
                <div class="summary-card">
                    <span class="summary-label">Ø§Ø³ØªÙ‡Ù„Ø§Ùƒ Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø©</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsToday['packaging']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">Ø§Ø³ØªÙ‡Ù„Ø§Ùƒ Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù…</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsToday['raw']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">Ø§Ù„ØµØ§ÙÙŠ Ø§Ù„ÙƒÙ„ÙŠ</span>
                    <span class="summary-value text-success">
                        <?php
                        $todayNet = (float)($productionReportsToday['packaging']['net'] ?? 0) + (float)($productionReportsToday['raw']['net'] ?? 0);
                        echo number_format($todayNet, 3);
                        ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">Ø¥Ø¬Ù…Ø§Ù„ÙŠ Ø§Ù„Ø­Ø±ÙƒØ§Øª</span>
                    <span class="summary-value text-secondary">
                        <?php
                        $todayMovements = productionPageSumMovements($productionReportsToday['packaging']['items'] ?? [])
                            + productionPageSumMovements($productionReportsToday['raw']['items'] ?? []);
                        echo number_format($todayMovements);
                        ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-box-seam me-2"></i>Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø© Ø§Ù„Ù…Ø³ØªÙ‡Ù„ÙƒØ© Ø§Ù„ÙŠÙˆÙ…</span>
        </div>
        <div class="card-body">
            <?php productionPageRenderConsumptionTable($productionReportsToday['packaging']['items'] ?? []); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-5">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-droplet-half me-2"></i>Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù… Ø§Ù„Ù…Ø³ØªÙ‡Ù„ÙƒØ© Ø§Ù„ÙŠÙˆÙ…</span>
        </div>
        <div class="card-body">
            <?php productionPageRenderConsumptionTable($productionReportsToday['raw']['items'] ?? [], true); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-calendar-month me-2"></i>Ù…Ù„Ø®Øµ Ø§Ù„Ø´Ù‡Ø± Ø§Ù„Ø­Ø§Ù„ÙŠ</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($productionReportsMonth['date_from'] ?? $productionReportsMonthStart); ?>
                        â€”
                        <?php echo htmlspecialchars($productionReportsMonth['date_to'] ?? $productionReportsTodayDate); ?>
                    </p>
                </div>
                <span class="badge bg-light text-primary border border-primary-subtle">
                    Ø¢Ø®Ø± ØªØ­Ø¯ÙŠØ«: <?php echo htmlspecialchars($productionReportsMonth['generated_at'] ?? date('Y-m-d H:i:s')); ?>
                </span>
            </div>
            <div class="production-summary-grid mt-3">
                <div class="summary-card">
                    <span class="summary-label">Ø¥Ø¬Ù…Ø§Ù„ÙŠ Ø§Ø³ØªÙ‡Ù„Ø§Ùƒ Ø§Ù„ØªØ¹Ø¨Ø¦Ø©</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsMonth['packaging']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">Ø¥Ø¬Ù…Ø§Ù„ÙŠ Ø§Ø³ØªÙ‡Ù„Ø§Ùƒ Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù…</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsMonth['raw']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">Ø§Ù„ØµØ§ÙÙŠ Ø§Ù„Ø´Ù‡Ø±ÙŠ</span>
                    <span class="summary-value text-success">
                        <?php
                        $monthNet = (float)($productionReportsMonth['packaging']['net'] ?? 0) + (float)($productionReportsMonth['raw']['net'] ?? 0);
                        echo number_format($monthNet, 3);
                        ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">Ø¥Ø¬Ù…Ø§Ù„ÙŠ Ø§Ù„Ø­Ø±ÙƒØ§Øª</span>
                    <span class="summary-value text-secondary">
                        <?php
                        $monthMovements = productionPageSumMovements($productionReportsMonth['packaging']['items'] ?? [])
                            + productionPageSumMovements($productionReportsMonth['raw']['items'] ?? []);
                        echo number_format($monthMovements);
                        ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-box-seam me-2"></i>Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø© Ù„Ù„Ø´Ù‡Ø± Ø§Ù„Ø­Ø§Ù„ÙŠ</span>
        </div>
        <div class="card-body">
            <?php productionPageRenderConsumptionTable($productionReportsMonth['packaging']['items'] ?? []); ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-droplet-half me-2"></i>Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù… Ù„Ù„Ø´Ù‡Ø± Ø§Ù„Ø­Ø§Ù„ÙŠ</span>
        </div>
        <div class="card-body">
            <?php if (!empty($productionReportsMonth['raw']['sub_totals'])): ?>
                <div class="mb-3">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($productionReportsMonth['raw']['sub_totals'] as $subTotal): ?>
                            <span class="badge bg-light text-dark border">
                                <?php echo htmlspecialchars($subTotal['label'] ?? 'ØºÙŠØ± Ù…ØµÙ†Ù'); ?>:
                                <?php echo number_format((float)($subTotal['total_out'] ?? 0), 3); ?>
                                (ØµØ§ÙÙŠ <?php echo number_format((float)($subTotal['net'] ?? 0), 3); ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php productionPageRenderConsumptionTable($productionReportsMonth['raw']['items'] ?? [], true); ?>
        </div>
    </div>
</div>

<!-- Modal Ø¥Ù†Ø´Ø§Ø¡ Ø¥Ù†ØªØ§Ø¬ Ù…Ù† Ù‚Ø§Ù„Ø¨ -->
<div class="modal fade" id="createFromTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable production-template-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Ø¥Ù†Ø´Ø§Ø¡ ØªØ´ØºÙŠÙ„Ø© Ø¥Ù†ØªØ§Ø¬</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createFromTemplateForm">
                <input type="hidden" name="action" value="create_from_template">
                <input type="hidden" name="template_id" id="template_id">
                <input type="hidden" name="template_mode" id="template_mode" value="advanced">
                <input type="hidden" name="template_type" id="template_type" value="">
                <div class="modal-body production-template-body">
                    <!-- Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„Ù…Ù†ØªØ¬ -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-box-seam me-2"></i>Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„Ù…Ù†ØªØ¬</h6>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Ø§Ø³Ù… Ø§Ù„Ù…Ù†ØªØ¬</label>
                            <input type="text" class="form-control" id="template_product_name" readonly>
                        </div>
                    </div>
                    
                    <!-- Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„ØªØ´ØºÙŠÙ„Ø© -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-info-circle me-2"></i>Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„ØªØ´ØºÙŠÙ„Ø©</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Ø§Ù„ÙƒÙ…ÙŠØ© Ø§Ù„Ù…Ø±Ø§Ø¯ Ø¥Ù†ØªØ§Ø¬Ù‡Ø§ <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" class="form-control" min="1" required value="1">
                                <small class="text-muted">Ø³ÙŠØªÙ… Ø¥Ù†Ø´Ø§Ø¡ Ø±Ù‚Ù… ØªØ´ØºÙŠÙ„Ø© ÙˆØ§Ø­Ø¯ (LOT) Ù„Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…Ù†ØªØ¬Ø§Øª</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ØªØ§Ø±ÙŠØ® Ø§Ù„Ø¥Ù†ØªØ§Ø¬ <span class="text-danger">*</span></label>
                                <input type="date" name="production_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ù…Ù„Ø®Øµ Ù…ÙƒÙˆÙ†Ø§Øª Ø§Ù„Ù‚Ø§Ù„Ø¨ -->
                    <div class="mb-3 section-block d-none" id="templateComponentsSummary">
                        <h6 class="text-primary section-heading">
                            <i class="bi bi-activity me-2"></i>Ù…Ù„Ø®Øµ Ø§Ù„Ù…ÙƒÙˆÙ†Ø§Øª
                        </h6>
                        <div class="template-summary-grid" id="templateComponentsSummaryGrid"></div>
                    </div>
                    
                    <!-- Ø§Ù„Ù…ÙˆØ±Ø¯ÙˆÙ† Ø§Ù„Ø¯ÙŠÙ†Ø§Ù…ÙŠÙƒÙŠÙˆÙ† -->
                    <div class="mb-3 section-block d-none" id="templateSuppliersWrapper">
                        <h6 class="text-primary section-heading">
                            <i class="bi bi-truck me-2"></i>Ø§Ù„Ù…ÙˆØ±Ø¯ÙˆÙ† Ù„ÙƒÙ„ Ù…Ø§Ø¯Ø© <span class="text-danger">*</span>
                        </h6>
                        <p class="text-muted small mb-3" id="templateSuppliersHint">ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ù†Ø§Ø³Ø¨ Ù„ÙƒÙ„ Ù…Ø§Ø¯Ø© Ø³ÙŠØªÙ… Ø§Ø³ØªØ®Ø¯Ø§Ù…Ù‡Ø§ ÙÙŠ Ù‡Ø°Ù‡ Ø§Ù„ØªØ´ØºÙŠÙ„Ø©.</p>
                        <div class="row g-3" id="templateSuppliersContainer"></div>
                    </div>
                    
                    <!-- Ø¹Ù…Ø§Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø§Ù„Ø­Ø§Ø¶Ø±ÙŠÙ† -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-people me-2"></i>Ø¹Ù…Ø§Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø§Ù„Ø­Ø§Ø¶Ø±ÙŠÙ†</h6>
                        <?php
                        // Ø§Ù„Ø­ØµÙˆÙ„ Ø¹Ù„Ù‰ Ø§Ù„Ø¹Ù…Ø§Ù„ Ø§Ù„Ø­Ø§Ø¶Ø±ÙŠÙ† Ø§Ù„ÙŠÙˆÙ…
                        $presentWorkersToday = [];
                        $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                        if (!empty($attendanceTableCheck)) {
                            $presentWorkersToday = $db->query(
                                "SELECT DISTINCT u.id, u.username, u.full_name 
                                 FROM attendance_records ar
                                 JOIN users u ON ar.user_id = u.id
                                 WHERE ar.date = ? 
                                 AND ar.check_in_time IS NOT NULL 
                                 AND u.role = 'production' 
                                 AND u.status = 'active'
                                 ORDER BY ar.check_in_time DESC",
                                [date('Y-m-d')]
                            );
                        }
                        ?>
                        <?php if (!empty($presentWorkersToday)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>Ø§Ù„Ø¹Ù…Ø§Ù„ Ø§Ù„Ø­Ø§Ø¶Ø±ÙŠÙ† Ø§Ù„ÙŠÙˆÙ…:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($presentWorkersToday as $worker): ?>
                                        <li><?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <small class="text-muted">Ø³ÙŠØªÙ… Ø±Ø¨Ø· Ø§Ù„ØªØ´ØºÙŠÙ„Ø© ØªÙ„Ù‚Ø§Ø¦ÙŠØ§Ù‹ Ø¨Ø¬Ù…ÙŠØ¹ Ø¹Ù…Ø§Ù„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ø§Ù„Ø­Ø§Ø¶Ø±ÙŠÙ† Ø§Ù„ÙŠÙˆÙ…</small>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                Ù„Ø§ ÙŠÙˆØ¬Ø¯ Ø¹Ù…Ø§Ù„ Ø¥Ù†ØªØ§Ø¬ Ø­Ø§Ø¶Ø±ÙŠÙ† Ø§Ù„ÙŠÙˆÙ…. Ø³ÙŠØªÙ… Ø±Ø¨Ø· Ø§Ù„ØªØ´ØºÙŠÙ„Ø© Ø¨Ø§Ù„Ø¹Ø§Ù…Ù„ Ø§Ù„Ø­Ø§Ù„ÙŠ ÙÙ‚Ø·.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($currentUser['role'] !== 'production' && $userIdColumn): ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php endif; ?>
                    
                    <!-- Ù…Ù„Ø§Ø­Ø¸Ø§Øª -->
                    <div class="mb-3 section-block">
                        <label class="form-label fw-bold">Ù…Ù„Ø§Ø­Ø¸Ø§Øª (Ø§Ø®ØªÙŠØ§Ø±ÙŠ)</label>
                        <textarea name="batch_notes" class="form-control" rows="3" 
                                  placeholder="Ø³ÙŠØªÙ… Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„Ù…Ù„Ø§Ø­Ø¸Ø§Øª ØªÙ„Ù‚Ø§Ø¦ÙŠØ§Ù‹ Ø¨Ù†Ø§Ø¡Ù‹ Ø¹Ù„Ù‰ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ù…Ø­Ø¯Ø¯Ø©"></textarea>
                        <small class="text-muted">Ø¥Ø°Ø§ ØªØ±ÙƒØªÙ‡Ø§ ÙØ§Ø±ØºØ©ØŒ Ø³ÙŠØªÙ… Ø¥Ù†Ø´Ø§Ø¡ Ù…Ù„Ø§Ø­Ø¸Ø§Øª ØªÙ„Ù‚Ø§Ø¦ÙŠØ© ØªØªØ¶Ù…Ù† Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ† ÙˆØ§Ù„Ù‚Ø§Ù„Ø¨</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Ø¥Ù„ØºØ§Ø¡
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„ØªØ´ØºÙŠÙ„Ø©
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ø·Ø¨Ø§Ø¹Ø© Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª -->
<div class="modal fade" id="printBarcodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>Ø·Ø¨Ø§Ø¹Ø© Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    ØªÙ… Ø¥Ù†Ø´Ø§Ø¡ <strong id="barcode_quantity">0</strong> Ø³Ø¬Ù„ Ø¥Ù†ØªØ§Ø¬ Ø¨Ù†Ø¬Ø§Ø­ Ù…Ø¹ Ø£Ø±Ù‚Ø§Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø©
                </div>
                <div class="mb-3">
                    <label class="form-label">Ø§Ø³Ù… Ø§Ù„Ù…Ù†ØªØ¬</label>
                    <input type="text" class="form-control" id="barcode_product_name" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Ø¹Ø¯Ø¯ Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª Ø§Ù„Ù…Ø±Ø§Ø¯ Ø·Ø¨Ø§Ø¹ØªÙ‡Ø§</label>
                    <input type="number" class="form-control" id="barcode_print_quantity" min="1" value="1">
                    <small class="text-muted">Ø³ÙŠØªÙ… Ø·Ø¨Ø§Ø¹Ø© Ù†ÙØ³ Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø© Ø¨Ø¹Ø¯Ø¯ Ø§Ù„Ù…Ø±Ø§Øª Ø§Ù„Ù…Ø­Ø¯Ø¯</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Ø£Ø±Ù‚Ø§Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø©</label>
                    <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                        <div id="batch_numbers_list"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ø¥ØºÙ„Ø§Ù‚</button>
                <button type="button" class="btn btn-primary" onclick="printBarcodes()">
                    <i class="bi bi-printer me-2"></i>Ø·Ø¨Ø§Ø¹Ø© Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ø¥Ø¶Ø§ÙØ© Ø¥Ù†ØªØ§Ø¬ -->
<div class="modal fade" id="addProductionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add_production']) ? $lang['add_production'] : 'Ø¥Ø¶Ø§ÙØ© Ø¥Ù†ØªØ§Ø¬'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addProductionForm">
                <input type="hidden" name="action" value="add_production">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['product']) ? $lang['product'] : 'Ø§Ù„Ù…Ù†ØªØ¬'; ?> <span class="text-danger">*</span></label>
                            <select name="product_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_product']) ? $lang['select_product'] : 'Ø§Ø®ØªØ± Ø§Ù„Ù…Ù†ØªØ¬'; ?></option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'Ø§Ù„ÙƒÙ…ÙŠØ©'; ?> <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Ø§Ù„ÙˆØ­Ø¯Ø©</label>
                            <select name="unit" class="form-select">
                                <option value="kg">ÙƒØ¬Ù…</option>
                                <option value="g">Ø¬Ø±Ø§Ù…</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'ØªØ§Ø±ÙŠØ® Ø§Ù„Ø¥Ù†ØªØ§Ø¬'; ?> <span class="text-danger">*</span></label>
                            <input type="date" name="production_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <?php if ($currentUser['role'] !== 'production' && $userIdColumn): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['worker']) ? $lang['worker'] : 'Ø§Ù„Ø¹Ø§Ù…Ù„'; ?> <span class="text-danger">*</span></label>
                            <select name="user_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_worker']) ? $lang['select_worker'] : 'Ø§Ø®ØªØ± Ø§Ù„Ø¹Ø§Ù…Ù„'; ?></option>
                                <?php foreach ($workers as $worker): ?>
                                    <option value="<?php echo $worker['id']; ?>">
                                        <?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['materials_used']) ? $lang['materials_used'] : 'Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…Ø©'; ?></label>
                            <textarea name="materials_used" class="form-control" rows="3" placeholder="<?php echo isset($lang['materials_used_placeholder']) ? $lang['materials_used_placeholder'] : 'ÙˆØµÙ Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…Ø©...'; ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'Ù…Ù„Ø§Ø­Ø¸Ø§Øª'; ?></label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="<?php echo isset($lang['notes_placeholder']) ? $lang['notes_placeholder'] : 'Ù…Ù„Ø§Ø­Ø¸Ø§Øª Ø¥Ø¶Ø§ÙÙŠØ©...'; ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'Ø¥Ù„ØºØ§Ø¡'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['add']) ? $lang['add'] : 'Ø¥Ø¶Ø§ÙØ©'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ØªØ¹Ø¯ÙŠÙ„ Ø¥Ù†ØªØ§Ø¬ -->
<div class="modal fade" id="editProductionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?php echo isset($lang['edit_production']) ? $lang['edit_production'] : 'ØªØ¹Ø¯ÙŠÙ„ Ø¥Ù†ØªØ§Ø¬'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editProductionForm">
                <input type="hidden" name="action" value="update_production">
                <input type="hidden" name="production_id" id="edit_production_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['product']) ? $lang['product'] : 'Ø§Ù„Ù…Ù†ØªØ¬'; ?> <span class="text-danger">*</span></label>
                            <select name="product_id" id="edit_product_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_product']) ? $lang['select_product'] : 'Ø§Ø®ØªØ± Ø§Ù„Ù…Ù†ØªØ¬'; ?></option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'Ø§Ù„ÙƒÙ…ÙŠØ©'; ?> <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" id="edit_quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Ø§Ù„ÙˆØ­Ø¯Ø©</label>
                            <select name="unit" id="edit_unit" class="form-select">
                                <option value="kg">ÙƒØ¬Ù…</option>
                                <option value="g">Ø¬Ø±Ø§Ù…</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'ØªØ§Ø±ÙŠØ® Ø§Ù„Ø¥Ù†ØªØ§Ø¬'; ?> <span class="text-danger">*</span></label>
                            <input type="date" name="production_date" id="edit_production_date" class="form-control" required>
                        </div>
                        <?php if (in_array($currentUser['role'], ['accountant', 'manager'])): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'Ø§Ù„Ø­Ø§Ù„Ø©'; ?></label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="pending"><?php echo isset($lang['pending']) ? $lang['pending'] : 'Ù…Ø¹Ù„Ù‚'; ?></option>
                                <option value="approved"><?php echo isset($lang['approved']) ? $lang['approved'] : 'Ù…ÙˆØ§ÙÙ‚ Ø¹Ù„ÙŠÙ‡'; ?></option>
                                <option value="rejected"><?php echo isset($lang['rejected']) ? $lang['rejected'] : 'Ù…Ø±ÙÙˆØ¶'; ?></option>
                                <option value="completed"><?php echo isset($lang['completed']) ? $lang['completed'] : 'Ù…ÙƒØªÙ…Ù„'; ?></option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['materials_used']) ? $lang['materials_used'] : 'Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…Ø©'; ?></label>
                            <textarea name="materials_used" id="edit_materials_used" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'Ù…Ù„Ø§Ø­Ø¸Ø§Øª'; ?></label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'Ø¥Ù„ØºØ§Ø¡'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['update']) ? $lang['update'] : 'ØªØ­Ø¯ÙŠØ«'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* âš™ï¸ ØªØ­Ø³ÙŠÙ† Ø¹Ø±Ø¶ Ù†Ù…ÙˆØ°Ø¬ Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„ØªØ´ØºÙŠÙ„Ø© */
.production-template-dialog {
    width: min(960px, 94vw);
    height: calc(100vh - 2rem);
    margin: 1rem auto;
    display: flex;
    flex-direction: column;
}

.production-template-dialog .modal-content {
    border-radius: 16px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
    height: 100%;
    display: flex;
    flex-direction: column;
}

.production-template-dialog .modal-header,
.production-template-dialog .modal-footer {
    padding: 0.75rem 1.25rem;
    flex-shrink: 0;
}

.production-template-dialog .modal-header {
    background: linear-gradient(135deg, #1f4db8, #4a7dfb);
    color: #fff;
    border-bottom: 0;
    box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.15);
}

.production-template-dialog .modal-header .modal-title,
.production-template-dialog .modal-header .btn-close {
    color: #fff;
}

.production-template-dialog .modal-header .btn-close {
    opacity: 0.85;
    filter: invert(1) grayscale(100%) brightness(120%);
}

.production-template-dialog .modal-header .btn-close:hover {
    opacity: 1;
}

.production-template-body {
    padding: 1rem 1.1rem;
    flex: 1 1 auto;
    overflow-y: auto;
}

@media (max-width: 991.98px) {
    .production-template-dialog {
        width: 100%;
        height: 100vh;
        margin: 0;
        border-radius: 0;
    }

    .production-template-dialog .modal-content {
        border-radius: 0;
    }
}

.production-template-body .section-block {
    background: #f9fafb;
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 12px;
    padding: 0.85rem 1rem;
    margin-bottom: 0.75rem;
}

.production-template-body .section-heading {
    font-size: 1rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.production-template-body .form-label {
    font-size: 0.95rem;
    margin-bottom: 0.35rem;
}

.production-template-body .small {
    font-size: 0.75rem;
}

.production-template-body .alert {
    margin-bottom: 0.75rem;
    padding: 0.75rem 0.9rem;
}

.template-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.75rem;
}

.template-summary-item {
    background: rgba(248, 250, 252, 0.95);
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 12px;
    padding: 0.75rem 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
}

.template-summary-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.16), rgba(37, 99, 235, 0.28));
    color: #1d4ed8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
}

.template-summary-content {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.template-summary-value {
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
}

.template-summary-label {
    font-size: 0.8rem;
    color: #475569;
    font-weight: 500;
}

.component-card {
    --component-accent: #2563eb;
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    padding: 0.95rem 1rem;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.12);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.component-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    border-left: 4px solid var(--component-accent);
    opacity: 0.9;
    pointer-events: none;
}

.component-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
}

.component-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}

.component-card-badge {
    background: var(--component-accent);
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
}

.component-card-meta {
    display: flex;
    align-items: center;
    font-size: 0.85rem;
    color: #475569;
    gap: 0.45rem;
}

.component-card-meta i {
    color: var(--component-accent);
}

.component-card-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.component-card-chip {
    background: rgba(37, 99, 235, 0.08);
    border-radius: 999px;
    padding: 0.25rem 0.55rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #1d4ed8;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.component-card .form-label {
    font-size: 0.85rem;
    color: #475569;
}

.component-card .form-select,
.component-card .form-control {
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 10px;
    font-size: 0.9rem;
    padding: 0.45rem 0.65rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.component-card .form-select:focus,
.component-card .form-control:focus {
    border-color: var(--component-accent);
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.12);
}

.component-card .text-muted {
    font-size: 0.75rem;
}

.template-card.selected-template {
    transform: translateY(-6px);
    box-shadow: 0 24px 42px rgba(37, 99, 235, 0.20) !important;
}

.template-card.selected-template .badge {
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
}

.production-template-body .row.g-3 > [class*="col-"] {
    margin-bottom: 0;
}

/* ðŸŽ¯ Ø¶Ø¨Ø· ØªØ¨ÙˆÙŠØ¨Ø§Øª Ø§Ù„ØµÙØ­Ø© ÙˆØªÙ‚Ø§Ø±ÙŠØ± Ø§Ù„Ø¥Ù†ØªØ§Ø¬ */
.production-tab-toggle {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.production-tab-toggle .production-tab-btn {
    flex: 1 1 200px;
    min-width: 160px;
    padding: 0.65rem 1.2rem !important;
    border-radius: 12px !important;
    border: 1px solid rgba(29, 78, 216, 0.35) !important;
    background: #ffffff !important;
    color: #1d4ed8 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08) !important;
    font-weight: 600 !important;
    transition: all 0.25s ease !important;
}

.production-tab-toggle .production-tab-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(59, 130, 246, 0.25) !important;
}

.production-tab-toggle .production-tab-btn.active {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    color: #ffffff !important;
    border-color: transparent !important;
    box-shadow: 0 12px 26px rgba(37, 99, 235, 0.35) !important;
}

#productionReportsSection {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

#productionReportsSection .card {
    border-radius: 16px !important;
}

#productionReportsSection .production-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 1rem;
}

#productionReportsSection .summary-card {
    background: #f8fafc;
    border-radius: 14px;
    padding: 1.1rem;
    border: 1px solid rgba(15, 23, 42, 0.05);
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}

#productionReportsSection .summary-label {
    font-size: 0.85rem;
    color: #6b7280;
    letter-spacing: 0.3px;
}

#productionReportsSection .summary-value {
    font-size: 1.45rem;
    font-weight: 700;
    line-height: 1.2;
}

#productionReportsSection .summary-value.text-secondary {
    color: #475569 !important;
}

#productionReportsSection .badge {
    font-size: 0.8rem;
    padding: 0.45rem 0.75rem;
    border-radius: 10px;
}

#productionReportsSection .table {
    font-size: 0.9rem;
}

#productionReportsSection .table th {
    font-size: 0.85rem;
    letter-spacing: 0.4px;
}

#productionReportsSection .table td {
    vertical-align: middle;
    font-size: 0.85rem;
}

/* ðŸŽ¨ ØªÙ†Ø³ÙŠÙ‚ Ø§Ù„Ø£Ù„ÙˆØ§Ù† ÙˆØ§Ù„Ø¸Ù„Ø§Ù„ - ØªØ¯Ø±Ø¬Ø§Øª Ø§Ù„Ø£Ø²Ø±Ù‚ */

/* Ø§Ù„Ø¨Ø·Ø§Ù‚Ø§Øª ÙˆØ§Ù„ÙƒØ±ÙˆØª */
.card {
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 2px 15px rgba(13, 110, 253, 0.08) !important;
    transition: all 0.3s ease !important;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(13, 110, 253, 0.15) !important;
}

/* Ø±Ø£Ø³ Ø§Ù„Ø¨Ø·Ø§Ù‚Ø§Øª - ØªØ¯Ø±Ø¬ Ø£Ø²Ø±Ù‚ Ø¬Ù…ÙŠÙ„ */
.card-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    border: none !important;
    border-radius: 12px 12px 0 0 !important;
    padding: 1rem 1.5rem !important;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3) !important;
}

.card-header.bg-primary,
.card-header.bg-info,
.card-header.bg-success {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35) !important;
}

/* Ø§Ù„Ø£Ø²Ø±Ø§Ø± - ØªØ¯Ø±Ø¬Ø§Øª Ø£Ø²Ø±Ù‚ Ø­Ø¯ÙŠØ«Ø© */
.btn-primary,
.btn-info,
.btn-success,
.btn-outline-primary,
.btn-outline-info,
.btn-outline-success {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    border: none !important;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4) !important;
    transition: all 0.3s ease !important;
    border-radius: 8px !important;
    padding: 0.5rem 1.5rem !important;
    font-weight: 500 !important;
    color: #fff !important;
}

.btn-primary:hover,
.btn-info:hover,
.btn-success:hover,
.btn-outline-primary:hover,
.btn-outline-info:hover,
.btn-outline-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.6) !important;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
}

.btn-primary:focus,
.btn-info:focus,
.btn-success:focus,
.btn-outline-primary:focus,
.btn-outline-info:focus,
.btn-outline-success:focus {
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.35) !important;
}

/* Ø§Ù„Ø¬Ø¯Ø§ÙˆÙ„ */
.table {
    border-radius: 8px !important;
    overflow: hidden !important;
}

.table thead th {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important;
    color: white !important;
    border: none !important;
    font-weight: 600 !important;
    padding: 1rem !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table tbody tr {
    transition: all 0.2s ease !important;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.06) 0%, rgba(59, 130, 246, 0.06) 100%) !important;
    transform: scale(1.01);
}

/* Ø±Ø³Ø§Ø¦Ù„ Ø§Ù„ØªÙ†Ø¨ÙŠÙ‡ */
.alert {
    border: none !important;
    border-radius: 10px !important;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1) !important;
}

.alert-success {
    background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%) !important;
    color: #155724 !important;
}

.alert-danger {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%) !important;
    color: #721c24 !important;
}

.alert-info {
    background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%) !important;
    color: #004085 !important;
}

/* Modal */
.modal-content {
    border: none !important;
    border-radius: 15px !important;
    box-shadow: 0 10px 40px rgba(13, 110, 253, 0.2) !important;
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    color: white !important;
    border: none !important;
    border-radius: 15px 15px 0 0 !important;
    padding: 1.25rem 1.5rem !important;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
}

.modal-footer {
    border-top: 1px solid rgba(102, 126, 234, 0.1) !important;
    padding: 1rem 1.5rem !important;
    background: rgba(37, 99, 235, 0.04) !important;
}

/* Badges */
.badge {
    border-radius: 6px !important;
    padding: 0.4rem 0.8rem !important;
    font-weight: 500 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
}

.badge.bg-success {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%) !important;
}

.badge.bg-danger {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%) !important;
}

.badge.bg-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}

.badge.bg-info {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
}

.badge.bg-warning {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%) !important;
    color: #856404 !important;
}

/* Form Controls */
.form-control:focus, .form-select:focus {
    border-color: #667eea !important;
    box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25) !important;
}

/* ØªØ­Ø³ÙŠÙ†Ø§Øª ØªØµÙ…ÙŠÙ… modal Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„Ù‚Ø§Ù„Ø¨ */
#createTemplateModal .modal-body {
    padding: 1.5rem;
}

#createTemplateModal .form-label {
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
    font-weight: 500;
    color: #495057;
}

#createTemplateModal .border {
    border-color: rgba(102, 126, 234, 0.2) !important;
    border-radius: 8px !important;
}

#createTemplateModal .form-check {
    padding: 0.5rem;
    border-radius: 8px;
    transition: all 0.2s;
}

#createTemplateModal .form-check:hover {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(59, 130, 246, 0.08) 100%);
}

#createTemplateModal .form-check-input:checked ~ .form-check-label {
    font-weight: 600;
    color: #667eea;
}

#createTemplateModal .badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

/* Ø¹Ù†Ø§ÙˆÙŠÙ† Ø§Ù„ØµÙØ­Ø© */
h2, h3, h4, h5 {
    color: #2d3748 !important;
    font-weight: 600 !important;
}

/* ØªØ£Ø«ÙŠØ±Ø§Øª Ø¥Ø¶Ø§ÙÙŠØ© */
.shadow-sm {
    box-shadow: 0 2px 15px rgba(13, 110, 253, 0.08) !important;
}

.shadow {
    box-shadow: 0 4px 20px rgba(13, 110, 253, 0.12) !important;
}

.shadow-lg {
    box-shadow: 0 10px 40px rgba(13, 110, 253, 0.15) !important;
}

/* Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card, .alert, .btn {
    animation: fadeIn 0.3s ease-in-out;
}

.production-template-body .section-block {
     background: #f9fafb;
     border: 1px solid rgba(148, 163, 184, 0.25);
     border-radius: 12px;
     padding: 0.85rem 1rem;
     margin-bottom: 0.75rem;
 }

.template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.25rem;
}

.template-card-modern {
    position: relative;
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    box-shadow: 0 18px 32px rgba(15, 23, 42, 0.12);
    padding: 1.2rem 1.35rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    cursor: pointer;
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
    overflow: hidden;
}

.template-card-modern::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(135deg, var(--template-accent-light, rgba(14, 165, 233, 0.18)) 0%, transparent 65%);
    opacity: 0.9;
    pointer-events: none;
    transition: opacity 0.25s ease;
}

.template-card-modern:hover {
    transform: translateY(-6px);
    box-shadow: 0 22px 42px rgba(15, 23, 42, 0.18);
    border-color: var(--template-accent, rgba(14, 165, 233, 0.9));
}

.template-card-modern:hover::before {
    opacity: 1;
}

.template-card-modern.selected-template {
    border-color: var(--template-accent, rgba(14, 165, 233, 0.9));
    box-shadow: 0 26px 48px rgba(15, 23, 42, 0.22);
}

.template-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    position: relative;
    z-index: 1;
}

.template-type-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: var(--template-accent, #0ea5e9);
    color: #ffffff;
    border-radius: 999px;
    padding: 0.22rem 0.65rem;
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}

.template-title {
    margin: 0.4rem 0 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}

.template-products-count {
    background: rgba(15, 118, 110, 0.08);
    color: #0f766e;
    border-radius: 12px;
    padding: 0.45rem 0.75rem;
    font-size: 0.78rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.template-products-count span {
    font-weight: 500;
    color: #0f4e4a;
}

.template-metrics {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.7rem;
    background: rgba(15, 23, 42, 0.04);
    border-radius: 14px;
    padding: 0.65rem 0.8rem;
    position: relative;
    z-index: 1;
}

.metric-item {
    display: flex;
    flex-direction: column;
    text-align: center;
    gap: 0.2rem;
}

.metric-label {
    font-size: 0.68rem;
    color: #64748b;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.metric-value {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--template-accent, #0ea5e9);
}

.metric-separator {
    width: 1px;
    height: 32px;
    background: rgba(148, 163, 184, 0.35);
    justify-self: center;
}

.template-materials {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    background: rgba(15, 23, 42, 0.03);
    border-radius: 14px;
    padding: 0.75rem 0.85rem;
    position: relative;
    z-index: 1;
}

.material-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.material-icon {
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 12px;
    background: var(--template-accent-light, rgba(14, 165, 233, 0.18));
    color: var(--template-accent, #0ea5e9);
    display: grid;
    place-items: center;
    font-size: 0.9rem;
    font-weight: 600;
}

.material-info {
    flex: 1;
}

.material-name {
    font-size: 0.84rem;
    font-weight: 600;
    color: #1f2937;
}

.material-quantity {
    font-size: 0.78rem;
    color: #475569;
}

.material-quantity span {
    margin-right: 0.25rem;
    color: #94a3b8;
}

.material-tag {
    background: rgba(253, 224, 71, 0.25);
    color: #854d0e;
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
}

.materials-more {
    font-size: 0.75rem;
    color: #475569;
    font-weight: 600;
}

.template-actions {
    display: flex;
    justify-content: center;
    align-items: center;
}

.template-action-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff;
    padding: 0.55rem 1.4rem;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.8rem;
    letter-spacing: 0.02em;
    box-shadow: 0 10px 25px rgba(5, 150, 105, 0.25);
}

.template-card-modern:hover .template-action-badge {
    box-shadow: 0 16px 30px rgba(5, 150, 105, 0.35);
}

@media (max-width: 991.98px) {
    .template-grid {
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    }
}

@media (max-width: 575.98px) {
    .template-card-modern {
        padding: 1rem;
    }

    .template-metrics {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.4rem;
        padding: 0.5rem;
    }

    .metric-label {
        font-size: 0.65rem;
    }

    .metric-value {
        font-size: 0.85rem;
    }
}


</style>

<?php
$honeyStockDataForJs = [];
try {
    $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
    if (!empty($honeyStockTableCheck)) {
        $honeyStockRows = $db->query("
            SELECT 
                supplier_id, 
                honey_variety, 
                COALESCE(raw_honey_quantity, 0) AS raw_quantity, 
                COALESCE(filtered_honey_quantity, 0) AS filtered_quantity
            FROM honey_stock
        ");
        foreach ($honeyStockRows as $row) {
            $supplierId = (int)($row['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }
            if (!isset($honeyStockDataForJs[$supplierId])) {
                $honeyStockDataForJs[$supplierId] = [
                    'all' => [],
                    'honey_raw' => [],
                    'honey_filtered' => []
                ];
            }
            $varietyName = trim((string)($row['honey_variety'] ?? ''));
            $entry = [
                'variety' => $varietyName,
                'raw_quantity' => (float)($row['raw_quantity'] ?? 0),
                'filtered_quantity' => (float)($row['filtered_quantity'] ?? 0)
            ];
            $honeyStockDataForJs[$supplierId]['all'][] = $entry;
            if ($entry['raw_quantity'] > 0) {
                $honeyStockDataForJs[$supplierId]['honey_raw'][] = $entry;
            }
            if ($entry['filtered_quantity'] > 0) {
                $honeyStockDataForJs[$supplierId]['honey_filtered'][] = $entry;
            }
        }
    }
} catch (Exception $honeyDataException) {
    error_log('Production honey stock fetch error: ' . $honeyDataException->getMessage());
}
?>

<script>
window.productionSuppliers = <?php
$suppliersForJs = is_array($suppliers) ? $suppliers : [];
echo json_encode(array_map(function($supplier) {
    return [
        'id' => (int)($supplier['id'] ?? 0),
        'name' => $supplier['name'] ?? '',
        'type' => $supplier['type'] ?? ''
    ];
}, $suppliersForJs), JSON_UNESCAPED_UNICODE);
?>;
window.honeyStockData = <?php echo json_encode($honeyStockDataForJs, JSON_UNESCAPED_UNICODE); ?>;
let currentTemplateMode = 'advanced';

const HONEY_COMPONENT_TYPES = ['honey_raw', 'honey_filtered', 'honey_general', 'honey_main'];

function isHoneyComponent(component) {
    if (!component) {
        return false;
    }
    const type = (component.type || '').toString();
    if (type && HONEY_COMPONENT_TYPES.includes(type)) {
        return true;
    }
    const key = (component.key || '').toString();
    return key.startsWith('honey_');
}

function getSuppliersForComponent(component) {
    const suppliers = window.productionSuppliers || [];
    if (!component) {
        return suppliers;
    }
    const type = (component.type || '').toString();
    const key = (component.key || '').toString();

    const filterByTypes = (allowedTypes) => suppliers.filter(supplier => allowedTypes.includes(supplier.type));

    if (isHoneyComponent(component)) {
        return filterByTypes(['honey']);
    }

    if (type === 'packaging' || key.startsWith('pack_')) {
        return filterByTypes(['packaging']);
    }

    if (type === 'olive_oil' || key.startsWith('olive')) {
        return filterByTypes(['olive_oil']);
    }

    if (type === 'beeswax' || key.startsWith('beeswax')) {
        return filterByTypes(['beeswax']);
    }

    if (type === 'derivatives' || key.startsWith('derivative')) {
        return filterByTypes(['derivatives']);
    }

    if (type === 'nuts' || key.startsWith('nuts')) {
        return filterByTypes(['nuts']);
    }

    return suppliers;
}

function normalizeSupplierKey(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const numeric = Number(value);
    if (!Number.isNaN(numeric) && numeric > 0) {
        return String(numeric);
    }
    return String(value);
}

function populateHoneyVarietyOptions(inputEl, datalistEl, supplierId, component) {
    if (!inputEl || !datalistEl) {
        return;
    }

    const normalizedKey = normalizeSupplierKey(supplierId);
    if (!normalizedKey) {
        datalistEl.innerHTML = '';
        inputEl.value = '';
        inputEl.disabled = true;
        inputEl.placeholder = 'Ø§Ø®ØªØ± Ø§Ù„Ù…ÙˆØ±Ø¯ Ø£ÙˆÙ„Ø§Ù‹';
        inputEl.dataset.defaultApplied = '';
        return;
    }

    const honeyData = window.honeyStockData || {};
    const supplierData = honeyData[normalizedKey] ?? honeyData[String(parseInt(normalizedKey, 10))] ?? null;
    const componentType = (component?.type || '').toString();

    let items = [];
    if (supplierData) {
        if (componentType === 'honey_raw' && Array.isArray(supplierData.honey_raw) && supplierData.honey_raw.length) {
            items = supplierData.honey_raw;
        } else if (componentType === 'honey_filtered' && Array.isArray(supplierData.honey_filtered) && supplierData.honey_filtered.length) {
            items = supplierData.honey_filtered;
        } else if (Array.isArray(supplierData.all)) {
            items = supplierData.all;
        }
    }

    datalistEl.innerHTML = '';
    const uniqueVarieties = new Set();
    items.forEach(item => {
        const varietyName = item && item.variety ? String(item.variety) : '';
        if (!varietyName || uniqueVarieties.has(varietyName)) {
            return;
        }
        uniqueVarieties.add(varietyName);
        const option = document.createElement('option');
        option.value = varietyName;
        datalistEl.appendChild(option);
    });

    inputEl.disabled = false;
    inputEl.placeholder = uniqueVarieties.size > 0
        ? 'Ø§Ø®ØªØ± Ø£Ùˆ Ø§ÙƒØªØ¨ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„'
        : 'Ø§ÙƒØªØ¨ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ø§Ù„Ù…ØªÙˆÙØ± Ù„Ø¯Ù‰ Ø§Ù„Ù…ÙˆØ±Ø¯';

    if (!inputEl.dataset.defaultApplied) {
        const defaultValue = inputEl.dataset.defaultValue || '';
        if (defaultValue !== '') {
            if (uniqueVarieties.size === 0 || uniqueVarieties.has(defaultValue)) {
                inputEl.value = defaultValue;
            }
        }
        inputEl.dataset.defaultApplied = '1';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const tabButtons = document.querySelectorAll('[data-production-tab]');
    const sections = {
        records: document.getElementById('productionRecordsSection'),
        reports: document.getElementById('productionReportsSection')
    };

    tabButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const target = button.getAttribute('data-production-tab');
            if (!target || !sections[target]) {
                return;
            }

            tabButtons.forEach(function(btn) {
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
            });

            Object.keys(sections).forEach(function(key) {
                if (sections[key]) {
                    sections[key].classList.add('d-none');
                }
            });

            button.classList.add('active');
            button.setAttribute('aria-pressed', 'true');
            sections[target].classList.remove('d-none');
        });
    });
});

function renderTemplateSuppliers(details) {
    const cacheKey = details?.cache_key;
    if (!details || !details.success) {
        return;
    }
    if (cacheKey) {
        window.templateDetailsCache = window.templateDetailsCache || {};
        window.templateDetailsCache[cacheKey] = details;
    }
    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');
    const hintText = document.getElementById('templateSuppliersHint');
    const summaryWrapper = document.getElementById('templateComponentsSummary');
    const summaryGrid = document.getElementById('templateComponentsSummaryGrid');

    if (!container || !wrapper || !modeInput) {
        return;
    }

    const components = Array.isArray(details?.components) ? details.components : [];

    container.innerHTML = '';
    if (summaryGrid) {
        summaryGrid.innerHTML = '';
    }
    if (summaryWrapper) {
        summaryWrapper.classList.add('d-none');
    }

    if (components.length === 0) {
        wrapper.classList.remove('d-none');
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…ÙˆØ§Ø¯ Ù…Ø±ØªØ¨Ø·Ø© Ø¨Ø§Ù„Ù‚Ø§Ù„Ø¨. ÙŠØ±Ø¬Ù‰ ØªØ­Ø¯ÙŠØ« Ø§Ù„Ù‚Ø§Ù„Ø¨ ÙˆØ¥Ø¶Ø§ÙØ© Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ù…Ø·Ù„ÙˆØ¨Ø©.
                </div>
            </div>
        `;
        if (hintText) {
            hintText.textContent = 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…ÙˆØ§Ø¯ Ù„Ø¹Ø±Ø¶Ù‡Ø§.';
        }
        currentTemplateMode = 'advanced';
        modeInput.value = 'advanced';
        return;
    }

    const determineComponentType = (component) => {
        if (!component) {
            return 'generic';
        }
        const type = (component.type || '').toString().toLowerCase();
        if (type) {
            return type;
        }
        const key = (component.key || '').toString().toLowerCase();
        if (key.startsWith('pack_')) return 'packaging';
        if (key.startsWith('honey_')) return 'honey_raw';
        if (key.startsWith('raw_')) return 'raw_general';
        if (key.startsWith('olive')) return 'olive_oil';
        if (key.startsWith('beeswax')) return 'beeswax';
        if (key.startsWith('derivative')) return 'derivatives';
        if (key.startsWith('nuts')) return 'nuts';
        return 'generic';
    };

    const accentColors = {
        packaging: '#0dcaf0',
        honey_raw: '#f59e0b',
        honey_filtered: '#fb923c',
        honey_main: '#facc15',
        olive_oil: '#22c55e',
        beeswax: '#a855f7',
        derivatives: '#6366f1',
        nuts: '#d97706',
        raw_general: '#3b82f6',
        generic: '#2563eb',
        default: '#2563eb'
    };

    const typeLabelsMap = {
        packaging: 'Ø£Ø¯Ø§Ø© ØªØ¹Ø¨Ø¦Ø©',
        honey_raw: 'Ø¹Ø³Ù„ Ø®Ø§Ù…',
        honey_filtered: 'Ø¹Ø³Ù„ Ù…ØµÙÙ‰',
        honey_main: 'Ø¹Ø³Ù„',
        olive_oil: 'Ø²ÙŠØª Ø²ÙŠØªÙˆÙ†',
        beeswax: 'Ø´Ù…Ø¹ Ø¹Ø³Ù„',
        derivatives: 'Ù…Ø´ØªÙ‚Ø§Øª',
        nuts: 'Ù…ÙƒØ³Ø±Ø§Øª',
        raw_general: 'Ù…Ø§Ø¯Ø© Ø®Ø§Ù…',
        generic: 'Ù…ÙƒÙˆÙ†'
    };

    const componentIcons = {
        packaging: 'bi-box-seam',
        honey_raw: 'bi-droplet-half',
        honey_filtered: 'bi-droplet',
        honey_main: 'bi-bezier',
        olive_oil: 'bi-bezier2',
        beeswax: 'bi-hexagon',
        derivatives: 'bi-intersect',
        nuts: 'bi-record-circle',
        raw_general: 'bi-diagram-3',
        generic: 'bi-diagram-2'
    };

    const stats = {
        total: components.length,
        packaging: 0,
        honey: 0,
        raw: 0,
        special: 0
    };

    components.forEach(component => {
        const canonicalType = determineComponentType(component);
        if (canonicalType === 'packaging') {
            stats.packaging += 1;
            return;
        }
        if (isHoneyComponent(component) || canonicalType === 'honey_raw' || canonicalType === 'honey_filtered' || canonicalType === 'honey_main') {
            stats.honey += 1;
            stats.raw += 1;
            return;
        }
        if (['olive_oil', 'beeswax', 'derivatives', 'nuts', 'raw_general'].includes(canonicalType) || canonicalType.startsWith('raw_')) {
            stats.raw += 1;
            return;
        }
        stats.special += 1;
    });

    stats.special = Math.max(0, stats.total - stats.raw - stats.packaging);

    if (summaryWrapper && summaryGrid) {
        const summaryItems = [
            { key: 'total', label: 'Ø¥Ø¬Ù…Ø§Ù„ÙŠ Ø§Ù„Ù…ÙƒÙˆÙ†Ø§Øª', value: stats.total, icon: 'bi-collection' },
            { key: 'raw', label: 'Ù…ÙˆØ§Ø¯ Ø®Ø§Ù… / Ø£Ø³Ø§Ø³ÙŠØ©', value: stats.raw, icon: 'bi-droplet-half' },
            { key: 'packaging', label: 'Ø£Ø¯ÙˆØ§Øª Ø§Ù„ØªØ¹Ø¨Ø¦Ø©', value: stats.packaging, icon: 'bi-box' },
            { key: 'honey', label: 'ØªØªØ·Ù„Ø¨ Ù†ÙˆØ¹ Ø¹Ø³Ù„', value: stats.honey, icon: 'bi-stars' },
            { key: 'special', label: 'Ù…ÙƒÙˆÙ†Ø§Øª Ø¥Ø¶Ø§ÙÙŠØ©', value: stats.special, icon: 'bi-puzzle' }
        ].filter(item => item.value > 0 || item.key === 'total');

        summaryGrid.innerHTML = summaryItems.map(item => `
            <div class="template-summary-item">
                <span class="template-summary-icon">
                    <i class="bi ${item.icon}"></i>
                </span>
                <div class="template-summary-content">
                    <span class="template-summary-value">${item.value}</span>
                    <span class="template-summary-label">${item.label}</span>
                </div>
            </div>
        `).join('');

        summaryWrapper.classList.remove('d-none');
    }

    const createChip = (iconClass, text) => {
        const chip = document.createElement('span');
        chip.className = 'component-card-chip';
        chip.innerHTML = `<i class="bi ${iconClass} me-1"></i>${text}`;
        return chip;
    };

    components.forEach(function(component) {
        const canonicalType = determineComponentType(component);
        const safeTypeClass = canonicalType.replace(/[^a-z0-9_-]/g, '') || 'generic';
        const componentKey = (component.key || component.name || ('component_' + Math.random().toString(36).slice(2)));

        const col = document.createElement('div');
        col.className = 'col-12 col-lg-6';

        const card = document.createElement('div');
        card.className = `component-card component-type-${safeTypeClass}`;
        card.style.setProperty('--component-accent', accentColors[canonicalType] || accentColors.default);

        const header = document.createElement('div');
        header.className = 'component-card-header';

        const title = document.createElement('span');
        title.className = 'component-card-title';
        title.textContent = component.name || component.label || 'Ù…ÙƒÙˆÙ†';

        const badge = document.createElement('span');
        badge.className = 'component-card-badge';
        badge.textContent = typeLabelsMap[canonicalType] || typeLabelsMap.generic;

        header.appendChild(title);
        header.appendChild(badge);
        card.appendChild(header);

        const meta = document.createElement('div');
        meta.className = 'component-card-meta';
        const metaIcon = document.createElement('i');
        metaIcon.className = `bi ${componentIcons[canonicalType] || componentIcons.generic} me-2`;
        meta.appendChild(metaIcon);
        const metaText = document.createElement('span');
        metaText.textContent = component.description || 'Ù„Ø§ ØªÙˆØ¬Ø¯ ØªÙØ§ØµÙŠÙ„ Ø¥Ø¶Ø§ÙÙŠØ©.';
        meta.appendChild(metaText);
        card.appendChild(meta);

        const chipsWrapper = document.createElement('div');
        chipsWrapper.className = 'component-card-chips';
        if (component.requires_variety || isHoneyComponent(component)) {
            chipsWrapper.appendChild(createChip('bi-stars', 'ÙŠØªØ·Ù„Ø¨ ØªØ­Ø¯ÙŠØ¯ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„'));
        }
        if (component.default_supplier) {
            chipsWrapper.appendChild(createChip('bi-person-check', 'Ù…ÙˆØ±Ø¯ Ù…Ù‚ØªØ±Ø­'));
        }
        if (chipsWrapper.children.length > 0) {
            card.appendChild(chipsWrapper);
        }

        const controlLabel = document.createElement('label');
        controlLabel.className = 'form-label fw-semibold small text-muted mb-1';
        controlLabel.textContent = 'Ø§Ø®ØªØ± Ø§Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ù†Ø§Ø³Ø¨';
        card.appendChild(controlLabel);

        const select = document.createElement('select');
        select.className = 'form-select form-select-sm component-supplier-select';
        select.name = 'material_suppliers[' + componentKey + ']';
        select.dataset.role = 'component-supplier';
        select.required = component.required !== false;
        select.dataset.componentType = component.type || '';

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = component.placeholder || 'Ø§Ø®ØªØ± Ø§Ù„Ù…ÙˆØ±Ø¯';
        select.appendChild(placeholderOption);

        const suppliersForComponent = getSuppliersForComponent(component);
        const suppliersList = suppliersForComponent.length ? suppliersForComponent : (window.productionSuppliers || []);

        suppliersList.forEach(function(supplier) {
            const option = document.createElement('option');
            option.value = supplier.id;
            option.textContent = supplier.name;
            if (component.default_supplier && parseInt(component.default_supplier, 10) === supplier.id) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        if (suppliersList.length === 0) {
            const noSupplierOption = document.createElement('option');
            noSupplierOption.value = '';
            noSupplierOption.disabled = true;
            noSupplierOption.textContent = 'Ù„Ø§ ÙŠÙˆØ¬Ø¯ Ù…ÙˆØ±Ø¯ Ù…Ù†Ø§Ø³Ø¨ - Ø±Ø§Ø¬Ø¹ Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ù…ÙˆØ±Ø¯ÙŠÙ†';
            select.appendChild(noSupplierOption);
        }

        card.appendChild(select);

        if (isHoneyComponent(component)) {
            const honeyWrapper = document.createElement('div');
            honeyWrapper.className = 'mt-2';

            const honeyLabel = document.createElement('label');
            honeyLabel.className = 'form-label fw-bold mb-1';
            honeyLabel.textContent = 'Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ù„Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ø®ØªØ§Ø±';

            const honeyInput = document.createElement('input');
            honeyInput.type = 'text';
            honeyInput.className = 'form-control form-control-sm';
            honeyInput.name = 'material_honey_varieties[' + componentKey + ']';
            honeyInput.required = true;
            honeyInput.dataset.role = 'honey-variety-input';
            honeyInput.dataset.defaultValue = component.honey_variety ? component.honey_variety : '';
            honeyInput.placeholder = 'Ø§Ø®ØªØ± Ø§Ù„Ù…ÙˆØ±Ø¯ Ø£ÙˆÙ„Ø§Ù‹';
            honeyInput.disabled = true;

            const honeyHelper = document.createElement('small');
            honeyHelper.className = 'text-muted d-block mt-1';
            honeyHelper.textContent = 'Ø§Ø®ØªØ± Ø£Ùˆ Ø§ÙƒØªØ¨ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ ÙƒÙ…Ø§ Ù‡Ùˆ Ù…ØªÙˆÙØ± Ù„Ø¯Ù‰ Ø§Ù„Ù…ÙˆØ±Ø¯.';

            const datalist = document.createElement('datalist');
            const sanitizedKey = componentKey.toString().replace(/[^a-zA-Z0-9_-]/g, '');
            datalist.id = 'honey-variety-list-' + sanitizedKey + '-' + Math.random().toString(36).slice(2, 6);
            honeyInput.setAttribute('list', datalist.id);

            honeyWrapper.appendChild(honeyLabel);
            honeyWrapper.appendChild(honeyInput);
            honeyWrapper.appendChild(datalist);
            honeyWrapper.appendChild(honeyHelper);

            select.addEventListener('change', function() {
                honeyInput.dataset.defaultApplied = '';
                honeyInput.dataset.defaultValue = '';
                populateHoneyVarietyOptions(honeyInput, datalist, this.value, component);
            });

            card.appendChild(honeyWrapper);

            populateHoneyVarietyOptions(honeyInput, datalist, select.value, component);
        }

        col.appendChild(card);
        container.appendChild(col);
    });

    wrapper.classList.remove('d-none');

    if (hintText) {
        hintText.textContent = details.hint || 'ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ù…ÙˆØ±Ø¯ Ø§Ù„Ù…Ù†Ø§Ø³Ø¨ Ù„ÙƒÙ„ Ù…Ø§Ø¯Ø© ÙˆØªØ­Ø¯ÙŠØ¯ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ø¹Ù†Ø¯ Ø§Ù„Ø­Ø§Ø¬Ø©.';
    }

    currentTemplateMode = 'advanced';
    modeInput.value = 'advanced';
}

// ØªØ­Ù…ÙŠÙ„ Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ø¥Ù†ØªØ§Ø¬ Ù„Ù„ØªØ¹Ø¯ÙŠÙ„
function editProduction(id) {
    const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
    const url = baseUrl + '/dashboard/production.php?page=production&ajax=1&id=' + id;
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                document.getElementById('edit_production_id').value = data.production.id;
                document.getElementById('edit_product_id').value = data.production.product_id;
                document.getElementById('edit_quantity').value = data.production.quantity;
                document.getElementById('edit_production_date').value = data.production.date;
                document.getElementById('edit_materials_used').value = data.production.materials_used || '';
                document.getElementById('edit_notes').value = data.production.notes || '';
                if (document.getElementById('edit_status')) {
                    document.getElementById('edit_status').value = data.production.status || 'pending';
                }
                
                const modal = new bootstrap.Modal(document.getElementById('editProductionModal'));
                modal.show();
            } else {
                alert(data.message || 'Ø­Ø¯Ø« Ø®Ø·Ø£ ÙÙŠ ØªØ­Ù…ÙŠÙ„ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ø­Ø¯Ø« Ø®Ø·Ø£ ÙÙŠ ØªØ­Ù…ÙŠÙ„ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª: ' + error.message);
        });
}

// Ø­Ø°Ù Ø§Ù„Ø¥Ù†ØªØ§Ø¬
function deleteProduction(id) {
    if (confirm('<?php echo isset($lang['confirm_delete']) ? $lang['confirm_delete'] : 'Ù‡Ù„ Ø£Ù†Øª Ù…ØªØ£ÙƒØ¯ Ù…Ù† Ø­Ø°Ù Ù‡Ø°Ø§ Ø§Ù„Ø³Ø¬Ù„ØŸ'; ?>')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_production">
            <input type="hidden" name="production_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Ø¹Ø±Ø¶ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬
function viewProduction(id) {
    // ÙŠÙ…ÙƒÙ† Ø¥Ø¶Ø§ÙØ© modal Ù„Ø¹Ø±Ø¶ Ø§Ù„ØªÙØ§ØµÙŠÙ„ Ø§Ù„ÙƒØ§Ù…Ù„Ø©
    alert('Ø¹Ø±Ø¶ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø¥Ù†ØªØ§Ø¬ #' + id);
}

// ÙØªØ­ modal Ø¥Ù†Ø´Ø§Ø¡ Ø¥Ù†ØªØ§Ø¬ Ù…Ù† Ù‚Ø§Ù„Ø¨
function openCreateFromTemplateModal(element) {
    const templateId = element.getAttribute('data-template-id');
    const templateName = element.getAttribute('data-template-name');
    const templateType = element.getAttribute('data-template-type') || 'legacy';
    
    try {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (scrollError) {
        window.scrollTo(0, 0);
    }
    
    document.querySelectorAll('.template-card-modern.selected-template').forEach(card => {
        card.classList.remove('selected-template');
        card.style.setProperty('--template-accent', card.dataset.originalAccent || '#0ea5e9');
        card.style.setProperty('--template-accent-light', card.dataset.originalAccentLight || 'rgba(14, 165, 233, 0.15)');
    });
    if (element) {
        element.classList.add('selected-template');
        element.dataset.originalAccent = getComputedStyle(element).getPropertyValue('--template-accent');
        element.dataset.originalAccentLight = getComputedStyle(element).getPropertyValue('--template-accent-light');
        element.style.setProperty('--template-accent', '#1d4ed8');
        element.style.setProperty('--template-accent-light', '#1d4ed822');
    }
    
    document.getElementById('template_id').value = templateId;
    document.getElementById('template_product_name').value = templateName;
    document.getElementById('template_type').value = templateType;
    
    // Ø¥Ø¹Ø§Ø¯Ø© ØªØ¹ÙŠÙŠÙ† Ø§Ù„Ù‚ÙŠÙ… Ø§Ù„ØªÙ„Ù‚Ø§Ø¦ÙŠØ©
    const batchNotesField = document.querySelector('textarea[name="batch_notes"]');
    if (batchNotesField) {
        batchNotesField.value = '';
    }

    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');
    const summaryWrapper = document.getElementById('templateComponentsSummary');
    const summaryGrid = document.getElementById('templateComponentsSummaryGrid');

    if (container) {
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-info mb-0">
                    <i class="bi bi-hourglass-split me-2"></i>
                    Ø¬Ø§Ø±ÙŠ ØªØ­Ù…ÙŠÙ„ Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ù…ÙˆØ§Ø¯...
                </div>
            </div>
        `;
    }
    if (wrapper) {
        wrapper.classList.add('d-none');
    }
    if (summaryGrid) {
        summaryGrid.innerHTML = '';
    }
    if (summaryWrapper) {
        summaryWrapper.classList.add('d-none');
    }
    currentTemplateMode = 'advanced';
    if (modeInput) {
        modeInput.value = 'advanced';
    }

    const templateCacheKey = templateId + '::' + templateType;
    window.templateDetailsCache = window.templateDetailsCache || {};

    const modalElement = document.getElementById('createFromTemplateModal');
    if (!modalElement) {
        console.error('createFromTemplateModal element not found in DOM.');
        return;
    }
    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    const handleTemplateResponse = (data) => {
        if (data && data.success) {
            renderTemplateSuppliers(data);
        } else {
            if (container) {
                container.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Ù„Ù… ÙŠØªÙ… Ø§Ù„Ø¹Ø«ÙˆØ± Ø¹Ù„Ù‰ Ù…ÙˆØ§Ø¯ Ù„Ù‡Ø°Ø§ Ø§Ù„Ù‚Ø§Ù„Ø¨. ÙŠØ±Ø¬Ù‰ Ù…Ø±Ø§Ø¬Ø¹Ø© Ø¥Ø¹Ø¯Ø§Ø¯Ø§Øª Ø§Ù„Ù‚Ø§Ù„Ø¨.
                        </div>
                    </div>
                `;
            }
        }
    };

    if (window.templateDetailsCache[templateCacheKey]) {
        handleTemplateResponse(window.templateDetailsCache[templateCacheKey]);
        return;
    }

    const createModalUrl = (relativePath) => {
        if (/^https?:\/\//i.test(relativePath)) {
            return relativePath;
        }
        try {
            return new URL(relativePath, window.location.origin).toString();
        } catch (error) {
            return window.location.origin + relativePath;
        }
    };

    const requestUrl = createModalUrl('/dashboard/production.php?page=production&ajax=template_details&template_id=' + templateId + '&template_type=' + encodeURIComponent(templateType));

    fetch(requestUrl, { cache: 'no-store' })
        .then(response => response.ok ? response.json() : Promise.reject(new Error('Network error')))
        .then(data => {
            if (data && data.success) {
                const cacheKey = data.cache_key || templateCacheKey;
                window.templateDetailsCache[cacheKey] = data;
                window.templateDetailsCache[templateCacheKey] = data;
            }
            handleTemplateResponse(data);
        })
        .catch(error => {
            console.error('Error loading template details:', error);
            if (container) {
                container.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-x-circle me-2"></i>
                            ØªØ¹Ø°Ù‘Ø± ØªØ­Ù…ÙŠÙ„ Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ù‚Ø§Ù„Ø¨: ${error.message}
                        </div>
                    </div>
                `;
            }
        });
}

// Ø¥Ø¶Ø§ÙØ© Ù…Ø¹Ø§Ù„Ø¬ Ù„Ù„Ù†Ù…ÙˆØ°Ø¬ Ù„Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„Ø­Ù‚ÙˆÙ„ Ø§Ù„Ù…Ø·Ù„ÙˆØ¨Ø©
document.getElementById('createFromTemplateForm')?.addEventListener('submit', function(e) {
    const quantity = document.querySelector('input[name="quantity"]').value;

    const supplierSelects = document.querySelectorAll('#templateSuppliersContainer select[data-role="component-supplier"]');

    if (supplierSelects.length === 0) {
            e.preventDefault();
        alert('Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…ÙˆØ§Ø¯ Ù…Ø±ØªØ¨Ø·Ø© Ø¨Ø§Ù„Ù‚Ø§Ù„Ø¨ØŒ ÙŠØ±Ø¬Ù‰ Ù…Ø±Ø§Ø¬Ø¹Ø© Ø§Ù„Ù‚Ø§Ù„Ø¨ Ù‚Ø¨Ù„ Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„ØªØ´ØºÙŠÙ„Ø©.');
            return false;
        }

        for (let select of supplierSelects) {
            if (!select.value) {
                e.preventDefault();
                alert('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ø§Ù„Ù…ÙˆØ±Ø¯ Ù„ÙƒÙ„ Ù…Ø§Ø¯Ø© Ù‚Ø¨Ù„ Ø§Ù„Ù…ØªØ§Ø¨Ø¹Ø©');
                select.focus();
                return false;
            }
    }

    const honeyVarietyInputs = document.querySelectorAll('#templateSuppliersContainer input[data-role="honey-variety-input"]');
    for (let input of honeyVarietyInputs) {
        if (input.disabled) {
            e.preventDefault();
            alert('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ù…ÙˆØ±Ø¯ Ø§Ù„Ø¹Ø³Ù„ Ù‚Ø¨Ù„ ØªØ­Ø¯ÙŠØ¯ Ù†ÙˆØ¹Ù‡');
            input.focus();
            return false;
        }
        if (!input.value || !input.value.trim()) {
            e.preventDefault();
            alert('ÙŠØ±Ø¬Ù‰ Ø¥Ø¯Ø®Ø§Ù„ Ù†ÙˆØ¹ Ø§Ù„Ø¹Ø³Ù„ Ù„ÙƒÙ„ Ù…ÙˆØ±Ø¯ Ù…Ø®ØªØ§Ø±');
            input.focus();
            return false;
        }
    }

    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ù„ÙƒÙ…ÙŠØ©
    if (!quantity || parseInt(quantity) <= 0) {
        e.preventDefault();
        alert('ÙŠØ±Ø¬Ù‰ Ø¥Ø¯Ø®Ø§Ù„ ÙƒÙ…ÙŠØ© ØµØ­ÙŠØ­Ø© Ø£ÙƒØ¨Ø± Ù…Ù† Ø§Ù„ØµÙØ±');
        document.querySelector('input[name="quantity"]').focus();
        return false;
    }
});

document.getElementById('createFromTemplateModal')?.addEventListener('shown.bs.modal', function() {
    const modalBody = document.querySelector('#createFromTemplateModal .production-template-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
        modalBody.style.overflowY = 'auto';
    }
});

document.getElementById('createFromTemplateModal')?.addEventListener('hidden.bs.modal', function() {
    const modalBody = document.querySelector('#createFromTemplateModal .production-template-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
        modalBody.style.overflowY = '';
    }
});

// Ø·Ø¨Ø§Ø¹Ø© Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª
function printBarcodes() {
    const batchNumbers = window.batchNumbersToPrint || [];
    const printQuantity = parseInt(document.getElementById('barcode_print_quantity').value) || 1;
    
    if (batchNumbers.length === 0) {
        alert('Ù„Ø§ ØªÙˆØ¬Ø¯ Ø£Ø±Ù‚Ø§Ù… ØªØ´ØºÙŠÙ„Ø© Ù„Ù„Ø·Ø¨Ø§Ø¹Ø©');
        return;
    }
    
    // Ø·Ø¨Ø§Ø¹Ø© Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª - ÙƒÙ„ Ø¨Ø§Ø±ÙƒÙˆØ¯ ÙŠØ­ØªÙˆÙŠ Ø¹Ù„Ù‰ Ù†ÙØ³ Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø©
    // Ø§Ù„ÙƒÙ…ÙŠØ© Ø§Ù„Ù…Ø·Ù„ÙˆØ¨Ø© Ù„Ù„Ø·Ø¨Ø§Ø¹Ø©
    const batchNumber = batchNumbers[0]; // ÙƒÙ„ Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª Ù„Ù‡Ø§ Ù†ÙØ³ Ø§Ù„Ø±Ù‚Ù…
    const printUrl = 'print_barcode.php?batch=' + encodeURIComponent(batchNumber) + '&quantity=' + printQuantity + '&print=1';
    
    window.open(printUrl, '_blank');
}

// Ø¥Ø¯Ø§Ø±Ø© Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø®Ø§Ù… ÙÙŠ modal Ø¥Ù†Ø´Ø§Ø¡ Ø§Ù„Ù‚Ø§Ù„Ø¨
let rawMaterialIndex = 0;

function addRawMaterial() {
    const container = document.getElementById('rawMaterialsContainer');
    const materialHtml = `
        <div class="raw-material-item mb-2 border p-2 rounded">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small">Ø§Ø³Ù… Ø§Ù„Ù…Ø§Ø¯Ø© <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][name]" 
                           placeholder="Ù…Ø«Ù„: Ù…ÙƒØ³Ø±Ø§ØªØŒ Ù„ÙˆØ²" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Ø§Ù„ÙƒÙ…ÙŠØ© <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][quantity]" 
                           step="0.001" min="0.001" placeholder="0.000" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Ø§Ù„ÙˆØ­Ø¯Ø©</label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][unit]" 
                           value="Ø¬Ø±Ø§Ù…" placeholder="Ø¬Ø±Ø§Ù…">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger w-100" onclick="removeRawMaterial(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', materialHtml);
    rawMaterialIndex++;
}

function removeRawMaterial(btn) {
    btn.closest('.raw-material-item').remove();
}

// Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØµØ­Ø© Ø§Ù„Ù†Ù…ÙˆØ°Ø¬ Ù‚Ø¨Ù„ Ø§Ù„Ø¥Ø±Ø³Ø§Ù„
document.getElementById('createTemplateForm')?.addEventListener('submit', function(e) {
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ø®ØªÙŠØ§Ø± Ø£Ø¯Ø§Ø© ØªØ¹Ø¨Ø¦Ø© ÙˆØ§Ø­Ø¯Ø© Ø¹Ù„Ù‰ Ø§Ù„Ø£Ù‚Ù„
    const packagingCheckboxes = document.querySelectorAll('input[name="packaging_ids[]"]:checked');
    if (packagingCheckboxes.length === 0) {
        e.preventDefault();
        alert('ÙŠØ±Ø¬Ù‰ Ø§Ø®ØªÙŠØ§Ø± Ø£Ø¯Ø§Ø© ØªØ¹Ø¨Ø¦Ø© ÙˆØ§Ø­Ø¯Ø© Ø¹Ù„Ù‰ Ø§Ù„Ø£Ù‚Ù„');
        const firstCheckbox = document.querySelector('input[name="packaging_ids[]"]');
        if (firstCheckbox) {
            firstCheckbox.focus();
        }
        return false;
    }
    
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† ØµØ­Ø© ÙƒÙ…ÙŠØ© Ø§Ù„Ø¹Ø³Ù„
    const honeyQuantity = parseFloat(document.querySelector('input[name="honey_quantity"]').value);
    if (!honeyQuantity || honeyQuantity <= 0) {
        e.preventDefault();
        alert('ÙŠØ±Ø¬Ù‰ Ø¥Ø¯Ø®Ø§Ù„ ÙƒÙ…ÙŠØ© Ø¹Ø³Ù„ ØµØ­ÙŠØ­Ø© Ø£ÙƒØ¨Ø± Ù…Ù† Ø§Ù„ØµÙØ±');
        return false;
    }
    
    // Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ø§Ø³Ù… Ø§Ù„Ù…Ù†ØªØ¬
    const productName = document.querySelector('input[name="product_name"]').value.trim();
    if (!productName) {
        e.preventDefault();
        alert('ÙŠØ±Ø¬Ù‰ Ø¥Ø¯Ø®Ø§Ù„ Ø§Ø³Ù… Ø§Ù„Ù…Ù†ØªØ¬');
        return false;
    }
});

<?php
// Ø¹Ø±Ø¶ modal Ø§Ù„Ø·Ø¨Ø§Ø¹Ø© Ø¥Ø°Ø§ ØªÙ… Ø¥Ù†Ø´Ø§Ø¡ Ø¥Ù†ØªØ§Ø¬ Ù…Ù† Ù‚Ø§Ù„Ø¨
if (isset($_GET['show_barcode_modal']) && isset($_SESSION['created_batch_numbers'])) {
    $batchNumbers = $_SESSION['created_batch_numbers'];
    $productName = $_SESSION['created_batch_product_name'] ?? '';
    $quantity = $_SESSION['created_batch_quantity'] ?? count($batchNumbers);
    
    // ØªÙ†Ø¸ÙŠÙ session
    unset($_SESSION['created_batch_numbers']);
    unset($_SESSION['created_batch_product_name']);
    unset($_SESSION['created_batch_quantity']);
    
    echo "
    <script>
    window.batchNumbersToPrint = " . json_encode($batchNumbers) . ";
    
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('barcode_product_name').value = " . json_encode($productName) . ";
        document.getElementById('barcode_quantity').textContent = " . $quantity . ";
        document.getElementById('barcode_print_quantity').value = " . $quantity . ";
        
        // Ø¹Ø±Ø¶ Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø© (ÙƒÙ„ Ø§Ù„Ø¨Ø§Ø±ÙƒÙˆØ¯Ø§Øª Ù„Ù‡Ø§ Ù†ÙØ³ Ø§Ù„Ø±Ù‚Ù…)
        const batchNumber = " . json_encode($batchNumbers[0] ?? '') . ";
        let batchListHtml = '<div class=\"alert alert-info mb-0\">';
        batchListHtml += '<i class=\"bi bi-info-circle me-2\"></i>';
        batchListHtml += '<strong>Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø©:</strong> ' + batchNumber + '<br>';
        batchListHtml += '<small>Ø³ÙŠØªÙ… Ø·Ø¨Ø§Ø¹Ø© Ù†ÙØ³ Ø±Ù‚Ù… Ø§Ù„ØªØ´ØºÙŠÙ„Ø© Ø¨Ø¹Ø¯Ø¯ ' + " . $quantity . " . ' Ø¨Ø§Ø±ÙƒÙˆØ¯</small>';
        batchListHtml += '</div>';
        document.getElementById('batch_numbers_list').innerHTML = batchListHtml;
        
        const modal = new bootstrap.Modal(document.getElementById('printBarcodesModal'));
        modal.show();
    });
    </script>
    ";
}
?>
</script>

<style>
.template-card {
    min-height: 180px;
}

@media (max-width: 768px) {
    .template-card {
        margin-bottom: 0.75rem;
        min-height: 160px;
    }
}

@media (min-width: 1400px) {
    .template-card {
        min-height: 200px;
    }
}
 
</style>

<?php
// Ù…Ø¹Ø§Ù„Ø¬Ø© AJAX Ù„ØªØ­Ù…ÙŠÙ„ Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ø¥Ù†ØªØ§Ø¬
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['id'])) {
    $productionId = intval($_GET['id']);
    $production = $db->queryOne(
        "SELECT p.*, pr.name as product_name FROM production p 
         LEFT JOIN products pr ON p.product_id = pr.id 
         WHERE p.id = ?",
        [$productionId]
    );
    
    if ($production) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'production' => [
                'id' => $production['id'],
                'product_id' => $production['product_id'],
                'quantity' => $production['quantity'],
                'date' => $production[$dateColumn] ?? $production['created_at'],
                'materials_used' => $production['materials_used'] ?? '',
                'notes' => $production['notes'] ?? '',
                'status' => $production['status'] ?? 'pending'
            ]
        ]);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ù„Ù… ÙŠØªÙ… Ø§Ù„Ø¹Ø«ÙˆØ± Ø¹Ù„Ù‰ Ø§Ù„Ø³Ø¬Ù„']);
        exit;
    }
}
?>

<script>
// Ù…Ù†Ø¹ ØªÙƒØ±Ø§Ø± Ø§Ù„Ø¥Ø±Ø³Ø§Ù„ - Ø¥Ø¶Ø§ÙØ© ØªÙˆÙƒÙ† ÙØ±ÙŠØ¯ Ù„ÙƒÙ„ Ù†Ù…ÙˆØ°Ø¬
document.addEventListener('DOMContentLoaded', function() {
    // Ø§Ù„Ø¨Ø­Ø« Ø¹Ù† Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù†Ù…Ø§Ø°Ø¬ ÙÙŠ Ø§Ù„ØµÙØ­Ø©
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    
    forms.forEach(function(form) {
        // ØªØ­Ù‚Ù‚ Ù…Ù† Ø¹Ø¯Ù… ÙˆØ¬ÙˆØ¯ ØªÙˆÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯ Ø¨Ø§Ù„ÙØ¹Ù„
        if (!form.querySelector('input[name="submit_token"]')) {
            // Ø¥Ù†Ø´Ø§Ø¡ ØªÙˆÙƒÙ† ÙØ±ÙŠØ¯
            const token = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            // Ø¥Ù†Ø´Ø§Ø¡ Ø­Ù‚Ù„ Ù…Ø®ÙÙŠ Ù„Ù„ØªÙˆÙƒÙ†
            const tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'submit_token';
            tokenInput.value = token;
            
            // Ø¥Ø¶Ø§ÙØ© Ø§Ù„ØªÙˆÙƒÙ† Ù„Ù„Ù†Ù…ÙˆØ°Ø¬
            form.appendChild(tokenInput);
        }
        
        // Ù…Ù†Ø¹ Ø¥Ø¹Ø§Ø¯Ø© Ø§Ù„Ø¥Ø±Ø³Ø§Ù„ Ø¹Ù†Ø¯ Ø§Ù„Ø¶ØºØ· Ø¹Ù„Ù‰ Ø²Ø± Ø§Ù„Ø¥Ø±Ø³Ø§Ù„
        form.addEventListener('submit', function(e) {
            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitButton) {
                // ØªØ¹Ø·ÙŠÙ„ Ø§Ù„Ø²Ø± ÙÙˆØ±Ø§Ù‹
                submitButton.disabled = true;
                submitButton.style.opacity = '0.6';
                submitButton.style.cursor = 'not-allowed';
                
                // Ø¥Ø¶Ø§ÙØ© Ù†Øµ "Ø¬Ø§Ø±ÙŠ Ø§Ù„Ù…Ø¹Ø§Ù„Ø¬Ø©..."
                const originalText = submitButton.innerHTML || submitButton.value;
                if (submitButton.tagName === 'BUTTON') {
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Ø¬Ø§Ø±ÙŠ Ø§Ù„Ù…Ø¹Ø§Ù„Ø¬Ø©...';
                } else {
                    submitButton.value = 'Ø¬Ø§Ø±ÙŠ Ø§Ù„Ù…Ø¹Ø§Ù„Ø¬Ø©...';
                }
                
                // Ø¥Ø¹Ø§Ø¯Ø© ØªÙØ¹ÙŠÙ„ Ø§Ù„Ø²Ø± Ø¨Ø¹Ø¯ 3 Ø«ÙˆØ§Ù†ÙŠ (ÙÙŠ Ø­Ø§Ù„Ø© ÙØ´Ù„ Ø§Ù„Ø¥Ø±Ø³Ø§Ù„)
                setTimeout(function() {
                    submitButton.disabled = false;
                    submitButton.style.opacity = '1';
                    submitButton.style.cursor = 'pointer';
                    if (submitButton.tagName === 'BUTTON') {
                        submitButton.innerHTML = originalText;
                    } else {
                        submitButton.value = originalText;
                    }
                    
                    // ØªØ­Ø¯ÙŠØ« Ø§Ù„ØªÙˆÙƒÙ†
                    const newToken = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    const tokenInput = form.querySelector('input[name="submit_token"]');
                    if (tokenInput) {
                        tokenInput.value = newToken;
                    }
                }, 3000);
            }
        });
    });
    
    // Ù…Ù†Ø¹ Ø¥Ø¹Ø§Ø¯Ø© Ø§Ù„Ø¥Ø±Ø³Ø§Ù„ Ø¹Ù†Ø¯ Ø§Ù„Ø¶ØºØ· Ø¹Ù„Ù‰ F5 Ø£Ùˆ Refresh
    if (performance.navigation.type === 1) {
        // Ø§Ù„ØµÙØ­Ø© ØªÙ… ØªØ­Ø¯ÙŠØ«Ù‡Ø§ (Refresh)
        // Ø¥Ø²Ø§Ù„Ø© Ø£ÙŠ Ø±Ø³Ø§Ø¦Ù„ Ø®Ø·Ø£ Ù‚Ø¯ ØªÙƒÙˆÙ† Ù†Ø§ØªØ¬Ø© Ø¹Ù† Ø¥Ø¹Ø§Ø¯Ø© Ø§Ù„Ø¥Ø±Ø³Ø§Ù„
        console.log('ØªÙ… Ø§ÙƒØªØ´Ø§Ù Ø¥Ø¹Ø§Ø¯Ø© ØªØ­Ù…ÙŠÙ„ Ø§Ù„ØµÙØ­Ø© - ØªÙ… Ù…Ù†Ø¹ Ø¥Ø¹Ø§Ø¯Ø© Ø§Ù„Ø¥Ø±Ø³Ø§Ù„');
    }
});

// ØªØ­Ø°ÙŠØ± Ø¹Ù†Ø¯ Ù…Ø­Ø§ÙˆÙ„Ø© Ø¥Ø¹Ø§Ø¯Ø© Ø§Ù„Ø¥Ø±Ø³Ø§Ù„
window.addEventListener('beforeunload', function(e) {
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    let formModified = false;
    
    forms.forEach(function(form) {
        // ØªØ­Ù‚Ù‚ Ø¥Ø°Ø§ ØªÙ… ØªØ¹Ø¯ÙŠÙ„ Ø£ÙŠ Ø­Ù‚Ù„
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            if (input.value !== input.defaultValue) {
                formModified = true;
            }
        });
    });
    
    // Ù„Ø§ ØªØ¹Ø±Ø¶ ØªØ­Ø°ÙŠØ± Ø¥Ø°Ø§ Ù„Ù… ÙŠØªÙ… ØªØ¹Ø¯ÙŠÙ„ Ø£ÙŠ Ø´ÙŠØ¡
    if (!formModified) {
        return undefined;
    }
});
</script>

