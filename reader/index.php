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
    <link rel="apple-touch-icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAgAAAAIACAIAAAB7GkOtAAAACXBIWXMAAAsSAAALEgHS3X78AAAFwUlEQVR4nO3QMQEAAADCoPVPbQhPoAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAOBeeAAGmEuBSAAAAAElFTkSuQmCC">
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
            .reader-wrapper {
                padding: 28px;
                border-radius: 18px;
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
            }
            .input-group {
                flex-direction: column;
                align-items: stretch;
            }
            .input-group button {
                width: 100%;
            }
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
        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferredPrompt = event;
            installButton.style.display = 'inline-flex';
        });

        installButton.addEventListener('click', async () => {
            if (!deferredPrompt) {
                return;
            }
            installButton.disabled = true;
            try {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    installButton.textContent = 'تم تثبيت التطبيق';
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
    })();
    </script>
</body>
</html>
