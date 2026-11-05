# Database Cleaner - Safe Cleanup, Backup & Scheduler — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 3.6.1

### New
- The configuration page links to the free MEG Venture Updates module, which installs updates for this module in one click. The line is hidden once that module is installed

## 3.6.0

### New
- Orphaned cart data — a new panel between "Clean & optimize" and the reset actions. It finds two kinds of cart rows that cart quantities still add up although no valid cart line stands behind them:
- Customizations still marked as in a cart whose product has no customization field left — on cores that only flag deleted fields, a product whose fields are all flagged counts as having none;
- Cart lines saved under cart id 0, which no real cart can have
- Every cart behind a found customization is checked against the shop's orders. A cart with an order is never touched; it is listed as "Skipped: cart #X has a linked order, review manually" in the preview, after Apply, and in the log
- The preview lists the rows themselves (cart, product, customization, date added; first 200 per kind, with full totals). Apply deletes the customization values, then the customizations, then the cart lines holding those exact customizations, then the cart-id-0 lines — all in one transaction, rolled back on any database error. Lines of the same product without that customization stay in the cart
- Backs up the affected rows first (on by default, its own setting under Scheduled maintenance & backups), into the same compressed SQL backup files as every other action
- Each run that removes or skips something is written to Advanced Parameters › Logs with the counts per kind and the backup file name; a run that only repeats the same skipped carts is not logged again
- Can join the scheduled run (cron URL and back-office fallback) through a new "Also remove "Orphaned cart data" on each scheduled run" checkbox — off by default. The cron response and the stored last-run summary gain an orphaned-cart-rows count

## 3.5.0

### New
- Clean & optimize now reaps onefee's orphaned cart-fee configuration rows. The onefee module stores two configuration rows per fee line in a cart, keyed by cart id, and only specific cart events remove them — so every cart deleted by this module's own abandoned-cart cleanup (or by anything else) left its rows behind forever, and PrestaShop loads the whole configuration table into memory on every page view. The new rule runs right after the abandoned-cart cleanup and deletes only rows whose cart no longer exists; rows belonging to a living cart are never touched, however old, because a cart can come back to life and its fee pricing reads from these rows. Preview counts it, apply backs the rows up first, exactly like every other rule
