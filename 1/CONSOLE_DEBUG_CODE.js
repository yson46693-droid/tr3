/**
 * ============================================
 * كود تشخيصي لفحص مشكلة إرسال صور الحضور والانصراف إلى تليجرام
 * ============================================
 * 
 * 📋 كيفية الاستخدام:
 * 1. افتح صفحة تسجيل الحضور في المتصفح
 * 2. اضغط F12 لفتح Developer Tools
 * 3. اذهب إلى تبويب Console
 * 4. انسخ هذا الكود بالكامل والصقه في Console
 * 5. اضغط Enter
 * 6. اتبع التعليمات المعروضة
 * 
 * ============================================
 */

(async function() {
    console.log('%c=== 🔍 بدء التشخيص الشامل ===', 'color: #2196F3; font-size: 18px; font-weight: bold; padding: 10px;');
    
    // ============================================
    // 1. الحصول على API Path
    // ============================================
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
    console.log('%c📍 API Path:', 'color: #4CAF50; font-weight: bold;', apiPath);
    
    // ============================================
    // 2. اختبار الاتصال بـ API
    // ============================================
    console.log('\n%c[اختبار 1] 🔌 اختبار الاتصال بـ API', 'color: #FF9800; font-size: 14px; font-weight: bold;');
    
    async function testApiConnection() {
        try {
            const response = await fetch(apiPath + '?action=get_statistics', {
                credentials: 'include'
            });
            
            if (response.ok) {
                const data = await response.json();
                console.log('%c✅ الاتصال بـ API يعمل بشكل صحيح', 'color: #4CAF50;');
                return true;
            } else {
                console.error('%c❌ فشل الاتصال بـ API:', 'color: #f44336;', response.status, response.statusText);
                return false;
            }
        } catch (error) {
            console.error('%c❌ خطأ في الاتصال:', 'color: #f44336;', error);
            return false;
        }
    }
    
    // ============================================
    // 3. اختبار إرسال صورة
    // ============================================
    console.log('\n%c[اختبار 2] 📸 اختبار إرسال صورة', 'color: #FF9800; font-size: 14px; font-weight: bold;');
    
    async function testPhotoSend() {
        console.log('🔄 إنشاء صورة اختبارية...');
        
        // إنشاء صورة اختبارية
        const canvas = document.createElement('canvas');
        canvas.width = 400;
        canvas.height = 300;
        const ctx = canvas.getContext('2d');
        
        // خلفية خضراء
        ctx.fillStyle = '#4CAF50';
        ctx.fillRect(0, 0, 400, 300);
        
        // نص أبيض
        ctx.fillStyle = '#FFFFFF';
        ctx.font = 'bold 30px Arial';
        ctx.fillText('TEST IMAGE', 100, 120);
        ctx.font = '20px Arial';
        ctx.fillText('Attendance Debug', 100, 160);
        ctx.fillText(new Date().toLocaleString('ar-EG'), 50, 200);
        
        const testPhoto = canvas.toDataURL('image/jpeg', 0.8);
        
        console.log('📊 معلومات الصورة:');
        console.log('   - الحجم:', testPhoto.length, 'حرف');
        console.log('   - البداية:', testPhoto.substring(0, 50) + '...');
        console.log('   - النوع: data:image/jpeg;base64');
        
        // إرسال الصورة
        console.log('\n🔄 محاولة إرسال الصورة...');
        
        try {
            const payload = {
                action: 'check_in',
                photo: testPhoto
            };
            
            console.log('📤 البيانات المرسلة:');
            console.log('   - Action:', payload.action);
            console.log('   - Photo Length:', payload.photo.length);
            console.log('   - Photo Prefix:', payload.photo.substring(0, 50) + '...');
            
            const startTime = Date.now();
            const response = await fetch(apiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8'
                },
                credentials: 'include',
                body: JSON.stringify(payload)
            });
            const endTime = Date.now();
            
            console.log('\n📥 معلومات الاستجابة:');
            console.log('   - Status:', response.status, response.statusText);
            console.log('   - Time:', (endTime - startTime) + 'ms');
            console.log('   - Headers:', Object.fromEntries(response.headers.entries()));
            
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                console.log('\n📦 البيانات المستلمة:');
                console.log(JSON.stringify(data, null, 2));
                
                if (data.success) {
                    console.log('%c✅ تم إرسال الصورة بنجاح إلى الخادم', 'color: #4CAF50; font-weight: bold;');
                    console.log('📝 الرسالة:', data.message);
                    
                    if (data.photo_path) {
                        console.log('💾 مسار الصورة المحفوظة:', data.photo_path);
                    }
                    
                    if (data.record_id) {
                        console.log('🆔 رقم السجل:', data.record_id);
                    }
                } else {
                    console.error('%c❌ فشل إرسال الصورة:', 'color: #f44336; font-weight: bold;', data.message);
                    if (data.error) {
                        console.error('   الخطأ:', data.error);
                    }
                }
            } else {
                const text = await response.text();
                console.error('%c❌ استجابة غير متوقعة:', 'color: #f44336;');
                console.error(text.substring(0, 500));
            }
            
        } catch (error) {
            console.error('%c❌ خطأ في الإرسال:', 'color: #f44336; font-weight: bold;', error);
            console.error('📚 تفاصيل الخطأ:');
            console.error('   - Message:', error.message);
            console.error('   - Stack:', error.stack);
        }
    }
    
    // ============================================
    // 4. فحص إعدادات المتصفح
    // ============================================
    console.log('\n%c[اختبار 3] 🌐 فحص إعدادات المتصفح', 'color: #FF9800; font-size: 14px; font-weight: bold;');
    
    function checkBrowserSettings() {
        console.log('📋 معلومات المتصفح:');
        console.log('   - User Agent:', navigator.userAgent);
        console.log('   - URL الحالي:', window.location.href);
        console.log('   - Cookies:', document.cookie ? '✅ موجودة' : '❌ غير موجودة');
        console.log('   - Local Storage:', typeof(Storage) !== 'undefined' ? '✅ مدعوم' : '❌ غير مدعوم');
        
        // فحص الكاميرا
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            console.log('   - الكاميرا: ✅ مدعومة');
        } else {
            console.log('   - الكاميرا: ❌ غير مدعومة');
        }
    }
    
    // ============================================
    // 5. فحص الكود الحالي
    // ============================================
    console.log('\n%c[اختبار 4] 📝 فحص الكود الحالي', 'color: #FF9800; font-size: 14px; font-weight: bold;');
    
    function checkCurrentCode() {
        // التحقق من وجود دالة submitAttendance
        if (typeof submitAttendance === 'function') {
            console.log('✅ دالة submitAttendance موجودة');
        } else {
            console.log('❌ دالة submitAttendance غير موجودة');
        }
        
        // التحقق من وجود capturedPhoto
        if (typeof capturedPhoto !== 'undefined') {
            console.log('✅ متغير capturedPhoto موجود');
            if (capturedPhoto) {
                console.log('   - الحجم:', capturedPhoto.length);
            } else {
                console.log('   - القيمة: فارغة');
            }
        } else {
            console.log('❌ متغير capturedPhoto غير موجود');
        }
    }
    
    // ============================================
    // 6. نصائح للتشخيص
    // ============================================
    console.log('\n%c[معلومات إضافية] 💡 نصائح للتشخيص', 'color: #2196F3; font-size: 14px; font-weight: bold;');
    
    function showTips() {
        console.log('📋 قائمة التحقق:');
        console.log('   1. ✅ تأكد من أن الصورة يتم التقاطها بشكل صحيح');
        console.log('   2. ✅ تأكد من أن الصورة يتم إرسالها في payload');
        console.log('   3. ✅ راجع سجلات الخادم (error_log)');
        console.log('   4. ✅ تأكد من إعدادات تليجرام في includes/config.php');
        console.log('   5. ✅ تأكد من أن البوت لديه صلاحيات إرسال الصور');
        console.log('   6. ✅ تأكد من أن TELEGRAM_BOT_TOKEN و TELEGRAM_CHAT_ID صحيحين');
        console.log('   7. ✅ راجع ملف includes/simple_telegram.php');
        console.log('   8. ✅ راجع ملف includes/attendance.php');
        
        console.log('\n📁 الملفات المهمة:');
        console.log('   - api/attendance.php');
        console.log('   - includes/attendance.php');
        console.log('   - includes/simple_telegram.php');
        console.log('   - includes/config.php');
        console.log('   - assets/js/attendance.js');
    }
    
    // ============================================
    // تشغيل جميع الاختبارات
    // ============================================
    console.log('\n%c🔄 بدء تشغيل الاختبارات...', 'color: #2196F3; font-weight: bold;');
    
    const apiConnected = await testApiConnection();
    if (apiConnected) {
        await testPhotoSend();
    }
    
    checkBrowserSettings();
    checkCurrentCode();
    showTips();
    
    console.log('\n%c=== ✅ انتهى التشخيص ===', 'color: #4CAF50; font-size: 18px; font-weight: bold; padding: 10px;');
    console.log('\n💡 إذا استمرت المشكلة:');
    console.log('   1. راجع سجلات الخادم (error_log)');
    console.log('   2. افتح ملف debug_telegram_attendance.php في المتصفح');
    console.log('   3. تأكد من إعدادات تليجرام');
    console.log('   4. تأكد من أن البوت نشط');
    
    // إرجاع دالة للتشخيص اليدوي
    window.debugAttendance = {
        testPhotoSend: testPhotoSend,
        testApiConnection: testApiConnection,
        getApiPath: () => apiPath,
        runAllTests: async function() {
            await testApiConnection();
            await testPhotoSend();
            checkBrowserSettings();
            checkCurrentCode();
            showTips();
        }
    };
    
    console.log('\n💡 يمكنك استخدام window.debugAttendance للاختبارات اليدوية:');
    console.log('   - window.debugAttendance.testPhotoSend()');
    console.log('   - window.debugAttendance.testApiConnection()');
    console.log('   - window.debugAttendance.runAllTests()');
    console.log('   - window.debugAttendance.getApiPath()');
})();

