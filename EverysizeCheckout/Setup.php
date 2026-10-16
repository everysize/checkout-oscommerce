<?php

declare(strict_types=1);

/**
 * everysize Checkout — installation, deinstallation, admin menu, ACL,
 * translations, hooks and cron registration.
 *
 * Install is idempotent (createTableIfNotExists), the schema version lives
 * in everysize_checkout_setting. Remove keeps all data (tables, settings,
 * credentials, links) unless the admin explicitly confirms dropping the
 * tables — same behaviour as the other everysize modules.
 */

namespace common\extensions\EverysizeCheckout;

use common\classes\modules\SetupExtensions;

class Setup extends SetupExtensions
{
    public const SCHEMA_VERSION = '1.0.0';

    public static function getDescription()
    {
        return 'everysize Checkout: imports everysize orders and reports shipping, tracking, cancellations, returns and refunds.';
    }

    public static function getAdminMenu()
    {
        return [
            [
                'parent' => 'BOX_HEADING_TOOLS',
                'sort_order' => '25',
                'box_type' => '0',
                'acl_check' => 'EverysizeCheckout,allowed',
                'path' => 'everysize-checkout',
                'title' => 'BOX_EVERYSIZE_CHECKOUT',
            ],
        ];
    }

    public static function getAclArray()
    {
        return ['default' => ['TEXT_SETTINGS', 'BOX_HEADING_TOOLS', 'BOX_EVERYSIZE_CHECKOUT']];
    }

    /**
     * The admin hooks (pages the module adds content to). The files live in
     * the extension's hooks/ directory (the default path osCommerce builds).
     */
    public static function getAdminHooks()
    {
        $path = \Yii::getAlias('@common') . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR
            . 'EverysizeCheckout' . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR;

        return [
            // Order detail: the everysize box in the totals area.
            [
                'page_name' => 'orders/process-order',
                'page_area' => 'totals-block',
                'extension_file' => $path . 'orders.process-order.totals-block.tpl',
            ],
            // Order list: search by everysize order number + everysize filter.
            [
                'page_name' => 'orders/orderlist',
                'page_area' => '',
                'extension_file' => $path . 'orders.orderlist.php',
            ],
            // Order list filter panel: the "everysize orders only" checkbox.
            [
                'page_name' => 'filters/orders',
                'page_area' => 'filter-1-column',
                'extension_file' => $path . 'filters.orders.filter-1-column.tpl',
            ],
        ];
    }

    /**
     * All admin texts, English default plus German (the demo shop's admin
     * language). Setup::install writes the per-language values into the
     * translation table; at runtime the English defaults fall back.
     */
    public static function getTranslationArray()
    {
        return [
            'admin/main' => [
                'BOX_EVERYSIZE_CHECKOUT' => 'everysize Checkout',
            ],
            'extensions/everysize-checkout' => self::translations(),
        ];
    }

