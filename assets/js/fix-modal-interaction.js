/**
 * إصلاح مشكلة عدم القدرة على التفاعل مع Modal
 * Fix Modal Interaction Issue
 */

(function () {
    'use strict';

    function hidePageLoader() {
        const pageLoader = document.getElementById('pageLoader');
        if (pageLoader) {
            pageLoader.classList.add('hidden');
            pageLoader.style.display = 'none';
            pageLoader.style.visibility = 'hidden';
            pageLoader.style.pointerEvents = 'none';
            pageLoader.style.zIndex = '-1';
        }
    }

    function enableModal(modal) {
        if (!modal) {
            return;
        }

        modal.style.pointerEvents = 'auto';
        const focusable = modal.querySelector('input, select, textarea, button');
        if (focusable) {
            focusable.focus({ preventScroll: true });
        }
    }

    // إخفاء pageLoader فوراً عند تحميل الصفحة
    function ensurePageLoaderHidden() {
        const pageLoader = document.getElementById('pageLoader');
        if (pageLoader && !pageLoader.classList.contains('hidden')) {
            hidePageLoader();
        }
    }

    // إخفاء pageLoader فوراً
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            hidePageLoader();
            ensurePageLoaderHidden();
        });
    } else {
        hidePageLoader();
        ensurePageLoaderHidden();
    }

    window.addEventListener('load', function () {
        hidePageLoader();
        ensurePageLoaderHidden();
    });

    // التأكد من إخفاء pageLoader بعد تأخير قصير
    setTimeout(function() {
        ensurePageLoaderHidden();
    }, 100);

    setTimeout(function() {
        ensurePageLoaderHidden();
    }, 500);

    setTimeout(function() {
        ensurePageLoaderHidden();
    }, 1000);

    document.addEventListener('show.bs.modal', function (event) {
        hidePageLoader();
        enableModal(event.target);
    });

    document.addEventListener('shown.bs.modal', function (event) {
        enableModal(event.target);
    });

    document.addEventListener('hidden.bs.modal', function () {
        hidePageLoader();
    });
})();

