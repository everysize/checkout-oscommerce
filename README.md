# everysize Checkout für osCommerce (Erweiterung 1.0.0)

Bindet einen osCommerce-4-Shop an den everysize-Direktkauf an: Bestellungen
aus everysize werden importiert (inkl. Gastkonten, Varianten, Bestandsabzug
und optionaler Bestellbestätigung), und Bestellnummer, Versand,
Sendungsnummern, Storno, Retoure und Auto-Erstattungen werden zurückgemeldet.

Den Quellcode gibt es auch als Composer-Paket `everysize/checkout-oscommerce`.
Installiert wird das Modul aber nicht per Composer, sondern über das ZIP
(siehe [Installation](#installation)): osCommerce lädt Erweiterungen nur aus
`lib/common/extensions/`.

## Voraussetzungen

- osCommerce 4.14 (getestet mit 4.14.63493)
- PHP ≥ 8.1 mit `curl` und `json`
- Ein Cron, der den Abgleich alle paar Minuten aufruft (siehe unten)

Kein PayPal-Modul nötig: bezahlt wird bei everysize. Erstattet wird über
everysize (das Geld geht über das PayPal-Konto zurück, mit dem bezahlt wurde).

## Installation

1. ZIP (`everysize-checkout-oscommerce-<version>.zip`, Download in everysize
   Business unter „Shop-Integration“ → osCommerce) entpacken und den
   enthaltenen Ordner `lib` in das **Shop-Root** kopieren (per FTP/SFTP), so
   dass `lib/common/extensions/EverysizeCheckout/distribution.json` liegt.
2. **Module → Extensions**: „everysize Checkout“ → Installieren. Das legt die
   Modul-Tabellen an (`everysize_checkout_*`, idempotent), registriert Menü,
   Hooks und den Cron-Endpoint und hält beim Deinstallieren standardmäßig
   alle Daten (wie die anderen everysize-Plugins).
3. **Werkzeuge → everysize Checkout → Einstellungen**: Client-ID und
   Client-Secret (aus everysize Business, „Shop-Integration“) eintragen und
   speichern — das Secret wird nie wieder angezeigt, ein leeres Feld behält
   den gespeicherten Wert.
4. Konfiguration: Versand-/Storno-/Retoure-Status, Import-Status,
   Bestellbestätigung an/aus, „Automatischer Abgleich“.

**Update:** Neues ZIP entpacken und den Ordner
`lib/common/extensions/EverysizeCheckout` über den bestehenden kopieren
(Dateien werden ersetzt, Zugangsdaten und Einstellungen bleiben in der
Datenbank); neue oder geänderte Texte übernimmt das Modul beim nächsten
Aufruf des Admin-Bereichs selbst. Steht
eine neue Schema-Version an, wird sie beim nächsten Install-Lauf idempotent
angehoben — das Modul bleibt dabei installiert und aktiv.

## Abgleich (Import + Rückmeldung)

- Der Sync läuft über den eigenen Cron-Endpoint:
  `{SHOP_URL}/everysize-checkout-cron/everysize?token={TOKEN}` — die URL
  steht fertig im Protokoll der Einstellungsseite (der Token wird bei der
  Installation erzeugt und kann dort neu erzeugt werden). Für externe
  Cron-Dienste (cron-job.org & Co.) einfach diese URL alle 5 Minuten
  aufrufen; der Abgleich selbst respektiert den Schalter „Automatischer
  Abgleich".
- Alternativ per CLI im Shop-Root:
  `php yii.php everysize-checkout/sync` (`--force` umgeht den Schalter,
  Exit-Code 1 bei Fehlern).
- „Verbindung testen" auf der Einstellungsseite prüft Zugangsdaten und
  Shop-Zuordnung und zeigt die everysize-Shop-ID.

## Was der Import anlegt

- `orders`-Zeile wie der osCommerce-Checkout: Namensspalten `customers_`,
  `delivery_`, `billing_`, komplette Straße mit Hausnummer, Bestellsprache
  aus der everysize-Sprache, Gastbestellung ohne Konto, Zahlart „everysize
  (PayPal)".
- `orders_products` mit **Netto**-Preisen (so rechnet der osCommerce-Admin),
  Varianten über die echte Artikel-SKU (exakt > Groß-/Kleinschreibung,
  aktive > inaktive Artikel) und `orders_products_attributes` aus dem
  uprid — je Variante eine Attribut-Zeile mit Options- und Wert-ID.
- `orders_total` mit den 6 Klassen des Shops (Subtotal, Shipping, Tax,
  Total, Paid, Due) in den Währungsformaten des Shops, Steuerzeile je
  Steuersatz aus `tax_rates`/`zones_to_geo_zones` (mehrstufige Prioritäten
  wie `Tax::get_tax_rate`).
- Bestandsabzug über `inventory` (Größen-/Variantenbestand) bzw. den
  Artikelbestand; `STOCK_LIMITED` wird respektiert, negativer Bestand ist
  eine Warnung, kein Fehler — die Bestellung ist bezahlt.
- Eine REVOKED-Bestellung wird direkt in den konfigurierten Storno-Status
  gestellt und bucht keinen Bestand ab.
- Alles in einer Transaktion: scheitert der Import, bleibt die Datenbank
  unverändert (idempotent beim Wiederholungsversuch).

## Rückmeldung an everysize

- Versand (Status-Wechsel) + Sendungsnummern (das Tracking-Feld der
  Bestellung bzw. die Sendungsverfolgung), Nachmeldung bis 14 Tage.
  Sendungsnummern trägt der Händler auf dem Standardweg ein: Bestellung
  öffnen → Tracking-Bereich → Sendungsnummer je Position
  (`tracking-edit` → `orders/tracking-save`). Die veraltete Route
  `orders/savetracking` wird von der Oberfläche nicht mehr benutzt.
- Storno vor dem Versand (konfigurierter Status) mit Grund `MERCHANT_DECLINE`,
  bei aktivem Schalter mit automatischer Vollerstattung.
- **Retoure:** osCommerce 4 kennt keinen Retoure-Status, und eine versendete
  Bestellung lässt sich dort nicht stornieren (der Shop leitet den Status aus
  den Positionen ab und setzt „Storniert“ sofort auf „Versendet“ zurück).
  Kommt Ware zurück, erstatten Sie wie gewohnt — bei everysize-Bestellungen
  in der everysize-Box im Bestelldetail: Häkchen „Ware ist zurückgekommen:
  Retoure an everysize melden“ setzen, den zu erstattenden Betrag eintragen
  (z. B. ohne Versandkosten) und „Erstatten“. Das Modul meldet zuerst die
  Retoure (Grund `RETOUR`) und erstattet dann den Betrag. Ist schon alles
  erstattet, meldet „Retoure melden“ die Retoure allein.
- Optional: Wer bereits einen eigenen Status für Retouren nutzt, ordnet ihn in
  den Einstellungen als Retoure-Status zu — Bestellungen in diesem Status
  werden ebenfalls als Retoure gemeldet (ohne Erstattung).
- Jede Rückmeldung wird idempotent vermerkt (`everysize_checkout_report`)
  und ist im Protokoll sichtbar (Werkzeuge → everysize Checkout → Protokoll)
- Der everysize-Import schreibt die Händler-Bestellnummer NACH dem Commit
  zurück; eine fehlgeschlagene Rückmeldung wird beim nächsten Lauf geholt.

## Bestellungen im Admin

- Bestelldetail: die everysize-Box im Summen-Bereich — everysize-Nummer,
  PayPal-Capture, Zahlstände (bezahlt/erstattet/offen), Erstattungen
  ganz/teilweise mit Idempotency-Key und die Rückmeldungen dieser Bestellung.
- Bestellübersicht: Kennzeichen „everysize (PayPal)“ in der Zeile, Suche
  nach der everysize-Nummer, Filter „nur everysize-Bestellungen“.
- Erstattungen laufen über everysize an PayPal; jede Erstattung (Box oder
  automatisch) steht als Kommentar im Bestellverlauf, ohne Statuswechsel.

## Architektur

- `src/` — Framework-freier Kern (Settings, API-Client, Sync,
  Import/Export-Logik), unit-getestet (130 Tests, PHP 8.1–8.3).
- `classes/` — osCommerce-Anbindung (Db, Settings, OrderImporter,
  OrderMailer, Protocol).
- `Setup.php` — Install/Remove (idempotent, Datenhalt) + Übernahme alter
  Zugangsdaten aus `platforms_configuration`.
- `frontend/controllers/CronController.php` — der Cron-Endpoint.

## Lizenz

MIT, siehe `LICENSE`.
