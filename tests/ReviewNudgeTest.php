<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Guards the 3.4.0 review-request line.
 *
 * Runs without PrestaShop: Configuration, Tools and Module are stubbed, so the
 * whole file is `php tests/ReviewNudgeTest.php` and nothing else.
 *
 * What it is here to catch: the line must stay silent for the first 21 days
 * (including on a fresh install and right after the 3.4.0 upgrade), must go
 * away forever on a click or a dismiss, must give up after three unanswered
 * displays, and uninstall must remove exactly its three keys and nothing else.
 */
define('_PS_VERSION_', '8.1.0');
define('_PS_MODULE_DIR_', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR);

class Configuration
{
    public static $store = [];
    public static function get($k, $l = null, $s = null, $sh = null, $default = false)
    {
        return array_key_exists($k, self::$store) ? self::$store[$k] : false;
    }
    public static function updateValue($k, $v)
    {
        self::$store[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        return true;
    }
    public static function updateGlobalValue($k, $v)
    {
        return self::updateValue($k, $v);
    }
    public static function deleteByName($k)
    {
        unset(self::$store[$k]);
        return true;
    }
}

class Tools
{
    public static $values = [];
    public static $redirectedTo = null;
    public static function getValue($k, $d = false)
    {
        return array_key_exists($k, self::$values) ? self::$values[$k] : $d;
    }
    public static function isSubmit($k) { return array_key_exists($k, self::$values); }
    public static function strtoupper($s) { return strtoupper($s); }
    public static function safeOutput($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    public static function passwdGen($n = 8) { return str_repeat('a', $n); }
    public static function redirect($url) { self::$redirectedTo = $url; }
}

class Validate { public static function isInt($v) { return (string) (int) $v === (string) $v; } }
class Shop { const CONTEXT_ALL = 1; }

abstract class Module
{
    public $name, $tab, $version, $author, $need_instance, $multishop_context, $bootstrap,
           $displayName, $description, $confirmUninstall, $ps_versions_compliancy, $context, $_path, $active;
    public function __construct() {}
    public function trans($id, $params = [], $domain = null) { return $id; }
    public function l($s, $specific = false) { return $s; }
    public function registerHook($h) { return true; }
    public function unregisterHook($h) { return true; }
    public function isRegisteredInHook($h) { return false; }
    public function display($f, $t) { return ''; }
    public function displayConfirmation($s) { return ''; }
    public function displayError($s) { return ''; }
    public function install() { return true; }
    public function uninstall() { return true; }
}

require dirname(__DIR__) . '/prestacleaner.php';
require_once dirname(__DIR__) . '/classes/MegVentureReviewNudge.php';

/** What render()/handleRequest() actually receive: the module, for l() and the BO language. */
class FakeNudgeModule
{
    public $context;
    public function __construct($iso = 'tr')
    {
        $this->context = new stdClass();
        $this->context->language = new stdClass();
        $this->context->language->iso_code = $iso;
    }
    public function l($s, $specific = false) { return $s; }
}

$fail = 0;
function ok($cond, $label) {
    global $fail;
    if ($cond) { echo "  ok   $label\n"; } else { echo "  FAIL $label\n"; $fail++; }
}

const DAY = 86400;
$configureUrl = 'index.php?controller=AdminModules&configure=prestacleaner';
$fake = new FakeNudgeModule('tr');

echo "1) Keys carry the module prefix\n";
$keys = MegVentureReviewNudge::configurationKeys();
ok(count($keys) === 3, 'exactly three configuration keys');
$unprefixed = array_filter($keys, function ($k) { return strpos($k, 'PRESTACLEANER_') !== 0; });
ok($unprefixed === [], 'all three carry the PRESTACLEANER_ prefix (' . implode(', ', $keys) . ')');

echo "\n2) Fresh install: hidden on day 0\n";
Configuration::$store = [];
$m = new PrestaCleaner();
ok($m->install(), 'install() succeeds');
$installedAt = (int) Configuration::get('PRESTACLEANER_REVIEW_INSTALLED_AT');
ok($installedAt > 0 && abs(time() - $installedAt) < 5, 'install() wrote the installed-at timestamp');
ok(!MegVentureReviewNudge::shouldDisplay(), 'hidden on the day of install');
ok(MegVentureReviewNudge::render($fake, $configureUrl) === '', 'render() returns nothing on day 0');
ok((int) Configuration::get('PRESTACLEANER_REVIEW_DISPLAYS') === 0, 'a hidden page view does not count as a display');

echo "\n3) Quiet period: day 20 hidden, day 21 shown\n";
Configuration::$store['PRESTACLEANER_REVIEW_INSTALLED_AT'] = (string) (time() - 20 * DAY);
ok(!MegVentureReviewNudge::shouldDisplay(), 'day 20: hidden');
Configuration::$store['PRESTACLEANER_REVIEW_INSTALLED_AT'] = (string) (time() - 21 * DAY);
ok(MegVentureReviewNudge::shouldDisplay(), 'day 21: shown');
Configuration::$store['PRESTACLEANER_REVIEW_INSTALLED_AT'] = (string) (time() - 100 * DAY);
ok(MegVentureReviewNudge::shouldDisplay(), 'day 100, never answered: still shown');

echo "\n4) The line itself\n";
$html = MegVentureReviewNudge::render($fake, $configureUrl);
ok(strpos($html, 'Happy with this module?') !== false, 'render() contains the request text');
ok(strpos($html, 'prestacleaner_review_go=1') !== false, 'render() contains the review link');
ok(strpos($html, 'prestacleaner_review_dismiss=1') !== false, 'render() contains the dismiss link');
ok(strpos($html, 'megventure.com') === false, 'no direct external URL in the HTML (click routes through the configure page)');
ok((int) Configuration::get('PRESTACLEANER_REVIEW_DISPLAYS') === 1, 'the view was counted');

echo "\n5) Three unanswered displays, hidden on the fourth\n";
Configuration::$store['PRESTACLEANER_REVIEW_DISPLAYS'] = '0';
ok(MegVentureReviewNudge::render($fake, $configureUrl) !== '', 'display 1 shown');
ok(MegVentureReviewNudge::render($fake, $configureUrl) !== '', 'display 2 shown');
ok(MegVentureReviewNudge::render($fake, $configureUrl) !== '', 'display 3 shown');
ok(MegVentureReviewNudge::render($fake, $configureUrl) === '', 'display 4: given up');
ok((int) Configuration::get('PRESTACLEANER_REVIEW_DISPLAYS') === 3, 'counter stopped at 3');

echo "\n6) A form-POST re-render is shown but not counted\n";
Configuration::$store['PRESTACLEANER_REVIEW_DISPLAYS'] = '1';
$_POST['submitPrestacleanerSettings'] = '1';
ok(MegVentureReviewNudge::render($fake, $configureUrl) !== '', 'still shown while saving settings');
ok((int) Configuration::get('PRESTACLEANER_REVIEW_DISPLAYS') === 1, 'but the save re-render did not burn a display');
$_POST = [];

echo "\n7) Dismissed: hidden forever\n";
Configuration::$store['PRESTACLEANER_REVIEW_DISPLAYS'] = '0';
Tools::$values = ['prestacleaner_review_dismiss' => '1'];
$banner = MegVentureReviewNudge::handleRequest($fake);
Tools::$values = [];
ok(strpos($banner, 'we will not ask again') !== false, 'dismiss answers with a confirmation');
ok((int) Configuration::get('PRESTACLEANER_REVIEW_DISMISSED') === 1, 'dismissed flag written');
ok(!MegVentureReviewNudge::shouldDisplay(), 'hidden right after dismissing');
Configuration::$store['PRESTACLEANER_REVIEW_INSTALLED_AT'] = (string) (time() - 365 * DAY);
ok(!MegVentureReviewNudge::shouldDisplay(), 'hidden a year later too');

echo "\n8) Review link clicked: recorded, redirected, hidden forever\n";
Configuration::$store = ['PRESTACLEANER_REVIEW_INSTALLED_AT' => (string) (time() - 30 * DAY)];
Tools::$redirectedTo = null;
Tools::$values = ['prestacleaner_review_go' => '1'];
MegVentureReviewNudge::handleRequest($fake);
Tools::$values = [];
ok(Tools::$redirectedTo === 'https://megventure.com/tr/testimonials/write?id_product=90',
   'redirected to the review form in the BO language (' . Tools::$redirectedTo . ')');
ok(!MegVentureReviewNudge::shouldDisplay(), 'never shown again after the click');
ok(MegVentureReviewNudge::reviewUrl('xx') === 'https://megventure.com/en/testimonials/write?id_product=90',
   'a language megventure.com does not serve falls back to en');

echo "\n9) Upgrade path: no timestamp -> gets one, not shown immediately\n";
require_once dirname(__DIR__) . '/upgrade/upgrade-3.4.0.php';
Configuration::$store = ['PRESTACLEANER_CRON_TOKEN' => 'existing-shop']; // a 3.3.1 shop: no review keys at all
ok(upgrade_module_3_4_0($m) === true, 'upgrade script succeeds');
$stamp = (int) Configuration::get('PRESTACLEANER_REVIEW_INSTALLED_AT');
ok($stamp > 0 && abs(time() - $stamp) < 5, 'missing timestamp was written with the current time');
ok(!MegVentureReviewNudge::shouldDisplay(), 'not shown immediately after upgrading');
Configuration::$store['PRESTACLEANER_REVIEW_INSTALLED_AT'] = '12345';
upgrade_module_3_4_0($m);
ok(Configuration::get('PRESTACLEANER_REVIEW_INSTALLED_AT') === '12345', 'an existing timestamp is never overwritten');

echo "\n10) Uninstall removes the three keys and touches nothing else\n";
Configuration::$store = [
    'PRESTACLEANER_REVIEW_INSTALLED_AT' => '12345',
    'PRESTACLEANER_REVIEW_DISMISSED' => '1',
    'PRESTACLEANER_REVIEW_DISPLAYS' => '2',
    'PRESTACLEANER_CRON_TOKEN' => 'tok',
    'theme' => 'another-modules-value',
    'MEGTESTIMONIAL_WHO' => 'another-modules-value',
];
MegVentureReviewNudge::onUninstall();
$reviewLeft = array_filter(array_keys(Configuration::$store), function ($k) { return strpos($k, 'PRESTACLEANER_REVIEW_') === 0; });
ok($reviewLeft === [], 'onUninstall() removed all three review keys');
ok(Configuration::get('PRESTACLEANER_CRON_TOKEN') === 'tok', 'the module\'s other keys were left for the module');
ok(Configuration::get('theme') === 'another-modules-value', 'a bare foreign key was not touched');
ok(Configuration::get('MEGTESTIMONIAL_WHO') === 'another-modules-value', 'another module\'s prefixed key was not touched');
ok($m->uninstall(), 'full module uninstall() succeeds');
$prefixedLeft = array_filter(array_keys(Configuration::$store), function ($k) { return strpos($k, 'PRESTACLEANER_') === 0; });
ok($prefixedLeft === [], 'module uninstall removed every PRESTACLEANER_ key');
ok(Configuration::get('theme') === 'another-modules-value' && Configuration::get('MEGTESTIMONIAL_WHO') === 'another-modules-value',
   'and still touched nothing foreign');

echo "\n" . ($fail === 0 ? "OK - all passed\n" : "$fail test(s) FAILED\n");
exit($fail === 0 ? 0 : 1);
