<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Import\ImportException;

/**
 * SKU → orderable unit. An inventory row (variant, the classic options
 * combination) wins — everysize SKUs are the purchasable variant —, the bare
 * product (products_model) is the fallback. Case-sensitive matches beat
 * case-insensitive ones, active products beat inactive ones; two equally
 * good candidates are an error ("not unique").
 *
 * The SQL relies on the shop's case-insensitive collation for the WHERE
 * match and flags the exact-case hits itself (BINARY compare), so one query
 * covers both cases.
 */
final class SkuResolver
{
    private DbInterface $db;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{productsId: string, inventoryId: ?string, uprid: string, taxClassId: string, model: string, isVariant: bool}
     */
    public function resolve(string $sku): array
    {
        $rows = $this->db->fetchRows(
            'SELECT p.products_id AS PRODUCTS_ID, p.products_status AS PRODUCTS_STATUS,
                    p.products_tax_class_id AS TAX_CLASS_ID, p.products_model AS PRODUCTS_MODEL,
                    NULL AS INVENTORY_ID, NULL AS UPRID, 0 AS IS_VARIANT, 0 AS NON_EXISTENT,
                    (BINARY p.products_model = BINARY :sku) AS EXACT_MATCH
             FROM products p
             WHERE p.products_model = :sku
             UNION ALL
             SELECT p.products_id, p.products_status, p.products_tax_class_id,
                    COALESCE(NULLIF(i.products_model, \'\'), p.products_model),
                    i.inventory_id, i.products_id, 1, i.non_existent,
                    (BINARY i.products_model = BINARY :sku)
             FROM inventory i
             JOIN products p ON p.products_id = i.prid
             WHERE i.products_model = :sku',
            [':sku' => $sku]
        );
        if ($rows === []) {
            throw new ImportException(sprintf('SKU %s not found in the shop', $sku));
        }

        // Exact case first, then the active article (a variant with
        // non_existent = 1 counts as inactive), then the variant.
        usort($rows, static function (array $a, array $b): int {
            return [(int) $b['EXACT_MATCH'], (int) $b['PRODUCTS_STATUS'], (int) ((int) $b['IS_VARIANT'] === 1 ? 1 - (int) $b['NON_EXISTENT'] : 1), (int) $b['IS_VARIANT']]
                <=> [(int) $a['EXACT_MATCH'], (int) $a['PRODUCTS_STATUS'], (int) ((int) $a['IS_VARIANT'] === 1 ? 1 - (int) $a['NON_EXISTENT'] : 1), (int) $a['IS_VARIANT']];
        });
        if (count($rows) > 1 && self::rank($rows[0]) === self::rank($rows[1])) {
            throw new ImportException(sprintf('SKU %s is not unique in the shop', $sku));
        }

        $row = $rows[0];
        $isVariant = (int) $row['IS_VARIANT'] === 1;

        return [
            'productsId' => (string) $row['PRODUCTS_ID'],
            'inventoryId' => $isVariant ? (string) $row['INVENTORY_ID'] : null,
            'uprid' => $isVariant ? (string) $row['UPRID'] : '',
            'taxClassId' => (string) $row['TAX_CLASS_ID'],
            'model' => (string) $row['PRODUCTS_MODEL'],
            'isVariant' => $isVariant,
        ];
    }

    /** @return array<int, int> */
    private static function rank(array $row): array
    {
        $active = (int) $row['PRODUCTS_STATUS'] === 1
            && ((int) $row['IS_VARIANT'] !== 1 || (int) $row['NON_EXISTENT'] !== 1);

        return [(int) $row['EXACT_MATCH'], $active ? 1 : 0, (int) $row['IS_VARIANT']];
    }
}
