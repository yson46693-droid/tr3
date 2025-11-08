<?php
session_start([
    'cookie_lifetime' => 0,
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

$sessionTimeout = 15 * 60; // 15 minutes
$now = time();

if (isset($_SESSION['reader_last_activity']) && ($now - $_SESSION['reader_last_activity']) > $sessionTimeout) {
    session_unset();
    session_destroy();
    session_start([
        'cookie_lifetime' => 0,
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'use_strict_mode' => true,
    ]);
}

$_SESSION['reader_last_activity'] = $now;
$_SESSION['reader_session_id'] = $_SESSION['reader_session_id'] ?? bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1d4ed8">
    <link rel="manifest" href="manifest.json">
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
            flex: 1 1 320px;
            padding: 14px 46px 14px 16px;
            border: 1px solid rgba(59, 130, 246, 0.35);
            border-radius: 14px;
            font-size: 1.05rem;
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
                max-width: 320px;
                padding: 10px 14px;
                font-size: 1rem;
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
    </style>
</head>
<body>
    <div class="reader-wrapper">
        <div class="branding">
            <img src="assets/icon.svg" alt="شعار القارئ" loading="lazy">
            <div>
                <h1>قارئ باركود التشغيلات</h1>
                <p>حل سريع وآمن للوصول إلى بيانات التشغيلات باستخدام أجهزة سطح المكتب أو الهواتف الذكية.</p>
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
        </section>
        <form id="scannerForm" autocomplete="off">
            <label for="batchInput">رقم التشغيلة أو الباركود</label>
            <div class="input-group">
                <input type="text" id="batchInput" name="batch" placeholder="قم بمسح الباركود أو إدخال رقم التشغيلة يدويًا" required autofocus>
                <button type="submit" id="scanButton">
                    <span>قراءة التفاصيل</span>
                </button>
            </div>
        </form>
        <div class="results" id="resultsContainer" hidden>
            <div id="feedbackArea"></div>
            <div class="card" id="batchSummary" hidden></div>
            <div class="card" id="materialsCard" hidden></div>
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

        let currentStream = null;
        let scanning = false;
        let detectionCooldown = false;
        let barcodeInterval = null;
        let barcodeProcessing = false;
        let ocrInterval = null;
        let ocrProcessing = false;
        let barcodeDetectorInstance = null;
        let tesseractScriptPromise = null;
        let tesseractWorkerPromise = null;
        let ocrWorker = null;

        const historyEntries = [];

        function setLoading(isLoading) {
            scanButton.disabled = isLoading;
            scanButton.textContent = isLoading ? 'جاري البحث...' : 'قراءة التفاصيل';
        }

        function renderError(message) {
            resultsContainer.hidden = false;
            batchSummary.hidden = true;
            materialsCard.hidden = true;
            workersCard.hidden = true;
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

            const summaryRows = [
                ['رقم التشغيلة', data.batch_number ?? '—'],
                ['المنتج', data.product_name ?? '—'],
                ['الفئة', data.product_category ?? '—'],
                ['التاريخ', data.production_date ?? '—'],
                ['الكمية', data.quantity ?? '—'],
                ['الحالة', data.status_label ?? '—'],
            ];

            if (data.honey_supplier_name) {
                summaryRows.push(['مورد العسل', data.honey_supplier_name]);
            }
            if (data.packaging_supplier_name) {
                summaryRows.push(['مورد التعبئة', data.packaging_supplier_name]);
            }
            if (data.created_by_name) {
                summaryRows.push(['تم الإنشاء بواسطة', data.created_by_name]);
            }
            if (data.notes) {
                summaryRows.push(['ملاحظات', data.notes]);
            }

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

            if (Array.isArray(data.materials) && data.materials.length) {
                materialsCard.hidden = false;
                materialsCard.innerHTML = `
                    <h2>مواد التعبئة المستخدمة</h2>
                    <table>
                        ${data.materials.map(item => `
                            <tr>
                                <th>${item.name ?? '—'}</th>
                                <td>${item.details ?? ''}</td>
                            </tr>
                        `).join('')}
                    </table>
                `;
            } else {
                materialsCard.hidden = true;
                materialsCard.innerHTML = '';
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

        function stopDetectionLoops() {
            if (barcodeInterval) {
                clearInterval(barcodeInterval);
                barcodeInterval = null;
            }
            if (ocrInterval) {
                clearInterval(ocrInterval);
                ocrInterval = null;
            }
            barcodeProcessing = false;
            ocrProcessing = false;
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
            const cleaned = (value || '').replace(/[^0-9A-Z\-]/gi, '').trim();
            return cleaned.length >= 4 ? cleaned.toUpperCase() : '';
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
                if (!barcodeReady) {
                    const ocrReady = await startOcrDetection();
                    if (!ocrReady) {
                        cameraError.textContent = 'تعذر تشغيل آلية قراءة الباركود. يرجى المحاولة لاحقًا أو استخدام الإدخال اليدوي.';
                        cameraError.style.display = 'block';
                        stopCamera();
                    }
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

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const batchNumber = batchInput.value.trim();
            if (!batchNumber) {
                renderError('يرجى إدخال رقم التشغيلة أو مسح الباركود.');
                return;
            }

            setLoading(true);
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ batch_number: batchNumber }),
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    renderError(data.message ?? 'تعذر استرجاع بيانات رقم التشغيلة.');
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
