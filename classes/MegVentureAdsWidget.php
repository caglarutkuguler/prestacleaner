<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2007-2026 MEG Venture
 * @license   All rights reserved
 */

/**
 * ============================================================================
 *  MegVentureAdsWidgetV2 — drop-in "you might also like" widget for MEG Venture
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
 *       $output .= MegVentureAdsWidgetV2::render('https://megventure.com/.../adswidget');
 *
 *     (append $output near the top or bottom of whatever getContent() already
 *     returns — it renders to an empty string if the widget URL is unreachable
 *     or misconfigured, so it can never break that module's own settings page.)
 *  -----------------------------------------------------------------------
 */
if (!class_exists('MegVentureAdsWidgetV2')) {
class MegVentureAdsWidgetV2
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

        // Hidden by the merchant (Hide button): remembered in a cookie shared by
        // every MEG Venture module, and nothing is fetched from megventure.com.
        if (self::isHidden()) {
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
              . '#' . $uid . ' .mvads-card{transition:transform .15s ease,box-shadow .15s ease;flex:0 0 260px;'
              . 'scroll-snap-align:start;}'
              . '#' . $uid . ' .mvads-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(20,20,40,.12);}'
              . '#' . $uid . ' .mvads-card:hover .mvads-title{color:#3b5bdb;}'
              . '#' . $uid . ' .mvads-track{display:flex;gap:18px;overflow-x:auto;scroll-snap-type:x proximity;'
              . 'scroll-behavior:smooth;padding:2px 2px 10px;-webkit-overflow-scrolling:touch;}'
              . '#' . $uid . ' .mvads-track::-webkit-scrollbar{height:6px;}'
              . '#' . $uid . ' .mvads-track::-webkit-scrollbar-thumb{background:#d7dae4;border-radius:3px;}'
              . '#' . $uid . ' .mvads-nav{position:absolute;top:50%;transform:translateY(-50%);width:32px;'
              . 'height:32px;border-radius:50%;border:1px solid #e2e5ee;background:#fff;box-shadow:0 2px 8px '
              . 'rgba(0,0,0,.12);cursor:pointer;font-size:15px;color:#444;line-height:1;padding:0;}'
              . '#' . $uid . ' .mvads-nav:hover{background:#f2f3f8;}'
              . '#' . $uid . ' .mvads-prev{left:-4px;}'
              . '#' . $uid . ' .mvads-next{right:-4px;}'
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

        // Horizontal slider, not a wrapping grid: in a narrow admin content column, a flex-wrap
        // grid leaves an uneven partial last row (cards "falling" onto their own row). A
        // horizontally-scrolling track with snap points and prev/next buttons stays tidy at any
        // container width — native scroll (drag/trackpad/wheel) works even with JS disabled; the
        // buttons are just a discoverability aid on top of that.
        $html .= '<div style="position:relative;">'
              . '<div class="mvads-track" id="' . $uid . 'track">';

        foreach ($items as $item) {
            $name = isset($item['name']) ? (string) $item['name'] : '';
            $link = self::safeUrl(isset($item['link_url']) ? $item['link_url'] : '');
            $img = self::safeUrl(isset($item['image_url']) ? $item['image_url'] : '');
            $desc = isset($item['description_short']) ? (string) $item['description_short'] : '';
            $price = isset($item['price_formatted']) ? (string) $item['price_formatted'] : '';
            if ($name === '' || $link === '') {
                continue;
            }
            $html .= '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" '
                   . 'class="mvads-card" '
                   . 'style="text-decoration:none;border:1px solid #e2e5ee;border-radius:8px;'
                   . 'overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);background:#fff;'
                   . 'display:flex;flex-direction:column;">';
            if ($img !== '') {
                // Match the Hummingbird theme's own product-listing image treatment (see e.g.
                // /en/12-free-modules): a plain SQUARE box, no crop/zoom. These source images
                // are natively 1:1 (1600x1600) — the previous landscape-ish box (260x220) added
                // its own extra letterboxing on top of the images' built-in decorative padding,
                // and the zoom-crop was compensating for that mismatch rather than fixing it.
                // A square box needs neither: the image already fills it edge to edge.
                $html .= '<div style="width:100%;height:260px;background:#0f1c2e;overflow:hidden;">'
                       . '<img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" '
                       . 'style="width:100%;height:100%;object-fit:contain;display:block;">'
                       . '</div>';
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

        $html .= '</div>';
        if (count($items) > 1) {
            $html .= '<button type="button" class="mvads-nav mvads-prev" data-mvads-dir="-1" '
                   . 'data-mvads-track="' . $uid . 'track" aria-label="Previous">&#8249;</button>'
                   . '<button type="button" class="mvads-nav mvads-next" data-mvads-dir="1" '
                   . 'data-mvads-track="' . $uid . 'track" aria-label="Next">&#8250;</button>';
        }
        $html .= '</div></div>';

        $html .= '<script>(function(){'
              . 'var root=document.getElementById(' . json_encode($uid) . ');if(!root)return;'
              . 'var track=document.getElementById(' . json_encode($uid . 'track') . ');'
              . 'var btns=root.querySelectorAll(".mvads-nav");'
              . 'var timer=null;'
              . 'var reduceMotion=window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches;'
              . 'function stopAuto(){if(timer){clearInterval(timer);timer=null;}}'
              . 'function startAuto(){'
              . 'if(reduceMotion||!track||track.scrollWidth<=track.clientWidth+4)return;'
              . 'stopAuto();'
              . 'timer=setInterval(function(){'
              . 'if(track.scrollLeft+track.clientWidth>=track.scrollWidth-4){'
              . 'track.scrollTo({left:0,behavior:"smooth"});'
              . '}else{'
              . 'track.scrollBy({left:280,behavior:"smooth"});'
              . '}'
              . '},4000);'
              . '}'
              . 'for(var i=0;i<btns.length;i++){'
              . 'btns[i].addEventListener("click",function(){'
              . 'stopAuto();'
              . 'var t=document.getElementById(this.getAttribute("data-mvads-track"));'
              . 'if(!t)return;'
              . 't.scrollBy({left:parseInt(this.getAttribute("data-mvads-dir"),10)*280,behavior:"smooth"});'
              . 'startAuto();'
              . '});'
              . '}'
              . 'if(track){'
              . 'track.addEventListener("mouseenter",stopAuto);'
              . 'track.addEventListener("mouseleave",startAuto);'
              . 'track.addEventListener("touchstart",stopAuto,{passive:true});'
              . '}'
              . 'startAuto();'
              . '})();</script>';

        $html .= self::hideControl($uid);

        return $html;
    }

    const HIDE_COOKIE = 'mvads_hidden';

    /**
     * @return bool
     */
    private static function isHidden()
    {
        return isset($_COOKIE[self::HIDE_COOKIE]) && $_COOKIE[self::HIDE_COOKIE] === '1';
    }

    /**
     * "Hide" button: removes the widget and remembers the choice in a cookie
     * for this browser (ten years, every MEG Venture module). Plain
     * JavaScript, no request to the shop or to megventure.com.
     *
     * @param string $uid
     *
     * @return string
     */
    private static function hideControl($uid)
    {
        return '<script>(function(){'
            . 'var root=document.getElementById(' . json_encode($uid) . ');if(!root)return;'
            . 'root.style.position="relative";root.style.paddingTop="38px";'
            . 'var b=document.createElement("button");b.type="button";'
            . 'b.title="Hide these suggestions in all MEG Venture modules";'
            . 'b.textContent="Hide \u00d7";'
            . 'b.style.cssText="position:absolute;top:10px;right:12px;border:1px solid #e2e5ee;border-radius:12px;'
            . 'background:#fff;color:#6c7280;font-size:11.5px;font-weight:600;line-height:1;padding:5px 10px;cursor:pointer;";'
            . 'b.addEventListener("click",function(){'
            . 'document.cookie="' . self::HIDE_COOKIE . '=1; path=/; max-age=315360000; SameSite=Lax";'
            . 'if(root.parentNode){root.parentNode.removeChild(root);}'
            . '});'
            . 'root.appendChild(b);'
            . '})();</script>';
    }
    /** Same dual cURL/stream-context GET pattern used throughout MEG Venture modules. */
    private static function fetchData($url)
    {
        $empty = ['items' => [], 'shop_name' => '', 'shop_logo_url' => '', 'shop_url' => ''];

        if (!self::isAllowedWidgetUrl($url)) {
            return $empty;
        }

        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
            ];
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $opts);
            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($result !== false && $status >= 200 && $status < 300) {
                $body = $result;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => self::TIMEOUT,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                ],
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
            'shop_logo_url' => self::safeUrl(isset($data['shop_logo_url']) ? $data['shop_logo_url'] : ''),
            'shop_url' => self::safeUrl(isset($data['shop_url']) ? $data['shop_url'] : ''),
        ];
    }

    /**
     * Every URL rendered by this widget comes out of a third-party JSON feed,
     * which makes it untrusted input no matter who publishes it. htmlspecialchars
     * keeps it inside the attribute but says nothing about the scheme, so a feed
     * answering "javascript:..." or "data:text/html,..." would produce a link
     * that runs script in the merchant's back office on click (CWE-79 via
     * CWE-20). Only absolute http(s) URLs are renderable here; anything else
     * becomes empty, and the caller already treats an empty URL as "no card"
     * and "no image".
     *
     * @param mixed $url
     *
     * @return string
     */
    private static function safeUrl($url)
    {
        // A JSON feed can answer with an array or an object where a string was
        // expected; casting that would raise a notice and produce "Array".
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }

    /**
     * Widget URL is authored in module PHP (not request input), but still pin
     * host + scheme so a compromised/redirecting feed cannot become SSRF
     * into the shop's private network (CWE-918).
     *
     * @param string $url
     *
     * @return bool
     */
    private static function isAllowedWidgetUrl($url)
    {
        $parts = parse_url((string) $url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) $parts['host']);

        return $host === 'megventure.com' || $host === 'www.megventure.com';
    }
}
}
