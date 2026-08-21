# Changelog

All notable changes to **Database Cleaner** (`prestacleaner`).

## 3.4.0

### Added
- A one-line review request on the module's own configure page (and nowhere else). It first appears 21 days after install — for existing installations, 21 days after this upgrade — and disappears forever once the merchant clicks the review link, clicks "No thanks", or has simply seen it three times. The link goes to the module's review form on megventure.com, in the back office language where the shop serves it. What it does **not** do: no tracking, no external request of any kind (the line is plain HTML; the review link routes through the configure page so the click is remembered, then the merchant's own browser is sent to the form), no JavaScript required, and nothing stored beyond three of the module's own prefixed configuration values (install timestamp, dismissed flag, display count), all removed on uninstall.

## 3.3.1

### Fixed
- **Fatal error on the configure page and in "Check & fix": `Table 'ps_referrer_cache' doesn't exist`.** Two of the ~110 known table relationships (`referrer`/`referrer_cache`) date back to PrestaShop 1.6/1.7 - core dropped both tables entirely somewhere between 1.7.2 and 8.2, so any PS8/9 shop hit a fatal the moment the health score (or "Check & fix") tried to query them. The whole relationship list is now checked against a live `SHOW TABLES` snapshot first (one query, cached for the request), so any table a given PrestaShop version doesn't have is skipped instead of crashing - covering this pair specifically and any other version-specific gap that turns up later.
- The health-score computation is now wrapped so it can never take the configure page down with it: on failure it logs the real error and falls back to the last successfully computed score, or a plain "temporarily unavailable" notice if there isn't one yet - either way, every action panel below it keeps working.

## 3.3.0

### Fixed
- **The store health widget appeared on every back-office page, not just the Dashboard.** 3.2.0 hooked `displayDashboardTop`, assuming it was Dashboard-specific - it's actually rendered by the shared page-header toolbar included on every admin controller. Moved to `dashboardZoneOne`, the hook `AdminDashboardController` renders exclusively into its own left-hand column; the upgrade script unregisters the old hook and registers the new one automatically.

### Changed
- Redesigned the Dashboard widget to match the configure page: a circular badge showing the score, colored by the same excellent/good/attention/poor thresholds, instead of a flat colored strip. Its own `<style>` block is embedded directly in the hook output (a module's CSS never loads on the Dashboard controller, only on its own configure page), so it renders correctly with zero extra asset files.

## 3.2.0

### Added
- The store health score now also appears at the top of the PrestaShop Dashboard (`displayDashboardTop`), as a compact strip clearly labelled "Data from the Database Cleaner module" with a link straight to the configure page - so the score is visible without opening the module at all, and it's always clear which module produced it.

### Changed
- The health-score computation (~110 COUNT queries plus a couple of table scans) is now cached for up to an hour. The configure page still always computes it live and refreshes the cache as a side effect; the Dashboard widget reuses that cache so it never adds real load to a page most admins land on multiple times a day. Running any action, or a scheduled run, also refreshes it immediately.

## 3.1.0

### Added
- **Delete selected orders**: search orders by ID/reference, status or date range, tick the ones to remove, and delete only those. Cascades through every related table (order lines and their tax rows, history, carriers, applied vouchers, invoices and their tax rows and payments, returns, credit slips, order messages) in child-first order, safely drops a shared `order_payment` row only once no surviving order still references its `order_reference`, and clears the now-dangling employee "last order" shortcut. Reuses the same table relationships already known from the "Check & fix" engine rather than a second, separately-maintained list. Has the same optional backup-first and a dry-run preview as every other action; gated by a required, server-checked confirmation checkbox rather than the typed phrase used for the two full resets, since one page of search results is a naturally bounded, admin-picked blast radius.

### Fixed
- Order totals in the new "Delete selected orders" list are formatted through the current locale (falling back to `Tools::displayPrice()` only where it still exists), since that method was removed in PrestaShop 9.

## 3.0.0

### Added
- Automatic backups: every action can back up exactly the rows it's about to touch (a full snapshot before a catalog/orders reset) to PrestaShop's own Advanced Parameters > DB Backup folder, with a configurable retention period and a one-click delete.
- A "Preview" dry run for every action, sharing the exact same logic as the real run, so the report can never drift out of sync with what Apply actually does.
- A computed store health score with plain-language reasons, refreshed on every configure-page load.
- Scheduled maintenance for "Check & fix" and "Clean & optimize": a token-protected cron endpoint, plus a zero-configuration fallback that runs on a set interval the next time an admin opens the back office (after the page has already been sent to the browser).
- A "How this module works" guide at the top of the configure page.

### Changed
- Destructive actions (catalog reset, orders & customers reset) now require typing an exact confirmation phrase, checked server-side, replacing a checkbox plus a client-side `confirm()` dialog that a direct form submission could bypass entirely.
- Results are reported per-table in plain language ("12 row(s) pointing at a deleted product") instead of dumping raw SQL to the screen.
- The configure page was redesigned into clearly separated panels (guide, health, scheduling, backups, each action) and no longer depends on jQuery for its confirmation dialogs.
- Minimum supported version is now PrestaShop 1.7.0; older compatibility code paths were removed.

### Fixed
- The table-dependency sort used before "Check & fix" now has an iteration cap, so a future bad entry can never turn into an infinite loop.
- `truncate()`/related helpers were called as static methods while declared as instance methods (a PHP 8 deprecation); the whole cleanup engine is now consistently static.
