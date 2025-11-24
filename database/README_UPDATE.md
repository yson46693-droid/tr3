# تحديث قيد UNIQUE في جدول vehicle_inventory

## ملخص التحديث
تم تحديث القيد UNIQUE في جدول `vehicle_inventory` ليشمل `finished_batch_id`، مما يسمح بتخزين منتجات من نفس النوع برقم تشغيلة مختلف في نفس السيارة.

## الطرق المتاحة للتنفيذ

### الطريقة 1: التحديث التلقائي (موصى به)
التحديث سيعمل تلقائياً عند أول اتصال بقاعدة البيانات من خلال:
- `includes/db.php` → دالة `ensureVehicleInventoryAutoUpgrade()`
- يتم التنفيذ تلقائياً عند استخدام أي صفحة في الموقع

**لا حاجة لأي إجراء - سيعمل تلقائياً!**

### الطريقة 2: تنفيذ SQL مباشرة
افتح phpMyAdmin وانسخ/الصق محتوى ملف:
- `database/EXECUTE_THIS.sql`

### الطريقة 3: استدعاء صفحة PHP
افتح في المتصفح:
- `http://localhost/v1/database/run_all_updates.php`
- أو `http://localhost/v1/database/execute_update_once.php`

### الطريقة 4: من سطر الأوامر
```powershell
php database/execute_update_once.php
```

## حالة التحديث
✅ الكود موجود في `includes/db.php`  
✅ يتم التنفيذ تلقائياً عند الاتصال بقاعدة البيانات  
✅ لا حاجة لأي إجراء إضافي

