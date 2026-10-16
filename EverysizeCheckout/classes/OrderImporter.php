<?php

/**
 * everysize Checkout — creates an osCommerce 4 order from an everysize order.
 *
 * Same import semantics as the accepted everysize plugins for other
 * shop systems, mapped to the osCommerce 4.14
 * schema: orders / orders_products / orders_products_attributes (the classic
 * options system — a size is an option value, shown on order page, invoice
 * and packing slip) / orders_total / orders_status_history, stock in
 * inventory (variants) and products. Everything is written in one
 * transaction after validating; a REVOKED order is created and immediately
 * put into the cancellation status without stock reduction. The merchant
 * order number is reported by the sync AFTER the commit (Sync::import).
 *
 * Verified against the 4.14.63493 schema (_tmp/unpacked, sql/trueloaded.sql):
 * - orders: platform_id, the customers/delivery/billing address columns
 *   (varchar limits), payment_method ("everysize (PayPal)"), payment_class,
 *   shipping_method, shipping_class, transaction_id (capture id), comments,
 *   date_purchased, orders_status, currency + currency_value (rate from the
 *   currencies table), language_id (default platform's default language),
 *   stock_updated (the stock is reduced in the same transaction)
 * - orders_products: products_model (variant SKU), products_name,
 *   products_price = final_price (NET unit price — the admin renders
 *   final_price * (1 + tax/100) * qty), products_tax, products_quantity, uprid
 * - orders_products_attributes: products_options(_id), products_options_
 *   values(_id) — the size "Größe (EU): 38" the admin and the PDFs show
 * - inventory: products_model (variant SKU), products_id (uprid string
 *   "{prid}{optId}{valId}"), products_quantity (variant stock)
 */

declare(strict_types=1);

namespace Everysize\Checkout\osCommerce;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Import\ImportException;
use Everysize\Checkout\Import\OrderImporter as OrderImporterInterface;
use Everysize\Checkout\Import\PriceCalculator;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Settings;
use Everysize\Checkout\SkuResolver;
use Everysize\Checkout\Texts;
use Everysize\Checkout\Uprid;

class OrderImporter implements OrderImporterInterface
{
    private DbInterface $db;
    private Settings $settings;
    private OrderLinks $links;
    private ?OrderMailer $mailer;
    private SkuResolver $resolver;
    private Texts $texts;

    public function __construct(DbInterface $db, Settings $settings, OrderLinks $links, ?OrderMailer $mailer = null, ?Texts $texts = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->links = $links;
        $this->mailer = $mailer;
        $this->resolver = new SkuResolver($db);
        $this->texts = $texts ?? new Texts();
    }

    /** Cached default language (id, directory, code) — read once per import. */
    private ?array $languageCache = null;

    /** Cached row of the order currency from the currencies table. */
    private ?array $currencyCache = null;

