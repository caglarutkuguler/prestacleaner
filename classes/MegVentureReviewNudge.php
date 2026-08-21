<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2007-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

/**
 * ============================================================================
 *  MegVentureReviewNudge — drop-in review-request line for the configuration
 *  page of MEG Venture modules.
 *
 *  A single line asking the merchant to leave a review, shown only on this
 *  module's own configure page, starting DELAY_DAYS after install. It goes
 *  away forever the moment the merchant clicks the review link, clicks
 *  "No thanks", or has simply seen it MAX_DISPLAYS times.
 *
 *  It makes no outbound request of any kind: the line is plain HTML, the
 *  review link routes through the configure page (so the click can be
 *  remembered without JavaScript) and then sends the merchant's own browser
 *  to the review form. Nothing is stored beyond the three configuration
 *  values named by configurationKeys().
 *
 *  HOW TO USE IN ANOTHER MODULE
 *  -----------------------------------------------------------------------
 *  1. Copy this file into that module as `classes/MegVentureReviewNudge.php`.
 *     It has no dependency on anything outside the module folder.
 *  2. Adjust the constants below: CONFIG_PREFIX to that module's own prefix,
 *     REVIEW_URL to that module's product page form. For a module sold on
 *     PrestaShop Addons, REVIEW_URL is simply the Addons product page URL
 *     (with or without a {lang} placeholder) — a one-line change.
 *  3. In install():    MegVentureReviewNudge::onInstall()
 *     In uninstall():  MegVentureReviewNudge::onUninstall()
 *     In getContent(): $out = MegVentureReviewNudge::handleRequest($this)
 *                           . MegVentureReviewNudge::render($this, $configureUrl);
 *     In upgrade-X.Y.Z.php: MegVentureReviewNudge::ensureInstalledAt()
 *     (existing installs have no install date; this starts their clock at
 *     the upgrade instead).
 *  4. Add the four strings below to translations/<iso>.php with the source
 *     token `megventurereviewnudge`. Keep the English strings free of
 *     apostrophes: the legacy translation key is the md5 of the
 *     quote-escaped source string.
 *  -----------------------------------------------------------------------
 */
class MegVentureReviewNudge
{
    /* Everything module-specific lives in these four constants. */
    const CONFIG_PREFIX = 'PRESTACLEANER_';
    const REVIEW_URL = 'https://megventure.com/{lang}/testimonials/write?id_product=90';
    const DELAY_DAYS = 21;
    const MAX_DISPLAYS = 3;

    /** Languages megventure.com actually serves; anything else falls back to en. */
    const REVIEW_URL_LANGS = ['en', 'tr', 'fr', 'es', 'de', 'it', 'nl', 'pl', 'pt'];

    /**
     * The three configuration values this class owns — and the only thing it
     * ever stores. All carry the module's own prefix per the house rules.
     *
     * @return string[]
     */
    public static function configurationKeys()
    {
        return [
            self::CONFIG_PREFIX . 'REVIEW_INSTALLED_AT',
            self::CONFIG_PREFIX . 'REVIEW_DISMISSED',
            self::CONFIG_PREFIX . 'REVIEW_DISPLAYS',
        ];
    }

    /**
     * Called from the module's install(). A module reset (uninstall +
     * install) lands here too, so a reset restarts the quiet period.
     *
     * @return bool
     */
    public static function onInstall()
    {
        return Configuration::updateGlobalValue(self::CONFIG_PREFIX . 'REVIEW_INSTALLED_AT', time());
    }

    /**
     * Called from the version upgrade script. Existing installations have no
     * install date, so their clock starts at the upgrade — we genuinely do
     * not know when they installed, and "ask DELAY_DAYS from now" is the
     * honest reading of that. Safe to run twice: an existing value is kept.
     *
     * Uses get() === false semantics rather than Configuration::hasKey(),
     * which is not safe on the oldest supported cores; <= 0 also catches an
     * empty or damaged row, which must never mean "show immediately".
     *
     * @return bool
     */
    public static function ensureInstalledAt()
    {
        if ((int) Configuration::get(self::CONFIG_PREFIX . 'REVIEW_INSTALLED_AT') <= 0) {
            return Configuration::updateGlobalValue(self::CONFIG_PREFIX . 'REVIEW_INSTALLED_AT', time());
        }

        return true;
    }

