<?php

/**
 * everysize Checkout — order list hook (orders/orderlist).
 *
 * Included by OrdersController::actionOrderlist (lib/backend/controllers/
 * OrdersController.php) in its scope: $orders_query_raw (the orders query,
 * alias "o"), $output (the parsed filter form), $search_condition (the raw
 * keyword-search SQL the controller embedded into the WHERE) and $_session
 * are available.
 *
 * 1. The everysize order number becomes searchable: the general search box
 *    matches it (exact match first — the LIKE is just a convenience). The
 *    order number lives in everysize_checkout_order, which the fixed
 *    $searchFields list cannot reach. The controller has already ANDed its
 *    keyword search into the query — matching everysize orders must be
 *    found whether or not they match the keyword fields, so the keyword
 *    condition becomes "(keyword search OR orders_id IN (everysize
 *    matches))". The matching ids are fetched parameterized.
 * 2. The "everysize orders only" filter from the filter panel
 *    (filters.orders.filter-1-column.tpl) restricts the list — a real
 *    filter, so it is ANDed.
 *
 * The list marks everysize orders via their payment method: the order
 * description cell renders "via everysize (PayPal)" (TEXT_VIA).
 */

declare(strict_types=1);

/** @var \yii\db\ActiveQuery $orders_query_raw */
/** @var array $output */
/** @var string $search_condition */

$ecSearch = trim((string) ($_GET['search']['value'] ?? ''));
$ecOnly = !empty($output['everysize_only']);

if ($ecOnly) {
    $orders_query_raw->leftJoin('everysize_checkout_order eco', 'eco.orders_id = o.orders_id');
    //andWhere, NOT andFilterWhere: a null operand is exactly what this
    // condition is, and andFilterWhere() drops conditions with null operands.
    $orders_query_raw->andWhere(['IS NOT', 'eco.orders_id', null]);
}

if ($ecSearch !== '' && ($search_condition ?? '') !== '') {
    $ecMatches = \Yii::$app->db->createCommand(
        'SELECT orders_id FROM everysize_checkout_order WHERE everysize_order_id = :exact OR everysize_order_id LIKE :kw',
        [':exact' => $ecSearch, ':kw' => '%' . $ecSearch . '%']
    )->queryColumn();

    if ($ecMatches !== []) {
        $in = implode(',', array_map('intval', $ecMatches));
        // $search_condition looks like " and ( field LIKE '%kw%' or ... )":
        // turn it into " and ( ( field ... ) or o.orders_id IN (…) )".
        $inner = substr($search_condition, 7, -1);
        $orders_query_raw->where = str_replace(
            $search_condition,
            ' and ( ( ' . $inner . ' ) or o.orders_id IN (' . $in . ') )',
            (string) $orders_query_raw->where
        );
        // The order detail navigation (process-order) reuses the session
        // condition for next/prev — keep it consistent with the list.
        $_session->set('search_condition', ' and ( ( ' . $inner . ' ) or o.orders_id IN (' . $in . ') )');
    }
}