    /**
     * @param array<string, mixed> $esOrder order as returned by the everysize API
     *
     * @return array{orderId: string, orderNumber: string, warnings: string[]}
     */
    public function import(array $esOrder): array
    {
        // --- Validation, before writing anything ---------------------------------
        $everysizeOrderId = $this->requireString($esOrder, 'everysizeOrderId');
        $customer = is_array($esOrder['customer'] ?? null) ? $esOrder['customer'] : [];
        $email = $this->requireString($customer, 'email', 'customer.email');
        // Information only: the customer keeps the forwarding address above.
        $originalEmail = filter_var(trim((string) ($customer['originalEmail'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
        $currency = strtoupper((string) ($esOrder['currency'] ?? 'EUR'));
        $currencyValue = $this->currencyValue($currency);

        // The language first: the country names of the addresses come from
        // the countries table in the order's language (German shop →
        // „Deutschland", not „Germany").
        $language = $this->defaultLanguage();
        $billing = $this->address($esOrder['billingAddress'] ?? null, 'billingAddress', $language);
        $shipping = is_array($esOrder['shippingAddress'] ?? null) && $esOrder['shippingAddress'] !== []
            ? $this->address($esOrder['shippingAddress'], 'shippingAddress', $language)
            : $billing;
        $revoked = strtoupper((string) ($esOrder['status'] ?? '')) === Client::STATUS_REVOKED;
        $warnings = [];

        $lines = $this->resolveLines($everysizeOrderId, $esOrder['lineItems'] ?? null, $shipping['countryId'], $language, $warnings);
        $prices = PriceCalculator::calculate(
            array_map(static function (array $line): array {
                return ['gross' => $line['gross'], 'quantity' => $line['quantity'], 'vat' => $line['vat']];
            }, $lines),
            Amount::parse($esOrder['shippingCosts'] ?? 0, 'shippingCosts')
        );
        if (isset($esOrder['grossPrice'])) {
            $warning = PriceCalculator::checkTotal($esOrder['grossPrice'], $prices['totalOrderSum'], $everysizeOrderId);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $payment = is_array($esOrder['payment'] ?? null) ? $esOrder['payment'] : [];
        $payPalCaptureId = (string) ($payment['resourceId'] ?? $payment['transactionId'] ?? '');
        $orderDate = $this->localDate($esOrder['created'] ?? null, 'created');

        $importStatus = $this->settings->getImportStatus();
        $cancelStatus = $this->settings->getCancellationStatuses()[0] ?? 5;
        $orderStatus = $revoked ? $cancelStatus : $importStatus;

        // --- Writing -------------------------------------------------------------
        $ordersId = '0';
        $this->db->transaction(function () use (&$warnings, &$ordersId, $everysizeOrderId, $email, $originalEmail, $billing, $shipping, $orderDate, $orderStatus, $revoked, $prices, $language, $lines, $payPalCaptureId, $currency, $currencyValue) {
            $customerId = $this->resolveCustomer($email, $billing, $language);
            $ordersId = $this->insertOrder(
                $everysizeOrderId, $email, $customerId,
                $billing, $shipping, $orderDate, $orderStatus, $revoked, $prices, $language, $payPalCaptureId, $currency, $currencyValue
            );

            foreach ($lines as $key => $line) {
                $ordersProductsId = $this->insertOrderProduct($ordersId, $line, $prices['items'][$key]);
                if (!$revoked) {
                    $warnings = array_merge($warnings, $this->reduceStock($line, $everysizeOrderId));
                }
                $this->links->addOrderItem($ordersProductsId, $ordersId, $line['sku']);
            }

            $this->insertOrderTotals($ordersId, $prices, $language, $currency, $currencyValue);
            $this->insertStatusHistory($ordersId, $orderStatus, $everysizeOrderId);
            $this->links->addOrder($ordersId, $everysizeOrderId, $payPalCaptureId, $originalEmail);
        });

        // Only after the commit: the optional order confirmation. The mail
        // stack (\common\helpers\Mail, Swiftmailer) also runs in the console
        // boot — the cron container sends from the CLI the same way. A mail
        // failure is a warning; the order exists and is linked.
        if (!$revoked && $this->mailer !== null && $this->settings->sendOrderConfirmation()) {
            try {
                $this->mailer->sendConfirmation($ordersId ?? '0');
            } catch (\Throwable $e) {
                $warnings[] = sprintf('%s: order confirmation mail failed: %s', $everysizeOrderId, $e->getMessage());
            }
        }

        return ['orderId' => $ordersId, 'orderNumber' => $ordersId, 'warnings' => $warnings];
    }

    // --- Validation helpers --------------------------------------------------

    private function requireString(array $data, string $key, ?string $label = null): string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new ImportException(sprintf('everysize order has no %s', $label ?? $key));
        }

        return trim((string) $value);
    }

    /**
     * osCommerce 4 has no separate house number column: the complete street
     * (with house number, as the everysize address carries it) goes into
     * addressLine1/street — the admin and the invoices show it verbatim.
     *
     * @return array{firstName: string, lastName: string, company: string, street: string, addInfo: string, zip: string, city: string, state: string, countryId: string, country: string, addressFormatId: string}
     */
    private function address($address, string $field, array $language): array
    {
        if (!is_array($address)) {
            throw new ImportException(sprintf('everysize order has no %s', $field));
        }

        $street = $this->requireString($address, 'addressLine1', $field.'.addressLine1');
        $countryCode = strtoupper($this->requireString($address, 'countryCode', $field.'.countryCode'));
        $country = $this->db->fetchRows(
            'SELECT countries_id, countries_name, address_format_id FROM countries
             WHERE countries_iso_code_2 = :iso AND status = 1 AND language_id = :lang LIMIT 1',
            [':iso' => $countryCode, ':lang' => $language['id']]
        );
        if ($country === []) {
            throw new ImportException(sprintf('Unknown or inactive country %s in %s', $countryCode, $field));
        }

        return [
            'firstName' => trim((string) ($address['firstName'] ?? '')),
            'lastName' => $this->requireString($address, 'lastName', $field.'.lastName'),
            'company' => trim((string) ($address['company'] ?? '')),
            'street' => $street,
            'addInfo' => trim((string) ($address['addressLine2'] ?? '')),
            'zip' => $this->requireString($address, 'postalCode', $field.'.postalCode'),
            'city' => $this->requireString($address, 'city', $field.'.city'),
            'state' => trim((string) ($address['state'] ?? '')),
            'countryId' => (string) $country[0]['COUNTRIES_ID'],
            'country' => (string) $country[0]['COUNTRIES_NAME'],
            'addressFormatId' => (string) ($country[0]['ADDRESS_FORMAT_ID'] ?? '1'),
        ];
    }

    /**
     * SKU → orderable unit: an inventory row (variant, the classic options
     * combination) wins — everysize SKUs are the purchasable variant —, the
     * bare product (products_model) is the fallback. Case-sensitive matches
     * beat case-insensitive ones, active products beat inactive; two equally
     * good candidates are an error ("not unique").
     *
     * @return array{productsId: string, inventoryId: ?string, uprid: string, taxClassId: string, name: string, model: string}
     */
    private function resolveArticle(string $sku): array
    {
        $rows = $this->db->fetchRows(
            'SELECT p.products_id AS PRODUCTS_ID, p.products_status AS PRODUCTS_STATUS,
                    p.products_tax_class_id AS TAX_CLASS_ID, p.products_model AS PRODUCTS_MODEL,
                    NULL AS INVENTORY_ID, NULL AS UPRID, 0 AS IS_VARIANT,
                    (BINARY p.products_model = BINARY :sku) AS EXACT_MATCH
             FROM products p
             WHERE p.products_model = :sku
             UNION ALL
             SELECT p.products_id, p.products_status, p.products_tax_class_id, p.products_model,
                    i.inventory_id, i.products_id, 1,
                    (BINARY i.products_model = BINARY :sku)
             FROM inventory i
             JOIN products p ON p.products_id = i.prid
             WHERE i.products_model = :sku',
            [':sku' => $sku]
        );
        if ($rows === []) {
            throw new ImportException(sprintf('SKU %s not found in the shop', $sku));
        }
        // Exact case first, then the active product, then the variant.
        usort($rows, static function (array $a, array $b): int {
            return [(int) $b['EXACT_MATCH'], (int) $b['PRODUCTS_STATUS'], (int) $b['IS_VARIANT']]
                <=> [(int) $a['EXACT_MATCH'], (int) $a['PRODUCTS_STATUS'], (int) $a['IS_VARIANT']];
        });
        if (count($rows) > 1
            && (int) $rows[0]['EXACT_MATCH'] === (int) $rows[1]['EXACT_MATCH']
            && (int) $rows[0]['PRODUCTS_STATUS'] === (int) $rows[1]['PRODUCTS_STATUS']
            && (int) $rows[0]['IS_VARIANT'] === (int) $rows[1]['IS_VARIANT']) {
            throw new ImportException(sprintf('SKU %s is not unique in the shop', $sku));
        }

        $row = $rows[0];
        $isVariant = (int) $row['IS_VARIANT'] === 1;

        return [
            'productsId' => (string) $row['PRODUCTS_ID'],
            'inventoryId' => $isVariant ? (string) $row['INVENTORY_ID'] : null,
            'uprid' => $isVariant ? (string) $row['UPRID'] : '',
            'taxClassId' => (string) $row['TAX_CLASS_ID'],
            'name' => $this->productName((string) $row['PRODUCTS_ID']),
            'model' => (string) $row['PRODUCTS_MODEL'],
        ];
    }

    /**
     * @return list<array{sku: string, title: string, quantity: int, gross: float, vat: float, productsId: string, inventoryId: ?string, uprid: string, name: string, model: string, attributes: list<array{optionsId: int, valuesId: int, optionsName: string, valuesName: string}>}>
     */
    private function resolveLines(string $everysizeOrderId, $lineItems, string $countryId, array $language, array &$warnings): array
    {
        if (!is_array($lineItems) || $lineItems === []) {
            throw new ImportException('everysize order has no line items');
        }

        $lines = [];
        foreach (array_values($lineItems) as $item) {
            if (!is_array($item)) {
                throw new ImportException('invalid line item in everysize order');
            }
            $sku = $this->requireString($item, 'sku', 'lineItems.sku');
            $article = $this->resolver->resolve($sku);

            $attributes = [];
            foreach (Uprid::pairs($article['uprid']) as $pair) {
                $attributes[] = [
                    'optionsId' => $pair['optionsId'],
                    'valuesId' => $pair['valuesId'],
                    'optionsName' => $this->optionsName($pair['optionsId'], $language),
                    'valuesName' => $this->optionsValuesName($pair['valuesId'], $language),
                ];
            }

            $lines[] = [
                'sku' => $sku,
                'title' => trim((string) ($item['title'] ?? '')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'gross' => Amount::parse($item['price'] ?? null, 'lineItems.price'),
                'vat' => $this->taxRate($article['taxClassId'], $countryId, $everysizeOrderId, $sku, $warnings),
                'productsId' => $article['productsId'],
                'inventoryId' => $article['inventoryId'],
                'uprid' => $article['uprid'],
                'name' => $this->productName((string) $article['productsId']),
                'model' => $article['model'],
                // The sizes as orders_products_attributes rows (option name +
                // value in the order language), read from the variant's uprid.
                'attributes' => $attributes,
            ];
        }

        return $lines;
    }

    private function optionsName(int $optionsId, array $language): string
    {
        return (string) ($this->db->fetchValue(
            'SELECT products_options_name FROM products_options
             WHERE products_options_id = :id AND language_id = :lang LIMIT 1',
            [':id' => $optionsId, ':lang' => $language['id']]
        ) ?? '');
    }

    private function optionsValuesName(int $valuesId, array $language): string
    {
        return (string) ($this->db->fetchValue(
            'SELECT products_options_values_name FROM products_options_values
             WHERE products_options_values_id = :id AND language_id = :lang LIMIT 1',
            [':id' => $valuesId, ':lang' => $language['id']]
        ) ?? '');
    }

    /**
     * VAT rate of the article's tax class for the delivery country's tax
     * zone — the same query \common\helpers\Tax::get_tax_rate builds (the
     * zone stays 0: the everysize address carries no osCommerce zone id) —
     * summing the rates per priority and compounding the groups.
     */
    private function taxRate(string $taxClassId, string $countryId, string $everysizeOrderId, string $sku, array &$warnings): float
    {
        $rows = $this->db->fetchRows(
            'SELECT SUM(tr.tax_rate) AS TAX_RATE FROM tax_rates tr
             LEFT JOIN zones_to_geo_zones za ON za.geo_zone_id = tr.tax_zone_id
             WHERE tr.tax_class_id = :taxClassId
               AND (za.zone_country_id IS NULL OR za.zone_country_id = 0 OR za.zone_country_id = :countryId)
               AND (za.zone_id IS NULL OR za.zone_id = 0)
             GROUP BY tr.tax_priority ORDER BY tr.tax_priority',
            [':taxClassId' => $taxClassId, ':countryId' => $countryId]
        );
        if ($rows === []) {
            $warnings[] = sprintf('%s: no tax rate for tax class %s in delivery country, item %s imported with 0%%',
                $everysizeOrderId, $taxClassId, $sku);

            return 0.0;
        }

        $multiplier = 1.0;
        foreach ($rows as $row) {
            $multiplier *= 1.0 + ((float) $row['TAX_RATE']) / 100;
        }

        return ($multiplier - 1.0) * 100;
    }

    private function productName(string $productsId): string
    {
        return (string) ($this->db->fetchValue(
            'SELECT products_name FROM products_description
             WHERE products_id = :id AND language_id = :lang AND platform_id IN (0, :platform)
             ORDER BY platform_id DESC LIMIT 1',
            [':id' => $productsId, ':lang' => $this->defaultLanguage()['id'], ':platform' => $this->settings->getPlatformId()]
        ) ?? '');
    }

    private function localDate($value, string $field): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            throw new ImportException(sprintf('everysize order has an invalid %s date: %s', $field, $value));
        }
    }

    /**
     * The shop's default language: the default platform's default_language
     * (platforms.is_default = 1) resolved to the languages row; the first
     * active language by sort order is the fallback. Id, directory, code.
     */
    private function defaultLanguage(): array
    {
        if ($this->languageCache !== null) {
            return $this->languageCache;
        }

        $fallback = ['id' => '1', 'directory' => 'english', 'code' => 'en'];
        $row = $this->db->fetchRows(
            'SELECT l.languages_id AS LANG_ID, l.directory AS DIRECTORY, l.code AS CODE
             FROM languages l
             WHERE l.languages_status = 1 AND l.code = (SELECT p.default_language FROM platforms p WHERE p.is_default = 1 ORDER BY p.platform_id LIMIT 1)
             ORDER BY l.sort_order, l.languages_id LIMIT 1'
        );
        if ($row === []) {
            $row = $this->db->fetchRows(
                'SELECT languages_id AS LANG_ID, directory AS DIRECTORY, code AS CODE
                 FROM languages WHERE languages_status = 1 ORDER BY sort_order, languages_id LIMIT 1'
            );
        }
        if ($row === []) {
            return $this->languageCache = $fallback;
        }

        return $this->languageCache = [
            'id' => (string) $row[0]['LANG_ID'],
            'directory' => (string) ($row[0]['DIRECTORY'] ?? $fallback['directory']),
            'code' => (string) $row[0]['CODE'],
        ];
    }

    /**
     * The conversion rate of the order currency from the currencies table —
     * the same value the shop's own checkout writes into orders.currency_value.
     * A currency the shop does not have (or an inactive one) rejects the order.
     */
    private function currencyValue(string $currency): string
    {
        if ($this->currencyCache === null || strtoupper((string) $this->currencyCache['CODE']) !== $currency) {
            $row = $this->db->fetchRows(
                'SELECT code AS CODE, value AS VALUE FROM currencies WHERE code = :code AND status = 1 LIMIT 1',
                [':code' => $currency]
            );
            if ($row === [] || (float) $row[0]['VALUE'] <= 0) {
                throw new ImportException(sprintf(
                    'order currency %s is not an active currency of the shop',
                    $currency
                ));
            }
            $this->currencyCache = $row[0];
        }

        return number_format((float) $this->currencyCache['VALUE'], 8, '.', '');
    }

    // --- Writing helpers -----------------------------------------------------

    /**
     * The everysize customer either matches an existing shop account
     * (kept untouched, only referenced) or gets a guest account like the
     * one-page checkout creates (opc_temp_account = 1) — the admin can
     * show the customer column, no registration mail goes out, the
     * account cannot log in.
     */
    private function resolveCustomer(string $email, array $billing, array $language): string
    {
        $customerId = $this->db->fetchValue(
            'SELECT customers_id FROM customers WHERE customers_email_address = :email ORDER BY customers_id LIMIT 1',
            [':email' => $email]
        );
        if ($customerId !== null) {
            return (string) $customerId;
        }

        $this->db->execute(
            'INSERT INTO customers (customers_gender, customers_firstname, customers_lastname, customers_email_address,
                platform_id, customers_password, customers_selected_template, opc_temp_account, language_id)
             VALUES (:gender, :firstname, :lastname, :email, :platformId, \'\', \'\', 1, :languageId)',
            [
                ':gender' => '',
                ':firstname' => $this->cut($billing['firstName'], 32),
                ':lastname' => $this->cut($billing['lastName'], 32),
                ':email' => $this->cut($email, 96),
                ':platformId' => $this->settings->getPlatformId(),
                ':languageId' => $language['id'],
            ]
        );
        $customerId = (string) $this->db->lastInsertId();

        $this->db->execute(
            'INSERT INTO address_book (customers_id, entry_gender, entry_company, entry_firstname, entry_lastname,
                entry_street_address, entry_suburb, entry_postcode, entry_city, entry_state, entry_country_id)
             VALUES (:customerId, :gender, :company, :firstname, :lastname, :street, :suburb, :zip, :city, :state, :countryId)',
            [
                ':customerId' => $customerId,
                ':gender' => '',
                ':company' => $this->cut($billing['company'], 32),
                ':firstname' => $this->cut($billing['firstName'], 32),
                ':lastname' => $this->cut($billing['lastName'], 32),
                ':street' => $this->cut($billing['street'], 64),
                ':suburb' => $this->cut($billing['addInfo'], 32),
                ':zip' => $this->cut($billing['zip'], 10),
                ':city' => $this->cut($billing['city'], 32),
                ':state' => $this->cut($billing['state'], 32),
                ':countryId' => $billing['countryId'],
            ]
        );
        $addressId = (string) $this->db->lastInsertId();

        $this->db->execute(
            'UPDATE customers SET customers_default_address_id = :addressId WHERE customers_id = :customerId',
            [':addressId' => $addressId, ':customerId' => $customerId]
        );

        $this->db->execute(
            'INSERT INTO customers_info (customers_info_id, customers_info_number_of_logons, customers_info_date_account_created, token)
             VALUES (:customerId, 0, :now, \'\')',
            [':customerId' => $customerId, ':now' => date('Y-m-d H:i:s')]
        );

        return $customerId;
    }

    /**
     * @param array{firstName: string, lastName: string, company: string, street: string, addInfo: string, zip: string, city: string, state: string, countryId: string, country: string, addressFormatId: string} $billing
     * @param array{firstName: string, lastName: string, company: string, street: string, addInfo: string, zip: string, city: string, state: string, countryId: string, country: string, addressFormatId: string} $shipping
     * @param array{totalGross: float, totalNet: float, vatGroups: array<string, float>, deliveryCost: float, deliveryVat: float, totalOrderSum: float} $prices
     */
    private function insertOrder(
        string $everysizeOrderId, string $email, string $customerId,
        array $billing, array $shipping, string $orderDate, int $orderStatus, bool $revoked,
        array $prices, array $language, string $payPalCaptureId, string $currency, string $currencyValue
    ): string {
        $billingName = $this->name($billing['firstName'], $billing['lastName']);
        $shippingName = $this->name($shipping['firstName'], $shipping['lastName']);

        $this->db->execute(
            'INSERT INTO orders (platform_id, customers_id, customers_name, customers_firstname, customers_lastname,
                customers_company, customers_street_address, customers_suburb, customers_city, customers_postcode,
                customers_state, customers_country, customers_telephone, customers_email_address,
                customers_address_format_id,
                delivery_name, delivery_firstname, delivery_lastname, delivery_company, delivery_street_address,
                delivery_suburb, delivery_city, delivery_postcode, delivery_state, delivery_country,
                delivery_address_format_id,
                billing_name, billing_firstname, billing_lastname, billing_company, billing_street_address,
                billing_suburb, billing_city, billing_postcode, billing_state, billing_country,
                billing_address_format_id,
                payment_method, payment_class, shipping_method, shipping_class, transaction_id,
                date_purchased, orders_status, currency, currency_value, language_id, stock_updated)
             VALUES (:platformId, :customerId, :customersName, :billingFirstName, :billingLastName,
                :company, :street, :suburb, :city, :zip,
                :state, :country, :telephone, :email, :formatId,
                :deliveryName, :deliveryFirstName, :deliveryLastName, :deliveryCompany, :deliveryStreet,
                :deliverySuburb, :deliveryCity, :deliveryZip, :deliveryState, :deliveryCountry,
                :deliveryFormatId,
                :billingName, :billingFirstName, :billingLastName, :company, :street,
                :suburb, :city, :zip, :state, :country, :formatId,
                :paymentMethod, :paymentClass, :shippingMethod, :shippingClass, :transactionId,
                :datePurchased, :orderStatus, :currency, :currencyValue, :languageId, :stockUpdated)',
            [
                ':platformId' => $this->settings->getPlatformId(),
                ':customerId' => $customerId,
                ':customersName' => $billingName,
                ':billingFirstName' => $this->cut($billing['firstName'], 255),
                ':billingLastName' => $this->cut($billing['lastName'], 255),
                ':company' => $this->cut($billing['company'], 32),
                ':street' => $this->cut($billing['street'], 64),
                ':suburb' => $this->cut($billing['addInfo'], 32),
                ':city' => $this->cut($billing['city'], 32),
                ':zip' => $this->cut($billing['zip'], 10),
                ':state' => $this->cut($billing['state'], 32),
                ':country' => $this->cut($billing['country'], 64),
                ':telephone' => $this->cut('', 32),
                ':email' => $this->cut($email, 96),
                ':formatId' => $billing['addressFormatId'],
                ':deliveryName' => $shippingName,
                ':deliveryFirstName' => $this->cut($shipping['firstName'], 255),
                ':deliveryLastName' => $this->cut($shipping['lastName'], 255),
                ':deliveryCompany' => $this->cut($shipping['company'], 32),
                ':deliveryStreet' => $this->cut($shipping['street'], 64),
                ':deliverySuburb' => $this->cut($shipping['addInfo'], 32),
                ':deliveryCity' => $this->cut($shipping['city'], 32),
                ':deliveryZip' => $this->cut($shipping['zip'], 10),
                ':deliveryState' => $this->cut($shipping['state'], 32),
                ':deliveryCountry' => $this->cut($shipping['country'], 64),
                ':deliveryFormatId' => $shipping['addressFormatId'],
                ':billingName' => $billingName,
                ':paymentMethod' => $this->cut('everysize (PayPal)', 255),
                ':paymentClass' => 'everysize',
                ':shippingMethod' => $this->cut('everysize Versand', 255),
                ':shippingClass' => 'everysize',
                ':transactionId' => $this->cut($payPalCaptureId, 32),
                // The import note lives in orders_status_history (orders has
                // no comments column in the osC 4 schema).
                ':datePurchased' => $orderDate,
                ':orderStatus' => $orderStatus,
                ':currency' => $currency,
                ':currencyValue' => $currencyValue,
                ':languageId' => $language['id'],
                // The stock is reduced in the same transaction (STOCK_LIMITED
                // respected) — the flag tells the admin editor it was done.
                ':stockUpdated' => (!$revoked && $this->stockLimited()) ? 1 : 0,
            ]
        );

        $ordersId = $this->db->lastInsertId();
        if ($ordersId === null) {
            throw new ImportException(sprintf('%s: order could not be inserted', $everysizeOrderId));
        }

        return $ordersId;
    }

    /** "Firstname Lastname" as the address block name. */
    private function name(string $firstName, string $lastName): string
    {
        return $this->cut(trim($firstName . ' ' . $lastName), 255);
    }

    /**
     * @param array{sku: string, name: string, model: string, uprid: string, quantity: int, attributes: list<array{optionsId: int, valuesId: int, optionsName: string, valuesName: string}>} $line
     * @param array{unitGross: float, unitNet: float, totalGross: float, totalNet: float, totalVat: float, vat: float} $price
     */
    private function insertOrderProduct(string $ordersId, array $line, array $price): string
    {
        // The shop's article name first (like the other everysize plugins),
        // everysize's title only as fallback.
        $name = $line['name'] !== '' ? $line['name'] : $line['title'];

        // osCommerce expects the NET unit price (products_price) and the net
        // unit price again in final_price — the admin renders
        // final_price * (1 + tax/100) * qty. Both to six decimals.
        $unitNet = number_format($price['unitNet'], 6, '.', '');

        $this->db->execute(
            'INSERT INTO orders_products (orders_id, products_id, products_model, products_name,
                products_price, final_price, products_tax, products_quantity, uprid)
             VALUES (:ordersId, :productsId, :model, :name,
                :unitNet, :finalPrice, :tax, :quantity, :uprid)',
            [
                ':ordersId' => $ordersId,
                ':productsId' => $line['productsId'],
                ':model' => $this->cut($line['model'], 25),
                ':name' => $this->cut($name, 65535),
                ':unitNet' => $unitNet,
                ':finalPrice' => $unitNet,
                ':tax' => number_format($price['vat'], 4, '.', ''),
                ':quantity' => (string) $line['quantity'],
                ':uprid' => $this->cut($line['uprid'], 255),
            ]
        );
        $ordersProductsId = (string) $this->db->lastInsertId();

        // The sizes as classic attribute rows — that is what the order page,
        // the invoice and the packing slip show ("Größe (EU): 38").
        foreach ($line['attributes'] as $attribute) {
            $this->db->execute(
                'INSERT INTO orders_products_attributes (orders_id, orders_products_id,
                    products_options, products_options_values, options_values_price, price_prefix,
                    products_options_id, products_options_values_id)
                 VALUES (:ordersId, :ordersProductsId, :optionsName, :valuesName, 0.000000, \'+\',
                    :optionsId, :valuesId)',
                [
                    ':ordersId' => $ordersId,
                    ':ordersProductsId' => $ordersProductsId,
                    ':optionsName' => $this->cut($attribute['optionsName'], 255),
                    ':valuesName' => $this->cut($attribute['valuesName'], 255),
                    ':optionsId' => $attribute['optionsId'],
                    ':valuesId' => $attribute['valuesId'],
                ]
            );
        }

