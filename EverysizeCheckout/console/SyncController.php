<?php

declare(strict_types=1);

/**
 * everysize Checkout — the console command: `php yii.php everysize-checkout/sync`.
 *
 * Exit code 0 on success (or when not configured), 1 on errors — for the
 * system cron. Output is the message list. The automatic run (cron) honors
 * the "automatic sync" setting; `--force` runs anyway (manual call).
 */

namespace common\extensions\EverysizeCheckout\console;

use common\extensions\EverysizeCheckout\EverysizeCheckout;
use yii\console\Controller;

class SyncController extends Controller
{
    public $force = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['force']);
    }

    private function sync(): ?\Everysize\Checkout\Sync
    {
        $module = EverysizeCheckout::module();
        if (!$this->force && !$module->settings()->isAutoSyncEnabled()) {
            return null;
        }

        return $module->sync();
    }

    /** Run a full sync (import + export). */
    public function actionSync(): int
    {
        $sync = $this->sync();
        if ($sync === null) {
            echo "everysize checkout: nothing to do (credentials not configured or automatic sync disabled)\n";

            return 0;
        }

        $result = $sync->run();
        foreach ($result['messages'] as $message) {
            echo $message . "\n";
        }
        foreach ($result['errors'] as $error) {
            echo 'ERROR: ' . $error . "\n";
        }

        return $result['errors'] === [] ? 0 : 1;
    }

    /** Import only (order numbers are reported by the next run if missing). */
    public function actionImport(): int
    {
        $sync = $this->sync();
        if ($sync === null) {
            echo "everysize checkout: nothing to do (credentials not configured or automatic sync disabled)\n";

            return 0;
        }

        $result = $sync->run(true, false);
        foreach (array_merge($result['messages'], $result['errors']) as $message) {
            echo $message . "\n";
        }

        return $result['errors'] === [] ? 0 : 1;
    }

    /** Export only. */
    public function actionExport(): int
    {
        $sync = $this->sync();
        if ($sync === null) {
            echo "everysize checkout: nothing to do (credentials not configured or automatic sync disabled)\n";

            return 0;
        }

        $result = $sync->run(false, true);
        foreach (array_merge($result['messages'], $result['errors']) as $message) {
            echo $message . "\n";
        }

        return $result['errors'] === [] ? 0 : 1;
    }
}
