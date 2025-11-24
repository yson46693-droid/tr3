# دليل تطبيق خصم تلقائي لمادة تعبئة عند وجود مادة أخرى

## المثال المطبق: PKG-009 → PKG-001
عند وجود PKG-009 في القالب وكمية الإنتاج >= 12، يتم خصم 1 من PKG-001 لكل 12 وحدة.

---

## الخطوات المطلوبة

### 1. اكتشاف المادة المحفزة (Trigger Material)
**الموقع:** داخل حلقة `foreach ($packagingItems as $pkg)`

```php
// بعد تحديد $packagingMaterialCodeKey
$isPkg009 = false;
if ($packagingMaterialCodeKey === 'PKG009') {
    $isPkg009 = true;
} elseif ($packagingMaterialCodeNormalized !== null) {
    $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
    if ($normalizedCodeKey === 'PKG009') {
        $isPkg009 = true;
    }
} elseif ($packagingMaterialId > 0 && $packagingTableExists) {
    // محاولة أخيرة: البحث المباشر في قاعدة البيانات
    try {
        $materialCodeCheck = $db->queryOne(
            "SELECT material_id FROM packaging_materials WHERE id = ?",
            [$packagingMaterialId]
        );
        if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
            $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
            if ($checkCodeKey === 'PKG009') {
                $isPkg009 = true;
                $packagingMaterialCodeKey = 'PKG009';
            }
        }
    } catch (Throwable $checkError) {
        // تجاهل الخطأ
    }
}

if (!$templateIncludesPkg009 && $isPkg009) {
    $templateIncludesPkg009 = true;
    error_log('PKG-009 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-001 deduction if quantity >= 12');
}
```

**لعنصر آخر:** استبدل `'PKG009'` و `$templateIncludesPkg009` بالقيم المناسبة.

---

### 2. منطق الخصم التلقائي
**الموقع:** بعد حلقة `foreach ($packagingItems as $pkg)` وقبل `foreach ($rawMaterials as $raw)`

