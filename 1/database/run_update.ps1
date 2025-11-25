# سكريبت PowerShell لتحديث قاعدة البيانات
# يعمل في الخلفية بدون فتح المتصفح

Write-Host "=== تحديث قيد UNIQUE في جدول vehicle_inventory ===" -ForegroundColor Cyan
Write-Host ""

try {
    # استدعاء صفحة PHP عبر HTTP
    $url = "http://localhost/v1/database/run_all_updates.php"
    
    Write-Host "جاري تنفيذ التحديث..." -ForegroundColor Yellow
    
    # استدعاء الصفحة في الخلفية
    $response = Invoke-WebRequest -Uri $url -UseBasicParsing -ErrorAction SilentlyContinue
    
    if ($response.StatusCode -eq 200) {
        Write-Host "✓ تم تنفيذ التحديث بنجاح!" -ForegroundColor Green
        Write-Host ""
        Write-Host "يمكنك الآن استخدام الموقع بشكل طبيعي" -ForegroundColor Green
    } else {
        Write-Host "✗ حدث خطأ أثناء التنفيذ" -ForegroundColor Red
        Write-Host "Status Code: $($response.StatusCode)" -ForegroundColor Red
    }
    
} catch {
    Write-Host "✗ خطأ في الاتصال: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "ملاحظة: تأكد من أن الخادم المحلي يعمل (XAMPP/WAMP)" -ForegroundColor Yellow
    Write-Host "أو قم بتشغيل ملف SQL يدوياً من phpMyAdmin" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "اضغط Enter للخروج..."
Read-Host

