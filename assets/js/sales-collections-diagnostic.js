/**
 * ملف تشخيصي لمعرفة سبب مشكلة عدم استجابة الأزرار في صفحة المبيعات والتحصيلات
 * 
 * طريقة الاستخدام:
 * 1. افتح صفحة المبيعات والتحصيلات
 * 2. افتح Console (F12)
 * 3. ابحث عن رسالة "🔍 بدء التشخيص"
 */

// رسالة فورية للتأكد من تحميل الملف
console.log('%c🔍 ملف التشخيص تم تحميله!', 'color: #28a745; font-size: 18px; font-weight: bold; background: #d4edda; padding: 10px; border-radius: 5px;');
console.log('%c⏳ سيبدأ التشخيص خلال ثانية واحدة...', 'color: #0d6efd; font-size: 14px;');

(function() {
    'use strict';
    
    // التأكد من أن الكود يعمل
    try {
        console.log('%c✅ كود التشخيص يعمل', 'color: #28a745;');
    } catch(e) {
        console.error('❌ خطأ في كود التشخيص:', e);
        return;
    }
    
    const diagnosticReport = {
        timestamp: new Date().toLocaleString('ar-EG'),
        issues: [],
        warnings: [],
        info: [],
        recommendations: []
    };
    
    // انتظار تحميل الصفحة بالكامل
    async function runDiagnostic() {
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        console.log('%c🔍 بدء التشخيص - صفحة المبيعات والتحصيلات', 'color: #0d6efd; font-size: 16px; font-weight: bold;');
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        
        try {
            // انتظار تحميل DOM بالكامل
            if (document.readyState !== 'complete') {
                console.log('⏳ انتظار تحميل الصفحة بالكامل...');
                await new Promise(resolve => {
                    if (document.readyState === 'complete') {
                        resolve();
                    } else {
                        window.addEventListener('load', resolve);
                    }
                });
            }
            
            // انتظار إضافي للتأكد من تحميل جميع العناصر
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // 1. فحص Bootstrap
            checkBootstrap();
            
            // 2. فحص الأزرار والتبويبات
            await checkButtonsAndTabs();
            
            // 3. فحص pageLoader
            checkPageLoader();
            
            // 4. فحص Event Listeners
            checkEventListeners();
            
            // 5. فحص CSS
            checkCSS();
            
            // 6. فحص التداخل في Event Listeners
            checkEventConflicts();
            
            // 7. فحص بسيط - محاولة النقر على زر
            testButtonClick();
            
            // طباعة التقرير النهائي
            printReport();
        } catch(error) {
            console.error('❌ خطأ أثناء التشخيص:', error);
            console.log('%c💡 حاول إعادة تحميل الصفحة', 'color: #ffc107;');
        }
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
    
    async function checkButtonsAndTabs() {
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
                
                // فحص visibility
                if (computedStyle.visibility === 'hidden') {
                    diagnosticReport.issues.push({
                        severity: 'HIGH',
                        message: `تبويب ${tabName} مخفي (visibility: hidden)`,
                        fix: 'أزل visibility: hidden من CSS'
                    });
                    console.error(`❌ تبويب ${tabName} مخفي`);
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
        
        // فحص أزرار التبويبات - مع محاولات متعددة
        let tabButtons = document.querySelectorAll('#salesCollectionsTabs button');
        let attempts = 0;
        const maxAttempts = 10;
        
        while (tabButtons.length === 0 && attempts < maxAttempts) {
            attempts++;
            console.log(`⏳ محاولة ${attempts}/${maxAttempts} - البحث عن التبويبات...`);
            await new Promise(resolve => setTimeout(resolve, 300));
            tabButtons = document.querySelectorAll('#salesCollectionsTabs button');
        }
        
        console.log(`📊 عدد أزرار التبويبات في #salesCollectionsTabs: ${tabButtons.length}`);
        
        if (tabButtons.length === 0) {
            // محاولة البحث في جميع أنحاء الصفحة
            const allTabButtons = document.querySelectorAll('button[data-bs-toggle="tab"]');
            console.log(`📊 عدد أزرار التبويبات في الصفحة (جميعها): ${allTabButtons.length}`);
            
            // البحث عن العنصر نفسه
            const tabsContainer = document.getElementById('salesCollectionsTabs');
            if (tabsContainer) {
                console.log('✅ #salesCollectionsTabs موجود في DOM');
                const buttonsInContainer = tabsContainer.querySelectorAll('button');
                console.log(`📊 عدد الأزرار داخل #salesCollectionsTabs: ${buttonsInContainer.length}`);
                
                if (buttonsInContainer.length > 0) {
                    console.log('✅ تم العثور على أزرار داخل #salesCollectionsTabs');
                    buttonsInContainer.forEach((btn, idx) => {
                        const computedStyle = window.getComputedStyle(btn);
                        const isVisible = computedStyle.display !== 'none' && computedStyle.visibility !== 'hidden';
                        console.log(`   ${idx + 1}. ${btn.id || 'no-id'} - مرئي: ${isVisible}, pointer-events: ${computedStyle.pointerEvents}`);
                    });
                } else {
                    console.warn('⚠️ #salesCollectionsTabs موجود لكن بدون أزرار');
                    console.log('📋 محتوى العنصر:', tabsContainer.innerHTML.substring(0, 300));
                }
            } else {
                console.error('❌ #salesCollectionsTabs غير موجود في DOM');
                
                // البحث في جميع أنحاء الصفحة
                const allTabsContainers = document.querySelectorAll('[id*="tab"], [class*="tab"]');
                console.log(`📊 عدد العناصر التي تحتوي على "tab": ${allTabsContainers.length}`);
            }
            
            if (allTabButtons.length > 0) {
                console.log('✅ تم العثور على أزرار تبويبات في الصفحة');
                console.log('📍 مواقع الأزرار:');
                allTabButtons.forEach((btn, idx) => {
                    const parent = btn.closest('ul, div, section');
                    const computedStyle = window.getComputedStyle(btn);
                    console.log(`   ${idx + 1}. ${btn.id || 'no-id'} - داخل: ${parent ? (parent.id || parent.className || 'unknown') : 'none'}, pointer-events: ${computedStyle.pointerEvents}`);
                });
                diagnosticReport.warnings.push({
                    message: 'التبويبات موجودة لكن قد تكون في مكان آخر',
                    fix: 'تحقق من أن #salesCollectionsTabs موجود في DOM'
                });
            } else {
                diagnosticReport.issues.push({
                    severity: 'CRITICAL',
                    message: 'لم يتم العثور على أي أزرار تبويبات!',
                    fix: 'تحقق من وجود #salesCollectionsTabs في الصفحة'
                });
                console.error('❌ لم يتم العثور على أي أزرار تبويبات!');
            }
        } else {
            console.log('✅ تم العثور على أزرار التبويبات');
            tabButtons.forEach((btn, idx) => {
                const computedStyle = window.getComputedStyle(btn);
                const isVisible = computedStyle.display !== 'none' && computedStyle.visibility !== 'hidden';
                console.log(`   ${idx + 1}. ${btn.id} - ${btn.textContent.trim().substring(0, 30)} - مرئي: ${isVisible}, pointer-events: ${computedStyle.pointerEvents}`);
            });
        }
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
        const opacity = parseFloat(computedStyle.opacity) || 1;
        
        console.log(`📊 pageLoader - hidden: ${isHidden}, z-index: ${zIndex}, pointer-events: ${pointerEvents}, opacity: ${opacity}`);
        
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
        if (typeof getEventListeners !== 'undefined') {
            const clickListeners = getEventListeners(document);
            if (clickListeners && clickListeners.click) {
                console.log(`📊 عدد click listeners على document: ${clickListeners.click.length}`);
                
                if (clickListeners.click.length > 5) {
                    diagnosticReport.warnings.push({
                        message: `عدد كبير من click listeners على document (${clickListeners.click.length})`,
                        fix: 'قد يكون هناك تداخل في event listeners'
                    });
                    console.warn(`⚠️ عدد كبير من click listeners: ${clickListeners.click.length}`);
                }
            }
        } else {
            console.log('ℹ️ لا يمكن فحص event listeners (يتطلب Chrome DevTools)');
            console.log('💡 افتح Chrome DevTools وأعد تحميل الصفحة');
        }
    }
    
    function checkCSS() {
        console.log('%c5️⃣ فحص CSS...', 'color: #0d6efd; font-weight: bold;');
        
        const tabButtons = document.querySelectorAll('#salesCollectionsTabs button');
        if (tabButtons.length === 0) {
            console.warn('⚠️ لم يتم العثور على أزرار التبويبات');
            return;
        }
        
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
            } else {
                console.log(`✅ زر التبويب ${index + 1} - CSS سليم`);
            }
        });
    }
    
    function checkEventConflicts() {
        console.log('%c6️⃣ فحص التداخل في Event Listeners...', 'color: #0d6efd; font-weight: bold;');
        
        // محاولة إضافة test listener
        const testButton = document.getElementById('sales-tab');
        
        if (testButton) {
            let testEventFired = false;
            const testHandler = function(e) {
                testEventFired = true;
                console.log('✅ Test event fired successfully');
            };
            
            testButton.addEventListener('click', testHandler, { once: true });
            
            setTimeout(() => {
                if (!testEventFired) {
                    console.log('ℹ️ Test event لم يتم تشغيله بعد (هذا طبيعي - سيتم تشغيله عند النقر)');
                }
            }, 100);
        } else {
            console.warn('⚠️ لم يتم العثور على زر sales-tab للاختبار');
        }
    }
    
    function testButtonClick() {
        console.log('%c7️⃣ اختبار النقر على زر...', 'color: #0d6efd; font-weight: bold;');
        
        const testButton = document.getElementById('sales-tab');
        if (testButton) {
            console.log('💡 جرب النقر على زر "المبيعات" الآن');
            console.log('💡 إذا لم يحدث شيء، فالمشكلة في event handling');
            
            // محاولة برمجية
            try {
                const clickEvent = new MouseEvent('click', {
                    bubbles: true,
                    cancelable: true,
                    view: window
                });
                
                console.log('✅ تم إنشاء click event بنجاح');
                console.log('💡 يمكنك تجربة: testButton.click() في Console');
                
                window.testButtonClick = function() {
                    testButton.click();
                };
            } catch(e) {
                console.error('❌ خطأ في إنشاء click event:', e);
            }
        }
    }
    
    function printReport() {
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        console.log('%c📋 التقرير النهائي', 'color: #0d6efd; font-size: 16px; font-weight: bold;');
        console.log('%c═══════════════════════════════════════', 'color: #666;');
        
        if (diagnosticReport.issues.length === 0 && diagnosticReport.warnings.length === 0) {
            console.log('%c✅ لا توجد مشاكل واضحة!', 'color: #28a745; font-size: 14px; font-weight: bold;');
            console.log('%c💡 إذا كانت المشكلة لا تزال موجودة، جرب:', 'color: #ffc107;');
            console.log('   1. افتح Network tab وتحقق من تحميل جميع الملفات');
            console.log('   2. تحقق من عدم وجود أخطاء JavaScript حمراء');
            console.log('   3. جرب في متصفح آخر');
            console.log('   4. امسح cache المتصفح (Ctrl+Shift+Delete)');
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
        
        console.log('%c💡 يمكنك الوصول للتقرير بكتابة: salesCollectionsDiagnostic', 'color: #0d6efd; font-style: italic;');
    }
    
    // تشغيل التشخيص بعد تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(runDiagnostic, 1500);
        });
    } else {
        setTimeout(runDiagnostic, 1500);
    }
    
    // إضافة زر في الصفحة لإعادة التشغيل
    window.rerunDiagnostic = function() {
        console.clear();
        console.log('%c🔄 إعادة تشغيل التشخيص...', 'color: #0d6efd; font-size: 16px; font-weight: bold;');
        runDiagnostic();
    };
    
    console.log('%c💡 يمكنك إعادة تشغيل التشخيص بكتابة: rerunDiagnostic()', 'color: #0d6efd; font-style: italic;');
})();