    /** English defaults (getTranslationArray) + German rows (install). */
    private static function texts(): array
    {
        return [
            'EXT_EC_TITLE' => ['en' => 'everysize Checkout', 'de' => 'everysize Checkout'],
            'EXT_EC_MENU_OVERVIEW' => ['en' => 'Overview', 'de' => 'Übersicht'],
            'EXT_EC_MENU_SETTINGS' => ['en' => 'Settings', 'de' => 'Einstellungen'],
            'EXT_EC_MENU_PROTOCOL' => ['en' => 'Protocol', 'de' => 'Protokoll'],

            'EXT_EC_ENV_HEADING' => ['en' => 'API environment', 'de' => 'API-Umgebung'],
            'EXT_EC_ENV_PRODUCTION' => ['en' => 'Production', 'de' => 'Produktion'],
            'EXT_EC_ENV_TEST' => ['en' => 'Test environment', 'de' => 'Testumgebung'],
            'EXT_EC_ENV_INVALID' => ['en' => 'invalid — no API calls are made', 'de' => 'ungültig — es werden keine API-Aufrufe gemacht'],

            'EXT_EC_STATUS_CONFIGURED' => ['en' => 'Credentials are stored.', 'de' => 'Zugangsdaten sind gespeichert.'],
            'EXT_EC_STATUS_NOT_CONFIGURED' => ['en' => 'No credentials stored — no import, no export.', 'de' => 'Keine Zugangsdaten gespeichert — kein Import, kein Export.'],

            'EXT_EC_FIELD_CLIENT_ID' => ['en' => 'Client ID', 'de' => 'Client-ID'],
            'EXT_EC_FIELD_CLIENT_SECRET' => ['en' => 'Client secret', 'de' => 'Client-Secret'],
            'EXT_EC_SECRET_STORED' => ['en' => 'Stored (leave empty to keep)', 'de' => 'Gespeichert (leer lassen, um den Wert zu behalten)'],
            'EXT_EC_FIELD_SHOP_ID' => ['en' => 'Shop ID', 'de' => 'Shop-ID'],
            'EXT_EC_TEST_CONNECTION' => ['en' => 'Test connection', 'de' => 'Verbindung testen'],
            'EXT_EC_CONNECTION_OK' => ['en' => 'Connection successful: shop %s at %s.', 'de' => 'Verbindung erfolgreich: Shop %s an %s.'],
            'EXT_EC_SAVE' => ['en' => 'Save', 'de' => 'Speichern'],
            'EXT_EC_ORDERS_IMPORTED' => ['en' => 'imported orders', 'de' => 'importierte Bestellungen'],
            'EXT_EC_REMOVE_CREDENTIALS' => ['en' => 'Remove credentials', 'de' => 'Zugangsdaten entfernen'],
            'EXT_EC_CONFIRM_REMOVE' => ['en' => 'Remove the stored credentials? The links to imported orders are kept.', 'de' => 'Gespeicherte Zugangsdaten entfernen? Die Verknüpfungen zu importierten Bestellungen bleiben erhalten.'],
            'EXT_EC_CREDENTIALS_REMOVED' => ['en' => 'Credentials removed.', 'de' => 'Zugangsdaten entfernt.'],
            'EXT_EC_SAVED' => ['en' => 'Settings saved.', 'de' => 'Einstellungen gespeichert.'],

            'EXT_EC_IMPORT_HEADING' => ['en' => 'Import', 'de' => 'Import'],
            'EXT_EC_IMPORT_STATUS' => ['en' => 'Order status for new orders', 'de' => 'Bestellstatus für neue Bestellungen'],
            'EXT_EC_FIELD_PLATFORM' => ['en' => 'Platform for imported orders', 'de' => 'Plattform für importierte Bestellungen'],
            'EXT_EC_SEND_CONFIRMATION' => ['en' => 'Send order confirmation to the customer', 'de' => 'Bestellbestätigung an den Kunden senden'],

            'EXT_EC_EXPORT_HEADING' => ['en' => 'Export', 'de' => 'Export'],
            'EXT_EC_SHIPPING_STATUSES' => ['en' => 'Status "shipped" (report fulfillment)', 'de' => 'Status „versendet" (Fulfillment melden)'],
            'EXT_EC_CANCELLATION_STATUSES' => ['en' => 'Status "cancelled" (report revocation)', 'de' => 'Status „storniert" (Storno melden)'],
            'EXT_EC_RETURN_STATUSES' => ['en' => 'Optional status "returned" (report return)', 'de' => 'Optionaler Status „retoure" (Retoure melden)'],
            'EXT_EC_CARRIER' => ['en' => 'Carrier (if the parcel service is unknown to everysize)', 'de' => 'Carrier (falls das Versandpaket everysize unbekannt ist)'],

            'EXT_EC_REFUND_HEADING' => ['en' => 'Refund via everysize', 'de' => 'Erstattung über everysize'],
            'EXT_EC_AUTO_REFUND' => ['en' => 'Refund the full amount when cancelled before shipping', 'de' => 'Bei Storno vor Versand den vollen Betrag erstatten'],
            'EXT_EC_SYNC_HEADING' => ['en' => 'Synchronisation', 'de' => 'Abgleich'],
            'EXT_EC_AUTO_SYNC' => ['en' => 'Automatic sync (cron, every 5 minutes)', 'de' => 'Automatischer Abgleich (Cron, alle 5 Minuten)'],
            'EXT_EC_SYNC_NOW' => ['en' => 'Sync now', 'de' => 'Jetzt abgleichen'],
            'EXT_EC_SYNC_RUNNING' => ['en' => 'Sync started.', 'de' => 'Abgleich gestartet.'],
            'EXT_EC_CRON_TOKEN' => ['en' => 'Token of the frontend cron endpoint', 'de' => 'Token des Web-Cron-Endpunkts'],
            'EXT_EC_CRON_HINT' => ['en' => 'Without shell access: call every 5 minutes:', 'de' => 'Ohne Shell-Zugang: alle 5 Minuten aufrufen:'],
            'EXT_EC_CRON_NO_TOKEN' => ['en' => 'no token set — the endpoint is disabled until a token is stored', 'de' => 'kein Token gesetzt — der Endpunkt ist deaktiviert, bis ein Token gespeichert ist'],

            'EXT_EC_ORDER_BOX' => ['en' => 'everysize order', 'de' => 'everysize-Bestellung'],
            'EXT_EC_ORDER_ES_ID' => ['en' => 'everysize order', 'de' => 'everysize-Bestellung'],
            'EXT_EC_ORDER_CAPTURE' => ['en' => 'PayPal transaction (capture)', 'de' => 'PayPal-Transaktion (Capture)'],
            'EXT_EC_ORDER_EMAIL' => ['en' => 'Customer e-mail (original)', 'de' => 'Kunden-E-Mail (Original)'],
            'EXT_EC_ORDER_EMAIL_HINT' => ['en' => 'for information only — the shop’s mails go to the everysize forwarding address', 'de' => 'nur zur Information — die Mails des Shops gehen an die everysize-Weiterleitungsadresse'],
            'EXT_EC_ORDER_IMPORTED' => ['en' => 'Imported', 'de' => 'Importiert'],
            'EXT_EC_ORDER_NUMBER_REPORTED' => ['en' => 'Order number reported to everysize', 'de' => 'Bestellnummer an everysize gemeldet'],
            'EXT_EC_ORDER_NUMBER_PENDING' => ['en' => 'not yet', 'de' => 'noch nicht'],
            'EXT_EC_REFUND_AMOUNT' => ['en' => 'Amount', 'de' => 'Betrag'],
            'EXT_EC_REFUND_SEND' => ['en' => 'Refund', 'de' => 'Erstatten'],
            'EXT_EC_REFUND_DONE' => ['en' => 'Refund sent.', 'de' => 'Erstattung gesendet.'],
            'EXT_EC_REFUND_ALREADY' => ['en' => 'Refund already triggered.', 'de' => 'Erstattung bereits ausgelöst.'],
            'EXT_EC_REFUND_FAILED' => ['en' => 'Refund failed: %s', 'de' => 'Erstattung fehlgeschlagen: %s'],
            'EXT_EC_REFUND_INVALID' => ['en' => 'Invalid amount.', 'de' => 'Ungültiger Betrag.'],
            'EXT_EC_REFUND_TOKEN' => ['en' => 'The page has expired — please reload and try again.', 'de' => 'Die Seite ist abgelaufen — bitte neu laden und erneut versuchen.'],
            'EXT_EC_RETURN_CHECKBOX' => ['en' => 'Goods came back: report a return to everysize', 'de' => 'Ware ist zurückgekommen: Retoure an everysize melden'],
            'EXT_EC_RETURN_SEND' => ['en' => 'Report return', 'de' => 'Retoure melden'],
            'EXT_EC_RETURN_NOT_SHIPPED' => ['en' => 'A return needs a reported shipment — set the order to the "shipped" status first.', 'de' => 'Eine Retoure setzt einen gemeldeten Versand voraus — die Bestellung zuerst in den Status „versendet“ setzen.'],
            'EXT_EC_RETURN_DONE' => ['en' => 'Return reported to everysize.', 'de' => 'Retoure an everysize gemeldet.'],
            'EXT_EC_RETURN_SKIPPED' => ['en' => 'The order is already revoked at everysize, the return was not reported.', 'de' => 'Die Bestellung ist bei everysize bereits storniert, die Retoure wurde nicht gemeldet.'],
            'EXT_EC_RETURN_HINT' => ['en' => 'For a return, tick the box and enter the amount to refund — e.g. without the shipping costs. The return is reported first, then the amount is refunded.', 'de' => 'Bei einer Retoure das Häkchen setzen und den zu erstattenden Betrag eintragen — z. B. ohne Versandkosten. Gemeldet wird zuerst die Retoure, dann wird der Betrag erstattet.'],
            'EXT_EC_REFUND_TILE_PAID' => ['en' => 'Paid', 'de' => 'Bezahlt'],
            'EXT_EC_REFUND_TILE_REFUNDED' => ['en' => 'Refunded', 'de' => 'Erstattet'],
            'EXT_EC_REFUND_TILE_OPEN' => ['en' => 'Still open', 'de' => 'Noch offen'],
            'EXT_EC_REFUND_TABLE_DATE' => ['en' => 'Date', 'de' => 'Datum'],
            'EXT_EC_REFUND_TABLE_STATUS' => ['en' => 'Status', 'de' => 'Status'],
            'EXT_EC_REFUND_TABLE_ID' => ['en' => 'Refund ID', 'de' => 'Erstattungs-ID'],
            'EXT_EC_REFUND_NONE' => ['en' => 'No refunds yet.', 'de' => 'Noch keine Erstattungen.'],
            'EXT_EC_REFUND_HINT' => ['en' => 'At most the not-yet-refunded amount (%s) can be refunded. The refund runs through everysize (PayPal) on the original payment. When a cancellation is reported before shipping and the automatic refund is enabled, everysize refunds the full amount by itself.', 'de' => 'Es ist höchstens der noch nicht erstattete Betrag (%s) erstattbar. Die Erstattung läuft über everysize (PayPal) auf die ursprüngliche Zahlung. Wird ein Storno vor Versand gemeldet und die automatische Erstattung ist aktiviert, erstattet everysize den vollen Betrag von selbst.'],
            'EXT_EC_REPORTS_HEADING' => ['en' => 'Reports to everysize', 'de' => 'Rückmeldungen an everysize'],
            'EXT_EC_REPORTS_NONE' => ['en' => 'Nothing reported yet.', 'de' => 'Noch nichts gemeldet.'],
            'EXT_EC_REPORTS_SUBJECT' => ['en' => 'Item / tracking code / refund', 'de' => 'Artikel / Sendungsnummer / Erstattung'],
            'EXT_EC_REPORTS_REASON' => ['en' => 'Reason / amount', 'de' => 'Grund / Betrag'],
            'EXT_EC_REPORTS_RESULT' => ['en' => 'Result', 'de' => 'Ergebnis'],
            'EXT_EC_KIND_FULFILLMENT' => ['en' => 'Shipping', 'de' => 'Versand'],
            'EXT_EC_KIND_TRACKING' => ['en' => 'Tracking code', 'de' => 'Sendungsnummer'],
            'EXT_EC_KIND_REVOCATION' => ['en' => 'Cancellation', 'de' => 'Storno'],
            'EXT_EC_KIND_RETURN' => ['en' => 'Return', 'de' => 'Retoure'],
            'EXT_EC_KIND_REFUND' => ['en' => 'Refund', 'de' => 'Rückerstattung'],
            'EXT_EC_RESULT_SENT' => ['en' => 'reported', 'de' => 'gemeldet'],
            'EXT_EC_RESULT_SKIPPED' => ['en' => 'skipped', 'de' => 'übersprungen'],
            'EXT_EC_RESULT_KNOWN' => ['en' => 'known', 'de' => 'bekannt'],
            'EXT_EC_REFUND_AUTO' => ['en' => 'automatic (cancellation)', 'de' => 'automatisch (Storno)'],
            'EXT_EC_REASON_MERCHANT_DECLINE' => ['en' => 'cancelled by the merchant', 'de' => 'Händler storniert'],
            'EXT_EC_REASON_CUSTOMER_REVOKE' => ['en' => 'cancelled by the customer', 'de' => 'Kunde storniert'],
            'EXT_EC_REASON_RETOUR' => ['en' => 'return', 'de' => 'Retoure'],
            'EXT_EC_REASON_ALREADY_REFUNDED' => ['en' => 'already refunded at everysize', 'de' => 'bei everysize bereits erstattet'],
            'EXT_EC_REASON_NOT_PAID' => ['en' => 'not refundable: the order was not paid via everysize', 'de' => 'nicht erstattbar: die Bestellung wurde nicht über everysize bezahlt'],
            'EXT_EC_REASON_AMOUNT_INVALID' => ['en' => 'amount rejected by everysize', 'de' => 'Betrag von everysize abgelehnt'],
            'EXT_EC_REASON_PAYPAL_FAILED' => ['en' => 'PayPal refund failed', 'de' => 'PayPal-Erstattung fehlgeschlagen'],

            'EXT_EC_PROTOCOL_HEADING' => ['en' => 'Sync protocol', 'de' => 'Abgleich-Protokoll'],
            'EXT_EC_PROTOCOL_TIME' => ['en' => 'Time', 'de' => 'Zeit'],
            'EXT_EC_PROTOCOL_STATE' => ['en' => 'Status', 'de' => 'Status'],
            'EXT_EC_PROTOCOL_TITLE' => ['en' => 'Title', 'de' => 'Titel'],
            'EXT_EC_PROTOCOL_MESSAGE' => ['en' => 'Message', 'de' => 'Meldung'],
            'EXT_EC_PROTOCOL_OK' => ['en' => 'OK', 'de' => 'OK'],
            'EXT_EC_PROTOCOL_ERROR' => ['en' => 'error', 'de' => 'Fehler'],
            'EXT_EC_PROTOCOL_ALL' => ['en' => 'All', 'de' => 'Alle'],
            'EXT_EC_PROTOCOL_EMPTY' => ['en' => 'No protocol entries yet.', 'de' => 'Noch keine Protokolleinträge.'],

            'EXT_EC_ORDERS_ONLY' => ['en' => 'everysize orders only', 'de' => 'nur everysize-Bestellungen'],
        ];
    }

