# Changelog

All notable changes to **Database Cleaner** (`prestacleaner`).

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
