<?php

/**
 * everysize Checkout — module settings stored in the shop database.
 *
 * The values live in the module's own table everysize_checkout_setting
 * (installed by Setup). That keeps them independent of platforms and of
 * osCommerce configuration caches: Deinstallation never touches this table,
 * so credentials and settings survive remove/install — same behaviour as
 * the other everysize modules. The secret is only ever written to the DB, never
 * rendered back to the browser (empty field on submit keeps the stored
 * value, see Admin).
 */

declare(strict_types=1);

namespace Everysize\Checkout\osCommerce;

use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Settings;

class SettingsImpl implements Settings
{
    /** Carrier fallback (18 carriers exist at everysize; DHL is the common one). */
    public const DEFAULT_CARRIER = 'DHL';
    /** Default: osCommerce order status 2 "Processing" (paid, in progress). */
    public const DEFAULT_IMPORT_STATUS = 2;
    /** Default: osCommerce order status 3 "Delivered". */
    public const DEFAULT_SHIPPING_STATUS = 3;
    /** Default: osCommerce order status 5 "Canceled". */
    public const DEFAULT_CANCELLATION_STATUS = 5;

    private DbInterface $db;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    public function get(string $name, string $default = ''): string
    {
        $value = $this->db->fetchValue(
            'SELECT `value` FROM everysize_checkout_setting WHERE `name` = :name LIMIT 1',
            [':name' => $name]
        );

        return $value === null ? $default : $value;
    }

    public function set(string $name, string $value): void
    {
        $this->db->execute(
            'INSERT INTO everysize_checkout_setting (`name`, `value`) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [':name' => $name, ':value' => $value]
        );
    }

    public function delete(string $name): void
    {
        $this->db->execute('DELETE FROM everysize_checkout_setting WHERE `name` = :name', [':name' => $name]);
    }

    public function getClientId(): string
    {
        return $this->credential('client_id', 'EVERYSIZE_CHECKOUT_CLIENT_ID');
    }

    public function getClientSecret(): string
    {
        return $this->credential('client_secret', 'EVERYSIZE_CHECKOUT_CLIENT_SECRET');
    }

    /**
     * The module table first; a value an earlier deployment left in the
     * shop's platforms_configuration (platform_id 0, EVERYSIZE_CHECKOUT_*) is
     * the fallback. The value only ever travels into the API call — it is
     * never rendered, logged or echoed.
     */
    private function credential(string $name, string $legacyKey): string
    {
        $value = $this->get($name);
        if ($value !== '') {
            return $value;
        }

        return (string) ($this->db->fetchValue(
            'SELECT configuration_value FROM platforms_configuration
             WHERE configuration_key = :key AND platform_id = 0
             ORDER BY configuration_id DESC LIMIT 1',
            [':key' => $legacyKey]
        ) ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    public function getCarrier(): string
    {
        $carrier = $this->get('carrier', self::DEFAULT_CARRIER);

        return $carrier !== '' ? $carrier : self::DEFAULT_CARRIER;
    }

    public function isAutoRefundDecline(): bool
    {
        return $this->get('auto_refund_decline', 'true') === 'true';
    }

    public function getAutoRefundSince(): string
    {
        return $this->get('auto_refund_since');
    }

    /** @return list<int> */
    public function getShippingStatuses(): array
    {
        return $this->statusList('shipping_statuses', self::DEFAULT_SHIPPING_STATUS);
    }

    /** @return list<int> */
    public function getCancellationStatuses(): array
    {
        return $this->statusList('cancellation_statuses', self::DEFAULT_CANCELLATION_STATUS);
    }

    /** @return list<int> */
    public function getReturnStatuses(): array
    {
        return $this->statusList('return_statuses', 0);
    }

    public function getImportStatus(): int
    {
        return (int) ($this->get('import_status', (string) self::DEFAULT_IMPORT_STATUS) ?: self::DEFAULT_IMPORT_STATUS);
    }

    public function sendOrderConfirmation(): bool
    {
        return $this->get('send_order_confirmation', 'false') === 'true';
    }

    /**
     * The platform imported orders are placed on: the stored setting, or —
     * when nothing is set — the shop's default platform (platforms.is_default
     * = 1). Never 0 unless the shop has no default platform at all.
     */
    public function getPlatformId(): int
    {
        $configured = (int) $this->get('platform_id', '0');
        if ($configured > 0) {
            return $configured;
        }

        return (int) ($this->db->fetchValue(
            'SELECT platform_id FROM platforms WHERE is_default = 1 ORDER BY platform_id LIMIT 1'
        ) ?? 0);
    }

    /**
     * The platforms for the settings select (id => name); the shop's default
     * platform gets a "(default)" suffix.
     *
     * @return array<int, string>
     */
    public function platformOptions(): array
    {
        $options = [];
        foreach ($this->db->fetchRows(
            'SELECT p.platform_id AS PLATFORM_ID, p.platform_name AS PLATFORM_NAME, p.is_default AS IS_DEFAULT
             FROM platforms p ORDER BY p.is_default DESC, p.sort_order, p.platform_id'
        ) as $row) {
            $suffix = (int) $row['IS_DEFAULT'] === 1 ? ' (' . 'default' . ')' : '';
            $options[(int) $row['PLATFORM_ID']] = sprintf('%d — %s%s', (int) $row['PLATFORM_ID'], (string) $row['PLATFORM_NAME'], $suffix);
        }

        return $options;
    }

    /** Token of the frontend cron endpoint (empty endpoint = disabled). */
    public function getCronToken(): string
    {
        return $this->get('cron_token');
    }

    public function isAutoSyncEnabled(): bool
    {
        return $this->get('auto_sync', 'true') === 'true';
    }

    /** @return list<int> */
    private function statusList(string $name, int $default): array
    {
        $raw = $this->get($name);
        if ($raw === '') {
            return $default > 0 ? [$default] : [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $id) {
            $id = (int) trim($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
