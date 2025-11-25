<?php
define('ACCESS_ALLOWED', true);

session_start([
    'cookie_lifetime' => 0,
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole(['manager', 'accountant', 'production', 'sales']);

$_SESSION['reader_session_id'] = $_SESSION['reader_session_id'] ?? bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1d4ed8">
    <link rel="manifest" href="manifest.php">
    <link rel="icon" type="image/svg+xml" href="assets/icon.svg">
    <link rel="apple-touch-icon" href="assets/icon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <title>قارئ أرقام التشغيلات</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Tajawal', sans-serif;
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(180deg, #ffffff 0%, #e6f0ff 50%, #d1e5ff 100%);
            color: #1f2937;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 16px;
        }
        .reader-wrapper {
            width: min(960px, 100%);
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.15);
            border-radius: 22px;
            padding: 36px;
        }
        .branding {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 28px;
        }
        .branding img {
            width: 72px;
            height: 72px;
        }
        .branding h1 {
            font-size: clamp(1.6rem, 2vw + 1rem, 2.2rem);
            margin: 0;
            font-weight: 700;
            color: #0f1f4b;
        }
        .branding p {
            margin: 6px 0 0;
            color: #475569;
            font-size: 0.95rem;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 30px;
        }
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(37, 99, 235, 0.15);
            color: #1d4ed8;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .install-button {
            display: none;
            padding: 10px 18px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0f1f4b 0%, #2563eb 100%);
            color: #fff;
            border: none;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.22);
            transition: transform 0.2s ease;
        }
        .install-button:hover {
            transform: translateY(-1px);
        }
        form {
            display: grid;
            gap: 16px;
        }
        label {
            font-weight: 600;
            color: #1f2a44;
        }
        .input-group {
            position: relative;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        input[type="text"] {
            flex: 1 1 280px;
            max-width: 420px;
            padding: 10px 22px 10px 14px;
            min-height: 46px;
            border: 1px solid rgba(59, 130, 246, 0.35);
            border-radius: 12px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.95);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #1d4ed8;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.18);
        }
        .input-group button {
            padding: 14px 34px;
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 45%, #1d4ed8 100%);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .input-group button:focus-visible {
            outline: 3px solid rgba(37, 99, 235, 0.3);
            outline-offset: 2px;
        }
        .input-group button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(29, 78, 216, 0.25);
        }
        .input-group button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .results {
            margin-top: 28px;
            display: grid;
            gap: 18px;
        }
        .card {
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            background: linear-gradient(180deg, rgba(255,255,255,0.95), rgba(248,250,255,0.95));
            padding: 22px;
        }
        .card h2 {
            margin: 0 0 18px;
            font-size: 1.18rem;
            color: #0f172a;
        }
        .card table {
            width: 100%;
            border-collapse: collapse;
        }
        .card th, .card td {
            text-align: right;
            padding: 10px 8px;
            font-size: 0.95rem;
        }

        @media (max-width: 600px) {
            body {
                padding: 28px 12px;
            }
            .reader-wrapper {
                padding: 24px 18px;
                border-radius: 18px;
            }
            .input-group {
                gap: 10px;
            }
            input[type="text"] {
                flex: 1 1 200px;
                max-width: 260px;
                padding: 8px 16px 8px 12px;
                min-height: 40px;
                font-size: 0.95rem;
            }
            .input-group button {
                padding: 12px 22px;
                font-size: 0.95rem;
                border-radius: 12px;
            }
        }
        .card th {
            width: 160px;
            color: #475569;
            font-weight: 600;
        }
        .card tr + tr td {
            border-top: 1px solid rgba(226, 232, 240, 0.6);
        }
        .muted {
            color: #64748b;
            font-size: 0.92rem;
        }
        .error-message {
            border-radius: 14px;
            border: 1px solid rgba(239, 68, 68, 0.2);
            background: rgba(254, 242, 242, 0.9);
            color: #b91c1c;
            padding: 16px;
        }
        .history {
            margin-top: 16px;
            font-size: 0.88rem;
            color: #475569;
        }
        @media (max-width: 640px) {
            body {
                padding: 16px 8px;
            }
            .reader-wrapper {
                padding: 22px 18px;
                border-radius: 16px;
                margin: 0;
            }
            .branding {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .branding img {
                width: 64px;
                height: 64px;
            }
            .top-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .camera-section {
                padding: 16px 12px;
                align-items: center;
            }
            .camera-actions button {
                width: 100%;
            }
            .input-group {
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }
            .input-group button {
                width: 100%;
                max-width: 320px;
            }
            .input-group input[type="text"] {
                width: 100%;
                max-width: 280px;
                padding: 8px 14px;
                min-height: 42px;
                font-size: 0.95rem;
            }
            .camera-preview {
                width: 100%;
                max-width: 320px;
                aspect-ratio: 5 / 3;
                min-height: unset;
                border-width: 2px;
            }
            .camera-preview video {
                height: 100%;
                object-fit: cover;
            }
            .scan-overlay {
                width: 88%;
                height: 80px;
            }
            .floating-tip {
                inset-inline: 12px;
                bottom: 18px;
            }
        }
        .camera-section {
            margin-top: 24px;
            background: rgba(255, 255, 255, 0.92);
            border: 2px dashed rgba(37, 99, 235, 0.35);
            border-radius: 18px;
            padding: 24px;
            transition: all 0.3s ease;
        }
        .camera-section.active {
            border-style: solid;
            background: rgba(226, 232, 255, 0.55);
        }
        .camera-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-bottom: 16px;
        }
        .camera-actions button {
            padding: 12px 32px;
            border-radius: 999px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .camera-actions .start-camera {
            background: linear-gradient(135deg, #2563eb 0%, #0f1f4b 100%);
            color: #fff;
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.3);
        }
        .camera-actions .start-camera:hover {
            transform: translateY(-1px);
        }
        .camera-actions .stop-camera {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.35);
            display: none;
        }
        .camera-preview {
            position: relative;
            width: min(680px, 100%);
            margin: 0 auto 16px;
            border-radius: 14px;
            overflow: hidden;
            background: #000;
            border: 3px solid rgba(37, 99, 235, 0.45);
            display: none;
        }
        .camera-preview video {
            width: 100%;
            height: auto;
            display: block;
            object-fit: cover;
        }
        .scan-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            height: 140px;
            border: 2px solid rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.35);
            pointer-events: none;
        }
        .scan-line {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: rgba(79, 70, 229, 0.9);
            animation: scan 2s linear infinite;
        }
        @keyframes scan {
            0% { top: 0; }
            100% { top: calc(100% - 3px); }
        }
        .camera-hint {
            text-align: center;
            color: #475569;
            font-size: 0.9rem;
        }
        .camera-error {
            background: rgba(254, 226, 226, 0.9);
            color: #b91c1c;
            border: 1px solid rgba(248, 113, 113, 0.45);
            border-radius: 12px;
            padding: 12px 16px;
            display: none;
            margin-top: 12px;
            text-align: center;
        }
        .floating-tip {
            position: fixed;
            bottom: 24px;
            inset-inline: 16px;
            padding: 0;
            background: rgba(15, 23, 42, 0.88);
            color: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 35px rgba(15, 23, 42, 0.35);
            z-index: 1100;
            transform: translateY(30px);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }
        .floating-tip.visible {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }
        .floating-tip .tip-content {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
        }
        .floating-tip .tip-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(59, 130, 246, 0.95);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .floating-tip .tip-text {
            flex: 1;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .floating-tip .tip-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.75);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 4px;
        }
        .advanced-settings {
            margin: 12px auto 0;
            max-width: 520px;
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 16px;
            padding: 12px 18px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .advanced-settings input[type="checkbox"] {
            margin-top: 4px;
            accent-color: #2563eb;
        }
        .advanced-settings label {
            font-weight: 600;
            color: #1f2a44;
        }
        .advanced-settings .tip {
            display: block;
            color: #475569;
            font-size: 0.85rem;
            font-weight: 400;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="reader-wrapper">
        <div class="branding">
            <img src="assets/icon.svg" alt="شعار القارئ" loading="lazy">
            <div>
                <h1>قارئ باركود التشغيلات</h1>
            </div>
        </div>
        <div class="top-bar">
            <span class="tag" id="sessionIndicator">جلسة آمنة</span>
            <button class="install-button" id="installButton">تثبيت التطبيق</button>
        </div>
        <div class="floating-tip" id="installTip" hidden>
            <div class="tip-content">
                <span class="tip-icon"><i class="bi bi-download"></i></span>
                <div class="tip-text" id="installTipMessage">يمكنك تثبيت القارئ كتطبيق واستخدامه بلا متصفح.</div>
                <button type="button" class="tip-close" id="dismissInstallTip"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <section class="camera-section" id="cameraSection">
            <div class="camera-actions">
                <button type="button" class="start-camera" id="startCameraBtn">
                    <i class="bi bi-camera-video" style="margin-inline-end:8px;"></i>تشغيل الكاميرا
                </button>
                <button type="button" class="stop-camera" id="stopCameraBtn">
                    <i class="bi bi-camera-video-off" style="margin-inline-end:8px;"></i>إيقاف الكاميرا
                </button>
            </div>
            <div class="camera-preview" id="videoContainer">
                <video id="video" autoplay playsinline></video>
                <div class="scan-overlay"><div class="scan-line"></div></div>
            </div>
            <canvas id="snapshotCanvas" style="display:none;"></canvas>
            <p class="camera-hint">استخدم الكاميرا لمسح رقم التشغيلة من الملصق أو أدخل الرقم يدويًا أدناه.</p>
            <div class="camera-error" id="cameraError">حدث خطأ في الوصول إلى الكاميرا. يرجى التأكد من منح الأذونات.</div>
            <div class="advanced-settings">
                <input type="checkbox" id="advancedModeToggle" />
                <label for="advancedModeToggle">
                    وضع الدقة العالية
                    <span class="tip">يتم تحميل مكتبات إضافية عند التفعيل لتحسين قراءة الباركود والنصوص، وقد يستهلك بيانات أكثر.</span>
                </label>
            </div>
        </section>
        <form id="scannerForm" autocomplete="off">
            <label for="batchInput">رقم التشغيلة أو الباركود</label>
            <div class="input-group">
                <input type="text" id="batchInput" name="batch" placeholder="قم بمسح الباركود أو إدخال رقم التشغيلة (مثال: 251111-151656)" required autofocus pattern="\d{6}-\d{6}">
                <button type="submit" id="scanButton">
                    <span>قراءة التفاصيل</span>
                </button>
            </div>
        </form>
        <div class="results" id="resultsContainer" hidden>
            <div id="feedbackArea"></div>
            <div class="card" id="batchSummary" hidden></div>
            <div class="card" id="materialsCard" hidden></div>
            <div class="card" id="suppliersCard" hidden></div>
            <div class="card" id="workersCard" hidden></div>
        </div>
        <div class="history" id="historyLog" hidden></div>
    </div>

    <script>
    (function() {
        const installButton = document.getElementById('installButton');
        const installTip = document.getElementById('installTip');
        const installTipMessage = document.getElementById('installTipMessage');
        const dismissInstallTip = document.getElementById('dismissInstallTip');
        let deferredPrompt = null;
        let currentTipKey = null;

        const TIP_KEY = 'reader-install-tip-dismissed';
        const IOS_TIP_KEY = 'reader-ios-tip-dismissed';

        function showTip(message, storageKey) {
            if (storageKey && localStorage.getItem(storageKey)) {
                return;
            }
            currentTipKey = storageKey || null;
            installTipMessage.textContent = message;
            installTip.hidden = false;
            requestAnimationFrame(() => installTip.classList.add('visible'));
        }

        function hideTip(storageKey) {
            const keyToStore = storageKey || currentTipKey;
            installTip.classList.remove('visible');
            setTimeout(() => {
                installTip.hidden = true;
            }, 250);
            if (keyToStore) {
                localStorage.setItem(keyToStore, '1');
            }
            currentTipKey = null;
        }

        dismissInstallTip.addEventListener('click', () => hideTip());

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferredPrompt = event;
            installButton.style.display = 'inline-flex';
            showTip('اضغط على زر "تثبيت التطبيق" لإضافته إلى شاشتك الرئيسية.', TIP_KEY);
        });

        window.addEventListener('appinstalled', () => {
            hideTip(TIP_KEY);
            localStorage.setItem('reader-app-installed', '1');
        });

        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
        const isIos = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());

        if (isIos && !isStandalone && !localStorage.getItem(IOS_TIP_KEY)) {
            setTimeout(() => {
                showTip('لتثبيت التطبيق على iPhone، اختر مشاركة ثم "أضف إلى الشاشة الرئيسية".', IOS_TIP_KEY);
            }, 1200);
        }

        installButton.addEventListener('click', async () => {
            if (!deferredPrompt) {
                hideTip();
                return;
            }
            installButton.disabled = true;
            try {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    hideTip(TIP_KEY);
                }
            } finally {
                deferredPrompt = null;
                installButton.disabled = false;
                installButton.style.display = 'none';
            }
        });

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('service-worker.js').catch(console.error);
        }

        const form = document.getElementById('scannerForm');
        const batchInput = document.getElementById('batchInput');
        const scanButton = document.getElementById('scanButton');
        const resultsContainer = document.getElementById('resultsContainer');
        const batchSummary = document.getElementById('batchSummary');
        const materialsCard = document.getElementById('materialsCard');
        const suppliersCard = document.getElementById('suppliersCard');
        const workersCard = document.getElementById('workersCard');
        const feedbackArea = document.getElementById('feedbackArea');
        const historyLog = document.getElementById('historyLog');
        const startCameraBtn = document.getElementById('startCameraBtn');
        const stopCameraBtn = document.getElementById('stopCameraBtn');
        const cameraSection = document.getElementById('cameraSection');
        const videoContainer = document.getElementById('videoContainer');
        const video = document.getElementById('video');
        const cameraError = document.getElementById('cameraError');
        const snapshotCanvas = document.getElementById('snapshotCanvas');
        const snapshotContext = snapshotCanvas.getContext('2d', { willReadFrequently: true });
        const advancedModeToggle = document.getElementById('advancedModeToggle');

        const detectionFormatsPreference = [
            'code_128',
            'code_39',
            'code_93',
            'ean_13',
            'ean_8',
            'upc_a',
            'upc_e',
            'itf',
            'codabar',
            'qr_code',
            'data_matrix',
            'pdf417'
        ];

        const statusLabels = {
            in_production: 'قيد الإنتاج',
            completed: 'مكتملة',
            in_stock: 'في المخزون',
            sold: 'مباعة',
            expired: 'منتهية',
            archived: 'مؤرشفة',
            cancelled: 'ملغاة',
        };

        const supplierRoleLabels = {
            raw_material: 'مواد خام',
            packaging: 'تعبئة',
            template_main: 'مورد أساسي',
            template_extra: 'مورد إضافي',
        };

        let currentStream = null;
        let scanning = false;
        let detectionCooldown = false;
        let barcodeInterval = null;
        let barcodeProcessing = false;
        let zxingReader = null;
        let zxingActive = false;
        let zxingScriptPromise = null;
        let ocrInterval = null;
        let ocrProcessing = false;
        let barcodeDetectorInstance = null;
        let tesseractScriptPromise = null;
        let tesseractWorkerPromise = null;
        let ocrWorker = null;
        let advancedMode = false;
        let advancedDetectionActive = false;
        let lastAdvancedDetectionResult = { zxingReady: false, ocrReady: false };

        const historyEntries = [];

        function formatQuantity(value) {
            if (value === null || value === undefined) {
                return null;
            }
            const numeric = Number(value);
            if (!Number.isFinite(numeric)) {
                return null;
            }
            if (Math.abs(numeric - Math.round(numeric)) < 1e-6) {
                return Math.round(numeric).toString();
            }
            return numeric.toFixed(3).replace(/\.?0+$/, '');
        }

        function formatSupplierRoles(entry) {
            if (!entry) {
                return '';
            }
            const rawRoles = Array.isArray(entry.roles) && entry.roles.length
                ? entry.roles
                : (entry.role ? String(entry.role).split(',') : []);
            const normalizedRoles = rawRoles
                .map(role => (role || '').trim())
                .filter(Boolean);

            if (!normalizedRoles.length) {
                return '';
            }

            return normalizedRoles
                .map(role => supplierRoleLabels[role] || role)
                .join('، ');
        }

        function formatMaterialEntry(entry, fallbackName) {
            if (!entry || typeof entry !== 'object') {
                return { name: fallbackName, details: '' };
            }
            const name = entry.name ?? fallbackName;
            if (entry.details) {
                return { name, details: entry.details };
            }
            const detailsParts = [];
            const quantityLabel = formatQuantity(entry.quantity_used);
            if (quantityLabel) {
                const unitLabel = entry.unit ? ` ${entry.unit}` : '';
                detailsParts.push(`${quantityLabel}${unitLabel}`.trim());
            }
            if (entry.supplier_name) {
                detailsParts.push(`المورد: ${entry.supplier_name}`);
            }
            return {
                name,
                details: detailsParts.filter(Boolean).join(' • ')
            };
        }

        if (advancedModeToggle) {
            advancedMode = localStorage.getItem('reader-advanced-mode') === '1';
            advancedModeToggle.checked = advancedMode;
            advancedModeToggle.addEventListener('change', () => {
                advancedMode = advancedModeToggle.checked;
                if (advancedMode) {
                    localStorage.setItem('reader-advanced-mode', '1');
                } else {
                    localStorage.removeItem('reader-advanced-mode');
                }
            });
        }

        function setLoading(isLoading) {
            scanButton.disabled = isLoading;
            scanButton.textContent = isLoading ? 'جاري البحث...' : 'قراءة التفاصيل';
        }

        function renderError(message) {
            resultsContainer.hidden = false;
            batchSummary.hidden = true;
            materialsCard.hidden = true;
            materialsCard.innerHTML = '';
            suppliersCard.hidden = true;
            suppliersCard.innerHTML = '';
            workersCard.hidden = true;
            workersCard.innerHTML = '';
            feedbackArea.innerHTML = `<div class="error-message">${message}</div>`;
        }

        function renderHistory() {
            if (!historyEntries.length) {
                historyLog.hidden = true;
                return;
            }
            historyLog.hidden = false;
            historyLog.innerHTML = historyEntries
                .slice(-5)
                .reverse()
                .map(entry => `<div>• تم فحص <strong>${entry.number}</strong> في ${entry.time}</div>`)
                .join('');
        }

        function renderBatch(data) {
            resultsContainer.hidden = false;
            feedbackArea.innerHTML = '';

            const quantityLabel = formatQuantity(data.quantity_produced ?? data.quantity);
            const summaryRows = [
                ['اسم المنتج', data.product_name ?? '—'],
                ['تاريخ الإنتاج', data.production_date ?? '—'],
                ['الكمية المنتجة', quantityLabel ?? data.quantity_produced ?? data.quantity ?? '—'],
            ];

            const suppliers = Array.isArray(data.suppliers) ? data.suppliers : [];

            const workers = Array.isArray(data.workers) ? data.workers : [];

            batchSummary.hidden = false;
            batchSummary.innerHTML = `
                <h2>ملخص التشغيلة</h2>
                <table>
                    ${summaryRows.map(([label, value]) => `
                        <tr>
                            <th>${label}</th>
                            <td>${value}</td>
                        </tr>
                    `).join('')}
                </table>
            `;

            const packagingItems = Array.isArray(data.materials) && data.materials.length
                ? data.materials.map(item => ({ ...formatMaterialEntry(item, 'مادة تعبئة'), category: 'مادة تعبئة' }))
                : Array.isArray(data.packaging_materials)
                    ? data.packaging_materials.map(item => ({ ...formatMaterialEntry(item, 'مادة تعبئة'), category: 'مادة تعبئة' }))
                    : [];
            const rawItems = Array.isArray(data.raw_materials) && data.raw_materials.length
                ? data.raw_materials.map(item => ({ ...formatMaterialEntry(item, 'مادة خام'), category: 'مادة خام' }))
                : Array.isArray(data.raw_materials_source)
                    ? data.raw_materials_source.map(item => ({ ...formatMaterialEntry(item, 'مادة خام'), category: 'مادة خام' }))
                    : [];

            const allMaterials = [...packagingItems, ...rawItems];

            if (allMaterials.length) {
                materialsCard.hidden = false;
                materialsCard.innerHTML = `
                    <h2>تفاصيل المواد</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>النوع</th>
                                <th>المادة</th>
                                <th>التفاصيل</th>
                            </tr>
                        </thead>
                        <tbody>
                        ${allMaterials.map(item => `
                            <tr>
                                <td>${item.category}</td>
                                <td>${item.name ?? '—'}</td>
                                <td>${item.details ?? '—'}</td>
                            </tr>
                        `).join('')}
                        </tbody>
                    </table>
                `;
            } else {
                materialsCard.hidden = true;
                materialsCard.innerHTML = '';
            }

            if (Array.isArray(data.suppliers) && data.suppliers.length) {
                const uniqueNames = [];
                const seenNames = new Set();
                data.suppliers.forEach(supplier => {
                    const rawName = supplier?.name;
                    const name = rawName ? String(rawName).trim() : '';
                    if (!name || seenNames.has(name)) {
                        return;
                    }
                    seenNames.add(name);
                    uniqueNames.push({
                        name,
                        details: formatSupplierRoles(supplier)
                    });
                });
                if (uniqueNames.length) {
                    suppliersCard.hidden = false;
                    suppliersCard.innerHTML = `
                        <h2>الموردون المرتبطون</h2>
                        <table>
                            ${uniqueNames.map(entry => `
                                    <tr>
                                        <th>${entry.name}</th>
                                        <td>${entry.details || '—'}</td>
                                    </tr>
                            `).join('')}
                        </table>
                    `;
                } else {
                    suppliersCard.hidden = true;
                    suppliersCard.innerHTML = '';
                }
            } else {
                suppliersCard.hidden = true;
                suppliersCard.innerHTML = '';
            }

            if (Array.isArray(data.workers) && data.workers.length) {
                workersCard.hidden = false;
                workersCard.innerHTML = `
                    <h2>فريق الإنتاج</h2>
                    <table>
                        ${data.workers.map(worker => `
                            <tr>
                                <th>${worker.full_name ?? worker.username ?? '—'}</th>
                                <td>${worker.role ?? 'عامل إنتاج'}</td>
                            </tr>
                        `).join('')}
                    </table>
                `;
            } else {
                workersCard.hidden = true;
                workersCard.innerHTML = '';
            }
        }

        function stopAdvancedDetection() {
            stopZxingDetection();
            // تم تعطيل OCR - لا نحتاج لإيقافه
            if (ocrInterval) {
                clearInterval(ocrInterval);
                ocrInterval = null;
            }
            ocrProcessing = false;
            advancedDetectionActive = false;
            lastAdvancedDetectionResult = { zxingReady: false, ocrReady: false };
        }

        function stopDetectionLoops() {
            if (barcodeInterval) {
                clearInterval(barcodeInterval);
                barcodeInterval = null;
            }
            barcodeProcessing = false;
            stopAdvancedDetection();
        }

        function stopZxingDetection() {
            if (zxingReader && zxingActive) {
                try {
                    zxingReader.reset();
                } catch (error) {
                    console.debug('ZXing reset error', error);
                }
            }
            zxingActive = false;
        }

        function stopCamera() {
            scanning = false;
            stopDetectionLoops();

            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }

            video.srcObject = null;
            videoContainer.style.display = 'none';
            cameraSection.classList.remove('active');
            startCameraBtn.style.display = 'inline-flex';
            stopCameraBtn.style.display = 'none';
            detectionCooldown = false;
        }

        async function ensureCameraPermission() {
            try {
                if (!navigator.permissions || !navigator.permissions.query) {
                    return true;
                }
                const status = await navigator.permissions.query({ name: 'camera' });
                if (status.state === 'denied') {
                    cameraError.textContent = 'تم رفض إذن الكاميرا. الرجاء منح الإذن في إعدادات المتصفح ثم إعادة المحاولة.';
                    cameraError.style.display = 'block';
                    return false;
                }
                if (status.state === 'prompt') {
                    try {
                        const tempStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                        tempStream.getTracks().forEach(track => track.stop());
                    } catch (promptError) {
                        cameraError.textContent = 'يجب قبول إذن الكاميرا لاستخدام المسح. الرجاء المحاولة مرة أخرى والسماح بالوصول.';
                        cameraError.style.display = 'block';
                        return false;
                    }
                }
                return true;
            } catch (permError) {
                return true;
            }
        }

        function extractCandidateFromText(rawText) {
            const cleaned = (rawText || '').replace(/\s+/g, ' ').trim();
            if (!cleaned) {
                return '';
            }
            const batchMatch = cleaned.match(/BATCH[:\s-]*([A-Z0-9\-]+)/i);
            if (batchMatch && batchMatch[1]) {
                const normalized = batchMatch[1].replace(/[^A-Z0-9\-]/gi, '').toUpperCase();
                return normalized ? `BATCH: ${normalized}` : '';
            }
            const alphanumericMatch = cleaned.match(/[A-Z0-9\-]{6,}/i);
            if (alphanumericMatch) {
                return alphanumericMatch[0].replace(/[^A-Z0-9\-]/gi, '').toUpperCase();
            }
            const numericMatch = cleaned.match(/\d{6,}/);
            return numericMatch ? numericMatch[0] : '';
        }

        function normalizeBarcodeValue(value) {
            if (!value) {
                return '';
            }
            
            // التحقق من تنسيق رقم التشغيلة: XXXXXX-XXXXXX (6 أرقام-6 أرقام مع شرطة)
            // مثال: 251111-151656
            const batchNumberPattern = /^(\d{6})-(\d{6})$/;
            const match = value.trim().match(batchNumberPattern);
            
            if (match) {
                // إذا كان التنسيق صحيحاً، نعيده كما هو
                return match[0];
            }
            
            // محاولة استخراج التنسيق من نص قد يحتوي على مسافات أو أحرف إضافية
            // البحث عن نمط 6 أرقام-6 أرقام في النص
            const extractedMatch = value.match(/(\d{6})-(\d{6})/);
            if (extractedMatch) {
                return extractedMatch[0];
            }
            
            // إذا لم يكن التنسيق صحيحاً، نعيد قيمة فارغة
            return '';
        }

        function completeDetection(candidate) {
            if (!candidate) {
                return;
            }
            detectionCooldown = true;
            batchInput.value = candidate;
            batchInput.focus();
            setTimeout(() => form.requestSubmit(), 120);
            stopCamera();
            setTimeout(() => {
                detectionCooldown = false;
            }, 500);
        }

        async function loadTesseractLibrary() {
            if (window.Tesseract?.createWorker) {
                return;
            }
            if (!tesseractScriptPromise) {
                tesseractScriptPromise = new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js';
                    script.async = true;
                    script.onload = resolve;
                    script.onerror = () => reject(new Error('تعذر تحميل مكتبة التعرف على النصوص.'));
                    document.head.appendChild(script);
                });
            }
            await tesseractScriptPromise;
        }

        async function getOcrWorker() {
            if (ocrWorker) {
                return ocrWorker;
            }
            try {
                await loadTesseractLibrary();
                if (!window.Tesseract?.createWorker) {
                    return null;
                }
                if (!tesseractWorkerPromise) {
                    tesseractWorkerPromise = (async () => {
                        const worker = await Tesseract.createWorker({ logger: () => {} });
                        await worker.load();
                        await worker.loadLanguage('eng');
                        await worker.initialize('eng');
                        ocrWorker = worker;
                        return worker;
                    })().catch(error => {
                        console.error('Failed to initialise OCR worker', error);
                        tesseractWorkerPromise = null;
                        return null;
                    });
                }
                return await tesseractWorkerPromise;
            } catch (error) {
                console.error('Failed to prepare OCR worker', error);
                return null;
            }
        }

        async function getBarcodeDetector() {
            if (barcodeDetectorInstance) {
                return barcodeDetectorInstance;
            }
            if (!('BarcodeDetector' in window)) {
                return null;
            }
            try {
                const supportedFormats = typeof BarcodeDetector.getSupportedFormats === 'function'
                    ? await BarcodeDetector.getSupportedFormats()
                    : null;
                const filteredFormats = detectionFormatsPreference.filter(format =>
                    !Array.isArray(supportedFormats) || supportedFormats.includes(format)
                );
                barcodeDetectorInstance = new BarcodeDetector({ formats: filteredFormats });
                return barcodeDetectorInstance;
            } catch (error) {
                console.warn('BarcodeDetector unavailable', error);
                barcodeDetectorInstance = null;
                return null;
            }
        }

        async function loadZxingLibrary() {
            if (window.ZXing?.BrowserMultiFormatReader) {
                return true;
            }
            if (!zxingScriptPromise) {
                zxingScriptPromise = new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.20.0/umd/index.min.js';
                    script.async = true;
                    script.onload = () => resolve(true);
                    script.onerror = () => reject(new Error('تعذر تحميل مكتبة قراءة الباركود الاحتياطية.'));
                    document.head.appendChild(script);
                });
            }
            try {
                await zxingScriptPromise;
                return !!window.ZXing?.BrowserMultiFormatReader;
            } catch (error) {
                console.warn(error);
                return false;
            }
        }

        async function startZxingDetection() {
            if (!await loadZxingLibrary()) {
                return false;
            }
            if (!window.ZXing?.BrowserMultiFormatReader) {
                return false;
            }
            if (!zxingReader) {
                try {
                    zxingReader = new ZXing.BrowserMultiFormatReader();
                } catch (error) {
                    console.warn('Failed to initialise ZXing reader', error);
                    zxingReader = null;
                    return false;
                }
            }
            zxingActive = true;
            try {
                await zxingReader.decodeFromVideoDevice(null, video, (result, error) => {
                    if (!zxingActive || detectionCooldown) {
                        return;
                    }
                    if (result) {
                        const candidate = normalizeBarcodeValue(result.getText?.() ?? result.text ?? '');
                        if (candidate) {
                            completeDetection(candidate);
                        }
                    }
                });
                return true;
            } catch (error) {
                console.warn('ZXing detection error', error);
                stopZxingDetection();
                return false;
            }
        }

        function drawFrameToCanvas() {
            const width = video.videoWidth;
            const height = video.videoHeight;
            if (!width || !height) {
                return false;
            }
            const sourceWidth = Math.floor(width * 0.75);
            const sourceHeight = Math.floor(height * 0.35);
            const sourceX = Math.floor((width - sourceWidth) / 2);
            const sourceY = Math.floor((height - sourceHeight) / 2);
            const targetWidth = Math.min(sourceWidth, 640);
            const scale = targetWidth / sourceWidth;
            const targetHeight = Math.max(Math.floor(sourceHeight * scale), 120);

            snapshotCanvas.width = targetWidth;
            snapshotCanvas.height = targetHeight;
            snapshotContext.imageSmoothingEnabled = false;
            snapshotContext.clearRect(0, 0, targetWidth, targetHeight);
            snapshotContext.drawImage(
                video,
                sourceX,
                sourceY,
                sourceWidth,
                sourceHeight,
                0,
                0,
                targetWidth,
                targetHeight
            );
            try {
                const imageData = snapshotContext.getImageData(0, 0, targetWidth, targetHeight);
                const data = imageData.data;
                const contrastFactor = 1.35;
                const brightnessOffset = 12;
                for (let i = 0; i < data.length; i += 4) {
                    const r = data[i];
                    const g = data[i + 1];
                    const b = data[i + 2];
                    let gray = 0.299 * r + 0.587 * g + 0.114 * b;
                    gray = ((gray - 128) * contrastFactor) + 128 + brightnessOffset;
                    gray = Math.max(0, Math.min(255, gray));
                    data[i] = gray;
                    data[i + 1] = gray;
                    data[i + 2] = gray;
                }
                snapshotContext.putImageData(imageData, 0, 0);
            } catch (processingError) {
                console.debug('Pre-processing frame failed', processingError);
            }
            return true;
        }

        async function startBarcodeDetection() {
            const detector = await getBarcodeDetector();
            if (!detector) {
                return false;
            }
            barcodeInterval = setInterval(async () => {
                if (!scanning || detectionCooldown || barcodeProcessing) {
                    return;
                }
                if (video.readyState < 2 || video.videoWidth === 0) {
                    return;
                }
                barcodeProcessing = true;
                try {
                    const result = await detector.detect(video);
                    if (!Array.isArray(result) || !result.length) {
                        return;
                    }
                    const match = result.find(item => item.rawValue);
                    const candidate = normalizeBarcodeValue(match?.rawValue);
                    if (candidate) {
                        completeDetection(candidate);
                    }
                } catch (error) {
                    console.error('Barcode detection error:', error);
                } finally {
                    barcodeProcessing = false;
                }
            }, 350);
            return true;
        }

        async function startOcrDetection() {
            const worker = await getOcrWorker();
            if (!worker) {
                return false;
            }
            const performOcr = async () => {
                if (!scanning || detectionCooldown || ocrProcessing) {
                    return;
                }
                if (video.readyState < 2 || video.videoWidth === 0) {
                    return;
                }
                if (!drawFrameToCanvas()) {
                    return;
                }
                ocrProcessing = true;
                try {
                    const result = await worker.recognize(snapshotCanvas);
                    const candidate = extractCandidateFromText(result?.data?.text || '');
                    if (candidate) {
                        completeDetection(candidate);
                    }
                } catch (error) {
                    console.error('OCR error:', error);
                } finally {
                    ocrProcessing = false;
                }
            };

            await performOcr();
            ocrInterval = setInterval(performOcr, 1800);
            return true;
        }

        async function startAdvancedDetection() {
            if (advancedDetectionActive) {
                return lastAdvancedDetectionResult;
            }
            advancedDetectionActive = true;
            // استخدام ZXing فقط لقراءة الباركودات (لا نستخدم OCR لأنه يقرأ النصوص)
            const zxingReady = await startZxingDetection();
            const ocrReady = false; // تم تعطيل OCR لأنه يقرأ النصوص وليس فقط الباركودات
            lastAdvancedDetectionResult = { zxingReady, ocrReady };
            return lastAdvancedDetectionResult;
        }

        async function enableAdvancedMode(autoTriggered = false) {
            if (!advancedMode) {
                advancedMode = true;
                if (advancedModeToggle) {
                    advancedModeToggle.checked = true;
                }
                localStorage.setItem('reader-advanced-mode', '1');
                if (autoTriggered) {
                    cameraError.textContent = 'تم تفعيل وضع الدقة العالية تلقائيًا لأن متصفحك لا يدعم قراءة الباركود المدمجة.';
                    cameraError.style.display = 'block';
                }
            }
            const advancedResults = await startAdvancedDetection();
            if (advancedResults.zxingReady) {
                cameraError.style.display = 'none';
            }
            return advancedResults;
        }

        async function startCamera() {
            if (!navigator.mediaDevices?.getUserMedia) {
                cameraError.textContent = 'المتصفح لا يدعم تشغيل الكاميرا لهذه الوظيفة.';
                cameraError.style.display = 'block';
                return;
            }

            const hasPermission = await ensureCameraPermission();
            if (!hasPermission) {
                return;
            }

            try {
                const constraints = {
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                };

                currentStream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = currentStream;
                videoContainer.style.display = 'block';
                cameraSection.classList.add('active');
                startCameraBtn.style.display = 'none';
                stopCameraBtn.style.display = 'inline-flex';
                cameraError.style.display = 'none';

                scanning = true;
                detectionCooldown = false;
                stopDetectionLoops();
                barcodeProcessing = false;
                ocrProcessing = false;
                const barcodeReady = await startBarcodeDetection();
                let zxingReady = false;
                let ocrReady = false;

                if (!barcodeReady) {
                    const advancedResults = await enableAdvancedMode(true);
                    zxingReady = advancedResults.zxingReady;
                    ocrReady = advancedResults.ocrReady;
                } else if (advancedMode) {
                    const advancedResults = await startAdvancedDetection();
                    zxingReady = advancedResults.zxingReady;
                    ocrReady = advancedResults.ocrReady;
                } else {
                    cameraError.style.display = 'none';
                    stopAdvancedDetection();
                }

                if (!barcodeReady && !zxingReady) {
                    cameraError.textContent = 'تعذر تشغيل آلية قراءة الباركود. يرجى التأكد من اتصالك بالإنترنت أو استخدام الإدخال اليدوي.';
                    cameraError.style.display = 'block';
                }

            } catch (error) {
                console.error('Camera error:', error);
                let message = 'تعذر الوصول إلى الكاميرا. يرجى السماح بالوصول أو التحقق من الجهاز.';
                if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                    message = 'تم رفض إذن الكاميرا. الرجاء منح الإذن من إعدادات المتصفح ثم المحاولة.';
                } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
                    message = 'لم يتم العثور على كاميرا متاحة. تأكد من توصيل الكاميرا بالجهاز.';
                }
                cameraError.textContent = message;
                cameraError.style.display = 'block';
                stopCamera();
            }
        }

        // التحقق من أن الإدخال يطابق تنسيق رقم التشغيلة: XXXXXX-XXXXXX
        if (batchInput) {
            batchInput.addEventListener('input', (e) => {
                let value = e.target.value;
                // السماح فقط بالأرقام والشرطة
                value = value.replace(/[^0-9-]/g, '');
                
                // التأكد من وجود شرطة واحدة فقط
                const dashCount = (value.match(/-/g) || []).length;
                if (dashCount > 1) {
                    // إذا كان هناك أكثر من شرطة، نأخذ فقط الأولى
                    const parts = value.split('-');
                    value = parts[0] + '-' + parts.slice(1).join('');
                }
                
                // التأكد من أن الشرطة في المكان الصحيح (بعد 6 أرقام)
                if (value.includes('-')) {
                    const parts = value.split('-');
                    // الجزء الأول: 6 أرقام كحد أقصى
                    parts[0] = parts[0].slice(0, 6);
                    // الجزء الثاني: 6 أرقام كحد أقصى
                    if (parts[1]) {
                        parts[1] = parts[1].slice(0, 6);
                    }
                    value = parts.join('-');
                } else if (value.length > 6) {
                    // إذا تجاوزت 6 أرقام بدون شرطة، نضيف الشرطة تلقائياً
                    value = value.slice(0, 6) + '-' + value.slice(6, 12);
                }
                
                e.target.value = value;
            });
            
            batchInput.addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                // محاولة استخراج تنسيق رقم التشغيلة
                const match = pastedText.match(/(\d{6})-(\d{6})/);
                if (match) {
                    e.target.value = match[0];
                } else {
                    // إذا لم يكن التنسيق صحيحاً، نأخذ الأرقام فقط ونحاول تنسيقها
                    const numbersOnly = pastedText.replace(/[^0-9]/g, '');
                    if (numbersOnly.length >= 6) {
                        e.target.value = numbersOnly.slice(0, 6) + '-' + numbersOnly.slice(6, 12);
                    } else {
                        e.target.value = numbersOnly;
                    }
                }
            });
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            let batchNumber = batchInput.value.trim();
            
            // التحقق من تنسيق رقم التشغيلة: XXXXXX-XXXXXX
            const batchNumberPattern = /^(\d{6})-(\d{6})$/;
            
            if (!batchNumber) {
                renderError('يرجى إدخال رقم التشغيلة أو مسح الباركود بالتنسيق: XXXXXX-XXXXXX');
                return;
            }
            
            // محاولة استخراج التنسيق الصحيح
            const match = batchNumber.match(batchNumberPattern);
            if (!match) {
                // محاولة إصلاح التنسيق إذا كان يحتوي على أرقام فقط
                const numbersOnly = batchNumber.replace(/[^0-9]/g, '');
                if (numbersOnly.length === 12) {
                    batchNumber = numbersOnly.slice(0, 6) + '-' + numbersOnly.slice(6, 12);
                    batchInput.value = batchNumber;
                } else {
                    renderError('رقم التشغيلة يجب أن يكون بالتنسيق: XXXXXX-XXXXXX (مثال: 251111-151656)');
                    return;
                }
            }

            setLoading(true);
            try {
                const response = await fetch('../api/production/get_batch_details.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        batch_number: batchNumber,
                        reader_public: true,
                    }),
                });

                const rawText = await response.text();
                let data = null;
                try {
                    data = rawText ? JSON.parse(rawText) : null;
                } catch (parseError) {
                    console.error('Reader API parse error:', parseError, 'Response:', rawText);
                    if (rawText && rawText.trim().startsWith('<')) {
                        renderError('الخادم أعاد استجابة HTML. يرجى التأكد من أن قاعدة البيانات جاهزة وجداول التشغيلات متاحة.');
                    } else {
                        renderError('الخادم لم يرجع بيانات JSON صحيحة. تأكد من أن الخدمة تعمل ثم أعد المحاولة.');
                    }
                    return;
                }

                if (!response.ok || !data?.success) {
                    renderError(data?.message ?? 'تعذر استرجاع بيانات رقم التشغيلة.');
                    return;
                }

                renderBatch(data.batch);

                historyEntries.push({
                    number: data.batch.batch_number,
                    time: new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })
                });
                renderHistory();

                batchInput.select();
            } catch (error) {
                console.error(error);
                renderError('حدث خطأ أثناء الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
            } finally {
                setLoading(false);
            }
        });

        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', startCamera);
        }
        if (stopCameraBtn) {
            stopCameraBtn.addEventListener('click', stopCamera);
        }
        if (advancedModeToggle) {
            advancedModeToggle.addEventListener('change', async () => {
                if (!advancedModeToggle.checked) {
                    advancedMode = false;
                    localStorage.removeItem('reader-advanced-mode');
                    stopAdvancedDetection();
                    cameraError.style.display = 'none';
                    return;
                }

                advancedMode = true;
                localStorage.setItem('reader-advanced-mode', '1');

                if (scanning) {
                    const advancedResults = await startAdvancedDetection();
                    if (!advancedResults.zxingReady) {
                        cameraError.textContent = 'تعذر تشغيل وضع الدقة العالية. تأكد من اتصال الإنترنت ثم حاول مرة أخرى.';
                        cameraError.style.display = 'block';
                    } else {
                        cameraError.style.display = 'none';
                    }
                }
            });
        }
        window.addEventListener('beforeunload', () => {
            stopCamera();
            if (ocrWorker) {
                try {
                    ocrWorker.terminate();
                } catch (err) {
                    console.debug('Failed to terminate OCR worker', err);
                }
            }
        });

    })();
    </script>
</body>
</html>