    /**
     * Called from the module's uninstall(). Deletes exactly the keys in
     * configurationKeys() and nothing else.
     *
     * @return bool
     */
    public static function onUninstall()
    {
        foreach (self::configurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * @return bool
     */
    public static function shouldDisplay()
    {
        if ((int) Configuration::get(self::CONFIG_PREFIX . 'REVIEW_DISMISSED')) {
            return false;
        }

        if ((int) Configuration::get(self::CONFIG_PREFIX . 'REVIEW_DISPLAYS') >= self::MAX_DISPLAYS) {
            return false;
        }

        $installedAt = (int) Configuration::get(self::CONFIG_PREFIX . 'REVIEW_INSTALLED_AT');
        if ($installedAt <= 0) {
            // No usable install date (interrupted upgrade, hand-edited row).
            // Start the clock now rather than showing the line immediately.
            Configuration::updateGlobalValue(self::CONFIG_PREFIX . 'REVIEW_INSTALLED_AT', time());

            return false;
        }

        return (time() - $installedAt) >= self::DELAY_DAYS * 86400;
    }

    /**
     * Handle the two links the line renders. Both arrive on the module's own
     * configure page URL, exactly like every other configuration write this
     * module accepts, so they sit behind the same guard (an authenticated
     * back-office employee on the AdminModules controller).
     *
     * The review link routes through here so the click can be recorded
     * without JavaScript; the merchant's browser is then redirected to the
     * review form. The module itself makes no request anywhere.
     *
     * @param Module $module
     *
     * @return string Confirmation HTML after a dismiss, '' otherwise
     */
    public static function handleRequest($module)
    {
        $prefix = strtolower(self::CONFIG_PREFIX);

        if (Tools::getValue($prefix . 'review_go')) {
            self::dismiss();

            $iso = isset($module->context->language->iso_code) ? $module->context->language->iso_code : 'en';
            Tools::redirect(self::reviewUrl($iso));

            return '';
        }

        if (Tools::getValue($prefix . 'review_dismiss')) {
            self::dismiss();

            return '<div class="alert alert-success">'
                . $module->l('Okay, we will not ask again.', 'megventurereviewnudge')
                . '</div>';
        }

        return '';
    }

    /**
     * The line itself, or '' when it has nothing to say.
     *
     * @param Module $module       Supplies l() so the strings are translatable
     * @param string $configureUrl The module's own configure page URL; the two
     *                             links are built on it so they carry whatever
     *                             guard the module's other writes carry
     *
     * @return string
     */
    public static function render($module, $configureUrl)
    {
        if (!self::shouldDisplay()) {
            return '';
        }

        // Only a plain page view counts toward MAX_DISPLAYS. Every action on
        // this page re-renders it via POST, so counting re-renders would let
        // one save-heavy session burn the whole allowance in a minute.
        if (empty($_POST)) {
            self::recordDisplay();
        }

        $prefix = strtolower(self::CONFIG_PREFIX);
        $sep = strpos($configureUrl, '?') === false ? '?' : '&';
        $goUrl = htmlspecialchars($configureUrl . $sep . $prefix . 'review_go=1', ENT_QUOTES, 'UTF-8');
        $dismissUrl = htmlspecialchars($configureUrl . $sep . $prefix . 'review_dismiss=1', ENT_QUOTES, 'UTF-8');

        return '<div class="alert alert-info megventure-review-nudge">'
            . $module->l('Happy with this module? A short review helps other merchants find it.', 'megventurereviewnudge')
            . ' <a href="' . $goUrl . '" target="_blank" rel="noopener">'
            . $module->l('Leave a review', 'megventurereviewnudge')
            . '</a> &middot; <a href="' . $dismissUrl . '">'
            . $module->l('No thanks', 'megventurereviewnudge')
            . '</a></div>';
    }

    /**
     * The review form for this module, in the given back-office language when
     * megventure.com serves it, in English otherwise. A REVIEW_URL without a
     * {lang} placeholder (the future Addons variant) passes through untouched.
     *
     * @param string $isoCode
     *
     * @return string
     */
    public static function reviewUrl($isoCode)
    {
        $iso = strtolower((string) $isoCode);
        if (!in_array($iso, self::REVIEW_URL_LANGS, true)) {
            $iso = 'en';
        }

        return str_replace('{lang}', $iso, self::REVIEW_URL);
    }

    private static function dismiss()
    {
        Configuration::updateGlobalValue(self::CONFIG_PREFIX . 'REVIEW_DISMISSED', 1);
    }

    private static function recordDisplay()
    {
        Configuration::updateGlobalValue(
            self::CONFIG_PREFIX . 'REVIEW_DISPLAYS',
            (int) Configuration::get(self::CONFIG_PREFIX . 'REVIEW_DISPLAYS') + 1
        );
    }
}
