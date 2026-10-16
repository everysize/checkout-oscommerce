<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * The uprid of an osCommerce 4 variant: "<prid>{<optionsId>}<valuesId>",
 * one option/value pair per classic option — e.g. "1{1}5" (product 1,
 * option 1, value 5) or "1{1}5{2}3" (two options). The importer writes one
 * orders_products_attributes row per pair.
 */
final class Uprid
{
    /**
     * All option/value pairs of an uprid, in order.
     *
     * @return list<array{optionsId: int, valuesId: int}>
     */
    public static function pairs(string $uprid): array
    {
        if (!preg_match('/^(\d+)((?:\{(\d+)\}(\d+))+)$/', $uprid, $m)) {
            return [];
        }

        $pairs = [];
        if (preg_match_all('/\{(\d+)\}(\d+)/', $m[2], $all, \PREG_SET_ORDER)) {
            foreach ($all as $pair) {
                $pairs[] = ['optionsId' => (int) $pair[1], 'valuesId' => (int) $pair[2]];
            }
        }

        return $pairs;
    }

    /** True when the uprid carries at least one option/value pair (a variant). */
    public static function isVariant(string $uprid): bool
    {
        return self::pairs($uprid) !== [];
    }
}