    /** The runtime array (plain strings, English — falls back from the DB). */
    private static function translations(): array
    {
        $rows = [];
        foreach (self::texts() as $key => $value) {
            $rows[$key] = is_array($value) ? (string) $value['en'] : (string) $value;
        }

        return $rows;
    }

    /**
     * Writes the German rows into the translation table (per language map).
     * ModuleExtensions::install already ran the plain-English default rows.
     */
    private static function installTranslations(\common\classes\Migration $migrate): void
    {
        foreach (['admin/main', 'extensions/everysize-checkout'] as $entity) {
            $keys = [];
            foreach (self::texts() as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                if ($entity === 'admin/main' && $key !== 'BOX_EVERYSIZE_CHECKOUT') {
                    continue;
                }
                if ($entity === 'extensions/everysize-checkout' && $key === 'BOX_EVERYSIZE_CHECKOUT') {
                    continue;
                }
                $keys[$key] = $value;
            }
            if ($keys !== []) {
                // Replace: the plain-English defaults from installTranslationArray
                // must not shadow the per-language values.
                $migrate->addTranslation($entity, $keys, true);
            }
        }
    }

    /**
     * Brings the admin texts up to date after a file update: osCommerce only
     * writes extension texts into the translation table on install, so new
     * keys of an update would stay undefined constants. Compares a hash of
     * texts() with the one stored at the last run and re-runs
     * installTranslations() (idempotent) when it differs — called by the
     * Bootstrap of the admin app, before the page defines its constants.
     */
    public static function updateTranslationsIfChanged(): bool
    {
        $hash = md5(serialize(self::texts()));
        $stored = (new \yii\db\Query())->select('value')->from('everysize_checkout_setting')
            ->where(['name' => 'texts_hash'])->scalar();
        if ($stored === $hash) {
            return false;
        }
        $migrate = new \common\classes\Migration();
        $migrate->compact = true;
        self::installTranslations($migrate);
        self::storeTextsHash($migrate);
        \yii\caching\TagDependency::invalidate(\Yii::$app->getCache(), 'translation');

        return true;
    }