```php
if ($templateIncludesPkg009 && $quantity >= 12) {
    error_log('PKG-001 deduction logic triggered: templateIncludesPkg009=' . ($templateIncludesPkg009 ? 'true' : 'false') . ', quantity=' . $quantity);
    
    // تعريف الكود المستهدف
    $pkg001CodeKey = 'PKG001';  // الكود بدون فواصل
    $pkg001DisplayCode = $formatPackagingCode($pkg001CodeKey) ?? 'PKG-001';  // الكود مع فواصل
    
    // البحث عن المادة في قاعدة البيانات
    $pkg001Id = null;
    $pkg001Name = 'مادة تعبئة PKG-001';
    $pkg001Unit = 'قطعة';
    
    if ($packagingTableExists) {
        try {
            // البحث المباشر - الأكثر موثوقية
            $directSearch = $db->queryOne(
                "SELECT id, name, unit, material_id FROM packaging_materials 
                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG001'
                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG001%'
                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG001%'
                 LIMIT 1"
            );
            if ($directSearch && !empty($directSearch['id'])) {
                $pkg001Id = (int)$directSearch['id'];
                if (!empty($directSearch['name'])) {
                    $pkg001Name = $directSearch['name'];
                }
                if (!empty($directSearch['unit'])) {
                    $pkg001Unit = $directSearch['unit'];
                }
                error_log('PKG-001 found by direct search: ID=' . $pkg001Id . ', Name=' . $pkg001Name);
            } else {
                // محاولة البحث بالكود
                $pkg001Info = $fetchPackagingMaterialByCode($pkg001DisplayCode) ?? [];
                if (!empty($pkg001Info['id'])) {
                    $pkg001Id = (int)$pkg001Info['id'];
                    if (!empty($pkg001Info['name'])) {
                        $pkg001Name = $pkg001Info['name'];
                    }
                    if (!empty($pkg001Info['unit'])) {
                        $pkg001Unit = $pkg001Info['unit'];
                    }
                    error_log('PKG-001 found by code search: ID=' . $pkg001Id);
                } else {
                    // محاولة البحث بالاسم
                    $pkg001ByName = $resolvePackagingByName('PKG-001');
                    if ($pkg001ByName && !empty($pkg001ByName['id'])) {
                        $pkg001Id = (int)$pkg001ByName['id'];
                        if (!empty($pkg001ByName['name'])) {
                            $pkg001Name = $pkg001ByName['name'];
                        }
                        if (!empty($pkg001ByName['unit'])) {
                            $pkg001Unit = $pkg001ByName['unit'];
                        }
                        error_log('PKG-001 found by name search: ID=' . $pkg001Id);
                    }
                }
            }
        } catch (Throwable $searchError) {
            error_log('PKG-001 search error: ' . $searchError->getMessage());
        }
    }
    
    if ($pkg001Id === null) {
        error_log('PKG-001 NOT FOUND in database. Automatic deduction will be skipped.');
    }

    // حساب الكمية المطلوبة للخصم
    $quantityForBoxes = (int)floor((float)$quantity);
    $additionalPkg001Qty = intdiv(max($quantityForBoxes, 0), 12);  // 1 لكل 12 وحدة
    
    error_log('PKG-001 deduction check: quantity=' . $quantity . ', additionalQty=' . $additionalPkg001Qty . ', pkg001Id=' . ($pkg001Id ?? 'NULL'));
    
    if ($additionalPkg001Qty > 0 && $pkg001Id !== null) {
        // محاولة الدمج مع عنصر موجود
        $pkg001Merged = false;
        foreach ($materialsConsumption['packaging'] as &$packItem) {
            $materialIdMatches = isset($packItem['material_id'])
                && (int)$packItem['material_id'] === $pkg001Id;
            $packItemCodeKey = isset($packItem['material_code'])
                ? $normalizePackagingCodeKey($packItem['material_code'])
                : null;
            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg001CodeKey;

            if ($materialIdMatches || $materialCodeMatches) {
                $packItem['quantity'] += $additionalPkg001Qty;
                $packItem['material_code'] = $pkg001DisplayCode;
                $packItem['material_id'] = $pkg001Id;
                $pkg001Merged = true;
                error_log('PKG-001 merged with existing item. New quantity=' . $packItem['quantity']);
                break;
            }
        }
        unset($packItem);

        // إضافة عنصر جديد إذا لم يتم الدمج
        if (!$pkg001Merged) {
            $pkg001ProductId = ensureProductionMaterialProductId($pkg001Name, 'packaging', $pkg001Unit);

            $materialsConsumption['packaging'][] = [
                'material_id' => $pkg001Id,
                'quantity' => $additionalPkg001Qty,
                'name' => $pkg001Name,
                'unit' => $pkg001Unit,
                'product_id' => $pkg001ProductId,
                'supplier_id' => null,
                'template_item_id' => null,
                'material_code' => $pkg001DisplayCode
            ];
            error_log('PKG-001 added as new item. ID=' . $pkg001Id . ', Quantity=' . $additionalPkg001Qty);
        }

        $packagingIdsMap[$pkg001Id] = true;
    } elseif ($additionalPkg001Qty > 0 && $pkg001Id === null) {
        error_log('PKG-001 automatic deduction skipped: material_id is null. Quantity would have been: ' . $additionalPkg001Qty);
    }
}
```

**لعنصر آخر:** استبدل:
- `$templateIncludesPkg009` → متغير جديد (مثل `$templateIncludesPkgXXX`)
- `'PKG001'` → كود المادة المستهدفة
- `'PKG-001'` → كود المادة المستهدفة مع فواصل
- `12` → العدد المطلوب (مثل 24، 36، إلخ)
- `intdiv(..., 12)` → الصيغة الحسابية المناسبة

---

### 3. حلقة الخصم من قاعدة البيانات
**الموقع:** بعد `storeProductionMaterialsUsage()` وقبل `$db->commit()`

