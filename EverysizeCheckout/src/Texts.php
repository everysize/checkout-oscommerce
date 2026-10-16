<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * The protocol and sync texts, per language. The sync runs from the cron or
 * the CLI — outside any admin session — so its protocol entries are written
 * in the shop's default language (the shop layer picks it), not in English.
 * Embedded API/exception details (field paths, provider messages) stay as
 * they come; the frames around them are translated.
 */
final class Texts
{
    private const STRINGS = [
        'de' => [
            'not_configured' => 'everysize-Zugangsdaten nicht konfiguriert, nichts zu tun',
            'lock_busy' => 'Ein weiterer everysize-Sync läuft noch, übersprungen',
            'api_url' => 'everysize-API: %s',
            'api_url_invalid' => 'everysize-API-URL ungültig',
            'import_failed' => 'Import aus everysize fehlgeschlagen',
            'import_summary' => 'Aus everysize importiert: %d von %d Bestellungen',
            'order_without_id' => 'everysize-Bestellung ohne everysizeOrderId übersprungen',
            'export_failed' => 'Meldung an everysize fehlgeschlagen',
            'export_summary' => 'An everysize gemeldet: %d Versand, %d nachträglich übermittelte Sendungsnummern, %d Storno, %d Retoure, %d automatisch erstattet, %d übersprungen (bei everysize storniert)',
            'error_revoked_shipment' => 'Bestellung %s (%s) ist bei everysize storniert, Versand nicht gemeldet',
            'error_order_status_tracking' => 'Bestellung %s (%s) hat bei everysize den Status %s, Sendungsnummer %s nicht gemeldet',
            'error_tracking_api' => 'Bestellung %s (%s), Sendungsnummer %s: %s',
            'error_revocation_api' => 'Bestellung %s (%s), Position %s: %s',
            'unknown_status' => 'unbekannt',
            'auto_refund_failed' => 'Bestellung %s (%s): automatische Erstattung nach Storno fehlgeschlagen%s: %s',
            'auto_refund_retry' => ' (im nächsten Lauf erneut versucht)',
            'auto_refund_manual' => ', bitte in der Bestell-Box „everysize“ erstatten',
            'stock_warning' => '%s: Bestand von %s wurde negativ (%.0f)',
            'refund_already_title' => 'Erstattung über die Bestell-Box: bereits erstattet',
            'refund_already_message' => 'Bestellung %s (%s): everysize meldet die Zahlung als bereits erstattet, es wurde nicht erneut erstattet.',
            'refund_title' => 'Erstattung über die Bestell-Box',
            'refund_message' => 'Bestellung %s (%s): %s %s erstattet%s.',
            'refund_id_suffix' => ', PayPal-Erstattung %s',
            'refund_failed_title' => 'Erstattung über die Bestell-Box fehlgeschlagen',
            'refund_failed_message' => 'Bestellung %s: %s',
            'return_title' => 'Retoure über die Bestell-Box',
            'return_message' => 'Bestellung %s (%s): Retoure an everysize gemeldet.',
            'return_skipped_message' => 'Bestellung %s (%s) ist bei everysize bereits storniert, Retoure nicht gemeldet.',
            'history_imported' => 'Aus everysize importiert (%s)',
            'history_return' => 'everysize: Retoure gemeldet.',
            'history_refund' => 'everysize: %s erstattet',
            'history_refund_no_amount' => 'everysize: erstattet',
            'history_refund_auto' => ' (automatisch nach Storno vor Versand)',
        ],
        'en' => [
            'not_configured' => 'everysize credentials not configured, nothing to do',
            'lock_busy' => 'another everysize sync is still running, skipped',
            'api_url' => 'everysize API: %s',
            'api_url_invalid' => 'everysize API URL invalid',
            'import_failed' => 'Import from everysize failed',
            'import_summary' => 'Imported %d of %d orders from everysize',
            'order_without_id' => 'everysize order without everysizeOrderId skipped',
            'export_failed' => 'Report to everysize failed',
            'export_summary' => 'Reported to everysize: %d shipped, %d tracking codes added later, %d cancelled, %d returned, %d refunded automatically, %d skipped (revoked at everysize)',
            'error_revoked_shipment' => 'Order %s (%s) is revoked at everysize, shipping not reported',
            'error_order_status_tracking' => 'Order %s (%s) is %s at everysize, tracking code %s not reported',
            'error_tracking_api' => 'Order %s (%s), tracking code %s: %s',
            'error_revocation_api' => 'Order %s (%s), %s: %s',
            'unknown_status' => 'unknown',
            'auto_refund_failed' => 'Order %s (%s): automatic refund after cancellation failed%s: %s',
            'auto_refund_retry' => ' (retried next run)',
            'auto_refund_manual' => ', please refund in the order box "everysize"',
            'stock_warning' => '%s: stock of %s went negative (%.0f)',
            'refund_already_title' => 'Refund in the order box: already refunded',
            'refund_already_message' => 'Order %s (%s): everysize reports the payment as already refunded, nothing was refunded again.',
            'refund_title' => 'Refunded in the order box',
            'refund_message' => 'Order %s (%s): %s %s refunded%s.',
            'refund_id_suffix' => ', PayPal refund %s',
            'refund_failed_title' => 'Refund in the order box failed',
            'refund_failed_message' => 'Order %s: %s',
            'return_title' => 'Return in the order box',
            'return_message' => 'Order %s (%s): return reported to everysize.',
            'return_skipped_message' => 'Order %s (%s) is already revoked at everysize, return not reported.',
            'history_imported' => 'Imported from everysize (%s)',
            'history_return' => 'everysize: return reported.',
            'history_refund' => 'everysize: %s refunded',
            'history_refund_no_amount' => 'everysize: refunded',
            'history_refund_auto' => ' (automatically after cancellation before shipping)',
        ],
    ];

    private string $lang;

    /**
     * @param string $languageCode the shop's language code ('de', 'en', …);
     *                             unknown codes fall back to English
     */
    public function __construct(string $languageCode = 'en')
    {
        $this->lang = isset(self::STRINGS[$languageCode]) ? $languageCode : 'en';
    }

    /** The template for $key with $args inserted (vsprintf). */
    public function t(string $key, string|int|float ...$args): string
    {
        $template = self::STRINGS[$this->lang][$key] ?? self::STRINGS['en'][$key] ?? $key;

        return $args === [] ? $template : vsprintf($template, array_map(strval(...), $args));
    }

    /** An amount with currency in this language's number format: "1.234,50 EUR" / "1,234.50 EUR". */
    public function amount(float $amount, string $currency): string
    {
        $formatted = $this->lang === 'de'
            ? number_format($amount, 2, ',', '.')
            : number_format($amount, 2, '.', ',');

        return $formatted . ' ' . ($currency !== '' ? $currency : 'EUR');
    }
}
