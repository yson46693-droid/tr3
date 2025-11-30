<!-- 8837e38c-3598-41db-b268-8fa924e8fce2 cb9e7bd9-d912-486f-a23d-fccf3fdcfef9 -->
# خطة الأمان الشاملة

## 1. حماية بيانات قاعدة البيانات

### إنشاء ملف `.env`

- إنشاء ملف `.env` في المجلد الرئيسي
- نقل جميع البيانات الحساسة (DB credentials, API keys) من `config.php` إلى `.env`
- إضافة متغير `APP_ENV` لتحديد البيئة (development/production)

### تحديث `includes/config.php`

- إضافة كود لقراءة ملف `.env` تلقائياً
- استبدال القيم الثابتة بقراءة من متغيرات البيئة
- رفع `PASSWORD_MIN_LENGTH` من 1 إلى 8
- تعديل إعدادات عرض الأخطاء لتكون حسب البيئة (مخفية في production)
- إضافة Security Headers في PHP (CSP, HSTS, X-Frame-Options, etc.)

## 2. إصلاح CORS في APIs

### تحديث `api/attendance.php`

- استبدال `Access-Control-Allow-Origin: *` بقائمة محددة من النطاقات المسموحة
- إضافة دعم للتطوير المحلي (localhost)

### تحديث `api/notifications.php`

- نفس التعديلات على CORS headers
- تحديد النطاقات المسموحة فقط

## 3. إضافة Rate Limiting

### إنشاء `includes/rate_limiter.php`

- إنشاء جدول `rate_limits` في قاعدة البيانات
- دالة `checkRateLimit()` للتحقق من الحد المسموح
- دالة `cleanupRateLimits()` لتنظيف السجلات القديمة
- دعم Rate Limiting حسب IP أو User ID

### تطبيق Rate Limiting على APIs

- إضافة Rate Limiting في `api/attendance.php` (100 طلب/دقيقة)
- إضافة Rate Limiting في `api/notifications.php` (120 طلب/دقيقة)
- إضافة Response Headers (X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset)

## 4. إصلاح Session Fixation

### تحديث `includes/auth.php`

- التأكد من استدعاء `session_regenerate_id(true)` بعد كل تسجيل دخول ناجح
- إضافة `$_SESSION['login_time']` و `$_SESSION['ip_address']` لتتبع الجلسات
- التحقق من تطابق IP في كل طلب (اختياري)

## 5. تحديث Security Headers في `.htaccess`

### تحديث `.htaccess` الموجود

- إضافة HTTPS redirect إلزامي
- تحديث Security Headers (X-Frame-Options إلى DENY)
- إضافة HSTS header
- إضافة Content Security Policy
- إضافة Permissions Policy
- منع الوصول للملفات الحساسة (.env, .log, .ini, etc.)
- إضافة قواعد WAF بسيطة في .htaccess

## 6. إنشاء Web Application Firewall (WAF)

### إنشاء `includes/waf.php`

- فحص SQL Injection patterns
- فحص XSS patterns
- فحص Path Traversal patterns
- فحص Command Injection patterns
- تسجيل محاولات الهجوم في error log
- حظر الطلبات المشبوهة (HTTP 403)

### دمج WAF في النظام

- إضافة `require_once` لـ `waf.php` في بداية `config.php`

## 7. تنظيف سجلات الأخطاء من المعلومات الحساسة

### إنشاء `includes/safe_error_log.php`

- دالة `safeErrorLog()` لتنظيف المعلومات الحساسة قبل التسجيل
- إزالة passwords, tokens, secrets, keys من الرسائل
- استخدام regex patterns للبحث والاستبدال

## 8. إنشاء `.gitignore`

### إنشاء ملف `.gitignore`

- إضافة `.env` و `.env.*`
- إضافة ملفات السجلات (`*.log`, `logs/`)
- إضافة ملفات النسخ الاحتياطي (`*.bak`, `*.backup`)
- إضافة ملفات IDE (`.idea/`, `.vscode/`)
- إضافة ملفات OS (`.DS_Store`, `Thumbs.db`)

## 9. إضافة Rate Limiting cleanup في المهام اليومية

### تحديث `includes/config.php`

- إضافة استدعاء `cleanupRateLimits()` في المهام اليومية
- تنظيف سجلات Rate Limit الأقدم من 24 ساعة

## الملفات التي سيتم إنشاؤها:

- `.env` (جديد)
- `includes/rate_limiter.php` (جديد)
- `includes/waf.php` (جديد)
- `includes/safe_error_log.php` (جديد)
- `.gitignore` (جديد)

## الملفات التي سيتم تحديثها:

- `includes/config.php`
- `api/attendance.php`
- `api/notifications.php`
- `includes/auth.php`
- `.htaccess`

## ملاحظات مهمة:

- يجب تحديث النطاقات المسموحة في CORS headers بالنطاق الفعلي للموقع
- يجب التأكد من أن ملف `.env` غير موجود في Git
- يجب اختبار جميع التغييرات في بيئة التطوير أولاً

### To-dos

- [ ] إنشاء ملف .env ونقل البيانات الحساسة من config.php
- [ ] تحديث config.php لقراءة من .env وإضافة Security Headers
- [ ] إنشاء includes/rate_limiter.php مع جدول قاعدة البيانات
- [ ] تحديث api/attendance.php لإصلاح CORS وإضافة Rate Limiting
- [ ] تحديث api/notifications.php لإصلاح CORS وإضافة Rate Limiting
- [ ] تحديث includes/auth.php لإصلاح Session Fixation
- [ ] تحديث .htaccess لإضافة Security Headers وHTTPS redirect
- [ ] إنشاء includes/waf.php ودمجه في config.php
- [ ] إنشاء includes/safe_error_log.php لحماية المعلومات الحساسة
- [ ] إنشاء .gitignore لحماية الملفات الحساسة