**ملاحظة مهمة:** يجب أن تكون خارج `if (empty($batchResult['stock_deducted']))` لتعمل دائماً.

```php
// خصم مواد التعبئة - يجب أن يتم دائماً بغض النظر عن stock_deducted
try {
    $packagingItemsCount = count($materialsConsumption['packaging'] ?? []);
    error_log('Starting packaging deduction loop. Total items: ' . $packagingItemsCount);
    
    if (!empty($materialsConsumption['packaging'])) {
        foreach ($materialsConsumption['packaging'] as &$packItem) {
            $packMaterialId = isset($packItem['material_id']) ? (int)$packItem['material_id'] : 0;
            $packQuantity = (float)($packItem['quantity'] ?? 0);
            $packItemName = trim((string)($packItem['name'] ?? ''));
            $packItemCode = isset($packItem['material_code']) ? (string)$packItem['material_code'] : '';
            
            error_log('Processing packaging item: material_id=' . $packMaterialId . ', quantity=' . $packQuantity . ', name=' . $packItemName . ', code=' . $packItemCode);
            
            // محاولة حل material_id إذا كان مفقوداً
            if ($packMaterialId <= 0 && $packQuantity > 0 && $packagingTableExists) {
                $lookupName = $packItemName;
                $resolvedRow = $resolvePackagingByName($lookupName);
                if ($resolvedRow) {
                    $packMaterialId = (int)$resolvedRow['id'];
                    $packItem['material_id'] = $packMaterialId;
                    error_log('Resolved packaging by name: ' . $lookupName . ' -> ID=' . $packMaterialId);
                }
            }
            
            // الخصم الفعلي
            if ($packMaterialId > 0 && $packQuantity > 0) {
                $quantityBefore = null;
                $materialNameForLog = $packItemName;
                $materialUnitForLog = $packItem['unit'] ?? 'وحدة';

                // جلب الكمية الحالية للسجلات
                if ($packagingUsageLogsExists) {
                    try {
                        $packagingRowForLog = $db->queryOne(
                            "SELECT name, unit, quantity FROM packaging_materials WHERE id = ?",
                            [$packMaterialId]
                        );
                        if ($packagingRowForLog) {
                            $quantityBefore = (float)($packagingRowForLog['quantity'] ?? 0);
                            if (!empty($packagingRowForLog['name'])) {
                                $materialNameForLog = $packagingRowForLog['name'];
                            }
                            if (!empty($packagingRowForLog['unit'])) {
                                $materialUnitForLog = $packagingRowForLog['unit'];
                            }
                        }
                    } catch (Exception $packagingLogFetchError) {
                        error_log('Production packaging usage fetch warning: ' . $packagingLogFetchError->getMessage());
                    }
                }

                error_log('Deducting from packaging_materials: ID=' . $packMaterialId . ', Quantity=' . $packQuantity . ', Before=' . ($quantityBefore ?? 'N/A'));
                
                // تنفيذ الخصم
                try {
                    $db->execute(
                        "UPDATE packaging_materials 
                         SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                         WHERE id = ?",
                        [$packQuantity, $packMaterialId]
                    );
                    
                    // التحقق من نجاح الخصم
                    $verifyDeduction = $db->queryOne(
                        "SELECT quantity FROM packaging_materials WHERE id = ?",
                        [$packMaterialId]
                    );
                    if ($verifyDeduction) {
                        $quantityAfter = (float)($verifyDeduction['quantity'] ?? 0);
                        error_log('Deduction successful: ID=' . $packMaterialId . ', After=' . $quantityAfter);
                    }
                } catch (Exception $deductionError) {
                    error_log('Packaging deduction ERROR: ' . $deductionError->getMessage() . ' | ID=' . $packMaterialId . ', Qty=' . $packQuantity);
                }

                // تسجيل في packaging_usage_logs إذا كان موجوداً
                if ($packagingUsageLogsExists && $quantityBefore !== null) {
                    $quantityAfter = max($quantityBefore - $packQuantity, 0);
                    $quantityUsed = $quantityBefore - $quantityAfter;

                    if ($quantityUsed > 0) {
                        try {
                            $db->execute(
                                "INSERT INTO packaging_usage_logs 
                                 (material_id, material_name, material_code, source_table, quantity_before, quantity_used, quantity_after, unit, used_by) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                [
                                    $packMaterialId,
                                    $materialNameForLog,
                                    null,
                                    'packaging_materials',
                                    $quantityBefore,
                                    $quantityUsed,
                                    $quantityAfter,
                                    $materialUnitForLog ?: 'وحدة',
                                    $currentUser['id'] ?? null
                                ]
                            );
                        } catch (Exception $packagingUsageInsertError) {
                            error_log('Production packaging usage log insert failed: ' . $packagingUsageInsertError->getMessage());
                        }
                    }
                }
            }
        }
        unset($packItem);
    }
} catch (Exception $packagingDeductionError) {
    error_log('Packaging deduction error: ' . $packagingDeductionError->getMessage());
}
```

