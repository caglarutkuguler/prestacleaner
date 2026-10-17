<?php
/**
 * Pins cron-token entropy rules for PrestaCleaner 3.5.1+.
 *
 *     php tests/CronTokenTest.php
 *
 * @author    MEG Venture & Consulting Ltd. <info@megventure.com>
 * @copyright 2019-2026 MEG Venture
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('_PS_VERSION_', 'test');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

// Minimal Module stub so prestacleaner.php can load outside the shop.
if (!class_exists('Module', false)) {
    class Module
    {
        public $name;
        public $tab;
        public $version;
        public $author;
        public $need_instance;
        public $bootstrap;
        public $displayName;
        public $description;
        public $confirmUninstall;
        public $ps_versions_compliancy;
        public $multishop_context;
        public $context;
        public $active = true;

        public function __construct()
        {
        }

        public function install()
        {
            return true;
        }

        public function uninstall()
        {
            return true;
        }

        public function registerHook($hook)
        {
            return true;
        }

        public function trans($s, array $params = [], $domain = null)
        {
            return $s;
        }
    }
}

if (!class_exists('Shop', false)) {
    class Shop
    {
        const CONTEXT_ALL = 7;
    }
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        public static $store = [];

        public static function get($key)
        {
            return array_key_exists($key, self::$store) ? self::$store[$key] : false;
        }

        public static function updateGlobalValue($key, $value)
        {
            self::$store[$key] = $value;

            return true;
        }

        public static function deleteByName($key)
        {
            unset(self::$store[$key]);

            return true;
        }
    }
}

if (!class_exists('Tools', false)) {
    class Tools
    {
        public static function passwdGen($length)
        {
            // Deliberately weak stand-in for the PS helper under test.
            return str_repeat('A', (int) $length);
        }

        public static function safeOutput($s)
        {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        }
    }
}

require_once dirname(__FILE__).'/../prestacleaner.php';

$tests = 0;
$failures = 0;

/**
 * @param bool   $condition
 * @param string $label
 */
function ok($condition, $label)
{
    global $tests, $failures;
    ++$tests;
    if (!$condition) {
        ++$failures;
        echo 'FAIL: '.$label.PHP_EOL;
    }
}

$token = PrestaCleaner::generateCronToken();
ok(preg_match('/^[a-f0-9]{32}$/', $token) === 1, 'generateCronToken returns 32 lowercase hex chars');
ok(PrestaCleaner::isWeakCronToken($token) === false, 'fresh CSPRNG token is not weak');

ok(PrestaCleaner::isWeakCronToken('') === true, 'empty token is weak');
ok(PrestaCleaner::isWeakCronToken(str_repeat('A', 32)) === true, 'passwdGen-style mixed case is weak');
ok(PrestaCleaner::isWeakCronToken(bin2hex(random_bytes(12))) === true, '24-hex legacy shape is weak');
ok(PrestaCleaner::isWeakCronToken('not-a-token') === true, 'arbitrary string is weak');

Configuration::$store[PrestaCleaner::CONF_CRON_TOKEN] = str_repeat('B', 32);
ok(PrestaCleaner::ensureStrongCronToken() === true, 'ensureStrongCronToken rotates a weak secret');
$rotated = (string) Configuration::get(PrestaCleaner::CONF_CRON_TOKEN);
ok(preg_match('/^[a-f0-9]{32}$/', $rotated) === 1, 'rotated token is 32 hex');
ok(PrestaCleaner::ensureStrongCronToken() === false, 'second ensure does not rotate again');

$cronSrc = (string) file_get_contents(dirname(__FILE__).'/../controllers/front/cron.php');
ok(strpos($cronSrc, '$e->getMessage()') !== false, 'exception still logged server-side');
ok(strpos($cronSrc, "'error' => \$e->getMessage()") === false, 'exception text is not returned to the caller');
ok(strpos($cronSrc, 'Scheduled cleanup failed.') !== false, 'caller gets a generic error string');

$adsSrc = (string) file_get_contents(dirname(__FILE__).'/../classes/MegVentureAdsWidget.php');
ok(strpos($adsSrc, 'CURLOPT_FOLLOWLOCATION => false') !== false, 'ads widget disables redirect following');
ok(strpos($adsSrc, 'function safeUrl') !== false, 'ads widget has scheme allowlist helper');
ok(strpos($adsSrc, 'megventure.com') !== false, 'ads widget pins megventure.com host');

echo ($failures === 0 ? 'OK' : 'FAILURES').': '.($tests - $failures).'/'.$tests." assertions passed\n";
exit($failures === 0 ? 0 : 1);
