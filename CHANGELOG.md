# 1.0.1
- The shop version is reported to everysize as the bare version number
  (e.g. 4.14.63493), like the other everysize plugins, instead of
  "osCommerce 4.14.63493"

# 1.0.0
- Initial release: import of everysize orders into osCommerce 4.14 (guest
  accounts, variants via the real SKU and the uprid attributes, stock
  deduction from the inventory table, order language from everysize, complete
  street address, REVOKED orders straight into the configured cancellation
  status) and reporting back to everysize: merchant order number, shipping
  with tracking code, cancellations, returns and automatic refunds
- Order box "everysize" in the admin order page (totals area): the everysize
  order as an info table, refunds through everysize with tiles
  (paid / refunded / open), refund list and labelled form, reports to
  everysize as a table; returns are reported from the box together with
  the refund ("goods came back" checkbox, refund e.g. without shipping —
  osCommerce 4 has no return status and cannot cancel shipped orders) or
  alone; times in the shop's time zone; every refund (box or
  automatic) is noted in the order history (no status change) and, from the
  box, in the protocol
- Order list: "everysize (PayPal)" marker in the row, search by everysize
  order number and an "everysize orders only" filter
- Protocol page under Tools (50 entries per page, filters all/ok/errors,
  times in the shop's time zone)
- Configuration: credentials (secret never rendered back, an empty field
  keeps the stored one), platform and language of imported orders, shipping/
  cancellation/return statuses, import status, order confirmation on/off,
  one "automatic sync" switch, connection test showing the everysize shop ID
- Sync via the own cron endpoint (token-protected, URL shown in the admin)
  or CLI (`php yii.php everysize-checkout/sync`, `--force`, exit code 1 on
  errors); the confirmation mail also works from the cron/CLI boot
- Protocol entries and order history comments are written in the shop's
  default language, from the sync and from the order box alike
- Schema updates are idempotent; uninstalling keeps all data (tables,
  credentials, links) like the other everysize plugins; credentials once
  stored in `platforms_configuration` (platform 0) are taken over on install
  and additionally read as a fallback
- Unit tests (129) for the framework-free core under src/, PHP 8.1–8.3
