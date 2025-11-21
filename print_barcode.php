<?php
/**
 * صفحة طباعة الباركود - ملصق EAN-13 للطباعة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/batch_numbers.php';

requireRole(['production', 'accountant', 'manager']);

$batchNumber = $_GET['batch'] ?? '';
$quantity = isset($_GET['quantity']) ? max(1, intval($_GET['quantity'])) : 1;
$format = 'single';

if ($quantity < 1) {
    $quantity = 1;
}

if (empty($batchNumber)) {
    die('رقم التشغيلة مطلوب');
}

$batch = getBatchByNumber($batchNumber);
if (!$batch) {
    die('رقم التشغيلة غير موجود');
}

$batchNumber = trim($batchNumber);
$encodedBatchNumber = json_encode($batchNumber, JSON_UNESCAPED_UNICODE);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة باركود - <?php echo htmlspecialchars($batchNumber); ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --label-width: 58mm;
            --label-height: 40mm;
            --barcode-width: 52mm;
            --barcode-height: 28mm;
            --quiet-zone: 2.6mm;
            --font-main: 'Roboto', 'Arial', sans-serif;
        }

        @page {
            size: auto;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-main);
            background: #ffffff;
            margin: 0;
            padding: 0;
            color: #000;
            width: 100%;
            height: 100%;
        }

        .print-controls {
            display: none;
        }

        .labels-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0;
        }

        .label-wrapper {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            margin: 0;
            background: #fff;
        }

        .barcode-box {
            width: 100%;
            background: #fff;
            border: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 5mm 0 2mm 0;
            margin: 0;
        }

        .barcode-svg {
            width: 100%;
            height: auto;
            max-width: 100%;
        }

        .barcode-number {
            font-size: 20pt;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-align: center;
            margin-top: 4px;
            margin-bottom: 0;
            padding: 0;
            width: 100%;
        }

        .manual-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin: 12px 0;
        }

        .manual-actions button {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
        }

        .manual-actions .btn-primary {
            background: #1d4ed8;
            color: #fff;
        }

        .manual-actions .btn-secondary {
            background: #64748b;
            color: #fff;
        }

        @media screen {
            body {
                background: #f5f5f5;
                padding: 20px;
            }
            .print-controls {
                display: block;
                max-width: 320px;
                margin: 0 auto 20px;
                text-align: center;
                padding: 15px;
                border-radius: 8px;
                background: #fff;
                box-shadow: 0 3px 12px rgba(0,0,0,0.1);
            }

            .label-wrapper {
                margin: 0 auto;
                box-shadow: 0 10px 30px rgba(0,0,0,0.12);
                border-radius: 8px;
            }
        }

        @media print {
            * {
                margin: 0;
                padding: 0;
            }
            html, body {
                width: 100%;
                height: 100%;
                margin: 0;
                padding: 0;
                background: #fff;
            }
            .print-controls {
                display: none !important;
            }
            .labels-container {
                gap: 0;
                margin: 0;
                padding: 0;
            }
            .label-wrapper {
                box-shadow: none;
                border-radius: 0;
                margin: 0;
                padding: 0;
                page-break-after: auto;
                page-break-inside: avoid;
            }
            .barcode-box {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <h4>طباعة باركود تشغيلة</h4>
        <p>رقم التشغيلة: <?php echo htmlspecialchars($batchNumber); ?></p>
        <div class="manual-actions">
            <button class="btn-primary" onclick="window.print()">طباعة</button>
            <button class="btn-secondary" onclick="window.close()">إغلاق</button>
        </div>
    </div>

    <div class="labels-container">
        <?php for ($i = 0; $i < $quantity; $i++): ?>
            <div class="label-wrapper">
                <div class="barcode-box">
                    <svg class="barcode-svg" role="img" aria-label="Batch barcode"></svg>
                    <div class="barcode-number"></div>
                </div>
            </div>
        <?php endfor; ?>
    </div>

    <script>
        const batchNumberValue = <?php echo $encodedBatchNumber; ?>;

        function renderBarcodes() {
            if (!batchNumberValue) {
                return;
            }
            const barcodeNodes = document.querySelectorAll('.barcode-svg');
            const numberNodes = document.querySelectorAll('.barcode-number');
            if (numberNodes.length) {
                numberNodes.forEach(node => node.textContent = batchNumberValue);
            }
            try {
                JsBarcode('.barcode-svg', batchNumberValue, {
                    format: 'CODE128',
                    background: '#ffffff',
                    lineColor: '#000000',
                    margin: 0,
                    width: 1,
                    height: 60,
                    displayValue: false,
                    flat: true
                });
            } catch (error) {
                console.error('Barcode render error', error);
                barcodeNodes.forEach(node => {
                    node.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#000">Barcode Error</text>';
                });
            }
        }

        renderBarcodes();

        if (window.location.search.includes('print=1')) {
            setTimeout(function() {
                window.print();
            }, 400);
        }
    </script>
</body>
</html>

