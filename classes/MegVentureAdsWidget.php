<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2007-2026 MEG Venture
 * @license   All rights reserved
 */

/**
 * ============================================================================
 *  MegVentureAdsWidget — drop-in "you might also like" widget for MEG Venture
 *  modules. This file is NOT part of virtualproductcombination's own runtime;
 *  it's a template meant to be copied into each OTHER module you distribute
 *  (onefee, cloudflare, ...), so its own admin configuration page can show a
 *  small promo grid of your other modules — pulled live from this shop's
 *  own "Promo Ads" settings (virtualproductcombination's configure page).
 *
 *  HOW TO USE IN ANOTHER MODULE
 *  -----------------------------------------------------------------------
 *  1. Copy this file into that module, e.g. `classes/MegVentureAdsWidget.php`
 *     (or anywhere convenient — it has no dependency on this module).
 *  2. In virtualproductcombination's admin configure page, copy the
 *     "Public widget URL" value from the Promo Ads panel.
 *  3. In the OTHER module's getContent(), add:
 *
 *       require_once _PS_MODULE_DIR_ . 'yourmodule/classes/MegVentureAdsWidget.php';
 *       $output .= MegVentureAdsWidget::render('https://megventure.com/.../adswidget');
 *
 *     (append $output near the top or bottom of whatever getContent() already
 *     returns — it renders to an empty string if the widget URL is unreachable
 *     or misconfigured, so it can never break that module's own settings page.)
 *  -----------------------------------------------------------------------
 */
class MegVentureAdsWidget
{
    const TIMEOUT = 3;

    /**
     * Fetches the current promo pool from the given widget URL and returns ready-to-echo HTML,
     * or '' if anything goes wrong (network error, empty pool, malformed response) — deliberately
     * silent, this must never surface an error on the HOST module's own configuration page.
     */
    public static function render($widgetUrl)
    {
        $widgetUrl = trim((string) $widgetUrl);
        if ($widgetUrl === '') {
            return '';
        }

        try {
            $data = self::fetchData($widgetUrl);
        } catch (\Throwable $e) {
            return '';
        }
        $items = $data['items'];
        if (empty($items)) {
            return '';
        }
        $shopName = $data['shop_name'];
        $shopLogoUrl = $data['shop_logo_url'];
        $shopUrl = $data['shop_url'];

        $uid = 'mvads' . substr(md5($widgetUrl . microtime()), 0, 8);

        $html = '<style>'
              . '#' . $uid . ' .mvads-card{transition:transform .15s ease,box-shadow .15s ease;}'
              . '#' . $uid . ' .mvads-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(20,20,40,.12);}'
              . '#' . $uid . ' .mvads-card:hover .mvads-title{color:#3b5bdb;}'
              . '</style>';

        $html .= '<div id="' . $uid . '" style="margin:20px 0;padding:22px 24px;background:#f8f9fc;'
              . 'border:1px solid #e2e5ee;border-radius:10px;'
              . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';

        // Branded header: this shop's own logo + name, so the grid reads as "more from the
        // people who made the module you're using" rather than an anonymous ad block.
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;'
              . 'flex-wrap:wrap;gap:10px;margin-bottom:18px;">'
              . '<div style="display:flex;align-items:center;gap:10px;">';
        if ($shopLogoUrl !== '') {
            $html .= '<img src="' . htmlspecialchars($shopLogoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" '
                   . 'style="width:32px;height:32px;object-fit:contain;border-radius:6px;background:#fff;'
                   . 'border:1px solid #e2e5ee;padding:3px;">';
        }
        $html .= '<div>'
              . '<div style="font-size:11px;font-weight:700;color:#9096a8;text-transform:uppercase;'
              . 'letter-spacing:.5px;">You might also like</div>'
              . '<div style="font-size:14px;font-weight:700;color:#222;">'
              . ($shopName !== '' ? 'More modules from ' . htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') : 'More modules')
              . '</div>'
              . '</div>'
              . '</div>';
        if ($shopUrl !== '' && $shopName !== '') {
            $html .= '<a href="' . htmlspecialchars($shopUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
                   . 'style="font-size:12.5px;font-weight:600;color:#3b5bdb;text-decoration:none;white-space:nowrap;">'
                   . 'Visit ' . htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') . ' &rarr;'
                   . '</a>';
        }
        $html .= '</div>';

        $html .= '<div style="display:flex;flex-wrap:wrap;gap:18px;">';

        foreach ($items as $item) {
            $name = isset($item['name']) ? (string) $item['name'] : '';
            $link = isset($item['link_url']) ? (string) $item['link_url'] : '';
            $img = isset($item['image_url']) ? (string) $item['image_url'] : '';
            $desc = isset($item['description_short']) ? (string) $item['description_short'] : '';
            $price = isset($item['price_formatted']) ? (string) $item['price_formatted'] : '';
            if ($name === '' || $link === '') {
                continue;
            }
            $html .= '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
                   . 'class="mvads-card" '
                   . 'style="width:260px;text-decoration:none;border:1px solid #e2e5ee;border-radius:8px;'
                   . 'overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);background:#fff;'
                   . 'display:flex;flex-direction:column;">';
            if ($img !== '') {
                $html .= '<img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" '
                       . 'style="width:100%;height:180px;object-fit:cover;display:block;background:#eef0f6;">';
            }
            $html .= '<div style="padding:14px 16px 16px;flex:1;display:flex;flex-direction:column;">'
                   . '<div class="mvads-title" style="font-size:15px;font-weight:700;color:#222;line-height:1.35;'
                   . 'margin-bottom:6px;">'
                   . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                   . '</div>';
            if ($desc !== '') {
                $html .= '<div style="font-size:12.5px;color:#888;line-height:1.5;margin-bottom:10px;flex:1;">'
                       . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')
                       . '</div>';
            }
            if ($price !== '') {
                $html .= '<div style="font-size:16px;font-weight:700;color:#0ca678;margin-top:auto;">'
                       . htmlspecialchars($price, ENT_QUOTES, 'UTF-8')
                       . '</div>';
            }
            $html .= '</div></a>';
        }

        $html .= '</div></div>';

        return $html;
    }

    /** Same dual cURL/stream-context GET pattern used throughout MEG Venture modules. */
    private static function fetchData($url)
    {
        $empty = ['items' => [], 'shop_name' => '', 'shop_logo_url' => '', 'shop_url' => ''];

        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($result !== false && $status >= 200 && $status < 300) {
                $body = $result;
            }
        } else {
            $context = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => self::TIMEOUT, 'ignore_errors' => true],
            ]);
            $result = @file_get_contents($url, false, $context);
            $body = $result !== false ? $result : null;
        }

        if ($body === null) {
            return $empty;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $empty;
        }

        return [
            'items' => isset($data['items']) && is_array($data['items']) ? $data['items'] : [],
            'shop_name' => isset($data['shop_name']) ? (string) $data['shop_name'] : '',
            'shop_logo_url' => isset($data['shop_logo_url']) ? (string) $data['shop_logo_url'] : '',
            'shop_url' => isset($data['shop_url']) ? (string) $data['shop_url'] : '',
        ];
    }
}
