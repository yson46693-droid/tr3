/**
 * JavaScript لتبديل اللغة
 */

function switchLanguage(lang) {
    // الحصول على المسار الصحيح لـ API
    let apiPath = 'api/language.php';
    // إذا كان التطبيق في subdirectory، نحتاج لتعديل المسار
    const pathParts = window.location.pathname.split('/').filter(p => p);
    if (pathParts.length > 1) {
        // إزالة اسم الملف (آخر عنصر)
        pathParts.pop();
        if (pathParts.length > 0) {
            apiPath = '/' + pathParts.join('/') + '/api/language.php';
        } else {
            apiPath = '/api/language.php';
        }
    } else if (window.location.pathname !== '/') {
        // إذا كان في مجلد فرعي
        const basePath = window.location.pathname.split('/').slice(0, -1).join('/');
        if (basePath) {
            apiPath = basePath + '/api/language.php';
        }
    }
    
    // حفظ اللغة في الجلسة
    fetch(apiPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            language: lang
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // إعادة تحميل الصفحة
            window.location.reload();
        } else {
            console.error('Language switch failed:', data.error);
            alert('فشل تبديل اللغة: ' + (data.error || 'خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error switching language:', error);
        alert('حدث خطأ أثناء تبديل اللغة. يرجى المحاولة مرة أخرى.');
    });
}

// إضافة مستمعي الأحداث لتبديل اللغة
document.addEventListener('DOMContentLoaded', function() {
    const languageSwitches = document.querySelectorAll('.language-switch');
    
    languageSwitches.forEach(switchBtn => {
        switchBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const lang = this.getAttribute('data-lang');
            if (lang) {
                switchLanguage(lang);
            } else {
                console.error('Language attribute not found');
            }
        });
    });
});
