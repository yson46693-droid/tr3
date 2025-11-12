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
            size: var(--label-width) var(--label-height);
            margin: 0;
        }

        body {
            font-family: var(--font-main);
            background: #ffffff;
            width: var(--label-width);
            height: var(--label-height);
            margin: 0 auto;
            padding: 0;
            color: #000;
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
            width: var(--label-width);
            height: var(--label-height);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2mm;
            background: #fff;
        }

        .barcode-box {
            width: var(--barcode-width);
            height: var(--barcode-height);
            background: #fff;
            border: 0.25mm solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 var(--quiet-zone);
            margin-bottom: 2mm;
        }

        .barcode-svg {
            width: calc(var(--barcode-width) - (var(--quiet-zone) * 2));
            height: calc(var(--barcode-height) - 5mm);
        }

        .barcode-number {
            font-size: 9pt;
            font-weight: 600;
            letter-spacing: 1px;
            text-align: center;
            margin-top: 1mm;
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
            body {
                background: #fff;
            }
            .labels-container {
                gap: 0;
            }
            .label-wrapper {
                box-shadow: none;
                border-radius: 0;
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
                </div>
                <div class="barcode-number"></div>
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
                    margin: 8,
                    width: 1.25,
                    height: 90,
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

