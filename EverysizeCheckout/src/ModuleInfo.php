<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * The module's own identity. This is the single source of truth for the
 * version in code — composer.json and distribution.json must match it
 * (checked by ModuleVersionTest).
 */
final class ModuleInfo
{
    public const VERSION = '1.0.1';

    /**
     * osCommerce's own version as reported to everysize (ERP-Shop-System-Version):
     * the bare version. osCommerce defines PROJECT_VERSION as
     * "<name> MAJOR.MINOR.PATCH[ <white label>]" ("osCommerce 4.14.63493",
     * includes/application_top.php) and distributes the string
     * "v.4.14 build 63493" (install/index.php) — all normalized here to
     * "4.14.63493", the first dotted number in the string.
     */
    public static function shopSystemVersion(?string $installedVersion): string
    {
        $version = trim((string) $installedVersion);
        $version = preg_replace('/^v\.?\s*/i', '', $version);
        $version = str_replace(' build ', '.', (string) $version);
        if (preg_match('/\d+(?:\.\d+)+/', $version, $m) === 1) {
            return $m[0];
        }

        return $version !== '' ? $version : 'unknown';
    }
}
