# PrestaCleaner — test checklist

## Security fixes in 3.5.1

- [ ] Cron token after fresh install is 32 lowercase hex (`bin2hex(random_bytes(16))`), not `Tools::passwdGen`.
- [ ] Opening configure on a shop that still has a `passwdGen`-era token rotates it once and shows the warning banner; server cron URL must be updated.
- [ ] Upgrade `3.5.0 → 3.5.1` rotates a weak token via `upgrade-3.5.1.php`.
- [ ] Cron endpoint with wrong/empty token returns 403 JSON without leaking internals.
- [ ] Cron endpoint that throws returns `Scheduled cleanup failed.` (not `$e->getMessage()`); detail stays in PrestaShop logs.
- [ ] Promo ads widget: feed item with `link_url=javascript:…` does not render a card; `shop_url` / image URLs with non-http(s) schemes are dropped.
- [ ] Promo ads widget fetch does not follow redirects; only `megventure.com` / `www.megventure.com` widget URLs are fetched.
- [ ] Destructive actions (catalog/sales reset, delete orders) still require confirmation phrase / checkbox as before — behaviour preserved.

## Automated

```bash
php tests/CronTokenTest.php
php tests/AdsWidgetUrlTest.php
# from module-review:
_tools/php-lint.sh prestacleaner
_tools/php-lint.sh prestacleaner 7.4-cli-alpine
```
