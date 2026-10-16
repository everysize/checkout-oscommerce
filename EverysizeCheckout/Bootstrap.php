<?php

declare(strict_types=1);

/**
 * everysize Checkout — Yii bootstrap, run in all three apps
 * (app-backend: admin controller; app-frontend: cron endpoint; console: sync
 * command). Bootstrap.php is discovered by the extension scan
 * (lib/common/config/load-bootstrap-classes.php).
 */

namespace common\extensions\EverysizeCheckout;

use yii\base\Application;
use yii\base\BootstrapInterface;

class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        if (!EverysizeCheckout::allowed()) {
            return;
        }
        \Yii::setAlias('@ext-everysize-checkout', dirname(__FILE__));

        if ($app instanceof \yii\web\Application) {
            if ($app->id === 'app-backend') {
                // New admin texts of a file update reach the translation table
                // before the page defines its constants (one SELECT per request).
                try {
                    Setup::updateTranslationsIfChanged();
                } catch (\Throwable $e) {
                    \Yii::warning('everysize Checkout: texts not updated: ' . $e->getMessage());
                }
                $app->controllerMap = array_merge($app->controllerMap, [
                    'everysize-checkout' => ['class' => __NAMESPACE__ . '\backend\controllers\EverysizeCheckoutController'],
                ]);
            }
            if ($app->id === 'app-frontend') {
                // Own controller id: "cron" is the CORE frontend controller
                // (lib/frontend/controllers/CronController.php) — all
                // osCommerce cronjobs run through /cron/<job>, overriding it
                // would kill them.
                $app->controllerMap = array_merge($app->controllerMap, [
                    'everysize-checkout-cron' => ['class' => __NAMESPACE__ . '\frontend\controllers\CronController'],
                ]);
            }
        }
        if ($app instanceof \yii\console\Application) {
            $app->controllerMap = array_merge($app->controllerMap, [
                'everysize-checkout' => ['class' => __NAMESPACE__ . '\console\SyncController'],
            ]);
        }
    }
}
