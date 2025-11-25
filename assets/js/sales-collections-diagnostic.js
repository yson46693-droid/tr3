/**
 * ملف تشخيصي لمعرفة سبب مشكلة عدم استجابة الأزرار في صفحة المبيعات والتحصيلات
 * 
 * طريقة الاستخدام:
 * 1. أضف هذا السطر في dashboard/sales.php قبل </body>:
 *    <script src="<?php echo ASSETS_URL; ?>js/sales-collections-diagnostic.js"></script>
 * 2. افتح صفحة المبيعات والتحصيلات
 * 3. افتح Console (F12) وستجد تقرير مفصل عن المشكلة
 */

(function() {
    'use strict';
    
    console.log('%c🔍 بدء التشخيص - صفحة المبيعات والتحصيلات', 'color: #0d6efd; font-size: 16px; font-weight: bold;');
    
    const diagnosticReport = {
        timestamp: new Date().toLocaleString('ar-EG'),
        issues: [],
        warnings: [],
        info: [],
        recommendations: []
    };
    
    // انتظار تحميل الصفحة بالكامل
    function runDiagnostic() {
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        
        // 1. فحص Bootstrap
        checkBootstrap();
        
        // 2. فحص الأزرار والتبويبات
        checkButtonsAndTabs();
        
        // 3. فحص pageLoader
        checkPageLoader();
        
        // 4. فحص Event Listeners
        checkEventListeners();
        
        // 5. فحص CSS
        checkCSS();
        
        // 6. فحص الأخطاء في Console
        checkConsoleErrors();
        
        // 7. فحص التداخل في Event Listeners
        checkEventConflicts();
        
        // طباعة التقرير النهائي
        printReport();
    }
    
    function checkBootstrap() {
        console.log('%c1️⃣ فحص Bootstrap...', 'color: #0d6efd; font-weight: bold;');
        
        if (typeof bootstrap === 'undefined') {
            diagnosticReport.issues.push({
                severity: 'CRITICAL',
                message: 'Bootstrap غير محمّل!',
                fix: 'تأكد من تحميل Bootstrap قبل استخدام التبويبات'
            });
            console.error('❌ Bootstrap غير محمّل');
        } else {
            diagnosticReport.info.push('✅ Bootstrap محمّل بنجاح');
            console.log('✅ Bootstrap محمّل');
            
            if (typeof bootstrap.Tab === 'undefined') {
                diagnosticReport.issues.push({
                    severity: 'CRITICAL',
                    message: 'Bootstrap.Tab غير متاح',
                    fix: 'تأكد من تحميل Bootstrap 5 بشكل كامل'
                });
                console.error('❌ Bootstrap.Tab غير متاح');
            } else {
                console.log('✅ Bootstrap.Tab متاح');
            }
        }
    }
    
    function checkButtonsAndTabs() {
        console.log('%c2️⃣ فحص الأزرار والتبويبات...', 'color: #0d6efd; font-weight: bold;');
        
        // فحص التبويبات
        const tabs = {
            sales: document.getElementById('sales-tab'),
            collections: document.getElementById('collections-tab'),
            returns: document.getElementById('returns-tab')
        };
        
        Object.keys(tabs).forEach(tabName => {
            const tab = tabs[tabName];
            if (!tab) {
                diagnosticReport.issues.push({
                    severity: 'HIGH',
                    message: `تبويب ${tabName} غير موجود`,
                    fix: 'تأكد من وجود التبويب في HTML'
                });
                console.error(`❌ تبويب ${tabName} غير موجود`);
            } else {
                console.log(`✅ تبويب ${tabName} موجود`);
                
                // فحص attributes
                if (!tab.hasAttribute('data-bs-toggle')) {
                    diagnosticReport.issues.push({
                        severity: 'HIGH',
                        message: `تبويب ${tabName} لا يحتوي على data-bs-toggle="tab"`,
                        fix: 'أضف data-bs-toggle="tab" للتبويب'
                    });
                    console.error(`❌ تبويب ${tabName} لا يحتوي على data-bs-toggle`);
                }
                
                if (!tab.hasAttribute('data-bs-target')) {
                    diagnosticReport.issues.push({
                        severity: 'HIGH',
                        message: `تبويب ${tabName} لا يحتوي على data-bs-target`,
                        fix: 'أضف data-bs-target="#section-id" للتبويب'
                    });
                    console.error(`❌ تبويب ${tabName} لا يحتوي على data-bs-target`);
                }
                
                // فحص pointer-events
                const computedStyle = window.getComputedStyle(tab);
                if (computedStyle.pointerEvents === 'none') {
                    diagnosticReport.issues.push({
                        severity: 'CRITICAL',
                        message: `تبويب ${tabName} لديه pointer-events: none`,
                        fix: 'أزل pointer-events: none من CSS'
                    });
                    console.error(`❌ تبويب ${tabName} لديه pointer-events: none`);
                }
            }
        });
        
        // فحص الأزرار داخل الأقسام
        const buttons = {
            salesReport: document.getElementById('generateSalesReportBtn'),
            collectionsReport: document.getElementById('generateCollectionsReportBtn'),
            customerSalesReport: document.getElementById('generateCustomerSalesReportBtn'),
            customerCollectionsReport: document.getElementById('generateCustomerCollectionsReportBtn')
        };
        
        Object.keys(buttons).forEach(btnName => {
            const btn = buttons[btnName];
            if (!btn) {
                diagnosticReport.warnings.push({
                    message: `زر ${btnName} غير موجود`,
                    fix: 'قد يكون الزر في تبويب غير نشط'
                });
                console.warn(`⚠️ زر ${btnName} غير موجود`);
            } else {
                console.log(`✅ زر ${btnName} موجود`);
                
                // فحص pointer-events
                const computedStyle = window.getComputedStyle(btn);
                if (computedStyle.pointerEvents === 'none') {
                    diagnosticReport.issues.push({
                        severity: 'CRITICAL',
                        message: `زر ${btnName} لديه pointer-events: none`,
                        fix: 'أزل pointer-events: none من CSS'
                    });
                    console.error(`❌ زر ${btnName} لديه pointer-events: none`);
                }
                
                // فحص disabled
                if (btn.disabled) {
                    diagnosticReport.warnings.push({
                        message: `زر ${btnName} معطّل`,
                        fix: 'الزر معطّل برمجياً'
                    });
                    console.warn(`⚠️ زر ${btnName} معطّل`);
                }
            }
        });
        
        // فحص أزرار التبويبات
        const tabButtons = document.querySelectorAll('#salesCollectionsTabs button');
        console.log(`📊 عدد أزرار التبويبات: ${tabButtons.length}`);
        
        tabButtons.forEach((btn, index) => {
            const rect = btn.getBoundingClientRect();
            const isVisible = rect.width > 0 && rect.height > 0;
            const isInViewport = rect.top >= 0 && rect.left >= 0 && 
                                rect.bottom <= window.innerHeight && 
                                rect.right <= window.innerWidth;
            
            if (!isVisible) {
                diagnosticReport.issues.push({
                    severity: 'HIGH',
                    message: `زر التبويب ${index + 1} غير مرئي (width: ${rect.width}, height: ${rect.height})`,
                    fix: 'تحقق من CSS'
                });
                console.error(`❌ زر التبويب ${index + 1} غير مرئي`);
            }
        });
    }
    
    function checkPageLoader() {
        console.log('%c3️⃣ فحص pageLoader...', 'color: #0d6efd; font-weight: bold;');
        
        const pageLoader = document.getElementById('pageLoader');
        if (!pageLoader) {
            diagnosticReport.info.push('pageLoader غير موجود');
            console.log('ℹ️ pageLoader غير موجود');
            return;
        }
        
        const computedStyle = window.getComputedStyle(pageLoader);
        const isHidden = pageLoader.classList.contains('hidden') || 
                        computedStyle.display === 'none' ||
                        computedStyle.visibility === 'hidden';
        const zIndex = parseInt(computedStyle.zIndex) || 0;
        const pointerEvents = computedStyle.pointerEvents;
        
        console.log(`📊 pageLoader - hidden: ${isHidden}, z-index: ${zIndex}, pointer-events: ${pointerEvents}`);
        
        if (!isHidden && zIndex > 100) {
            diagnosticReport.issues.push({
                severity: 'CRITICAL',
                message: `pageLoader مرئي ويغطي الصفحة (z-index: ${zIndex})`,
                fix: 'أضف class="hidden" لـ pageLoader أو أزل z-index العالي'
            });
            console.error(`❌ pageLoader مرئي ويغطي الصفحة (z-index: ${zIndex})`);
        }
        
        if (!isHidden && pointerEvents !== 'none') {
            diagnosticReport.issues.push({
                severity: 'CRITICAL',
                message: 'pageLoader لديه pointer-events ويمكنه منع النقرات',
                fix: 'أضف pointer-events: none لـ pageLoader عندما يكون مخفياً'
            });
            console.error('❌ pageLoader لديه pointer-events ويمكنه منع النقرات');
        }
        
        // فحص إذا كان pageLoader يغطي الأزرار
        if (!isHidden) {
            const tabButtons = document.querySelectorAll('#salesCollectionsTabs button');
            tabButtons.forEach((btn, index) => {
                const btnRect = btn.getBoundingClientRect();
                const loaderRect = pageLoader.getBoundingClientRect();
                
                const isOverlapping = !(btnRect.right < loaderRect.left || 
                                      btnRect.left > loaderRect.right || 
                                      btnRect.bottom < loaderRect.top || 
                                      btnRect.top > loaderRect.bottom);
                
                if (isOverlapping && zIndex > 100) {
                    diagnosticReport.issues.push({
                        severity: 'CRITICAL',
                        message: `pageLoader يغطي زر التبويب ${index + 1}`,
                        fix: 'أخفِ pageLoader أو قلل z-index'
                    });
                    console.error(`❌ pageLoader يغطي زر التبويب ${index + 1}`);
                }
            });
        }
    }
    
    function checkEventListeners() {
        console.log('%c4️⃣ فحص Event Listeners...', 'color: #0d6efd; font-weight: bold;');
        
        // فحص عدد event listeners على document
        const clickListeners = getEventListeners ? getEventListeners(document) : null;
        if (clickListeners && clickListeners.click) {
            console.log(`📊 عدد click listeners على document: ${clickListeners.click.length}`);
            
            if (clickListeners.click.length > 5) {
                diagnosticReport.warnings.push({
                    message: `عدد كبير من click listeners على document (${clickListeners.click.length})`,
                    fix: 'قد يكون هناك تداخل في event listeners'
                });
                console.warn(`⚠️ عدد كبير من click listeners: ${clickListeners.click.length}`);
            }
        } else {
            console.log('ℹ️ لا يمكن فحص event listeners (يتطلب Chrome DevTools)');
        }
        
        // فحص event listeners على التبويبات
        const tabButtons = document.querySelectorAll('#salesCollectionsTabs button');
        tabButtons.forEach((btn, index) => {
            const listeners = getEventListeners ? getEventListeners(btn) : null;
            if (listeners) {
                console.log(`📊 زر التبويب ${index + 1} - click listeners: ${listeners.click ? listeners.click.length : 0}`);
            }
        });
    }
    
    function checkCSS() {
        console.log('%c5️⃣ فحص CSS...', 'color: #0d6efd; font-weight: bold;');
        
        const tabButtons = document.querySelectorAll('#salesCollectionsTabs button');
        tabButtons.forEach((btn, index) => {
            const computedStyle = window.getComputedStyle(btn);
            
            const issues = [];
            if (computedStyle.pointerEvents === 'none') issues.push('pointer-events: none');
            if (computedStyle.opacity === '0') issues.push('opacity: 0');
            if (computedStyle.visibility === 'hidden') issues.push('visibility: hidden');
            if (parseFloat(computedStyle.zIndex) < 0) issues.push(`z-index: ${computedStyle.zIndex}`);
            
            if (issues.length > 0) {
                diagnosticReport.issues.push({
                    severity: 'HIGH',
                    message: `زر التبويب ${index + 1} لديه مشاكل CSS: ${issues.join(', ')}`,
                    fix: 'أزل هذه القيم من CSS'
                });
                console.error(`❌ زر التبويب ${index + 1} - ${issues.join(', ')}`);
            }
        });
    }
    
    function checkConsoleErrors() {
        console.log('%c6️⃣ فحص الأخطاء في Console...', 'color: #0d6efd; font-weight: bold;');
        
        // حفظ الأخطاء الحالية
        const originalError = console.error;
        const errors = [];
        
        console.error = function(...args) {
            errors.push(args.join(' '));
            originalError.apply(console, args);
        };
        
        setTimeout(() => {
            if (errors.length > 0) {
                diagnosticReport.warnings.push({
                    message: `تم اكتشاف ${errors.length} خطأ في Console`,
                    fix: 'تحقق من Console للأخطاء'
                });
                console.warn(`⚠️ تم اكتشاف ${errors.length} خطأ`);
            }
        }, 1000);
    }
    
    function checkEventConflicts() {
        console.log('%c7️⃣ فحص التداخل في Event Listeners...', 'color: #0d6efd; font-weight: bold;');
        
        // محاولة إضافة test listener
        let testEventFired = false;
        const testButton = document.getElementById('sales-tab');
        
        if (testButton) {
            const testHandler = function(e) {
                testEventFired = true;
                console.log('✅ Test event fired successfully');
            };
            
            testButton.addEventListener('click', testHandler, { once: true });
            
            setTimeout(() => {
                if (!testEventFired) {
                    diagnosticReport.issues.push({
                        severity: 'HIGH',
                        message: 'Event listeners قد لا تعمل بشكل صحيح',
                        fix: 'تحقق من event propagation و stopPropagation'
                    });
                    console.warn('⚠️ Test event لم يتم تشغيله');
                }
            }, 2000);
        }
    }
    
    function printReport() {
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        console.log('%c📋 التقرير النهائي', 'color: #0d6efd; font-size: 16px; font-weight: bold;');
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        
        if (diagnosticReport.issues.length === 0 && diagnosticReport.warnings.length === 0) {
            console.log('%c✅ لا توجد مشاكل!', 'color: #28a745; font-size: 14px; font-weight: bold;');
            console.log('%c💡 إذا كانت المشكلة لا تزال موجودة، قد تكون المشكلة في:', 'color: #ffc107;');
            console.log('   - Network issues (Bootstrap لم يتم تحميله)');
            console.log('   - JavaScript errors في ملفات أخرى');
            console.log('   - Browser extensions تتداخل مع الصفحة');
        } else {
            // طباعة المشاكل الحرجة
            if (diagnosticReport.issues.length > 0) {
                console.log('%c❌ المشاكل الحرجة:', 'color: #dc3545; font-size: 14px; font-weight: bold;');
                diagnosticReport.issues.forEach((issue, index) => {
                    console.log(`%c${index + 1}. ${issue.message}`, 'color: #dc3545;');
                    console.log(`   🔧 الحل: ${issue.fix}`);
                });
            }
            
            // طباعة التحذيرات
            if (diagnosticReport.warnings.length > 0) {
                console.log('%c⚠️ التحذيرات:', 'color: #ffc107; font-size: 14px; font-weight: bold;');
                diagnosticReport.warnings.forEach((warning, index) => {
                    console.log(`%c${index + 1}. ${warning.message}`, 'color: #ffc107;');
                    if (warning.fix) {
                        console.log(`   💡 ${warning.fix}`);
                    }
                });
            }
        }
        
        // طباعة المعلومات
        if (diagnosticReport.info.length > 0) {
            console.log('%cℹ️ المعلومات:', 'color: #17a2b8; font-size: 14px; font-weight: bold;');
            diagnosticReport.info.forEach((info, index) => {
                console.log(`${index + 1}. ${info}`);
            });
        }
        
        // التوصيات
        if (diagnosticReport.issues.length > 0 || diagnosticReport.warnings.length > 0) {
            console.log('%c💡 التوصيات:', 'color: #0d6efd; font-size: 14px; font-weight: bold;');
            console.log('1. تأكد من تحميل Bootstrap قبل أي JavaScript آخر');
            console.log('2. تأكد من أن pageLoader مخفي بعد تحميل الصفحة');
            console.log('3. تحقق من عدم وجود CSS يمنع النقرات (pointer-events: none)');
            console.log('4. تحقق من عدم وجود z-index عالي يغطي الأزرار');
            console.log('5. افتح Network tab وتحقق من تحميل جميع الملفات');
        }
        
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        
        // حفظ التقرير في window للوصول إليه لاحقاً
        window.salesCollectionsDiagnostic = diagnosticReport;
    }
    
    // تشغيل التشخيص بعد تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(runDiagnostic, 1000);
        });
    } else {
        setTimeout(runDiagnostic, 1000);
    }
    
    // إضافة زر في الصفحة لإعادة التشغيل
    window.rerunDiagnostic = function() {
        console.clear();
        runDiagnostic();
    };
    
    console.log('%c💡 يمكنك إعادة تشغيل التشخيص بكتابة: rerunDiagnostic()', 'color: #0d6efd; font-style: italic;');
})();