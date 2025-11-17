/**
 * إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
 * يجب إضافة id="successAlert" data-auto-refresh="true" لرسالة النجاح
 * و id="errorAlert" data-auto-refresh="true" لرسالة الخطأ
 */
(function() {
    'use strict';
    
    // انتظار تحميل الصفحة بالكامل
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAutoRefresh);
    } else {
        initAutoRefresh();
    }
    
    function initAutoRefresh() {
        const successAlert = document.getElementById('successAlert');
        const errorAlert = document.getElementById('errorAlert');
        
        // التحقق من وجود رسالة نجاح أو خطأ
        const alertElement = successAlert || errorAlert;
        
        if (alertElement && alertElement.dataset.autoRefresh === 'true') {
            // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
            setTimeout(function() {
                // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
                const currentUrl = new URL(window.location.href);
                // إزالة معاملات success و error من URL
                currentUrl.searchParams.delete('success');
                currentUrl.searchParams.delete('error');
                // إعادة تحميل الصفحة
                window.location.href = currentUrl.toString();
            }, 3000);
        }
    }
})();