        return $ordersProductsId;
    }

    /**
     * Stock like the shop's own checkout: the variant's stock lives in
     * inventory (the classic options combination), a bare product's in
     * products. Only reduced when the shop limits stock (STOCK_LIMITED);
     * orders.stock_updated tells the admin editor it was done. Stock may go
     * negative (paid order!) — that is a warning, not an error. Sales
     * statistics always, independent of stock handling.
     *
     * @param array{sku: string, quantity: int, productsId: string, inventoryId: ?string} $line
     *
     * @return list<string>
     */
    private function reduceStock(array $line, string $everysizeOrderId): array
    {
        $warnings = [];
        $this->db->execute(
            'UPDATE products SET products_ordered = products_ordered + :qty WHERE products_id = :id',
            [':qty' => (string) $line['quantity'], ':id' => $line['productsId']]
        );

        if (!$this->stockLimited()) {
            return $warnings;
        }

        if ($line['inventoryId'] !== null) {
            $this->db->execute(
                'UPDATE inventory SET products_quantity = products_quantity - :qty WHERE inventory_id = :id',
                [':qty' => (string) $line['quantity'], ':id' => $line['inventoryId']]
            );
            $remaining = $this->db->fetchValue(
                'SELECT products_quantity FROM inventory WHERE inventory_id = :id',
                [':id' => $line['inventoryId']]
            );
        } else {
            $this->db->execute(
                'UPDATE products SET products_quantity = products_quantity - :qty WHERE products_id = :id',
                [':qty' => (string) $line['quantity'], ':id' => $line['productsId']]
            );
            $remaining = $this->db->fetchValue(
                'SELECT products_quantity FROM products WHERE products_id = :id',
                [':id' => $line['productsId']]
            );
        }
        if ($remaining !== null && (float) $remaining < 0) {
            $warnings[] = $this->texts->t('stock_warning', $everysizeOrderId, $line['sku'], (float) $remaining);
        }

        return $warnings;
    }

    /**
     * orders_total with the classes the shop's own order total modules write:
     * ot_subtotal (gross item sum), ot_shipping, one ot_tax line per VAT rate
     * (each with its own sort order — the admin keys totals by sort order),
     * ot_total, ot_paid (paid in full via PayPal) and ot_due (0 — without
     * them the order page warns "Undefined key"). Titles from the order
     * language with sane fallbacks; the text columns carry the money string
     * the admin shows, formatted like Currencies::format (symbol, decimal
     * point, thousands separator, rate from the currencies table).
     *
     * @param array{totalGross: float, totalNet: float, vatGroups: array<string, float>, deliveryCost: float, deliveryVat: float, totalOrderSum: float} $prices
     */
    private function insertOrderTotals(string $ordersId, array $prices, array $language, string $currency, string $currencyValue): void
    {
        $texts = $this->languageTexts($language['id'], [
            'MODULE_ORDER_TOTAL_SUBTOTAL_TITLE',
            'MODULE_ORDER_TOTAL_SHIPPING_TITLE',
            'MODULE_ORDER_TOTAL_TOTAL_TITLE',
            'MODULE_ORDER_TOTAL_PAID_TITLE',
            'MODULE_ORDER_TOTAL_DUE_TITLE',
        ]);
        $subtotalTitle = $texts['MODULE_ORDER_TOTAL_SUBTOTAL_TITLE'] ?: 'Sub-Total';
        $shippingTitle = $texts['MODULE_ORDER_TOTAL_SHIPPING_TITLE'] ?: 'Shipping';
        $totalTitle = $texts['MODULE_ORDER_TOTAL_TOTAL_TITLE'] ?: 'Total';
        $paidTitle = $texts['MODULE_ORDER_TOTAL_PAID_TITLE'] ?: 'Amount Paid';
        $dueTitle = $texts['MODULE_ORDER_TOTAL_DUE_TITLE'] ?: 'Amount Due';

        $sort = $this->totalSortOrders();
        $format = $this->currencyFormatter($currency, $currencyValue);

        // Shipping carries the VAT rate of the items (PriceCalculator) — its
        // VAT joins the highest item rate, like the shop shows one tax line.
        $deliveryVat = $prices['deliveryCost'] > 0 && $prices['deliveryVat'] > 0
            ? round($prices['deliveryCost'] - $prices['deliveryCost'] / (1 + $prices['deliveryVat'] / 100), 2)
            : 0.0;
        $deliveryNet = round($prices['deliveryCost'] - $deliveryVat, 2);
        $totalNet = round($prices['totalNet'] + $deliveryNet, 2);

        $this->insertTotal(
            $ordersId, $this->cut($subtotalTitle.':', 255), round($prices['totalGross'], 2), round($prices['totalNet'], 2), 'ot_subtotal', $sort['subtotal'], $format
        );
        $this->insertTotal(
            $ordersId, $this->cut($shippingTitle.':', 255), $prices['deliveryCost'], $deliveryNet, 'ot_shipping', $sort['shipping'], $format
        );

        // One row per VAT rate, each with its own sort order (the admin keys
        // totals by sort order — equal orders would overwrite each other),
        // like ot_tax writes sort_order + step. The tax amount is the same
        // gross and net (it IS the tax), like the core's text_exc_tax.
        $descriptions = $this->taxDescriptions();
        $step = 0;
        foreach ($prices['vatGroups'] as $rate => $amount) {
            $description = $descriptions[(float) $rate] ?? sprintf('%s%%', rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.'));
            $taxAmount = round((float) $amount + ($step === 0 ? $deliveryVat : 0.0), 2);
            $this->insertTotal(
                $ordersId, $this->cut($description.':', 255), $taxAmount, $taxAmount, 'ot_tax', $sort['tax'] + $step, $format
            );
            $step++;
        }

        $this->insertTotal(
            $ordersId, $this->cut($totalTitle.':', 255), $prices['totalOrderSum'], $totalNet, 'ot_total', $sort['total'], $format
        );
        $this->insertTotal(
            $ordersId, $this->cut($paidTitle.':', 255), $prices['totalOrderSum'], $totalNet, 'ot_paid', $sort['paid'], $format
        );
        $this->insertTotal(
            $ordersId, $this->cut($dueTitle.':', 255), 0.0, 0.0, 'ot_due', $sort['due'], $format
        );
    }

    /**
     * Sort orders of the order total modules, from the shop configuration
     * (MODULE_ORDER_TOTAL_*_SORT_ORDER as the admin set them) with
     * collision-free fallbacks when the modules are not installed — the
     * admin keys totals by sort order, so no two rows may share one.
     * (ot_paid/ot_due carry the core default 2, which would collide with
     * shipping; they go behind ot_total instead.)
     *
     * @return array{subtotal: int, shipping: int, tax: int, total: int, paid: int, due: int}
     */
    private function totalSortOrders(): array
    {
        $defaults = [
            'subtotal' => ['MODULE_ORDER_TOTAL_SUBTOTAL_SORT_ORDER', 1],
            'shipping' => ['MODULE_ORDER_TOTAL_SHIPPING_SORT_ORDER', 2],
            'tax' => ['MODULE_ORDER_TOTAL_TAX_SORT_ORDER', 3],
            'total' => ['MODULE_ORDER_TOTAL_TOTAL_SORT_ORDER', 4],
            'paid' => ['MODULE_ORDER_TOTAL_PAID_SORT_ORDER', 5],
            'due' => ['MODULE_ORDER_TOTAL_DUE_SORT_ORDER', 6],
        ];
        $sort = [];
        foreach ($defaults as $key => [$configKey, $default]) {
            $value = $this->db->fetchValue(
                'SELECT configuration_value FROM configuration WHERE configuration_key = :key LIMIT 1',
                [':key' => $configKey]
            );
            $sort[$key] = (int) ($value !== null && $value !== '' ? $value : $default);
        }

        return $sort;
    }

    /** The tax description per rate of the delivery-country zones ("MwSt. 19%"). */
    private function taxDescriptions(): array
    {
        $descriptions = [];
        foreach ($this->db->fetchRows('SELECT tax_rate AS TAX_RATE, tax_description AS TAX_DESCRIPTION FROM tax_rates') as $row) {
            $descriptions[(float) $row['TAX_RATE']] = (string) $row['TAX_DESCRIPTION'];
        }

        return $descriptions;
    }

    private function insertTotal(string $ordersId, string $title, float $incTax, float $excTax, string $class, int $sortOrder, array $format): void
    {
        $this->db->execute(
            'INSERT INTO orders_total (orders_id, title, text, value, class, sort_order, text_inc_tax, text_exc_tax,
                tax_class_id, value_inc_tax, value_exc_vat, is_removed, currency, currency_value)
             VALUES (:ordersId, :title, :text, :value, :class, :sortOrder, :textInc, :textExc,
                0, :valueInc, :valueExc, 0, :currency, :currencyValue)',
            [
                ':ordersId' => $ordersId,
                ':title' => $title,
                ':text' => $format['text']($incTax),
                ':value' => number_format($incTax, 6, '.', ''),
                ':class' => $class,
                ':sortOrder' => (string) $sortOrder,
                ':textInc' => $format['text']($incTax),
                ':textExc' => $format['text']($excTax),
                ':valueInc' => number_format($incTax, 6, '.', ''),
                ':valueExc' => number_format($excTax, 6, '.', ''),
                ':currency' => $format['currency'],
                ':currencyValue' => $format['value'],
            ]
        );
    }

    /**
     * Money text like the shop's own Currencies::format writes it: currency
     * symbol left/right, decimal point and thousands separator from the
     * currencies row, the amount multiplied by the rate. The rate comes from
     * the currencies table (the same value the order carries) — the input
     * amount is already in the order currency, so the factor is 1; the row
     * is read for the formatting settings only.
     *
     * @return array{currency: string, value: string, text: \Closure(float): string}
     */
    private function currencyFormatter(string $currency, string $currencyValue): array
    {
        $row = $this->db->fetchRows(
            'SELECT symbol_left AS SYMBOL_LEFT, symbol_right AS SYMBOL_RIGHT, decimal_point AS DECIMAL_POINT,
                    thousands_point AS THOUSANDS_POINT, decimal_places AS DECIMAL_PLACES
             FROM currencies WHERE code = :code LIMIT 1',
            [':code' => $currency]
        )[0] ?? [];

        $left = (string) ($row['SYMBOL_LEFT'] ?? '');
        $right = (string) ($row['SYMBOL_RIGHT'] ?? '');
        $point = ((string) ($row['DECIMAL_POINT'] ?? '')) ?: '.';
        $thousands = (string) ($row['THOUSANDS_POINT'] ?? '');
        $places = (int) ((((string) ($row['DECIMAL_PLACES'] ?? '')) !== '') ? $row['DECIMAL_PLACES'] : 2);

        return [
            'currency' => $currency,
            'value' => $currencyValue,
            'text' => static function (float $amount) use ($left, $right, $point, $thousands, $places): string {
                return $left . number_format(round($amount, $places), $places, $point, $thousands) . $right;
            },
        ];
    }

    private function insertStatusHistory(string $ordersId, int $statusId, string $everysizeOrderId): void
    {
        $this->db->execute(
            'INSERT INTO orders_status_history (orders_id, orders_status_id, date_added, customer_notified, comments, admin_id)
             VALUES (:ordersId, :statusId, :now, 0, :comments, 0)',
            [
                ':ordersId' => $ordersId,
                ':statusId' => (string) $statusId,
                ':now' => date('Y-m-d H:i:s'),
                ':comments' => $this->texts->t('history_imported', $everysizeOrderId),
            ]
        );
    }

    // --- Small helpers -------------------------------------------------------

    /** Whether the shop limits stock (configuration STOCK_LIMITED, default true). */
    private function stockLimited(): bool
    {
        return $this->db->fetchValue(
            "SELECT configuration_value FROM configuration WHERE configuration_key = 'STOCK_LIMITED' LIMIT 1"
        ) !== 'false';
    }

    /** Trims to the column length (all text columns are utf8-safe after that). */
    private function cut(string $value, int $length): string
    {
        return mb_substr($this->stripFourByte($value), 0, $length);
    }

    /** Removes 4-byte UTF-8 characters (emoji etc.): the order tables are utf8 (3-byte). */
    private function stripFourByte(string $value): string
    {
        return (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value);
    }

    /**
     * Order total titles of the order language, from the shop's translation
     * table (entity 'ordertotal', what the ot_* modules show). Unknown keys
     * stay empty; the callers have fallbacks.
     *
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function languageTexts(string $languageId, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = (string) ($this->db->fetchValue(
                'SELECT translation_value FROM translation
                 WHERE translation_entity = :entity AND translation_key = :key AND language_id = :lang LIMIT 1',
                [':entity' => 'ordertotal', ':key' => $key, ':lang' => $languageId]
            ) ?? '');
        }

        return $values;
    }
}