    private static function storeTextsHash(\common\classes\Migration $migrate): void
    {
        $migrate->delete('everysize_checkout_setting', ['name' => 'texts_hash']);
        $migrate->insert('everysize_checkout_setting', ['name' => 'texts_hash', 'value' => md5(serialize(self::texts()))]);
    }

    public static function install($platform_id, $migrate)
    {
        // The shop layer's classes are not autoloaded before the module is
        // allowed() — the install runs first.
        EverysizeCheckout::autoload();

        $migrate->createTableIfNotExists('everysize_checkout_order', [
            'orders_id' => $migrate->integer(11)->notNull(),
            'everysize_order_id' => $migrate->string(64)->notNull(),
            'paypal_capture_id' => $migrate->string(64)->notNull()->defaultValue(''),
            'original_email' => $migrate->string(255)->notNull()->defaultValue(''),
            'number_reported' => $migrate->dateTime()->null(),
            'created_at' => $migrate->dateTime()->notNull(),
        ],
            'orders_id',
            ['unique:everysize_order_id' => 'everysize_order_id']
        );

        $migrate->createTableIfNotExists('everysize_checkout_order_item', [
            'orders_products_id' => $migrate->integer(11)->notNull(),
            'orders_id' => $migrate->integer(11)->notNull(),
            'sku' => $migrate->string(255)->notNull(),
        ],
            'orders_products_id',
            ['everysize_order_item_order' => 'orders_id', 'everysize_order_item_sku' => 'sku']
        );

        $migrate->createTableIfNotExists('everysize_checkout_report', [
            'orders_id' => $migrate->integer(11)->notNull(),
            'kind' => $migrate->string(32)->notNull(),
            'sku' => $migrate->string(255)->notNull()->defaultValue(''),
            'reason' => $migrate->string(255)->notNull()->defaultValue(''),
            'result' => $migrate->string(32)->notNull(),
            'created_at' => $migrate->dateTime()->notNull(),
        ],
            null,
            ['unique:everysize_report_key' => ['orders_id', 'kind', 'sku'], 'everysize_report_order' => 'orders_id']
        );

        $migrate->createTableIfNotExists('everysize_checkout_protocol', [
            'protocol_id' => $migrate->primaryKey(11)->append('AUTO_INCREMENT'),
            'title' => $migrate->string(255)->notNull(),
            'message' => $migrate->text()->null(),
            'state' => $migrate->tinyInteger(1)->notNull()->defaultValue(0),
            'created_at' => $migrate->dateTime()->notNull(),
        ],
            null,
            ['everysize_protocol_created' => 'created_at']
        );

        $migrate->createTableIfNotExists('everysize_checkout_setting', [
            'name' => $migrate->string(64)->notNull(),
            'value' => $migrate->text()->null(),
        ],
            'name',
            null
        );

        // createTableIfNotExists does not alter existing tables: widen the
        // reason column of earlier installs (full API reason codes instead
        // of a 64-char cut).
        self::widenReasonColumn($migrate);

        self::installTranslations($migrate);
        self::storeTextsHash($migrate);

        self::migrateLegacySettings();

        // Schema version, for future idempotent update migrations.
        $migrate->delete('everysize_checkout_setting', ['name' => 'schema_version']);
        $migrate->insert('everysize_checkout_setting', ['name' => 'schema_version', 'value' => self::SCHEMA_VERSION]);
    }

