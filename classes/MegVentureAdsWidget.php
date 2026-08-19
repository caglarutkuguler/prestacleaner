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
            $items = self::fetchItems($widgetUrl);
        } catch (\Throwable $e) {
            return '';
        }
        if (empty($items)) {
            return '';
        }

        $html = '<div style="margin:16px 0;padding:14px 16px;background:#f5f6fa;border:1px solid #dde0e8;border-radius:6px;">'
              . '<div style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;">'
              . 'You might also like'
              . '</div>'
              . '<div style="display:flex;flex-wrap:wrap;gap:10px;">';

        foreach ($items as $item) {
            $name = isset($item['name']) ? (string) $item['name'] : '';
            $link = isset($item['link_url']) ? (string) $item['link_url'] : '';
            $img = isset($item['image_url']) ? (string) $item['image_url'] : '';
            if ($name === '' || $link === '') {
                continue;
            }
            $html .= '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
                   . 'style="width:110px;text-decoration:none;border:1px solid #dde0e8;border-radius:6px;overflow:hidden;'
                   . 'box-shadow:0 1px 3px rgba(0,0,0,.06);background:#fff;">';
            if ($img !== '') {
                $html .= '<img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" '
                       . 'style="width:100%;height:80px;object-fit:cover;display:block;">';
            }
            $html .= '<div style="padding:5px 6px;font-size:11px;color:#333;line-height:1.3;max-height:28px;overflow:hidden;">'
                   . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                   . '</div></a>';
        }

        $html .= '</div></div>';

        return $html;
    }

    /** Same dual cURL/stream-context GET pattern used throughout MEG Venture modules. */
    private static function fetchItems($url)
    {
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
            return [];
        }
        $data = json_decode($body, true);

        return isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    }
}
