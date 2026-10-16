<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use Everysize\Checkout\Api\ApiUrl;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Export\StatusExporter;
use Everysize\Checkout\Import\OrderImporter;

/**
 * Assembles the module from its parts. The shop layer provides the
 * Settings/Db/Protocol/OrderImporter implementations and the osCommerce
 * version; everything else (API client, links, exporter, sync) is built
 * here so every entry point (cron, CLI, admin) uses the same wiring.
 * The texts come from the shop layer too (its default language), so the
 * protocol entries are written in the shop's language.
 */
class Module
{
    private Settings $settings;
    private DbInterface $db;
    private OrderImporter $importer;
    private ?Protocol $protocol;
    private string $shopSystemVersion;
    private Texts $texts;

    private ?Client $client = null;

    public function __construct(
        Settings $settings,
        DbInterface $db,
        OrderImporter $importer,
        ?Protocol $protocol = null,
        string $shopSystemVersion = 'unknown',
        ?Texts $texts = null
    ) {
        $this->settings = $settings;
        $this->db = $db;
        $this->importer = $importer;
        $this->protocol = $protocol;
        $this->shopSystemVersion = $shopSystemVersion;
        $this->texts = $texts ?? new Texts();
    }

    /**
     * The API client for the configured environment; the credentials come
     * from the settings, the environment URL from EVERYSIZE_CHECKOUT_API_URL.
     */
    public function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client(
                $this->settings->getClientId(),
                $this->settings->getClientSecret(),
                ApiUrl::resolve(),
                $this->shopSystemVersion
            );
        }

        return $this->client;
    }

    /** The order links (own tables + osCommerce order tables). */
    public function links(): OrderLinks
    {
        return new OrderLinks($this->db, $this->settings);
    }

    /** The status exporter (shipping, cancellations, returns, auto refund). */
    public function exporter(): StatusExporter
    {
        return new StatusExporter($this->client(), $this->settings, $this->links(), $this->texts);
    }

    /** The protocol/sync texts in the shop's default language. */
    public function texts(): Texts
    {
        return $this->texts;
    }

    public function protocol(): ?Protocol
    {
        return $this->protocol;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /** One sync run (import + export + protocol cleanup) or null when not configured. */
    public function sync(): ?Sync
    {
        if (!$this->settings->isConfigured()) {
            return null;
        }

        return new Sync(
            $this->settings,
            $this->client(),
            $this->db,
            $this->importer,
            $this->exporter(),
            $this->links(),
            $this->protocol ?? new class implements Protocol {
                public function add(string $title, string $message, bool $success): void
                {
                }

                public function cleanup(int $days = Protocol::RETENTION_DAYS): void
                {
                }
            },
            $this->texts
        );
    }
}
