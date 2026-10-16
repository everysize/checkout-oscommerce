<?php

declare(strict_types=1);

/**
 * everysize Checkout — the optional order confirmation mail.
 *
 * Uses the shop's own mail stack (\common\helpers\Mail::send — SMTP/mail
 * settings from the platform configuration, so mails land in Mailpit in the
 * demo shop). Only sent after a successful import when "Send order
 * confirmation" is on; never for REVOKED orders. Also runs in the console
 * boot (the cron container's CLI sync) — a failure is a warning, not an
 * error (the order exists and is linked).
 */

namespace Everysize\Checkout\osCommerce;

use common\extensions\EverysizeCheckout\EverysizeCheckout;

class OrderMailer
{
    public function sendConfirmation(string $ordersId): void
    {
        $order = \common\models\Orders::findOne(['orders_id' => (int) $ordersId]);
        if (!$order instanceof \common\models\Orders) {
            return;
        }

        $lines = \common\models\OrdersProducts::find()
            ->where(['orders_id' => (int) $ordersId])
            ->orderBy('orders_products_id')
            ->asArray()->all();

        $items = '';
        foreach ($lines as $line) {
            $items .= sprintf("%s x %s\n", (int) $line['products_quantity'], (string) $line['products_name']);
        }
        $total = \common\models\OrdersTotal::find()
            ->where(['orders_id' => (int) $ordersId, 'class' => 'ot_total'])
            ->scalar();

        // The everysize order number (from the links table), the status line
        // shows the order's current state next to it.
        $db = new Db();
        $everysizeOrderId = (string) ($db->fetchValue(
            'SELECT everysize_order_id FROM everysize_checkout_order WHERE orders_id = :id LIMIT 1',
            [':id' => $ordersId]
        ) ?? '');
        $statusName = (string) \common\models\OrdersStatus::find()->select('orders_status_name')->where([
            'orders_status_id' => (int) $order->orders_status,
            'language_id' => (int) $order->language_id,
        ])->scalar();

        // Store identity via the platform configuration — the plain
        // STORE_NAME/STORE_OWNER constants are only defined in the web boot,
        // not in the console (cron/CLI) boot.
        $platformConfig = \Yii::$app->get('platform')->config((int) $order->platform_id);
        $storeName = (string) $platformConfig->const_value('STORE_NAME');
        if ($storeName === '') {
            $storeName = (string) (\common\models\Platforms::find()
                ->select('platform_name')
                ->where(['platform_id' => (int) $order->platform_id])
                ->scalar() ?: 'our shop');
        }
        $storeOwner = (string) ($platformConfig->const_value('STORE_OWNER') ?: $storeName);
        $storeOwnerEmail = (string) $platformConfig->const_value('STORE_OWNER_EMAIL_ADDRESS');

        $orderNumber = !empty($order->order_number) ? $order->order_number : $order->orders_id;
        $subject = sprintf('%s — order %s', $storeName, $orderNumber);
        $text = sprintf(
            "Hello %s,\n\nthank you for your order.\n\nOrder number: %s\nOrder date: %s\n\n%s\nTotal: %s\n\nYour everysize order: %s (current status: %s)\n\n%s",
            (string) $order->customers_firstname,
            $orderNumber,
            (string) $order->date_purchased,
            $items,
            (string) $total,
            $everysizeOrderId !== '' ? $everysizeOrderId : '—',
            $statusName,
            $storeName
        );

        \common\helpers\Mail::send(
            (string) $order->customers_name,
            (string) $order->customers_email_address,
            $subject,
            $text,
            $storeOwner,
            $storeOwnerEmail
        );
    }
}
