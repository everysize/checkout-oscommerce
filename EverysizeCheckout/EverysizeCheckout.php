<?php

declare(strict_types=1);

/**
 * everysize Checkout for osCommerce 4 — main extension class.
 *
 * The class name is the module code osCommerce uses everywhere
 * (<code>_EXTENSION_STATUS, extension_name in the hooks table, ACL),
 * so it must stay EverysizeCheckout.
 */

namespace common\extensions\EverysizeCheckout;

use common\classes\modules\ModuleExtensions;

class EverysizeCheckout extends ModuleExtensions
{
    public static function allowed()
    {
        // The core (src/) has no osCommerce dependency, but every entry point
        // (hooks, controller, cron) must only work when the module is enabled.
        if (!parent::allowed()) {
            return false;
        }
        self::autoload();

        return true;
    }

    /** Registers the core autoloader (Everysize\Checkout\* from src/). */
    public static function autoload(): void
    {
        require_once __DIR__ . '/src/autoload.php';
    }

    /** @return string The bare osCommerce version, e.g. "4.14.63493". */
    public static function shopVersion(): string
    {
        // MAJOR.MINOR.PATCH (includes/version.php) — PROJECT_VERSION carries the
        // product name in front ("osCommerce 4.14.63493") and, white-labelled, behind.
        $version = defined('PROJECT_VERSION_MAJOR') && defined('PROJECT_VERSION_MINOR') && defined('PROJECT_VERSION_PATCH')
            ? PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR . '.' . PROJECT_VERSION_PATCH
            : (defined('PROJECT_VERSION') ? PROJECT_VERSION : '');

        return \Everysize\Checkout\ModuleInfo::shopSystemVersion($version);
    }

    /**
     * The wired module: everything the sync, the import and the admin need,
     * built on the shop's DB connection.
     */
    public static function module(): \Everysize\Checkout\Module
    {
        self::autoload();
        $db = new \Everysize\Checkout\osCommerce\Db();
        $settings = new \Everysize\Checkout\osCommerce\SettingsImpl($db);
        $protocol = new \Everysize\Checkout\osCommerce\Protocol($db);
        $texts = \Everysize\Checkout\osCommerce\ShopLanguage::defaultTexts($db);
        $links = new \Everysize\Checkout\OrderLinks($db, $settings);

        return new \Everysize\Checkout\Module(
            $settings,
            $db,
            new \Everysize\Checkout\osCommerce\OrderImporter($db, $settings, $links, new \Everysize\Checkout\osCommerce\OrderMailer(), $texts),
            $protocol,
            self::shopVersion(),
            $texts
        );
    }
}
