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

    document.addEventListener('DOMContentLoaded', function () {
        hidePageLoader();
    });

    window.addEventListener('load', function () {
        hidePageLoader();
    });

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

