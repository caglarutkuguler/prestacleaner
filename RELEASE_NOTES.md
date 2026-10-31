# Database Cleaner - Safe Cleanup, Backup & Scheduler — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 3.6.0

### New
- Orphaned cart data — a new panel between "Clean & optimize" and the reset actions. It finds two kinds of cart rows that cart quantities still add up although no valid cart line stands behind them:
- Customizations still marked as in a cart (`in_cart = 1`) whose product has no customization field left — on cores that soft-delete fields (`customization_field.is_deleted`), a product whose fields are all flagged counts as having none;
- Cart lines saved under cart id 0, which no real cart can have
- Every cart behind a found customization is checked against `orders`. A cart with an order is never touched; it is listed as "Skipped: cart #X has a linked order, review manually" in the preview, after Apply, and in the log
- The preview lists the rows themselves (cart, product, customization, date added; first 200 per kind, with full totals). Apply deletes `customized_data`, then `customization`, then the `cart_product` lines holding those exact customizations, then the cart-id-0 lines — all in one transaction, rolled back on any database error. Lines of the same product without that customization stay in the cart
- Backs up the affected rows first (on by default, its own setting under Scheduled maintenance & backups), into the same `prestacleaner_*.sql.gz` files as every other action
- Each run that removes or skips something is written to Advanced Parameters > Logs with the counts per kind and the backup file name; a run that only repeats the same skipped carts is not logged again
- Can join the scheduled run (cron URL and back-office fallback) through a new "Also remove "Orphaned cart data" on each scheduled run" checkbox — off by default. The cron response and the stored last-run summary gain `cart_orphans_rows`

## 3.5.0

### New
- Clean & optimize now reaps onefee's orphaned cart-fee configuration rows. The onefee module stores two configuration rows per fee line in a cart, keyed by cart id, and only specific cart events remove them — so every cart deleted by this module's own abandoned-cart cleanup (or by anything else) left its rows behind forever, and PrestaShop loads the whole configuration table into memory on every page view. The new rule runs right after the abandoned-cart cleanup and deletes only rows whose cart no longer exists; rows belonging to a living cart are never touched, however old, because a cart can come back to life and its fee pricing reads from these rows. Preview counts it, apply backs the rows up first, exactly like every other rule

## 3.4.0

### New
- A one-line review request on the module's own configure page (and nowhere else). It first appears 21 days after install — for existing installations, 21 days after this upgrade — and disappears forever once the merchant clicks the review link, clicks "No thanks", or has simply seen it three times. The link goes to the module's review form on megventure.com, in the back office language where the shop serves it. What it does not do: no tracking, no external request of any kind (the line is plain HTML; the review link routes through the configure page so the click is remembered, then the merchant's own browser is sent to the form), no JavaScript required, and nothing stored beyond three of the module's own prefixed configuration values (install timestamp, dismissed flag, display count), all removed on uninstall
