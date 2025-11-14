/**
 * كود تشخيصي لفحص مشكلة إرسال صور الحضور والانصراف إلى تليجرام
 * 
 * كيفية الاستخدام:
 * 1. افتح Developer Tools (F12)
 * 2. اذهب إلى Console
 * 3. انسخ والصق هذا الكود بالكامل
 * 4. اضغط Enter
 * 5. اتبع التعليمات
 */

(function() {
    console.log('%c=== بدء التشخيص ===', 'color: blue; font-size: 16px; font-weight: bold;');
    
    // الحصول على API path
    function getAttendanceApiPath() {
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php') && p !== 'dashboard' && p !== 'modules');
        let basePath = '/';
        if (pathParts.length > 0) {
            basePath = '/' + pathParts[0] + '/';
        }
        return basePath + 'api/attendance.php';
    }
    
    const apiPath = getAttendanceApiPath();
    console.log('📍 API Path:', apiPath);
    
    // اختبار 1: التحقق من إعدادات تليجرام
    console.log('\n%c[اختبار 1] التحقق من إعدادات تليجرام', 'color: green; font-weight: bold;');
    
    async function testTelegramConfig() {
        try {
            const response = await fetch(apiPath + '?action=get_statistics', {
                credentials: 'include'
            });
            
            if (response.ok) {
                console.log('✅ الاتصال بـ API يعمل بشكل صحيح');
            } else {
                console.error('❌ فشل الاتصال بـ API:', response.status);
            }
        } catch (error) {
            console.error('❌ خطأ في الاتصال:', error);
        }
    }
    
    // اختبار 2: محاكاة إرسال صورة
    console.log('\n%c[اختبار 2] محاكاة إرسال صورة', 'color: green; font-weight: bold;');
    
    async function testPhotoSend() {
        // إنشاء صورة اختبارية صغيرة
        const canvas = document.createElement('canvas');
        canvas.width = 100;
        canvas.height = 100;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#4CAF50';
        ctx.fillRect(0, 0, 100, 100);
        ctx.fillStyle = '#FFFFFF';
        ctx.font = '20px Arial';
        ctx.fillText('TEST', 25, 55);
        
        const testPhoto = canvas.toDataURL('image/jpeg', 0.8);
        console.log('📸 تم إنشاء صورة اختبارية');
        console.log('📏 حجم الصورة:', testPhoto.length, 'حرف');
        console.log('🔍 بداية الصورة:', testPhoto.substring(0, 50) + '...');
        
        // محاولة إرسال الصورة
        console.log('\n🔄 محاولة إرسال الصورة...');
        
        try {
            const payload = {
                action: 'check_in',
                photo: testPhoto
            };
            
            console.log('📤 البيانات المرسلة:', {
                action: payload.action,
                photoLength: payload.photo.length,
                photoPrefix: payload.photo.substring(0, 50)
            });
            
            const response = await fetch(apiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8'
                },
                credentials: 'include',
                body: JSON.stringify(payload)
            });
            
            console.log('📥 حالة الاستجابة:', response.status, response.statusText);
            console.log('📋 Headers:', Object.fromEntries(response.headers.entries()));
            
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                console.log('📦 البيانات المستلمة:', data);
                
                if (data.success) {
                    console.log('✅ تم إرسال الصورة بنجاح إلى الخادم');
                    console.log('📝 رسالة:', data.message);
                    
                    if (data.photo_path) {
                        console.log('💾 مسار الصورة المحفوظة:', data.photo_path);
                    }
                } else {
                    console.error('❌ فشل إرسال الصورة:', data.message);
                }
            } else {
                const text = await response.text();
                console.error('❌ استجابة غير متوقعة:', text.substring(0, 500));
            }
            
        } catch (error) {
            console.error('❌ خطأ في الإرسال:', error);
            console.error('📚 تفاصيل الخطأ:', {
                message: error.message,
                stack: error.stack
            });
        }
    }
    
    // اختبار 3: فحص سجلات الخادم (إذا كان متاحاً)
    console.log('\n%c[اختبار 3] فحص سجلات الخادم', 'color: green; font-weight: bold;');
    
    async function checkServerLogs() {
        console.log('ℹ️ للتحقق من سجلات الخادم، راجع ملف error_log في الخادم');
        console.log('ℹ️ ابحث عن رسائل تحتوي على:');
        console.log('   - "Check-in: Photo received"');
        console.log('   - "Check-in: Sending photo with data to Telegram"');
        console.log('   - "Attendance check-in sent to Telegram successfully"');
        console.log('   - "Failed to send attendance check-in to Telegram"');
    }
    
    // اختبار 4: فحص إعدادات تليجرام في الكود
    console.log('\n%c[اختبار 4] فحص إعدادات تليجرام', 'color: green; font-weight: bold;');
    
    async function checkTelegramSettings() {
        console.log('ℹ️ للتحقق من إعدادات تليجرام:');
        console.log('   1. افتح ملف includes/simple_telegram.php');
        console.log('   2. تأكد من وجود TELEGRAM_BOT_TOKEN');
        console.log('   3. تأكد من وجود TELEGRAM_CHAT_ID');
        console.log('   4. تأكد من أن isTelegramConfigured() ترجع true');
    }
    
    // اختبار 5: فحص دالة sendTelegramPhoto
    console.log('\n%c[اختبار 5] معلومات إضافية', 'color: green; font-weight: bold;');
    
    function showAdditionalInfo() {
        console.log('📋 معلومات المتصفح:');
        console.log('   - User Agent:', navigator.userAgent);
        console.log('   - URL الحالي:', window.location.href);
        console.log('   - Cookies:', document.cookie ? 'موجودة' : 'غير موجودة');
        
        console.log('\n📋 نصائح للتشخيص:');
        console.log('   1. تأكد من أن الصورة يتم التقاطها بشكل صحيح');
        console.log('   2. تأكد من أن الصورة يتم إرسالها في payload');
        console.log('   3. راجع سجلات الخادم (error_log)');
        console.log('   4. تأكد من إعدادات تليجرام في config.php');
        console.log('   5. تأكد من أن البوت لديه صلاحيات إرسال الصور');
    }
    
    // تشغيل جميع الاختبارات
    async function runAllTests() {
        await testTelegramConfig();
        await testPhotoSend();
        checkServerLogs();
        checkTelegramSettings();
        showAdditionalInfo();
        
        console.log('\n%c=== انتهى التشخيص ===', 'color: blue; font-size: 16px; font-weight: bold;');
        console.log('\n💡 إذا استمرت المشكلة، راجع:');
        console.log('   1. سجلات الخادم (error_log)');
        console.log('   2. إعدادات تليجرام في includes/simple_telegram.php');
        console.log('   3. إعدادات تليجرام في includes/config.php');
        console.log('   4. تأكد من أن البوت نشط وله صلاحيات إرسال الصور');
    }
    
    // بدء التشخيص
    runAllTests();
    
    // إرجاع دالة للتشخيص اليدوي
    window.debugAttendance = {
        testPhotoSend: testPhotoSend,
        testTelegramConfig: testTelegramConfig,
        runAllTests: runAllTests,
        getApiPath: () => apiPath
    };
    
    console.log('\n💡 يمكنك استخدام window.debugAttendance للاختبارات اليدوية');
    console.log('   مثال: window.debugAttendance.testPhotoSend()');
})();

