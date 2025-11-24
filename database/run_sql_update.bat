@echo off
REM سكريبت لتحديث قيد UNIQUE في جدول vehicle_inventory
REM يعمل مع MySQL من سطر الأوامر

echo ============================================
echo تحديث قيد UNIQUE في جدول vehicle_inventory
echo ============================================
echo.

REM إعدادات قاعدة البيانات - عدّل حسب بيئتك
set DB_HOST=localhost
set DB_USER=root
set DB_PASS=
set DB_NAME=tr

REM تنفيذ SQL مباشرة
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME% -e "ALTER TABLE vehicle_inventory DROP INDEX IF EXISTS vehicle_product_unique;"
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME% -e "ALTER TABLE vehicle_inventory DROP INDEX IF EXISTS vehicle_product;"
mysql -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME% -e "ALTER TABLE vehicle_inventory ADD UNIQUE KEY vehicle_product_batch_unique (vehicle_id, product_id, finished_batch_id);"

echo.
echo تم التنفيذ!
pause

