<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Guards the 3.5.0 onefee cart-fee ledger rule.
 *
 * Runs without PrestaShop: Configuration, Tools and Module are stubbed, so
 * the whole file is `php tests/OnefeeLedgerRuleTest.php` and nothing else.
 *
 * What it is here to catch: the WHERE clause the Clean & optimize rule feeds
 * to applyOrCount() must select exactly onefee's well-formed cart-fee ledger
 * rows (both name shapes), extract the cart id from the right character
 * offset in each shape, and be guarded so no other configuration row can
 * ever match. The offsets are the part a human eye gets wrong, so they are
 * pinned against strlen() here and the extraction is mirrored in PHP over
 * fixture names.
 */
define('_PS_VERSION_', '8.1.0');
define('_PS_MODULE_DIR_', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR);
define('_DB_PREFIX_', 'ps_');

function pSQL($s) { return addslashes((string) $s); }
function bqSQL($s) { return str_replace('`', '', (string) $s); }

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
    public static function updateGlobalValue($k, $v) { return self::updateValue($k, $v); }
    public static function deleteByName($k) { unset(self::$store[$k]); return true; }
}

class Tools
{
    public static $values = [];
    public static function getValue($k, $d = false)
    {
        return array_key_exists($k, self::$values) ? self::$values[$k] : $d;
    }
    public static function isSubmit($k) { return array_key_exists($k, self::$values); }
    public static function strtoupper($s) { return strtoupper($s); }
    public static function safeOutput($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    public static function passwdGen($n = 8) { return str_repeat('a', $n); }
    public static function redirect($url) {}
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

$fail = 0;
function ok($cond, $label) {
    global $fail;
    if ($cond) { echo "  ok   $label\n"; } else { echo "  FAIL $label\n"; $fail++; }
}

$where = PrestaCleaner::orphanOnefeeLedgerWhere();
$regexp = '/^onefee_attribute_id_(settings_)?[0-9]+_[0-9]+_[0-9]+_[0-9]+$/';

echo "1) WHERE yapisi\n";
ok(strpos($where, "REGEXP '^onefee_attribute_id_(settings_)?[0-9]+_[0-9]+_[0-9]+_[0-9]+\$'") !== false,
   'REGEXP bekci tam bicimli defter adlarina demirli');
ok(strpos($where, "LIKE 'onefee\\_attribute\\_id\\_settings\\_%'") !== false,
   'settings dali kacisli LIKE ile ayriliyor');
ok(strpos($where, ', ' . (strlen('onefee_attribute_id_settings_') + 1) . ',') !== false,
   'settings ofseti strlen+1 = 30');
ok(strpos($where, ', ' . (strlen('onefee_attribute_id_') + 1) . ')') !== false,
   'duz ofset strlen+1 = 21');
ok(strpos($where, 'NOT IN (SELECT `id_cart` FROM `ps_cart`)') !== false,
   'yasayan sepetler NOT IN ile korunuyor');
ok(strpos($where, 'CAST(SUBSTRING_INDEX(SUBSTRING(') !== false,
   'sepet id adin icinden cikartiliyor');

echo "\n2) Ofsetler PHP aynasinda dogru id'yi cikariyor\n";
// SUBSTRING(name, N) MySQL'de 1 tabanli: PHP substr(name, N-1) esdegeri.
function mirror_extract($name)
{
    $settings = 'onefee_attribute_id_settings_';
    $plain = 'onefee_attribute_id_';
    $offset = (strpos($name, $settings) === 0) ? strlen($settings) + 1 : strlen($plain) + 1;
    $rest = substr($name, $offset - 1);
    $token = strstr($rest, '_', true);

    return (int) ($token === false ? $rest : $token);
}
ok(mirror_extract('onefee_attribute_id_10_12_0_0') === 10, 'duz ad -> sepet 10');
ok(mirror_extract('onefee_attribute_id_settings_10_12_0_0') === 10, 'settings adi -> sepet 10');
ok(mirror_extract('onefee_attribute_id_40587_10_24_3') === 40587, 'cok haneli sepet id dogru cikiyor');
ok(mirror_extract('onefee_attribute_id_settings_40587_10_24_3') === 40587, 'settings cok haneli de dogru');
ok(mirror_extract('onefee_attribute_id_0_5_0_0') === 0, 'hayalet sepet 0 -> 0');

echo "\n3) REGEXP bekcisi yabanci ve bozuk adlari disarida tutuyor\n";
foreach ([
    'onefee_attribute_id_10_12_0_0' => true,
    'onefee_attribute_id_settings_10_12_0_0' => true,
    'onefee_attribute_id_broken' => false,
    'onefee_attribute_id_10_12_0' => false,
    'onefee_attribute_id_' => false,
    'onefee_attribute_id_settings_' => false,
    'PS_SHOP_NAME' => false,
    'ONEFEE_ORPHAN_SWEEP_AT' => false,
    'onefee_order_fee' => false,
    'onefee_attribute_id_10_12_0_0_extra' => false,
] as $name => $expected) {
    $matches = (bool) preg_match($regexp, $name);
    ok($matches === $expected, "'$name' -> " . ($expected ? 'SECILIR' : 'secilmez'));
}

echo "\n4) Kural Clean & optimize icinde ve sepet temizliginden SONRA\n";
$src = (string) file_get_contents(dirname(__DIR__) . '/prestacleaner.php');
$cartPos = strpos($src, "'abandoned cart(s) older than 1 month with no order'");
$rulePos = strpos($src, 'orphanOnefeeLedgerWhere()');
ok($cartPos !== false && $rulePos !== false && $rulePos > $cartPos,
   'defter kurali, terk edilmis sepet silmesinin arkasinda calisiyor');
ok(strpos($src, "'orphaned onefee cart-fee configuration row(s) whose cart no longer exists'") !== false,
   'kural applyOrCount uzerinden isliyor (onizleme + yedek dahil)');

echo "\n" . ($fail === 0 ? "OK - hepsi gecti\n" : "$fail test BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
