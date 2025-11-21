<?php
/**
 * نظام الباركود المبسط
 * يستخدم JsBarcode (JavaScript) - لا يحتاج API خارجي
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/path_helper.php';

/**
 * توليد باركود بصيغة Code128 أو QR Code
 */
function generateBarcode($batchNumber, $format = 'barcode') {
    if ($format === 'qr') {
        return generateQRCode($batchNumber);
    } else {
        // استخدام JsBarcode في JavaScript
        return generateBarcodeHTML($batchNumber);
    }
}

/**
 * توليد QR Code باستخدام QR Server API (بسيط)
 */
function generateQRCode($batchNumber, $size = 200) {
    $detailsUrl = getAbsoluteUrl('view_batch.php?batch=' . urlencode($batchNumber));
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($detailsUrl);
    return '<img src="' . htmlspecialchars($qrUrl) . '" alt="QR Code: ' . htmlspecialchars($batchNumber) . '" style="max-width: 100%; height: auto;" />';
}

/**
 * توليد باركود HTML باستخدام JsBarcode
 */
function generateBarcodeHTML($batchNumber, $width = 2, $height = 100) {
    $batchNumber = trim($batchNumber);
    $id = 'barcode_' . md5($batchNumber . time());
    
    return '<svg id="' . $id . '" class="barcode-svg"></svg>
<script>
if (typeof JsBarcode !== "undefined") {
    JsBarcode("#' . $id . '", "' . htmlspecialchars($batchNumber, ENT_QUOTES) . '", {
        format: "CODE128",
        width: ' . $width . ',
        height: ' . $height . ',
        displayValue: true,
        fontSize: 14,
        margin: 10,
        background: "#ffffff",
        lineColor: "#000000"
    });
} else {
    // Fallback: عرض النص فقط
    document.getElementById("' . $id . '").innerHTML = \'<text x="50%" y="50%" text-anchor="middle" font-size="16" fill="#000">' . htmlspecialchars($batchNumber, ENT_QUOTES) . '</text>\';
}
</script>';
}

/**
 * توليد SVG مباشر (للطباعة بدون JavaScript)
 */
function generateBarcodeSVG($batchNumber, $width = 300, $height = 100) {
    $batchNumber = trim($batchNumber);
    
    // استخدام API بسيط كبديل
    $barcodeUrl = 'https://barcode.tec-it.com/barcode.ashx?data=' . urlencode($batchNumber) . '&code=Code128&translate-esc=on&dpi=96&imagetype=svg&dmsize=Default';
    
    // محاولة جلب الباركود من API (بسيط)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $barcodeUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $svgContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && !empty($svgContent) && strpos($svgContent, '<svg') !== false) {
            $svgContent = preg_replace('/width="[^"]*"/', 'width="' . $width . '"', $svgContent);
            $svgContent = preg_replace('/height="[^"]*"/', 'height="' . $height . '"', $svgContent);
            return $svgContent;
        }
    }
    
    // Fallback: SVG بسيط جداً
    return generateSimpleBarcodeSVG($batchNumber, $width, $height);
}

/**
 * توليد باركود SVG بسيط (Fallback)
 */
function generateSimpleBarcodeSVG($batchNumber, $width = 300, $height = 100) {
    $text = trim($batchNumber);
    $barHeight = $height - 25;
    
    $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="' . $width . '" height="' . $height . '" fill="white"/>';
    
    // نمط بسيط: أشرطة متناوبة
    $barWidth = 3;
    $x = ($width - (strlen($text) * $barWidth * 2)) / 2;
    
    for ($i = 0; $i < strlen($text); $i++) {
        $code = ord($text[$i]);
        $pattern = str_pad(decbin($code % 8), 3, '0', STR_PAD_LEFT);
        
        foreach (str_split($pattern) as $bit) {
            if ($bit === '1') {
                $svg .= '<rect x="' . $x . '" y="0" width="' . $barWidth . '" height="' . $barHeight . '" fill="black"/>';
            }
            $x += $barWidth;
        }
        $x += $barWidth; // مسافة بين الأحرف
    }
    
    // النص
    $svg .= '<text x="' . ($width / 2) . '" y="' . ($height - 5) . '" text-anchor="middle" font-family="Arial" font-size="14" font-weight="bold" fill="black">' . htmlspecialchars($text) . '</text>';
    $svg .= '</svg>';
    
    return $svg;
}

/**
 * حفظ باركود كصورة
 */
function saveBarcodeImage($batchNumber, $format = 'svg') {
    $svg = generateBarcodeSVG($batchNumber);
    $filePath = REPORTS_PATH . 'barcodes/' . $batchNumber . '.svg';
    
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    @file_put_contents($filePath, $svg);
    return $filePath;
}

