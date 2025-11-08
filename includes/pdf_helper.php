<?php
/**
 * أدوات مساعدة لإنشاء ملفات PDF عبر خدمة aPDF.io مع دعم كامل للعربية و RTL
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (!function_exists('curl_init')) {
    throw new RuntimeException('امتداد cURL غير مُفعل. يرجى تفعيله لاستخدام aPDF.io.');
}

if (!defined('APDF_IO_API_KEY') || APDF_IO_API_KEY === '') {
    throw new RuntimeException('مفتاح aPDF.io غير مُعرّف. يرجى ضبط القيمة APDF_IO_API_KEY.');
}

/**
 * إرسال HTML إلى aPDF.io واستلام المحتوى الثنائي لملف PDF.
 *
 * @param string $html نص HTML الكامل (بما في ذلك <html> و <head>)
 * @param array<string, mixed> $options خيارات إضافية يتم تمريرها إلى aPDF.io (مثل الهوامش أو الاتجاه)
 *
 * @throws RuntimeException عند فشل الطلب أو عدم الحصول على محتوى PDF صالح
 *
 * @return string محتوى PDF ثنائي
 */
function apdfGeneratePdf(string $html, array $options = []): string
{
    $endpoint = defined('APDF_IO_ENDPOINT') ? APDF_IO_ENDPOINT : 'https://api.apdf.io/v1/pdf/html';

    $payload = [
        'html' => $html,
        'options' => array_merge([
            'pageSize' => 'A4',
            'printBackground' => true,
            'scale' => 1,
            'margin' => [
                'top' => '15mm',
                'right' => '12mm',
                'bottom' => '15mm',
                'left' => '12mm',
            ],
        ], $options),
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        throw new RuntimeException('تعذر ترميز بيانات PDF إلى JSON.');
    }

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/pdf',
            'X-API-KEY: ' . APDF_IO_API_KEY,
        ],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('خطأ في الاتصال بخدمة aPDF.io: ' . $error);
    }

    $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = (string)(curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?? '');
    curl_close($curl);

    if ($statusCode >= 400 || stripos($contentType, 'application/pdf') === false) {
        $preview = function_exists('mb_substr')
            ? mb_substr($response, 0, 400, 'UTF-8')
            : substr($response, 0, 400);

        throw new RuntimeException(
            'فشل إنشاء ملف PDF عبر aPDF.io. رمز الاستجابة: '
            . $statusCode
            . '. معاينة الاستجابة: '
            . $preview
        );
    }

    return $response;
}

/**
 * حفظ ملف PDF من خدمة aPDF.io في مسار محدد.
 *
 * ملاحظة: لتغيير محتوى الملف أو الخطوط العربية، قم بتعديل نص HTML الذي تقوم بتمريره لهذه الدالة.
 *
 * @param string $html
 * @param string $filePath
 * @param array<string, mixed> $options
 * @return string المسار النهائي لملف PDF
 */
function apdfSavePdfToPath(string $html, string $filePath, array $options = []): string
{
    $pdfBinary = apdfGeneratePdf($html, $options);

    if (@file_put_contents($filePath, $pdfBinary) === false) {
        throw new RuntimeException('تعذر حفظ ملف PDF في المسار: ' . $filePath);
    }

    return $filePath;
}

/**
 * إرسال ملف PDF مباشرة إلى المتصفح مع الترويسات المناسبة.
 *
 * @param string $html
 * @param string $fileName اسم الملف المعروض للزائر (يمكن تعديله قبل استدعاء الدالة)
 * @param array<string, mixed> $options
 * @param bool $forceDownload استخدم true لفرض التحميل بدلاً من العرض داخل المتصفح
 * @return void
 */
function apdfStreamPdfToBrowser(string $html, string $fileName = 'document.pdf', array $options = [], bool $forceDownload = false): void
{
    $pdfBinary = apdfGeneratePdf($html, $options);

    header('Content-Type: application/pdf; charset=utf-8');
    header(($forceDownload ? 'Content-Disposition: attachment; filename="' : 'Content-Disposition: inline; filename="') . $fileName . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    header('Cache-Control: private, must-revalidate, max-age=0');

    echo $pdfBinary;
    exit;
}
