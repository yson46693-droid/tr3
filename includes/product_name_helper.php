<?php
/**
 * وظائف مساعدة للتعامل مع أسماء المنتجات والتأكد من ظهور الأسماء الحقيقية فقط.
 */

if (!function_exists('isPlaceholderProductName')) {
    /**
     * يتحقق مما إذا كان الاسم يمثل قيمة افتراضية مثل "منتج رقم X" أو "منتج غير معروف".
     */
    function isPlaceholderProductName(?string $name): bool
    {
        if ($name === null) {
            return true;
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            return true;
        }

        if (preg_match('/^منتج رقم\s+\d+$/u', $trimmed)) {
            return true;
        }

        static $placeholders = [
            'منتج غير معروف',
            'منتج غير محدد',
            'غير محدد',
            'منتج'
        ];

        return in_array($trimmed, $placeholders, true);
    }
}

if (!function_exists('resolveProductName')) {
    /**
     * يعيد أول اسم صالح من قائمة الأسماء المقترحة، أو رسالة افتراضية في حال عدم توفر أي اسم حقيقي.
     *
     * @param array<int, string|null> $candidates
     * @param string $fallback
     */
    function resolveProductName(array $candidates, string $fallback = 'اسم المنتج غير متوفر'): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed === '' || isPlaceholderProductName($trimmed)) {
                continue;
            }

            return $trimmed;
        }

        return $fallback;
    }
}



