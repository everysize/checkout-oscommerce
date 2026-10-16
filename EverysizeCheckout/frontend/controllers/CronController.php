<?php

declare(strict_types=1);

/**
 * everysize Checkout — the frontend cron endpoint for merchants without
 * shell access: https://<shop>/everysize-checkout-cron/everysize?token=...
 *
 * (Own controller id — "cron" is the osCommerce core frontend controller
 * all built-in cronjobs run through.) The token is set in the admin
 * settings; without a token the endpoint is disabled (403). The response is
 * plain text with the run summary, so it is visible in any scheduler's log.
 */

namespace common\extensions\EverysizeCheckout\frontend\controllers;

use common\extensions\EverysizeCheckout\EverysizeCheckout;
use Yii;
use yii\web\Controller;

class CronController extends Controller
{
    public function actionEverysize()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;

        $module = EverysizeCheckout::module();
        $token = $module->settings()->getCronToken();
        if ($token === '') {
            Yii::$app->response->statusCode = 403;

            return "everysize checkout: cron endpoint disabled (no token set)\n";
        }
        $given = (string) Yii::$app->request->get('token', '');
        if (!hash_equals($token, $given)) {
            Yii::$app->response->statusCode = 403;

            return "everysize checkout: invalid token\n";
        }

        if (!$module->settings()->isAutoSyncEnabled()) {
            return "everysize checkout: automatic sync disabled\n";
        }

        $sync = $module->sync();
        if ($sync === null) {
            return "everysize checkout: credentials not configured, nothing to do\n";
        }

        $result = $sync->run();
        $lines = array_merge($result['messages'], $result['errors']);
        Yii::$app->response->statusCode = $result['errors'] === [] ? 200 : 500;

        return implode("\n", $lines) . "\n";
    }
}
