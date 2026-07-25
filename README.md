# Database Cleaner

Safely clean up a PrestaShop database: fix orphan rows left behind by deleted products, orders, languages or modules; clear out abandoned carts and dead cart rules; or reset the whole catalog / order history to start fresh. Every action can back itself up first, has a dry-run preview, and destructive actions require typing a confirmation phrase - not just ticking a box.

**Compatibility:** PrestaShop 1.7.0 and above (including PrestaShop 8 and 9).

## What it does

- **Store health score** - a live, computed score (not a static claim) on the configure page, with the specific reasons behind it. The same score also appears as a circular badge in the left column of the PrestaShop Dashboard (not on every back-office page), clearly labelled as coming from this module, with a link back to the configure page.
- **Check & fix** - removes rows that point at something already deleted (a product, an order, a language, a shop...) across ~100 known table relationships, duplicate configuration entries, and orphan translations. Always safe to run.
- **Clean & optimize** - removes abandoned carts (older than a month, never ordered), expired or exhausted cart rules, and re-numbers admin menu positions left with gaps or duplicates. Always safe to run.
- **Reset the catalog / Reset orders & customers** - permanently wipes the corresponding tables and images. This is the only irreversible action, and it is never scheduled or reachable by cron.
- **Delete selected orders** - search by order ID/reference, status, or date range, tick the ones you want, and delete only those - a scoped alternative to a full orders reset for clearing out stray test transactions. Cleans up every related row (order lines, invoices, payments, history, carriers, returns, credit slips, order messages) in the correct order; everything else (customers, carts, other orders) is left untouched.
- **Preview (dry run)** - every action can be previewed first: it reports exactly what would change, using the same logic as the real run, without writing anything.
- **Automatic backup** - before any action, the module can back up exactly the rows about to be touched (a full snapshot for the two reset actions) to the same folder PrestaShop's own Advanced Parameters > DB Backup page reads from - restore from there, no separate restore tool to trust.
- **Scheduled maintenance** - "Check & fix" and "Clean & optimize" can run automatically: on an interval you set, either from a real server cron hitting a token-protected URL, or as a best-effort fallback the next time an admin opens the back office (run after the page has already been sent, so it's never felt as a slowdown).

## Installation

1. Back Office → Modules → Upload a module, select the zip file, then install.
2. Open the module's **Configure** page. The first panel is a short "How this module works" guide.
3. Everything works with its defaults; nothing is required before you can start using **Check & fix** or **Clean & optimize**.

## Configuration

The configure page is organized top to bottom:

1. **How this module works** - a short guide.
2. **Store health** - a live score with the specific issues found, if any.
3. **Scheduled maintenance & backups** - turn on automatic runs, set the interval, set how long backup files are kept, and copy the cron URL for a real server cron job.
4. **Backup files** - every backup this module has created, with size, date, and a delete button. Download or restore them from Advanced Parameters > DB Backup.
5. **Check & fix**, **Clean & optimize** - description, a "back up first" checkbox, Preview and Apply buttons.
6. **Reset the catalog**, **Reset orders & customers** - the same, plus a text field where you must type the exact confirmation phrase shown before Apply does anything.
7. **Delete selected orders** - search/filter orders, tick the ones to remove, optionally back them up, tick "I understand...", then Preview or Delete selected.

This module has no storefront component - there is nothing for a shop visitor to see; every setting here only affects the back office and the database.

**Multistore:** all actions and settings are shop-independent - there is only one database, so a reset or cleanup always applies to the whole installation, not to a single shop in a multistore group.

## Backups & restoring

Backup files are plain gzipped SQL, named `prestacleaner_<action>-<timestamp>.sql.gz`, stored in the same `backups` folder used by PrestaShop's own Advanced Parameters > DB Backup page - they show up there automatically, where you can download or restore them with the standard, already-audited core tool instead of a second restore engine this module would have to maintain. They are database-only: uploaded files and images are never included, so keep using your normal hosting backup for full disaster recovery.

## Scheduled cleaning

Two ways to automate "Check & fix" and "Clean & optimize" (never the catalog/orders reset):

- **Real cron (recommended):** copy the URL shown in the Scheduled maintenance panel into your hosting's cron job scheduler, once a day is enough. The URL is protected by a long random token; regenerate it any time from the same panel if it ever leaks.
- **No server access needed:** turn on "Automatically run..." and set an interval. The check runs once per admin page load (a single, cheap Configuration read) and only actually performs the cleanup when the interval has elapsed, after the admin's page has already been delivered to the browser.

## Troubleshooting

**The cron URL returns `{"success":false,"error":"Invalid token."}`.**
The token in your cron job doesn't match the one currently stored. Copy the URL again from the configure page, or click "Generate a new address" if you suspect it was regenerated.

**"Apply" on Reset the catalog / Reset orders does nothing and shows an error.**
The confirmation phrase must match exactly (it's not case-sensitive, but every word must be typed). This is intentional: a checkbox alone was too easy to click by accident.

**A backup wasn't created even though "back up first" was ticked.**
Check that the PrestaShop admin folder's `backups` directory is writable by the web server. The result message after running an action states plainly whether the backup succeeded.

**The health score won't reach 100.**
A few reasons are informational rather than problems (for example, "scheduled maintenance is off") and only disappear once you actually enable that setting; the rest point at a specific panel to run.

**"Delete selected" says nothing was deleted even though I checked orders.**
Tick the "I understand the checked orders will be permanently deleted" box - it's a required, server-checked confirmation, not just decoration.

**A payment record disappeared after deleting an order, but a sibling order using the same reference is fine.**
That's expected: a payment is only removed once none of the orders sharing its reference (multi-package orders can share one) still exist; as long as one survives, the payment record is kept.

**The Dashboard widget appears on every back-office page, not just the Dashboard.**
Fixed in 3.3.0 - it was registered on `displayDashboardTop`, which is actually rendered by the shared page-header toolbar on every admin page, not the Dashboard specifically. It now uses `dashboardZoneOne` (the Dashboard's own left-column hook) instead; updating past 3.3.0 unregisters the old hook automatically.

**The Dashboard widget shows an old score / doesn't match the configure page.**
The Dashboard strip is cached for up to an hour to avoid re-running the full health check on every Dashboard view; the configure page always computes it live (and refreshes that cache in the process). Running any action, or a scheduled run, also refreshes it immediately.

**Automatic scheduled runs never seem to happen.**
Without a real cron job, the fallback only triggers when an admin opens the back office, so a shop nobody logs into for weeks won't run on schedule either - use the cron URL instead for guaranteed automation.

## What changed in 3.0.0

- Complete rewrite: real automatic backups, a dry-run preview for every action, a computed store health score, scheduled maintenance (cron endpoint + back-office fallback), and a redesigned configure page.
- Added **Delete selected orders**: search/filter and remove specific orders instead of only being able to reset every order in the store.
- Added the store health score to the PrestaShop Dashboard itself, clearly attributed to this module (moved to the Dashboard's left column and redesigned as a circular badge in 3.3.0 - see below).
- Destructive actions (catalog/orders reset) now require typing an exact confirmation phrase, checked server-side, instead of a checkbox plus a client-side `confirm()` dialog that a direct form submission could bypass entirely.
- Results are now reported in plain language (which table, why, how many rows) instead of raw SQL.
- Removed the module's dependency on jQuery for its confirmation dialogs.

## Marketing notes

- "A dry-run preview before every action" is a genuine differentiator for a database-cleanup tool - most competitors are one-click-and-hope.
- The store health score turns an otherwise purely reactive maintenance tool into something worth checking in on - a good hook for an Addons listing screenshot.
- Consider a follow-up module bundling per-table storage-usage reporting; the health-score groundwork already computes several of the relevant numbers.

---
MEG Venture — info@megventure.com