    /**
     * Takes over settings an earlier deployment stored in the shop's
     * platforms_configuration (platform_id 0, keys EVERYSIZE_CHECKOUT_* —
     * written by provisioning scripts). Only
     * fills keys the module table does not have yet — an admin-set value
     * wins, nothing is overwritten, and no value is ever printed or logged.
     * SettingsImpl additionally falls back to those keys read-only, so the
     * sync works even without a fresh install.
     *
     * Public because it is idempotent and safe to re-run on updates
     * (e.g. `Setup::migrateLegacySettings();` from a console one-off).
     */
    /**
     * Widen everysize_checkout_report.reason to 255 on installs that created
     * it with 64 (idempotent: skipped when the column is already wide
     * enough). The API reason codes (e.g.
     * ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS) must not be cut.
     */
    private static function widenReasonColumn(\common\classes\Migration $migrate): void
    {
        $db = new \Everysize\Checkout\osCommerce\Db();
        $length = (int) ($db->fetchValue(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'everysize_checkout_report' AND COLUMN_NAME = 'reason'"
        ) ?? 255);
        if ($length > 0 && $length < 255) {
            $db->execute("ALTER TABLE everysize_checkout_report MODIFY reason VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }

    public static function migrateLegacySettings(): void
    {
        $map = [
            'EVERYSIZE_CHECKOUT_CLIENT_ID' => 'client_id',
            'EVERYSIZE_CHECKOUT_CLIENT_SECRET' => 'client_secret',
            'EVERYSIZE_CHECKOUT_CARRIER' => 'carrier',
            'EVERYSIZE_CHECKOUT_AUTO_REFUND' => 'auto_refund_decline',
            'EVERYSIZE_CHECKOUT_SHIPPING_STATUSES' => 'shipping_statuses',
            'EVERYSIZE_CHECKOUT_CANCELLATION_STATUSES' => 'cancellation_statuses',
            'EVERYSIZE_CHECKOUT_RETURN_STATUSES' => 'return_statuses',
            'EVERYSIZE_CHECKOUT_IMPORT_STATUS' => 'import_status',
            'EVERYSIZE_CHECKOUT_SEND_ORDER_CONFIRMATION' => 'send_order_confirmation',
            'EVERYSIZE_CHECKOUT_SYNC_ENABLED' => 'auto_sync',
            'EVERYSIZE_CHECKOUT_AUTO_REFUND_SINCE' => 'auto_refund_since',
        ];
        $booleans = ['auto_refund_decline', 'send_order_confirmation', 'auto_sync'];

        foreach ($map as $legacyKey => $name) {
            $value = \Yii::$app->db->createCommand(
                'SELECT configuration_value FROM platforms_configuration
                 WHERE configuration_key = :key AND platform_id = 0
                 ORDER BY configuration_id DESC LIMIT 1',
                [':key' => $legacyKey]
            )->queryScalar();
            if ($value === false || (string) $value === '') {
                continue;
            }
            $value = (string) $value;
            if (in_array($name, $booleans, true)) {
                $value = in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
            }

            $exists = (new \yii\db\Query())
                ->from('everysize_checkout_setting')
                ->where(['name' => $name])
                ->exists();
            if (!$exists) {
                \Yii::$app->db->createCommand()
                    ->insert('everysize_checkout_setting', ['name' => $name, 'value' => $value])
                    ->execute();
            }
        }
    }

    public static function remove($platform_id, $migrate, $userConfirmedDropDatatables = false)
    {
        // Nothing: credentials, settings, links and the protocol are kept so a
        // re-install picks up where the module left off.
    }

    public static function getDropDatabasesArray()
    {
        return [
            'everysize_checkout_order',
            'everysize_checkout_order_item',
            'everysize_checkout_report',
            'everysize_checkout_protocol',
            'everysize_checkout_setting',
        ];
    }

    public static function getVersionHistory()
    {
        return [
            '1.0.0' => 'First release: import, export (shipping/tracking/cancellation/return/refund), admin UI, cron/CLI sync',
        ];
    }

    /**
     * No CronScheduler dependency: merchants without shell access call the
     * frontend cron endpoint (everysize-checkout-cron/everysize?token=…),
     * servers run `php yii.php everysize-checkout/sync` (README).
     */
    public static function getCronJobs()
    {
        return [];
    }
}
