<?php
declare(strict_types=1);

// تعريف ACCESS_ALLOWED قبل تضمين أي ملفات
define('ACCESS_ALLOWED', true);

// تعطيل عرض الأخطاء في المتصفح لمنع HTML في JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// تنظيف أي output buffer موجود
while (ob_get_level() > 0) {
    ob_end_clean();
}

// بدء output buffering جديد
ob_start();

// إرسال header JSON أولاً
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/audit_log.php';
    
    // تنظيف أي output تم إنتاجه من الملفات المحملة
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error loading includes in factory_waste.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ في تحميل الملفات المطلوبة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من تسجيل الدخول
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول أولاً'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'خطأ في جلب بيانات المستخدم'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // السماح للمدير فقط بالحذف والتعديل
    if ($currentUser['role'] !== 'manager') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'غير مصرح لك بتنفيذ هذه العملية'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error checking session in factory_waste.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الصلاحيات'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $action = $_POST['action'] ?? '';
    $db = db();
    
    if ($action === 'edit_product') {
        $id = intval($_POST['id'] ?? 0);
        $damagedQuantity = floatval($_POST['damaged_quantity'] ?? 0);
        $addedDate = trim($_POST['added_date'] ?? '');
        $wasteValue = isset($_POST['waste_value']) ? floatval($_POST['waste_value']) : null;
        
        if ($id <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'معرف المنتج غير صحيح'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if ($damagedQuantity <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'الكمية التالفة يجب أن تكون أكبر من صفر'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (empty($addedDate)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'تاريخ الإضافة مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود السجل
        $product = $db->queryOne("SELECT * FROM factory_waste_products WHERE id = ?", [$id]);
        if (empty($product)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // تحديث السجل
        $updateSql = "UPDATE factory_waste_products SET damaged_quantity = ?, added_date = ?";
        $updateParams = [$damagedQuantity, $addedDate];
        
        if ($wasteValue !== null) {
            $updateSql .= ", waste_value = ?";
            $updateParams[] = $wasteValue;
        }
        
        $updateSql .= " WHERE id = ?";
        $updateParams[] = $id;
        
        $db->execute($updateSql, $updateParams);
        
        // تسجيل العملية
        logAudit($currentUser['id'], 'edit_factory_waste_product', 'factory_waste_products', $id, null, [
            'old_quantity' => $product['damaged_quantity'],
            'new_quantity' => $damagedQuantity,
            'old_date' => $product['added_date'],
            'new_date' => $addedDate
        ]);
        
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'تم التعديل بنجاح'], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'delete_product') {
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'معرف المنتج غير صحيح'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود السجل
        $product = $db->queryOne("SELECT * FROM factory_waste_products WHERE id = ?", [$id]);
        if (empty($product)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حذف السجل
        $db->execute("DELETE FROM factory_waste_products WHERE id = ?", [$id]);
        
        // تسجيل العملية
        logAudit($currentUser['id'], 'delete_factory_waste_product', 'factory_waste_products', $id, null, [
            'product_name' => $product['product_name'],
            'damaged_quantity' => $product['damaged_quantity']
        ]);
        
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'تم الحذف بنجاح'], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'edit_packaging') {
        $id = intval($_POST['id'] ?? 0);
        $damagedQuantity = floatval($_POST['damaged_quantity'] ?? 0);
        $addedDate = trim($_POST['added_date'] ?? '');
        
        if ($id <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'معرف الأداة غير صحيح'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if ($damagedQuantity <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'الكمية التالفة يجب أن تكون أكبر من صفر'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (empty($addedDate)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'تاريخ الإضافة مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود السجل
        $packaging = $db->queryOne("SELECT * FROM factory_waste_packaging WHERE id = ?", [$id]);
        if (empty($packaging)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // تحديث السجل
        $db->execute(
            "UPDATE factory_waste_packaging SET damaged_quantity = ?, added_date = ? WHERE id = ?",
            [$damagedQuantity, $addedDate, $id]
        );
        
        // تسجيل العملية
        logAudit($currentUser['id'], 'edit_factory_waste_packaging', 'factory_waste_packaging', $id, null, [
            'old_quantity' => $packaging['damaged_quantity'],
            'new_quantity' => $damagedQuantity,
            'old_date' => $packaging['added_date'],
            'new_date' => $addedDate
        ]);
        
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'تم التعديل بنجاح'], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'delete_packaging') {
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'معرف الأداة غير صحيح'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود السجل
        $packaging = $db->queryOne("SELECT * FROM factory_waste_packaging WHERE id = ?", [$id]);
        if (empty($packaging)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حذف السجل
        $db->execute("DELETE FROM factory_waste_packaging WHERE id = ?", [$id]);
        
        // تسجيل العملية
        logAudit($currentUser['id'], 'delete_factory_waste_packaging', 'factory_waste_packaging', $id, null, [
            'tool_type' => $packaging['tool_type'],
            'damaged_quantity' => $packaging['damaged_quantity']
        ]);
        
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'تم الحذف بنجاح'], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'edit_raw_material') {
        $id = intval($_POST['id'] ?? 0);
        $wastedQuantity = floatval($_POST['wasted_quantity'] ?? 0);
        $addedDate = trim($_POST['added_date'] ?? '');
        $wasteValue = isset($_POST['waste_value']) ? floatval($_POST['waste_value']) : null;
        
        if ($id <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'معرف الخامة غير صحيح'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if ($wastedQuantity <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'الكمية المهدرة يجب أن تكون أكبر من صفر'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (empty($addedDate)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'تاريخ الإضافة مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود السجل
        $rawMaterial = $db->queryOne("SELECT * FROM factory_waste_raw_materials WHERE id = ?", [$id]);
        if (empty($rawMaterial)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // تحديث السجل
        $updateSql = "UPDATE factory_waste_raw_materials SET wasted_quantity = ?, added_date = ?";
        $updateParams = [$wastedQuantity, $addedDate];
        
        if ($wasteValue !== null) {
            $updateSql .= ", waste_value = ?";
            $updateParams[] = $wasteValue;
        }
        
        $updateSql .= " WHERE id = ?";
        $updateParams[] = $id;
        
        $db->execute($updateSql, $updateParams);
        
        // تسجيل العملية
        logAudit($currentUser['id'], 'edit_factory_waste_raw_material', 'factory_waste_raw_materials', $id, null, [
            'old_quantity' => $rawMaterial['wasted_quantity'],
            'new_quantity' => $wastedQuantity,
            'old_date' => $rawMaterial['added_date'],
            'new_date' => $addedDate
        ]);
        
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'تم التعديل بنجاح'], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'delete_raw_material') {
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'معرف الخامة غير صحيح'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود السجل
        $rawMaterial = $db->queryOne("SELECT * FROM factory_waste_raw_materials WHERE id = ?", [$id]);
        if (empty($rawMaterial)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حذف السجل
        $db->execute("DELETE FROM factory_waste_raw_materials WHERE id = ?", [$id]);
        
        // تسجيل العملية
        logAudit($currentUser['id'], 'delete_factory_waste_raw_material', 'factory_waste_raw_materials', $id, null, [
            'material_name' => $rawMaterial['material_name'],
            'wasted_quantity' => $rawMaterial['wasted_quantity']
        ]);
        
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'تم الحذف بنجاح'], JSON_UNESCAPED_UNICODE);
        
    } else {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'عملية غير معروفة'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Error in factory_waste.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // التأكد من أن الـ header يتم إرساله بشكل صحيح
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ داخلي في الخادم'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التأكد من أنه لا يوجد أي output إضافي
if (ob_get_level() > 0) {
    ob_end_clean();
}

