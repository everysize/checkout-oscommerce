<?php

declare(strict_types=1);

namespace Everysize\Checkout\Import;

/**
 * Creates a shop order from an everysize order. Implemented by the shop
 * layer (writes orders, items, stock and the link in one transaction); the
 * core Sync only needs this contract.
 */
interface OrderImporter
{
    /**
     * @param array<string, mixed> $esOrder order as returned by the everysize API
     *
     * @return array{orderId: string, orderNumber: string, warnings: string[]}
     */
    public function import(array $esOrder): array;
}
