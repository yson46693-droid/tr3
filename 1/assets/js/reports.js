/**
 * JavaScript للتقارير
 */

function openReportInModal(url, opener) {
    if (!url) {
        alert('تعذّر فتح التقرير. لم يتم توفير رابط صالح.');
        return;
    }
    if (typeof window.openInAppModal === 'function') {
        window.openInAppModal(url, { opener: opener || (document.activeElement instanceof Element ? document.activeElement : null) });
    } else {
        window.open(url, '_blank', 'noopener');
    }
}

function openReportHtmlInModal(html, opener) {
    if (typeof window.openHtmlInAppModal === 'function') {
        window.openHtmlInAppModal(html, { opener: opener || (document.activeElement instanceof Element ? document.activeElement : null) });
    } else {
        const printWindow = window.open('', '_blank');
        if (printWindow && printWindow.document) {
            printWindow.document.open();
            printWindow.document.write(html);
            printWindow.document.close();
        } else {
            alert('يرجى السماح بالنوافذ المنبثقة لعرض التقرير.');
        }
    }
}

// دالة مساعدة للحصول على المسار الصحيح للـ API
function getRelativeUrl(path) {
    if (typeof window !== 'undefined' && window.location) {
        // إذا كان path يبدأ بـ /، استخدمه مباشرة
        if (path.startsWith('/')) {
            return path;
        }
        
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p);
        
        // إزالة اسم الملف من المسار (إذا كان يحتوي على نقطة)
        if (pathParts.length > 0) {
            const lastPart = pathParts[pathParts.length - 1];
            if (lastPart.includes('.')) {
                pathParts.pop();
            }
        }
        
        // إزالة 'dashboard' وكل ما بعده من المسار
        const dashboardIndex = pathParts.indexOf('dashboard');
        if (dashboardIndex !== -1) {
            pathParts.splice(dashboardIndex);
        }
        
        // إزالة 'modules' وكل ما بعده من المسار
        const modulesIndex = pathParts.indexOf('modules');
        if (modulesIndex !== -1) {
            pathParts.splice(modulesIndex);
        }
        
        // بناء المسار الأساسي ديناميكياً
        let basePath = '/';
        if (pathParts.length > 0) {
            const first = pathParts[0].toLowerCase();
            if (first !== 'dashboard' && first !== 'modules' && !first.includes('.')) {
                basePath = '/' + pathParts[0] + '/';
            }
        }
        
        return (basePath + path)
            .replace(/\/{2,}/g, '/')
            .replace(/\/dashboard\/api\//g, '/api/')
            .replace(/\/modules\/api\//g, '/api/');
    }
    return path;
}

/**
 * إنشاء تقرير PDF
 */
async function generatePDFReport(type, filters = {}, evt) {
    // تعريف المتغيرات خارج try block لتكون متاحة في catch
    let btn = null;
    let originalHTML = '';
    
    try {
        if (!type) {
            throw new Error('نوع التقرير مطلوب');
        }
        
        // الحصول على event من parameter أو من window
        const e = evt || window.event || (typeof event !== 'undefined' ? event : null);
        if (e && e.target) {
            btn = e.target.closest('button');
            originalHTML = btn ? btn.innerHTML : '';
        }
        
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإنشاء...';
        }
        
        // الحصول على المسار الصحيح للـ API
        let apiPath = getRelativeUrl('api/generate_report.php');
        
        // التأكد من أن المسار صحيح (لا يحتوي على dashboard أو modules)
        apiPath = apiPath.replace(/\/dashboard\/api\//g, '/api/').replace(/\/modules\/api\//g, '/api/');
        
        console.log('API Path:', apiPath); // للتشخيص
        
        let response;
        try {
            response = await fetch(apiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'generate_pdf',
                    type: type,
                    filters: JSON.stringify(filters)
                })
            });
        } catch (fetchError) {
            console.error('Fetch error:', fetchError);
            console.error('API Path used:', apiPath);
            throw new Error('فشل الاتصال بالخادم. يرجى التحقق من الاتصال بالإنترنت أو المسار الصحيح للـ API: ' + apiPath);
        }
        
        // قراءة الاستجابة مرة واحدة فقط
        const contentType = response.headers.get('content-type') || '';
        let responseText = '';
        
        // قراءة النص أولاً
        responseText = await response.text();
        
        if (!response.ok) {
            console.error('Response error:', response.status, responseText.substring(0, 200));
            throw new Error('خطأ في الاستجابة من الخادم: ' + response.status + ' ' + response.statusText);
        }
        
        // التحقق من أن الاستجابة هي JSON
        let data;
        try {
            if (contentType.includes('application/json')) {
                data = JSON.parse(responseText);
            } else {
                // إذا لم تكن JSON، قد تكون صفحة خطأ PHP
                console.error('Response is not JSON. Content-Type:', contentType);
                console.error('Response text (first 500 chars):', responseText.substring(0, 500));
                throw new Error('الخادم لم يعد استجابة صحيحة. قد تكون هناك مشكلة في الإعدادات.');
            }
        } catch (jsonError) {
            console.error('JSON parse error:', jsonError);
            console.error('Response text (first 500 chars):', responseText.substring(0, 500));
            throw new Error('خطأ في قراءة الاستجابة من الخادم. يرجى التحقق من إعدادات الخادم.');
        }
        
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        
        if (data.success) {
            window.__lastGeneratedReport = data;
            if (data.file_path) {
                if (data.is_html) {
                    openReportInModal(data.file_path, btn);
                } else {
                    window.location.href = data.file_path;
                }
            } else {
                alert('تم توليد التقرير ولكن لم يتم العثور على الملف');
            }
        } else {
            alert('خطأ في توليد التقرير: ' + (data.error || data.message || 'حدث خطأ غير معروف'));
        }
    } catch (error) {
        console.error('Error generating PDF report:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML || '<i class="bi bi-file-pdf me-2"></i>تصدير PDF';
        }
        alert('حدث خطأ في توليد التقرير: ' + error.message);
    }
}

