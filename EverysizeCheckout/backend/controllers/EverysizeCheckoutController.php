<?php

declare(strict_types=1);

/**
 * everysize Checkout — the admin controller (route everysize-checkout/…).
 *
 * Views: backend/views/everysize-checkout/*.tpl (Smarty, osCommerce admin
 * look). ACL comes from Setup::getAclArray via SceletonExtensionsBackend.
 * The osCommerce base controller (Sceleton) switches the global CSRF check
 * off, so every state-changing action validates its own session page token
 * (mirrors the base controller's own token check) — settings, credentials, sync and the
 * refund (which moves money).
 *
 * Refunds from the order box are POSTed to everysize-checkout/refund via
 * AJAX (no nested <form>): the idempotency key is generated per page view
 * and kept in the session, so a double submit refunds once — a SECOND
 * deliberate refund (partial refunds are fine) gets a fresh key with the
 * response, which the form picks up.
 */

namespace common\extensions\EverysizeCheckout\backend\controllers;

use common\extensions\EverysizeCheckout\EverysizeCheckout;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EverysizeCheckoutController extends \common\classes\modules\SceletonExtensionsBackend
{
    /** @var array<int, string> the 18 everysize carriers (Settings.php) */
    private const CARRIERS = [
        'Cargo', 'Der Courier', 'Deutsche Post', 'DHL', 'DPD', 'eparcel', 'FedEx', 'GdSK', 'GLS', 'GO!',
        'Hermes', 'iloxx', 'Midway', 'Noxxs Logistic', 'paket.ag', 'primeMail', 'TOMBA', 'UPS',
    ];

    /** Actions that change state and therefore need the page token. */
    private const TOKEN_ACTIONS = ['settings', 'test-connection', 'remove-credentials', 'sync-now', 'refund'];

    public function __construct($id, $module = null, $config = [])
    {
        parent::__construct($id, $module, $config);
        $this->initExtensionClass();
        $this->ext = EverysizeCheckout::class;
    }

    public function beforeAction($action)
    {
        $this->navigation[] = [
            'link' => Yii::$app->urlManager->createUrl('everysize-checkout/index'),
            'title' => EXT_EC_TITLE,
        ];

        $isPost = Yii::$app->request->getIsPost();
        if ($isPost && in_array($action->id, self::TOKEN_ACTIONS, true)) {
            $this->validatePageToken();
        }
        if (in_array($action->id, self::TOKEN_ACTIONS, true)) {
            self::ensurePageToken();
        }

        return parent::beforeAction($action);
    }

    // --- Pages ---------------------------------------------------------------

    public function actionIndex()
    {
        $module = EverysizeCheckout::module();
        $settings = $module->settings();
        $this->view->headingTitle = EXT_EC_TITLE;

        $env = \Everysize\Checkout\Api\ApiUrl::describe();
        $this->view->env = [
            'label' => match ($env['environment']) {
                'production' => EXT_EC_ENV_PRODUCTION,
                'test' => EXT_EC_ENV_TEST,
                default => EXT_EC_ENV_INVALID,
            },
            'url' => $env['url'] !== '' ? $env['url'] : $env['error'],
            'invalid' => $env['environment'] === 'invalid',
        ];
        $this->view->configured = $settings->isConfigured();
        $this->view->clientId = $settings->getClientId();
        $this->view->shopVersion = EverysizeCheckout::shopVersion();
        $this->view->pageToken = self::ensurePageToken();

        // Numbers of the own tables, for the overview.
        $db = new \Everysize\Checkout\osCommerce\Db();
        $this->view->counts = [
            'orders' => (int) ($db->fetchValue('SELECT COUNT(*) FROM everysize_checkout_order') ?? '0'),
            'reports' => (int) ($db->fetchValue('SELECT COUNT(*) FROM everysize_checkout_report') ?? '0'),
        ];

        $this->view->lastRun = [];
        foreach ($module->protocol()->list('', 5, 0) as $row) {
            $this->view->lastRun[] = $row;
        }

        return $this->render('index');
    }

    public function actionSettings()
    {
        $module = EverysizeCheckout::module();
        /** @var \Everysize\Checkout\osCommerce\SettingsImpl $settings */
        $settings = $module->settings();
        $this->view->headingTitle = EXT_EC_TITLE . ' — ' . EXT_EC_MENU_SETTINGS;
        $this->navigation[] = ['link' => Yii::$app->urlManager->createUrl('everysize-checkout/settings'), 'title' => $this->view->headingTitle];

        if (Yii::$app->request->getIsPost()) {
            $post = Yii::$app->request->post();
            $this->saveSettings($settings, $post);
            Yii::$app->getSession()->setFlash('everysize_checkout_saved', EXT_EC_SAVED);
            Yii::$app->getResponse()->redirect(Yii::$app->urlManager->createUrl('everysize-checkout/settings'));
        }

        $env = \Everysize\Checkout\Api\ApiUrl::describe();
        $this->view->env = [
            'label' => match ($env['environment']) {
                'production' => EXT_EC_ENV_PRODUCTION,
                'test' => EXT_EC_ENV_TEST,
                default => EXT_EC_ENV_INVALID,
            },
            'url' => $env['url'] !== '' ? $env['url'] : $env['error'],
            'invalid' => $env['environment'] === 'invalid',
        ];
        $this->view->clientId = $settings->getClientId();
        $this->view->secretStored = $settings->getClientSecret() !== '';
        $this->view->values = $this->viewData($settings);
        $this->view->statusOptions = $this->statusOptions();
        $this->view->carriers = self::CARRIERS;
        $this->view->platformOptions = $settings->platformOptions();
        $this->view->pageToken = self::ensurePageToken();
        $this->view->cronUrl = $this->cronUrl($settings);
        $this->view->cronTokenSet = $settings->getCronToken() !== '';

        return $this->render('settings');
    }

    /**
     * The frontend cron URL for the settings page: the admin host serves
     * the shop too, the stored token goes into the query (the admin page
     * may show it — never logs or screenshots).
     */
    private function cronUrl(\Everysize\Checkout\osCommerce\SettingsImpl $settings): string
    {
        $url = rtrim((string) Yii::$app->request->getHostInfo(), '/') . '/everysize-checkout-cron/everysize';
        $token = $settings->getCronToken();

        return $token !== '' ? $url . '?token=' . rawurlencode($token) : $url;
    }

    public function actionProtocol()
    {
        $module = EverysizeCheckout::module();
        $this->view->headingTitle = EXT_EC_TITLE . ' — ' . EXT_EC_MENU_PROTOCOL;
        $this->navigation[] = ['link' => Yii::$app->urlManager->createUrl('everysize-checkout/protocol'), 'title' => $this->view->headingTitle];

        $filter = (string) Yii::$app->request->get('filter', '');
        if (!in_array($filter, ['all', 'ok', 'error'], true)) {
            $filter = 'all';
        }

        $perPage = 50;
        $pages = (int) ceil($module->protocol()->count($filter === 'all' ? '' : $filter) / $perPage);
        // A way-back ?page=N must not show an empty page while rows exist.
        $page = min(max(1, (int) Yii::$app->request->get('page', 1)), max(1, $pages));

        $this->view->filter = $filter;
        $this->view->page = $page;
        $this->view->pages = $pages;
        $this->view->rows = $module->protocol()->list($filter === 'all' ? '' : $filter, $perPage, ($page - 1) * $perPage);

        return $this->render('protocol');
    }

    // --- Actions (POST/AJAX) -------------------------------------------------

    /** The "Test connection" button (entered values, else stored ones). */
    public function actionTestConnection()
    {
        if (!Yii::$app->request->getIsPost()) {
            throw new BadRequestHttpException();
        }
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $clientId = trim((string) Yii::$app->request->post('client_id', ''));
            $clientSecret = trim((string) Yii::$app->request->post('client_secret', ''));
            $module = EverysizeCheckout::module();
            $settings = $module->settings();
            if ($clientId === '' || $clientSecret === '') {
                $clientId = $clientId !== '' ? $clientId : $settings->getClientId();
                $clientSecret = $clientSecret !== '' ? $clientSecret : $settings->getClientSecret();
            }
            if ($clientId === '' || $clientSecret === '') {
                return ['ok' => false, 'message' => 'no credentials'];
            }

            $client = new \Everysize\Checkout\Api\Client(
                $clientId,
                $clientSecret,
                \Everysize\Checkout\Api\ApiUrl::resolve(),
                EverysizeCheckout::shopVersion()
            );
            $shopId = $client->testConnection();

            return ['ok' => true, 'shop_id' => $shopId, 'url' => $client->getApiUrl()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function actionRemoveCredentials()
    {
        if (!Yii::$app->request->getIsPost()) {
            throw new BadRequestHttpException();
        }
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $settings = new \Everysize\Checkout\osCommerce\SettingsImpl(new \Everysize\Checkout\osCommerce\Db());
            $settings->delete('client_id');
            $settings->delete('client_secret');

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** "Sync now": a full run in the admin process, result as JSON. */
    public function actionSyncNow()
    {
        if (!Yii::$app->request->getIsPost()) {
            throw new BadRequestHttpException();
        }
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $sync = EverysizeCheckout::module()->sync();
            if ($sync === null) {
                return ['ok' => false, 'message' => 'no credentials'];
            }
            $result = $sync->run();

            return ['ok' => $result['errors'] === [], 'messages' => $result['messages'], 'errors' => $result['errors']];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The refund of the order box (AJAX, no nested form): amount, reason —
     * refunded through everysize (PayPal), reported as kind "refund" and a
     * status history comment. Several partial refunds are fine (like in the
     * other everysize plugins); a refund the API already knows about comes
     * back as ALREADY_REFUNDED and is recorded, but refunds nothing again.
     *
     * "report_return" = 1 (shipped orders only): the goods came back — the
     * return is reported to everysize FIRST (a full refund may revoke the
     * order at everysize, which then takes no return), then the chosen amount
     * is refunded, e.g. without the shipping costs. "refund" = 0 reports the
     * return alone (already refunded in full). osCommerce 4 has no return
     * status, so this is the merchant's one step for an everysize return.
     */
    public function actionRefund()
    {
        if (!Yii::$app->request->getIsPost()) {
            throw new BadRequestHttpException();
        }
        Yii::$app->response->format = Response::FORMAT_JSON;

        $returned = null;
        try {
            $ordersId = (string) Yii::$app->request->post('orders_id', '');
            $amountInput = trim((string) Yii::$app->request->post('amount', ''));
            if ($ordersId === '' || !preg_match('/^\d+$/', $ordersId)) {
                throw new \RuntimeException('invalid order');
            }

            $links = EverysizeCheckout::module()->links();
            $link = $links->getLink($ordersId);
            if ($link === null) {
                throw new NotFoundHttpException();
            }

            $session = Yii::$app->getSession();
            $name = 'everysize_refund_key_' . $ordersId;
            // The posted key (the form's page-view key) makes a double submit
            // idempotent at the API; without one (second click without reload,
            // scripted call) a fresh key is issued and used.
            $key = trim((string) Yii::$app->request->post('key', ''));
            if ($key === '' || strlen($key) > 64 || !preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
                $key = (string) $session->get($name, '');
                if ($key === '') {
                    $key = self::newRefundKey();
                    $session->set($name, $key);
                }
            }

            $module = EverysizeCheckout::module();
            $client = $module->client();
            $amount = \Everysize\Checkout\RefundAmount::normalize($amountInput); // null = rest
            if ($amountInput !== '' && $amount === null) {
                throw new \RuntimeException('invalid amount');
            }
            $reportReturn = (string) Yii::$app->request->post('report_return', '') === '1';
            $doRefund = (string) Yii::$app->request->post('refund', '1') !== '0';
            if (!$doRefund && !$reportReturn) {
                throw new \RuntimeException('nothing to do');
            }
            // Protocol and history in the shop's default language, like the sync writes them.
            $texts = $module->texts();

            if ($reportReturn) {
                if (!$links->hasSentFulfillment($ordersId)) {
                    throw new \RuntimeException('not shipped');
                }
                $returned = $module->exporter()->reportReturn($ordersId, $link['EVERYSIZE_ORDER_ID']);
                $module->protocol()?->add(
                    $texts->t('return_title'),
                    $texts->t($returned['skipped'] > 0 ? 'return_skipped_message' : 'return_message', $ordersId, $link['EVERYSIZE_ORDER_ID']),
                    $returned['skipped'] === 0
                );
            }
            if (!$doRefund) {
                return ['ok' => true, 'returned' => $returned, 'refunded' => false];
            }

            $refund = $client->refund($link['EVERYSIZE_ORDER_ID'], $amount, $key);
            $status = strtoupper((string) ($refund['status'] ?? ''));
            $refundId = (string) ($refund['refundId'] ?? '');
            $refundAmount = (string) ($refund['amount'] ?? ($amount ?? ''));

            $already = $status === 'ALREADY_REFUNDED';
            $reportedAmount = is_numeric($refundAmount) ? $refundAmount : (string) ($amount ?? '');
            $links->addReport(
                $ordersId,
                \Everysize\Checkout\OrderLinks::KIND_REFUND,
                $refundId !== '' ? $refundId : $status,
                $already ? '0.00' : $reportedAmount,
                $already ? \Everysize\Checkout\OrderLinks::RESULT_KNOWN : \Everysize\Checkout\OrderLinks::RESULT_SENT
            );
            if (!$already) {
                $links->addHistoryComment($ordersId, \Everysize\Checkout\OrderLinks::refundComment(
                    $texts,
                    $refundAmount,
                    (string) ($refund['currency'] ?? 'EUR'),
                    $refundId,
                    false
                ));
            }
            $refundAmountDisplay = is_numeric($refundAmount) ? number_format((float) $refundAmount, 2, ',', '.') : $refundAmount;
            $module->protocol()?->add(
                $already ? $texts->t('refund_already_title') : $texts->t('refund_title'),
                $already
                    ? $texts->t('refund_already_message', $ordersId, $link['EVERYSIZE_ORDER_ID'])
                    : $texts->t(
                        'refund_message',
                        $ordersId,
                        $link['EVERYSIZE_ORDER_ID'],
                        $refundAmountDisplay,
                        (string) ($refund['currency'] ?? 'EUR') !== '' ? (string) ($refund['currency'] ?? 'EUR') : 'EUR',
                        $refundId !== '' ? $texts->t('refund_id_suffix', $refundId) : ''
                    ),
                true
            );

            // The next deliberate refund needs a fresh idempotency key; the
            // form picks it up from the response (double submit keeps the
            // old key and is idempotent at the API).
            $newKey = self::newRefundKey();
            $session->set($name, $newKey);

            return [
                'ok' => true,
                'returned' => $returned,
                'refunded' => true,
                'already' => $already,
                'refundId' => $refundId,
                'amount' => $refundAmount,
                'key' => $newKey,
            ];
        } catch (\Throwable $e) {
            $failedModule = EverysizeCheckout::module();
            $failedModule->protocol()?->add(
                $failedModule->texts()->t('refund_failed_title'),
                $failedModule->texts()->t('refund_failed_message', $ordersId ?? '', $e->getMessage()),
                false
            );

            // A return reported before a failed refund stays reported — the box shows both.
            return ['ok' => false, 'message' => $e->getMessage(), 'returned' => $returned];
        }
    }

    // --- Helpers (page view / hook) ------------------------------------------

    /**
     * The data of the order detail box (the hook in orders/process-order
     * totals-block calls this). Null for orders that are not everysize ones.
     *
     * @return array{ORDER_ID: string, EVERYSIZE_ORDER_ID: string, PAYPAL_CAPTURE_ID: string, ORIGINAL_EMAIL: string, NUMBER_REPORTED: ?string, CREATED: string}|null
     */
    public static function orderBoxData(string $ordersId): ?array
    {
        return EverysizeCheckout::module()->links()->getLink($ordersId);
    }

    /** The reports of an order (fulfillment/tracking/revocation/refund rows), labels translated for the box. */
    public static function orderBoxReports(string $ordersId): array
    {
        $db = new \Everysize\Checkout\osCommerce\Db();
        $currency = self::orderCurrency($ordersId, $db);
        $rows = [];
        foreach (EverysizeCheckout::module()->links()->getReports($ordersId) as $report) {
            $kind = (string) $report['KIND'];
            $reason = (string) $report['REASON'];
            $sku = (string) $report['SKU'];
            $rows[] = [
                'date' => self::formatUtc($report['CREATED']),
                'kind' => self::kindLabel($kind, $reason),
                'subject' => $sku !== '' ? ($sku === \Everysize\Checkout\OrderLinks::AUTO_REFUND ? EXT_EC_REFUND_AUTO : $sku) : '–',
                'reason' => self::reasonLabel($kind, $reason, $currency, $db),
                'result' => self::resultLabel((string) $report['RESULT']),
                'resultClass' => self::resultClass((string) $report['RESULT']),
            ];
        }

        return $rows;
    }

    /**
     * Whether the box offers "report return": the shipment was reported and
     * at least one position has no revocation yet.
     */
    public static function canReportReturn(string $ordersId): bool
    {
        $links = EverysizeCheckout::module()->links();

        return $links->hasSentFulfillment($ordersId) && $links->getUnrevokedSkus($ordersId) !== [];
    }

    /** The idempotency key of the refund form, generated per page view. */
    public static function ensureRefundKey(string $ordersId): string
    {
        $session = Yii::$app->getSession();
        $name = 'everysize_refund_key_' . $ordersId;
        $key = (string) $session->get($name, '');
        if ($key === '') {
            $key = self::newRefundKey();
            $session->set($name, $key);
        }

        return $key;
    }

    /**
     * The session page token every state-changing action validates (the
     * osCommerce base controller switches the global CSRF check off).
     */
    public static function ensurePageToken(): string
    {
        $session = Yii::$app->getSession();
        $token = (string) $session->get('everysize_page_token', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $session->set('everysize_page_token', $token);
        }

        return $token;
    }

    /** Rejects a state-changing request without the current page token. */
    private function validatePageToken(): void
    {
        $given = (string) Yii::$app->request->post('ec_token', '');
        $expected = (string) Yii::$app->getSession()->get('everysize_page_token', '');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw new BadRequestHttpException('invalid or missing page token');
        }
    }

    private static function newRefundKey(): string
    {
        return 'admin-' . bin2hex(random_bytes(16));
    }

    /**
     * The refund section of the order box: paid (order total), refunded and
     * still open, plus the individual refunds. Only real refunds count —
     * the reports of the skipped automatic refund carry a reason code in
     * the reason column, not an amount (they show in section 3).
     *
     * @return array{paid: float, refunded: float, open: float, format: array{paid: string, refunded: string, open: string}, rows: list<array{date: string, amount: string, status: string, statusClass: string, id: string}>, currency: string}
     */
    public static function refundData(string $ordersId): array
    {
        $db = new \Everysize\Checkout\osCommerce\Db();
        $currency = self::orderCurrency($ordersId, $db);

        $refunded = 0.0;
        $rows = [];
        foreach (EverysizeCheckout::module()->links()->getReports($ordersId) as $row) {
            if ((string) $row['KIND'] !== \Everysize\Checkout\OrderLinks::KIND_REFUND
                || (string) $row['SKU'] === \Everysize\Checkout\OrderLinks::AUTO_REFUND
                || !is_numeric((string) $row['REASON'])
            ) {
                continue;
            }
            $amount = round((float) $row['REASON'], 2);
            $refunded += $amount;
            $rows[] = [
                'date' => self::formatUtc($row['CREATED']),
                'amount' => self::money($amount, $currency, $db),
                'status' => self::resultLabel((string) $row['RESULT']),
                'statusClass' => self::resultClass((string) $row['RESULT']),
                'id' => (string) $row['SKU'],
            ];
        }
        $refunded = round($refunded, 2);

        $paid = round(self::orderTotal($ordersId, $db), 2);
        $open = max(0.0, round($paid - $refunded, 2));

        return [
            'paid' => $paid,
            'refunded' => $refunded,
            'open' => $open,
            'format' => [
                'paid' => self::money($paid, $currency, $db),
                'refunded' => self::money($refunded, $currency, $db),
                'open' => self::money($open, $currency, $db),
            ],
            'rows' => $rows,
            'currency' => $currency,
            // The amount input's prefill: plain number, comma decimal.
            'openInput' => number_format($open, 2, ',', ''),
        ];
    }

    /** The order's gross total (ot_total, value_inc_tax). */
    private static function orderTotal(string $ordersId, ?\Everysize\Checkout\osCommerce\Db $db = null): float
    {
        $db = $db ?? new \Everysize\Checkout\osCommerce\Db();

        return (float) ($db->fetchValue(
            'SELECT value_inc_tax FROM orders_total WHERE orders_id = :id AND class = :class LIMIT 1',
            [':id' => $ordersId, ':class' => 'ot_total']
        ) ?? '0');
    }

    private static function orderCurrency(string $ordersId, ?\Everysize\Checkout\osCommerce\Db $db = null): string
    {
        $db = $db ?? new \Everysize\Checkout\osCommerce\Db();

        return (string) ($db->fetchValue('SELECT currency FROM orders WHERE orders_id = :id', [':id' => $ordersId]) ?: 'EUR');
    }

    /** The report kind for the box: revocation with RETOUR is the return. */
    private static function kindLabel(string $kind, string $reason): string
    {
        if ($kind === \Everysize\Checkout\OrderLinks::KIND_REVOCATION) {
            return $reason === 'RETOUR' ? EXT_EC_KIND_RETURN : EXT_EC_KIND_REVOCATION;
        }

        return match ($kind) {
            \Everysize\Checkout\OrderLinks::KIND_FULFILLMENT => EXT_EC_KIND_FULFILLMENT,
            \Everysize\Checkout\OrderLinks::KIND_TRACKING => EXT_EC_KIND_TRACKING,
            \Everysize\Checkout\OrderLinks::KIND_REFUND => EXT_EC_KIND_REFUND,
            default => $kind,
        };
    }

    /**
     * The report's reason/amount column: translated codes, amounts as money,
     * empty as dash. Codes truncated to 32 characters by earlier versions
     * (the old reason column width) are recognised by prefix.
     */
    private static function reasonLabel(string $kind, string $reason, string $currency, \Everysize\Checkout\osCommerce\Db $db): string
    {
        if ($reason === '') {
            return '–';
        }
        if (is_numeric($reason)) {
            return $kind === \Everysize\Checkout\OrderLinks::KIND_REFUND ? self::money((float) $reason, $currency, $db) : $reason;
        }

        $known = [
            'MERCHANT_DECLINE' => EXT_EC_REASON_MERCHANT_DECLINE,
            'CUSTOMER_REVOKE' => EXT_EC_REASON_CUSTOMER_REVOKE,
            'RETOUR' => EXT_EC_REASON_RETOUR,
            'ALREADY_REFUNDED' => EXT_EC_REASON_ALREADY_REFUNDED,
            'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS' => EXT_EC_REASON_NOT_PAID,
            'REFUND_AMOUNT_INVALID' => EXT_EC_REASON_AMOUNT_INVALID,
            'PAYPAL_REFUND_FAILED' => EXT_EC_REASON_PAYPAL_FAILED,
        ];
        if (isset($known[$reason])) {
            return $known[$reason];
        }
        // Old data: codes cut to 32 characters — match by prefix (only long
        // enough prefixes, so arbitrary text is not mistaken for a code).
        if (strlen($reason) >= 8) {
            foreach ($known as $code => $label) {
                if (strlen($reason) < strlen($code) && strncmp($code, $reason, strlen($reason)) === 0) {
                    return $label;
                }
            }
        }

        return $reason;
    }

    private static function resultLabel(string $result): string
    {
        return match ($result) {
            \Everysize\Checkout\OrderLinks::RESULT_SENT => EXT_EC_RESULT_SENT,
            \Everysize\Checkout\OrderLinks::RESULT_SKIPPED => EXT_EC_RESULT_SKIPPED,
            \Everysize\Checkout\OrderLinks::RESULT_KNOWN => EXT_EC_RESULT_KNOWN,
            default => $result,
        };
    }

    /**
     * The badge classes the admin actually styles (admin main.css: only
     * label-success/-info/-danger — Bootstrap 5 knows no label-*).
     */
    private static function resultClass(string $result): string
    {
        return match ($result) {
            \Everysize\Checkout\OrderLinks::RESULT_SENT => 'label label-success',
            \Everysize\Checkout\OrderLinks::RESULT_KNOWN => 'label label-info',
            \Everysize\Checkout\OrderLinks::RESULT_SKIPPED => 'label label-danger',
            default => 'label label-info',
        };
    }

    /** Module tables store UTC; the admin sees shop time. */
    public static function formatUtc(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '–';
        }
        try {
            $date = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));

            return $date->format('d.m.Y H:i');
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * The money string the admin shows: the shop's currencies table format
     * (symbol, decimal point, thousands separator) for the order's currency.
     * The stored values are already in the order currency — no rate applied.
     */
    private static function money(float $value, string $currency = 'EUR', ?\Everysize\Checkout\osCommerce\Db $db = null): string
    {
        $db = $db ?? new \Everysize\Checkout\osCommerce\Db();
        $row = $db->fetchRows(
            'SELECT symbol_left AS SYMBOL_LEFT, symbol_right AS SYMBOL_RIGHT, decimal_point AS DECIMAL_POINT,
                    thousands_point AS THOUSANDS_POINT, decimal_places AS DECIMAL_PLACES
             FROM currencies WHERE code = :code LIMIT 1',
            [':code' => $currency]
        );
        if ($row === []) {
            return number_format($value, 2, ',', '.') . ' ' . $currency;
        }
        $decimals = max(0, min(4, (int) ($row[0]['DECIMAL_PLACES'] ?? 2)));
        $formatted = number_format(
            $value,
            $decimals,
            (string) ($row[0]['DECIMAL_POINT'] ?? '') !== '' ? (string) $row[0]['DECIMAL_POINT'] : ',',
            (string) ($row[0]['THOUSANDS_POINT'] ?? '') !== '' ? (string) $row[0]['THOUSANDS_POINT'] : '.'
        );

        return (string) $row[0]['SYMBOL_LEFT'] . $formatted . (string) $row[0]['SYMBOL_RIGHT'];
    }

    private function saveSettings(\Everysize\Checkout\osCommerce\SettingsImpl $settings, array $post): void
    {
        // Credentials: an empty (or masked) secret keeps the stored one (B4).
        $clientId = trim((string) ($post['client_id'] ?? ''));
        $clientSecret = (string) ($post['client_secret'] ?? '');
        if ($clientId !== '') {
            $settings->set('client_id', $clientId);
        }
        if ($clientSecret !== '' && !\Everysize\Checkout\Credentials::isMaskedPlaceholder($clientSecret)) {
            $settings->set('client_secret', $clientSecret);
        }

        $settings->set('carrier', trim((string) ($post['carrier'] ?? '')) !== '' ? mb_substr(trim((string) $post['carrier']), 0, 31) : $settings->getCarrier());

        $settings->set('import_status', (string) max(1, (int) ($post['import_status'] ?? 0)));
        $settings->set('send_order_confirmation', !empty($post['send_order_confirmation']) ? 'true' : 'false');
        $settings->set('shipping_statuses', implode(',', array_map('intval', (array) ($post['shipping_statuses'] ?? []))));
        $settings->set('cancellation_statuses', implode(',', array_map('intval', (array) ($post['cancellation_statuses'] ?? []))));
        $settings->set('return_statuses', implode(',', array_map('intval', (array) ($post['return_statuses'] ?? []))));
        $autoRefund = !empty($post['auto_refund_decline']);
        $settings->set('auto_refund_decline', $autoRefund ? 'true' : 'false');
        // The auto refund only covers cancellations from when it was switched
        // on (module tables store UTC) — a reset re-anchors the date.
        if ($autoRefund && ($settings->getAutoRefundSince() === '' || isset($post['auto_refund_since_reset']))) {
            $settings->set('auto_refund_since', gmdate('Y-m-d H:i:s'));
        }
        if (!$autoRefund && isset($post['auto_refund_since_reset'])) {
            $settings->delete('auto_refund_since');
        }
        $settings->set('auto_sync', !empty($post['auto_sync']) ? 'true' : 'false');

        $platformId = (int) ($post['platform_id'] ?? 0);
        if ($platformId > 0) {
            $settings->set('platform_id', (string) $platformId);
        }

        $token = trim((string) ($post['cron_token'] ?? ''));
        if ($token !== '') {
            $settings->set('cron_token', mb_substr($token, 0, 64));
        }
    }

    private function viewData(\Everysize\Checkout\osCommerce\SettingsImpl $settings): array
    {
        return [
            'importStatus' => $settings->getImportStatus(),
            'sendConfirmation' => $settings->sendOrderConfirmation(),
            'shippingStatuses' => $settings->getShippingStatuses(),
            'cancellationStatuses' => $settings->getCancellationStatuses(),
            'returnStatuses' => $settings->getReturnStatuses(),
            'carrier' => $settings->getCarrier(),
            'autoRefund' => $settings->isAutoRefundDecline(),
            'autoSync' => $settings->isAutoSyncEnabled(),
            'cronToken' => $settings->getCronToken(),
            'platformId' => $settings->getPlatformId(),
        ];
    }

    /** All osCommerce order statuses for the selects (current admin language). */
    private function statusOptions(): array
    {
        $languageId = (int) Yii::$app->settings->get('languages_id');
        $rows = [];
        foreach (\common\models\OrdersStatus::find()
            ->where(['language_id' => $languageId > 0 ? $languageId : 1])
            ->orderBy('orders_status_id')
            ->asArray()->all() as $row) {
            $rows[(int) $row['orders_status_id']] = sprintf('%d — %s', (int) $row['orders_status_id'], (string) $row['orders_status_name']);
        }

        return $rows;
    }
}
