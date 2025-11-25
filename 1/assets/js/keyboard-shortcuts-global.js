/**
 * اختصارات لوحة المفاتيح العامة لجميع الصفحات
 */
(function() {
    'use strict';

    // الحصول على اسم الملف من المسار
    function basename(path) {
        return path.split('/').pop() || '';
    }

    // حساب رابط الصفحة الأساسي
    const pathname = window.location.pathname;
    const pathParts = pathname.split('/').filter(function(p) { return p; });
    let baseUrl = window.location.origin;
    if (pathParts.length > 0) {
        const dashboardIndex = pathParts.indexOf('dashboard');
        if (dashboardIndex >= 0) {
            baseUrl += '/' + pathParts.slice(0, dashboardIndex + 1).join('/') + '/';
        } else {
            baseUrl += '/' + pathParts.slice(0, -1).join('/') + '/dashboard/';
        }
    } else {
        baseUrl += '/dashboard/';
    }

    const currentPage = basename(window.location.pathname) || 'index.php';
    const currentPageParam = new URLSearchParams(window.location.search).get('page') || '';
    const currentRole = (window.currentUser && window.currentUser.role) ? window.currentUser.role.toLowerCase() : '';

    // تحديد روابط الصفحات بناءً على الدور والصفحة الحالية
    function getPageLinks() {
        const links = {
            // روابط عامة
            'dashboard': '',
            'chat': '',
            'profile': ''
        };

        // روابط صفحة الإنتاج
        if (currentPage === 'production.php') {
            links.dashboard = 'production.php';
            links.chat = 'production.php?page=chat';
            links.production = 'production.php?page=production';
            links.tasks = 'production.php?page=tasks';
            links.inventory = 'production.php?page=inventory';
            links.packaging_warehouse = 'production.php?page=packaging_warehouse';
            links.raw_materials_warehouse = 'production.php?page=raw_materials_warehouse';
            links.attendance = 'attendance.php';
        }

        // روابط صفحة المدير
        if (currentPage === 'manager.php' || currentRole === 'manager') {
            links.dashboard = 'manager.php';
            links.chat = 'manager.php?page=chat';
            links.approvals = 'manager.php?page=approvals';
            links.audit = 'manager.php?page=audit';
            links.reports = 'manager.php?page=reports';
            links.users = 'manager.php?page=users';
            links.backups = 'manager.php?page=backups';
            links.permissions = 'manager.php?page=permissions';
            links.security = 'manager.php?page=security';
            links.warehouse_transfers = 'manager.php?page=warehouse_transfers';
            links.production_tasks = 'manager.php?page=production_tasks';
            links.final_products = 'manager.php?page=final_products';
            links.packaging_warehouse = 'manager.php?page=packaging_warehouse';
            links.raw_materials_warehouse = 'manager.php?page=raw_materials_warehouse';
            links.suppliers = 'manager.php?page=suppliers';
            links.customers = 'manager.php?page=customers';
            links.orders = 'manager.php?page=orders';
            links.pos = 'manager.php?page=pos';
        }

        // روابط صفحة المبيعات
        if (currentPage === 'sales.php' || currentRole === 'sales') {
            links.dashboard = 'sales.php';
            links.chat = 'sales.php?page=chat';
            links.sales = 'sales.php?page=sales';
            links.collections = 'sales.php?page=collections';
            links.customers = 'sales.php?page=customers';
            links.orders = 'sales.php?page=orders';
            links.pos = 'sales.php?page=pos';
            links.reports = 'sales.php?page=reports';
        }

        // روابط صفحة المحاسب
        if (currentPage === 'accountant.php' || currentRole === 'accountant') {
            links.dashboard = 'accountant.php';
            links.chat = 'accountant.php?page=chat';
            links.financial = 'accountant.php?page=financial';
            links.reports = 'accountant.php?page=reports';
            links.invoices = 'accountant.php?page=invoices';
            links.payments = 'accountant.php?page=payments';
            links.budgets = 'accountant.php?page=budgets';
        }

        return links;
    }

    // دالة للتنقل إلى صفحة معينة
    function navigateToPage(pageKey) {
        const pageLinks = getPageLinks();
        const url = pageLinks[pageKey];

        if (!url) {
            return false;
        }

        const fullUrl = baseUrl + url;

        // إذا كنا بالفعل في نفس الصفحة، لا نحتاج للتنقل
        if (window.location.href === fullUrl || 
            (window.location.href.includes(url) && currentPageParam === pageKey)) {
            return false;
        }

        window.location.href = fullUrl;
        return true;
    }

    // دالة للبحث عن حقل بحث وتركيزه
    function focusSearchInput() {
        const searchInputs = document.querySelectorAll(
            'input[type="search"], input[type="text"][placeholder*="بحث"], input[type="text"][placeholder*="search"], ' +
            '.search-input, #search, [data-search], input.search, .form-control[placeholder*="بحث"]'
        );

        for (let input of searchInputs) {
            if (input.offsetParent !== null && !input.disabled && !input.readOnly) {
                input.focus();
                input.select();
                return true;
            }
        }

        return false;
    }

    // دالة لإغلاق جميع الـ modals
    function closeAllModals() {
        const modals = document.querySelectorAll('.modal.show, .modal.in');
        modals.forEach(function(modal) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const bootstrapModal = bootstrap.Modal.getInstance(modal);
                if (bootstrapModal) {
                    bootstrapModal.hide();
                } else {
                    modal.classList.remove('show', 'in');
                    modal.style.display = 'none';
                    document.body.classList.remove('modal-open');
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
            } else {
                modal.classList.remove('show', 'in');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
                const backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) {
                    backdrop.remove();
                }
            }
        });
    }

    // دالة لإظهار رسالة مساعدة باختصارات لوحة المفاتيح
    function showKeyboardShortcutsHelp() {
        const pageLinks = getPageLinks();
        const shortcuts = [
            ['Ctrl/Cmd + /', 'البحث'],
            ['Ctrl/Cmd + ?', 'عرض المساعدة'],
            ['Esc', 'إغلاق النوافذ المنبثقة']
        ];

        // إضافة اختصارات التنقل بناءً على الصفحة الحالية
        if (currentPage === 'production.php') {
            shortcuts.push(['Ctrl/Cmd + 1', 'لوحة التحكم']);
            shortcuts.push(['Ctrl/Cmd + 2', 'الإنتاج']);
            shortcuts.push(['Ctrl/Cmd + 3', 'المهام']);
            shortcuts.push(['Ctrl/Cmd + 4', 'الدردشة']);
            shortcuts.push(['Ctrl/Cmd + 5', 'المخزون']);
        } else if (currentPage === 'manager.php') {
            shortcuts.push(['Ctrl/Cmd + 1', 'لوحة التحكم']);
            shortcuts.push(['Ctrl/Cmd + 2', 'الموافقات']);
            shortcuts.push(['Ctrl/Cmd + 3', 'التقارير']);
            shortcuts.push(['Ctrl/Cmd + 4', 'الدردشة']);
            shortcuts.push(['Ctrl/Cmd + 5', 'المستخدمين']);
        } else if (currentPage === 'sales.php') {
            shortcuts.push(['Ctrl/Cmd + 1', 'لوحة التحكم']);
            shortcuts.push(['Ctrl/Cmd + 2', 'المبيعات']);
            shortcuts.push(['Ctrl/Cmd + 3', 'التحصيلات']);
            shortcuts.push(['Ctrl/Cmd + 4', 'الدردشة']);
            shortcuts.push(['Ctrl/Cmd + 5', 'العملاء']);
        } else if (currentPage === 'accountant.php') {
            shortcuts.push(['Ctrl/Cmd + 1', 'لوحة التحكم']);
            shortcuts.push(['Ctrl/Cmd + 2', 'المالية']);
            shortcuts.push(['Ctrl/Cmd + 3', 'التقارير']);
            shortcuts.push(['Ctrl/Cmd + 4', 'الدردشة']);
            shortcuts.push(['Ctrl/Cmd + 5', 'الفواتير']);
        } else {
            shortcuts.push(['Ctrl/Cmd + 1', 'لوحة التحكم']);
        }

        let helpHTML = '<div class="keyboard-shortcuts-help"><h5>اختصارات لوحة المفاتيح</h5><table class="table table-sm table-hover">';
        shortcuts.forEach(function(shortcut) {
            helpHTML += '<tr><td><kbd class="bg-dark text-white">' + shortcut[0] + '</kbd></td><td>' + shortcut[1] + '</td></tr>';
        });
        helpHTML += '</table></div>';

        // إنشاء modal للمساعدة
        const modalId = 'keyboardShortcutsHelpModal';
        let modal = document.getElementById(modalId);

        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = modalId;
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'keyboardShortcutsHelpModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="keyboardShortcutsHelpModalLabel">
                                <i class="bi bi-keyboard me-2"></i>اختصارات لوحة المفاتيح
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                        </div>
                        <div class="modal-body">
                            ${helpHTML.replace('keyboard-shortcuts-help', '')}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const bootstrapModal = new bootstrap.Modal(modal);
            bootstrapModal.show();
        } else {
            modal.classList.add('show');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
            // إضافة backdrop يدوياً
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            // إغلاق عند النقر على الأزرار
            modal.querySelectorAll('[data-bs-dismiss="modal"], .btn-secondary').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    modal.classList.remove('show');
                    modal.style.display = 'none';
                    document.body.classList.remove('modal-open');
                    backdrop.remove();
                });
            });
        }
    }

    // معالج اختصارات لوحة المفاتيح الرئيسي
    document.addEventListener('keydown', function(event) {
        const isCtrl = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();
        const isInputFocused = document.activeElement && (
            document.activeElement.tagName === 'INPUT' ||
            document.activeElement.tagName === 'TEXTAREA' ||
            document.activeElement.tagName === 'SELECT' ||
            document.activeElement.isContentEditable ||
            document.activeElement.contentEditable === 'true'
        );

        // Ctrl/Cmd + / للبحث (لا يعمل إذا كان التركيز على حقل إدخال)
        if (isCtrl && (key === '/' || key === '?')) {
            event.preventDefault();
            if (key === '?') {
                showKeyboardShortcutsHelp();
            } else {
                if (!focusSearchInput()) {
                    // إذا لم يوجد حقل بحث، إظهار رسالة
                    console.log('لا يوجد حقل بحث متاح');
                }
            }
            return;
        }

        // التنقل باستخدام Ctrl/Cmd + رقم (يعمل دائماً إلا في حقول الإدخال النصية الطويلة)
        if (isCtrl && !event.shiftKey && !event.altKey) {
            const pageLinks = getPageLinks();
            let handled = false;

            if (currentPage === 'production.php') {
                const productionHandlers = {
                    '1': () => navigateToPage('dashboard'),
                    '2': () => navigateToPage('production'),
                    '3': () => navigateToPage('tasks'),
                    '4': () => navigateToPage('chat'),
                    '5': () => navigateToPage('inventory'),
                    '6': () => navigateToPage('packaging_warehouse'),
                    '7': () => navigateToPage('raw_materials_warehouse'),
                    '8': () => navigateToPage('attendance')
                };
                if (productionHandlers[key]) {
                    handled = productionHandlers[key]();
                }
            } else if (currentPage === 'manager.php') {
                const managerHandlers = {
                    '1': () => navigateToPage('dashboard'),
                    '2': () => navigateToPage('approvals'),
                    '3': () => navigateToPage('reports'),
                    '4': () => navigateToPage('chat'),
                    '5': () => navigateToPage('users'),
                    '6': () => navigateToPage('backups'),
                    '7': () => navigateToPage('permissions'),
                    '8': () => navigateToPage('security')
                };
                if (managerHandlers[key]) {
                    handled = managerHandlers[key]();
                }
            } else if (currentPage === 'sales.php') {
                const salesHandlers = {
                    '1': () => navigateToPage('dashboard'),
                    '2': () => navigateToPage('sales'),
                    '3': () => navigateToPage('collections'),
                    '4': () => navigateToPage('chat'),
                    '5': () => navigateToPage('customers'),
                    '6': () => navigateToPage('orders'),
                    '7': () => navigateToPage('pos'),
                    '8': () => navigateToPage('reports')
                };
                if (salesHandlers[key]) {
                    handled = salesHandlers[key]();
                }
            } else if (currentPage === 'accountant.php') {
                const accountantHandlers = {
                    '1': () => navigateToPage('dashboard'),
                    '2': () => navigateToPage('financial'),
                    '3': () => navigateToPage('reports'),
                    '4': () => navigateToPage('chat'),
                    '5': () => navigateToPage('invoices'),
                    '6': () => navigateToPage('payments'),
                    '7': () => navigateToPage('budgets')
                };
                if (accountantHandlers[key]) {
                    handled = accountantHandlers[key]();
                }
            }

            // اختصارات عامة
            if (!handled && key === '1') {
                handled = navigateToPage('dashboard');
            }

            if (handled && (!isInputFocused || document.activeElement.tagName === 'INPUT' && 
                document.activeElement.type !== 'text' && 
                document.activeElement.type !== 'textarea' &&
                document.activeElement.type !== 'search')) {
                event.preventDefault();
                return;
            }
        }

        // Esc لإغلاق الـ modals (يعمل دائماً)
        if (key === 'escape' || key === 'esc') {
            closeAllModals();
            return;
        }

        // Ctrl/Cmd + S للحفظ (في النماذج)
        if (isCtrl && key === 's') {
            const activeForm = document.querySelector('form:not([novalidate])');
            if (activeForm && (isInputFocused || document.activeElement.closest('form'))) {
                event.preventDefault();
                const submitBtn = activeForm.querySelector('button[type="submit"], input[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.click();
                }
            }
            return;
        }
    });

    // إضافة مؤشر بصري عند استخدام الاختصارات
    let shortcutIndicator = null;
    function showShortcutIndicator(text) {
        if (!shortcutIndicator) {
            shortcutIndicator = document.createElement('div');
            shortcutIndicator.id = 'shortcut-indicator';
            shortcutIndicator.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: rgba(0, 0, 0, 0.85);
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                z-index: 9999;
                font-size: 14px;
                font-weight: 500;
                pointer-events: none;
                opacity: 0;
                transition: opacity 0.3s ease;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;
            document.body.appendChild(shortcutIndicator);
        }

        shortcutIndicator.textContent = text;
        shortcutIndicator.style.opacity = '1';

        setTimeout(function() {
            shortcutIndicator.style.opacity = '0';
        }, 2000);
    }

    // تحديث المؤشر عند استخدام الاختصارات
    document.addEventListener('keydown', function(event) {
        const isCtrl = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        if (isCtrl && ['1', '2', '3', '4', '5', '6', '7', '8'].includes(key)) {
            const pageLinks = getPageLinks();
            let pageName = '';

            if (currentPage === 'production.php') {
                const names = {
                    '1': 'لوحة التحكم',
                    '2': 'الإنتاج',
                    '3': 'المهام',
                    '4': 'الدردشة',
                    '5': 'المخزون',
                    '6': 'مخزن التعبئة',
                    '7': 'مخزن الخامات',
                    '8': 'الحضور'
                };
                pageName = names[key] || '';
            } else if (currentPage === 'manager.php') {
                const names = {
                    '1': 'لوحة التحكم',
                    '2': 'الموافقات',
                    '3': 'التقارير',
                    '4': 'الدردشة',
                    '5': 'المستخدمين',
                    '6': 'النسخ الاحتياطية',
                    '7': 'الصلاحيات',
                    '8': 'الأمان'
                };
                pageName = names[key] || '';
            } else if (currentPage === 'sales.php') {
                const names = {
                    '1': 'لوحة التحكم',
                    '2': 'المبيعات',
                    '3': 'التحصيلات',
                    '4': 'الدردشة',
                    '5': 'العملاء',
                    '6': 'الطلبات',
                    '7': 'نقطة البيع',
                    '8': 'التقارير'
                };
                pageName = names[key] || '';
            } else if (currentPage === 'accountant.php') {
                const names = {
                    '1': 'لوحة التحكم',
                    '2': 'المالية',
                    '3': 'التقارير',
                    '4': 'الدردشة',
                    '5': 'الفواتير',
                    '6': 'المدفوعات',
                    '7': 'الميزانيات'
                };
                pageName = names[key] || '';
            }

            if (pageName) {
                showShortcutIndicator(pageName);
            }
        }
    });

    // تهيئة عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            console.log('تم تحميل اختصارات لوحة المفاتيح العامة. استخدم Ctrl/Cmd + ? للمساعدة.');
        });
    } else {
        console.log('تم تحميل اختصارات لوحة المفاتيح العامة. استخدم Ctrl/Cmd + ? للمساعدة.');
    }
})();