/**
 * توليد تقرير Excel
 */
async function generateExcelReport(type, filters = {}, evt) {
    try {
        if (!type) {
            throw new Error('نوع التقرير مطلوب');
        }
        
        let btn = null;
        let originalHTML = '';
        
        // محاولة الحصول على الزر من event parameter أو window.event
        const e = evt || window.event || (typeof event !== 'undefined' ? event : null);
        if (e && e.target) {
            btn = e.target.closest('button');
            originalHTML = btn ? btn.innerHTML : '';
        }
        
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التوليد...';
        }
        
        // الحصول على المسار الصحيح للـ API
        let apiPath = getRelativeUrl('api/generate_report.php');
        
        // التأكد من أن المسار صحيح (لا يحتوي على dashboard أو modules)
        apiPath = apiPath.replace(/\/dashboard\/api\//g, '/api/').replace(/\/modules\/api\//g, '/api/');
        
        console.log('API Path:', apiPath); // للتشخيص
        
        let response;
        try {
            response = await fetch(apiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'generate_excel',
                    type: type,
                    filters: JSON.stringify(filters)
                })
            });
        } catch (fetchError) {
            console.error('Fetch error:', fetchError);
            console.error('API Path used:', apiPath);
            throw new Error('فشل الاتصال بالخادم. يرجى التحقق من الاتصال بالإنترنت أو المسار الصحيح للـ API: ' + apiPath);
        }
        
        // قراءة الاستجابة مرة واحدة فقط
        const contentType = response.headers.get('content-type') || '';
        let responseText = '';
        
        // قراءة النص أولاً
        responseText = await response.text();
        
        if (!response.ok) {
            console.error('Response error:', response.status, responseText.substring(0, 200));
            throw new Error('خطأ في الاستجابة من الخادم: ' + response.status + ' ' + response.statusText);
        }
        
        // التحقق من أن الاستجابة هي JSON
        let data;
        try {
            if (contentType.includes('application/json')) {
                data = JSON.parse(responseText);
            } else {
                // إذا لم تكن JSON، قد تكون صفحة خطأ PHP
                console.error('Response is not JSON. Content-Type:', contentType);
                console.error('Response text (first 500 chars):', responseText.substring(0, 500));
                throw new Error('الخادم لم يعد استجابة صحيحة. قد تكون هناك مشكلة في الإعدادات.');
            }
        } catch (jsonError) {
            console.error('JSON parse error:', jsonError);
            console.error('Response text (first 500 chars):', responseText.substring(0, 500));
            throw new Error('خطأ في قراءة الاستجابة من الخادم. يرجى التحقق من إعدادات الخادم.');
        }
        
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        
        if (data.success) {
            // تحميل التقرير مباشرة (CSV)
            if (data.file_path) {
                window.location.href = data.file_path;
            } else {
                alert('تم توليد التقرير ولكن لم يتم العثور على الملف');
            }
        } else {
            alert('خطأ في توليد التقرير: ' + (data.error || data.message || 'حدث خطأ غير معروف'));
        }
    } catch (error) {
        console.error('Error generating Excel report:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML || '<i class="bi bi-file-excel me-2"></i>تصدير Excel';
        }
        alert('حدث خطأ في توليد التقرير: ' + error.message);
    }
}

/**
 * إرسال تقرير إلى Telegram
 */
async function sendReportToTelegram(reportInfo, type, reportName) {
    try {
        // الحصول على المسار الصحيح للـ API
        const apiPath = getRelativeUrl('api/generate_report.php');
        const payload = typeof reportInfo === 'object' && reportInfo !== null
            ? reportInfo
            : { file_path: reportInfo };
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'send_telegram',
                type: type,
                report_name: reportName,
                payload: JSON.stringify(payload)
            })
        });
        
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error sending report to Telegram:', error);
        return { success: false, error: error.message };
    }
}

/**
 * طباعة التقرير
 */
function printReport(elementId) {
    const printContent = document.getElementById(elementId);
    if (!printContent) {
        alert('العنصر غير موجود');
        return;
    }

    const pageLang = document.documentElement.getAttribute('lang') || 'ar';
    const pageDir = document.documentElement.getAttribute('dir') || 'rtl';
    const html = `
<!DOCTYPE html>
<html lang="${pageLang}" dir="${pageDir}">
<head>
    <meta charset="UTF-8">
    <title>تقرير قابل للطباعة</title>
    <style>
        body { background: #fff; color: #000; padding: 24px; font-family: "Segoe UI", Tahoma, sans-serif; }
        .print-wrapper { max-width: 1024px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="print-wrapper">
        ${printContent.innerHTML}
    </div>
    <script>
        window.addEventListener("load", function () {
            try {
                window.focus();
                window.print();
            } catch (error) {
                console.error("Failed to trigger print dialog:", error);
            }
        });
    <\/script>
</body>
</html>`;

    openReportHtmlInModal(html, document.activeElement instanceof Element ? document.activeElement : null);
}

