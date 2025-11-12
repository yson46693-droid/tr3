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
$format = $_GET['format'] ?? 'single';

if ($quantity < 1) {
    $quantity = 1;
}

if ($format !== 'single') {
    $format = 'single';
}

if (empty($batchNumber)) {
    die('رقم التشغيلة مطلوب');
}

$batch = getBatchByNumber($batchNumber);
if (!$batch) {
    die('رقم التشغيلة غير موجود');
}

/**
 * حساب رقم التحقق EAN-13
 */
function calculateEan13Checksum(string $digits12): int
{
    $digits = array_map('intval', str_split($digits12));
    $sumEven = 0;
    $sumOdd = 0;
    foreach ($digits as $index => $digit) {
        if (($index % 2) === 0) {
            $sumOdd += $digit;
        } else {
            $sumEven += $digit;
        }
    }
    $total = ($sumEven * 3) + $sumOdd;
    $nearestTen = ceil($total / 10) * 10;
    return ($nearestTen - $total) % 10;
}

/**
 * تحويل رقم التشغيلة إلى رمز EAN-13 صالح
 */
function convertBatchNumberToEan13(string $batchNumber): array
{
    $original = trim($batchNumber);
    $digitsOnly = preg_replace('/\D+/', '', $original);

    if ($digitsOnly === '') {
        $digitsOnly = (string) abs(crc32($original));
    }

    if (strlen($digitsOnly) >= 12) {
        $digitsOnly = substr($digitsOnly, 0, 12);
    } else {
        $digitsOnly = str_pad($digitsOnly, 12, '0', STR_PAD_RIGHT);
    }

    $checksum = calculateEan13Checksum($digitsOnly);
    $ean13 = $digitsOnly . $checksum;

    return [
        'ean13' => $ean13,
        'digits_only' => $digitsOnly,
        'checksum' => $checksum,
        'display_number' => $ean13,
    ];
}

$eanData = convertBatchNumberToEan13($batchNumber);
$labelProductName = $batch['product_name'] ?? 'تشغيلة إنتاج';
$productionDate = !empty($batch['production_date']) ? formatDate($batch['production_date']) : '';

$eanValueForJs = json_encode($eanData['ean13'], JSON_UNESCAPED_UNICODE);
$displayNumberForJs = json_encode($eanData['display_number'], JSON_UNESCAPED_UNICODE);
$productNameJs = json_encode($labelProductName, JSON_UNESCAPED_UNICODE);
$productionDateJs = json_encode($productionDate, JSON_UNESCAPED_UNICODE);
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
            --barcode-width: 32mm;
            --barcode-height: 22mm;
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

        .label-wrapper {
            width: var(--label-width);
            height: var(--label-height);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 4mm 3mm;
            background: #fff;
        }

        .label-header {
            width: 100%;
            text-align: center;
            margin-bottom: 3mm;
        }

        .label-header h2 {
            font-size: 10pt;
            font-weight: 600;
            margin-bottom: 1mm;
        }

        .label-header .meta {
            font-size: 7.5pt;
            color: #333;
        }

        .barcode-box {
            width: var(--barcode-width);
            height: var(--barcode-height);
            padding: 0 var(--quiet-zone);
            background: #fff;
            border: 0.2mm solid #d0d0d0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .barcode-svg {
            width: calc(var(--barcode-width) - (var(--quiet-zone) * 2));
            height: calc(var(--barcode-height) - 6mm);
        }

        .barcode-number {
            font-size: 8.5pt;
            font-weight: 600;
            letter-spacing: 1px;
            text-align: center;
            margin-top: 2mm;
        }

        .label-footer {
            margin-top: 2mm;
            font-size: 7pt;
            color: #333;
            text-align: center;
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

    <div class="label-wrapper">
        <div class="label-header">
            <h2><?php echo htmlspecialchars($labelProductName); ?></h2>
            <?php if (!empty($productionDate)): ?>
                <div class="meta">تاريخ الإنتاج: <?php echo htmlspecialchars($productionDate); ?></div>
            <?php endif; ?>
        </div>

        <div class="barcode-box">
            <svg id="batchBarcode" class="barcode-svg" role="img" aria-label="EAN-13 barcode"></svg>
        </div>

        <div class="barcode-number" id="barcodeNumberText"></div>

        <div class="label-footer">
            رقم التشغيلة (EAN-13): <?php echo htmlspecialchars($eanData['display_number']); ?>
        </div>
    </div>

    <script>
        const eanValue = <?php echo $eanValueForJs; ?>;
        const displayNumber = <?php echo $displayNumberForJs; ?>;

        function renderBarcode() {
            const target = document.getElementById('batchBarcode');
            const numberNode = document.getElementById('barcodeNumberText');
            if (!target || !eanValue) {
                if (numberNode) {
                    numberNode.textContent = displayNumber || '';
                }
                return;
            }
            try {
                JsBarcode(target, eanValue, {
                    format: 'EAN13',
                    background: '#ffffff',
                    lineColor: '#000000',
                    margin: 0,
                    width: 1.1,
                    height: 80,
                    displayValue: false,
                    flat: true
                });
                if (numberNode) {
                    numberNode.textContent = displayNumber || '';
                }
            } catch (error) {
                console.error('Barcode render error', error);
                if (numberNode) {
                    numberNode.textContent = displayNumber || eanValue;
                }
                target.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#000">EAN-13 غير مدعوم</text>';
            }
        }

        renderBarcode();

        if (window.location.search.includes('print=1')) {
            setTimeout(function() {
                window.print();
            }, 400);
        }
    </script>
</body>
</html>