**هذا الجزء لا يحتاج تعديل** - يعمل تلقائياً لجميع مواد التعبئة.

---

## مثال: تطبيق على عنصر آخر

### السيناريو: PKG-010 → PKG-002 (خصم 1 لكل 24 وحدة)

#### 1. إضافة متغير جديد في بداية الكود:
```php
$templateIncludesPkg010 = false;
```

#### 2. في حلقة اكتشاف المواد:
```php
// استبدل PKG009 بـ PKG010
$isPkg010 = false;
if ($packagingMaterialCodeKey === 'PKG010') {
    $isPkg010 = true;
} // ... باقي الكود

if (!$templateIncludesPkg010 && $isPkg010) {
    $templateIncludesPkg010 = true;
    error_log('PKG-010 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-002 deduction if quantity >= 24');
}
```

#### 3. منطق الخصم:
```php
if ($templateIncludesPkg010 && $quantity >= 24) {
    error_log('PKG-002 deduction logic triggered: templateIncludesPkg010=true, quantity=' . $quantity);
    
    $pkg002CodeKey = 'PKG002';
    $pkg002DisplayCode = $formatPackagingCode($pkg002CodeKey) ?? 'PKG-002';
    
    // ... البحث عن PKG-002 (استبدل PKG001 بـ PKG002)
    
    $quantityForBoxes = (int)floor((float)$quantity);
    $additionalPkg002Qty = intdiv(max($quantityForBoxes, 0), 24);  // 1 لكل 24
    
    // ... باقي الكود (استبدل pkg001 بـ pkg002)
}
```

---

## نقاط مهمة

1. **المتغيرات:** استخدم متغيرات منفصلة لكل قاعدة (مثل `$templateIncludesPkg009` و `$templateIncludesPkg010`)
2. **الكود:** استخدم الكود بدون فواصل في `$codeKey` (مثل `'PKG001'`) ومع فواصل في `$displayCode` (مثل `'PKG-001'`)
3. **الحساب:** استخدم `intdiv()` للقسمة الصحيحة (مثل `intdiv($quantity, 24)`)
4. **الشرط:** تأكد من الشرط (مثل `$quantity >= 24`)
5. **الموقع:** حلقة الخصم يجب أن تكون خارج `if (empty($batchResult['stock_deducted']))`
6. **Logging:** استخدم `error_log()` لتتبع المشاكل

---

## اختبار التعديل

بعد التطبيق، تحقق من:
1. ✅ اكتشاف المادة المحفزة في الـ logs
2. ✅ تفعيل منطق الخصم عند تحقيق الشرط
3. ✅ العثور على المادة المستهدفة
4. ✅ إضافة/دمج العنصر في `$materialsConsumption['packaging']`
5. ✅ تنفيذ الخصم من `packaging_materials`
6. ✅ رسالة "Deduction successful" في الـ logs

