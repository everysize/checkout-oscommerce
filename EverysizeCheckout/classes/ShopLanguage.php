<?php

declare(strict_types=1);

/**
 * everysize Checkout — which language the protocol is written in.
 *
 * Everything the extension writes (protocol, order history) uses the
 * shop's default language — the sync runs from the cron/CLI without an
 * admin session, and the order box writes the same way so the protocol
 * does not mix languages.
 */

namespace Everysize\Checkout\osCommerce;

use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Texts;

final class ShopLanguage
{
    /** The shop's default language as Texts (platforms.is_default → languages.code). */
    public static function defaultTexts(DbInterface $db): Texts
    {
        $code = (string) ($db->fetchValue(
            'SELECT l.code FROM languages l
             WHERE l.languages_status = 1 AND l.code = (SELECT p.default_language FROM platforms p WHERE p.is_default = 1 ORDER BY p.platform_id LIMIT 1)
             ORDER BY l.sort_order, l.languages_id LIMIT 1'
        ) ?? '');

        return new Texts($code);
    }
}
