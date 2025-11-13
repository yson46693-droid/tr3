<?php
declare(strict_types=1);

/**
 * Provides a centralized catalog of honey varieties and their identifiers.
 */
if (!function_exists('getHoneyVarietiesCatalog')) {
    /**
     * Returns honey varieties metadata keyed by the variety label.
     */
    function getHoneyVarietiesCatalog(): array
    {
        return [
            'حبة البركة' => [
                'code' => 'KI001',
                'label' => 'حبة البركة',
            ],
            'سدر' => [
                'code' => 'KI002',
                'label' => 'سدر',
            ],
            'موالح' => [
                'code' => 'KI004',
                'label' => 'موالح',
            ],
            'نوارة برسيم' => [
                'code' => 'KI005',
                'label' => 'نوارة برسيم',
            ],
            'أخرى' => [
                'code' => 'KI999',
                'label' => 'أخرى',
            ],
        ];
    }
}

if (!function_exists('getHoneyVarietyCode')) {
    /**
     * Returns the identifier code for a given honey variety.
     */
    function getHoneyVarietyCode(string $variety): ?string
    {
        $catalog = getHoneyVarietiesCatalog();

        return $catalog[$variety]['code'] ?? null;
    }
}

if (!function_exists('formatHoneyVarietyWithCode')) {
    /**
     * Returns a human-readable label including the honey variety and its identifier.
     */
    function formatHoneyVarietyWithCode(string $variety): string
    {
        $catalog = getHoneyVarietiesCatalog();

        if (!isset($catalog[$variety])) {
            return $variety;
        }

        $entry = $catalog[$variety];

        return sprintf('%s (%s)', $entry['label'], $entry['code']);
    }
}

