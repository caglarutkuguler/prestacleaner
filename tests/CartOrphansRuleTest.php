<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Guards the 3.6.0 "Orphaned cart data" rule.
 *
 * Runs without PrestaShop: the module's own SQL is executed against an
 * in-memory SQLite copy of the four tables it touches, through a Db stub
 * that only translates SHOW COLUMNS and START TRANSACTION. So the whole
 * file is `php tests/CartOrphansRuleTest.php` (needs pdo_sqlite).
 *
 * What it is here to catch: a cart with an order losing its customization,
 * a plain (uncustomized) line of the same product being deleted along with
 * the customized one, the is_deleted soft-delete being ignored or required
 * on a core that lacks the column, a second run finding anything, a failed
 * step leaving half of the deletes in place, and the log entry missing.
 */
define('_PS_VERSION_', '8.1.0');
define('_PS_MODULE_DIR_', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR);
define('_DB_PREFIX_', 'ps_');
define('_PS_ADMIN_DIR_', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prestacleaner_test_' . getmypid());

function pSQL($s, $html = false) { return addslashes((string) $s); }
function bqSQL($s) { return str_replace('`', '', (string) $s); }

class Configuration
{
    public static $store = [];
    public static function get($k, $l = null, $s = null, $sh = null, $default = false)
    {
        return array_key_exists($k, self::$store) ? self::$store[$k] : false;
    }
    public static function updateValue($k, $v) { self::$store[$k] = (string) $v; return true; }
    public static function updateGlobalValue($k, $v) { return self::updateValue($k, $v); }
    public static function deleteByName($k) { unset(self::$store[$k]); return true; }
}

class PrestaShopLogger
{
    public static $logs = [];
    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null)
    {
        self::$logs[] = compact('message', 'severity', 'objectType', 'allowDuplicate');
        return true;
    }
}

class Db
{
    public static $pdo;
    private static $instance;
    private $affected = 0;
    private $error = '';

    public static function getInstance() { return self::$instance ?: self::$instance = new Db(); }

    public function executeS($sql)
    {
        if (preg_match('/^\s*SHOW COLUMNS FROM `([^`]+)` LIKE \'([^\']+)\'/', $sql, $m)) {
            return array_values(array_filter(self::$pdo->query('PRAGMA table_info(' . $m[1] . ')')->fetchAll(PDO::FETCH_ASSOC), function ($c) use ($m) {
                return $c['name'] === $m[2];
            }));
        }
        try {
            return self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function execute($sql)
    {
        $sql = trim($sql) === 'START TRANSACTION' ? 'BEGIN' : $sql;
        try {
            $this->affected = self::$pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function getValue($sql)
    {
        $rows = $this->executeS($sql);
        return $rows ? current($rows[0]) : false;
    }

    public function Affected_rows() { return $this->affected; }
    public function getMsgError() { return $this->error; }
}

class Tools { public static function safeOutput($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); } }
class Shop { const CONTEXT_ALL = 1; }

abstract class Module
{
    public $name, $tab, $version, $author, $need_instance, $multishop_context, $bootstrap,
           $displayName, $description, $confirmUninstall, $ps_versions_compliancy, $context, $_path, $active;
    public function __construct() {}
    public function trans($id, $params = [], $domain = null) { return $id; }
}

require dirname(__DIR__) . '/prestacleaner.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    if ($cond) { echo "  ok   $label\n"; } else { echo "  FAIL $label\n"; $fail++; }
}

/**
 * Fixture, same in both schemas:
 *  product 10 - has a live field;  product 20 - no field at all;
 *  product 30 - only a field flagged is_deleted = 1.
 *  cart 1: live customization 100 (p10)            -> untouched
 *  cart 2: orphan 200 (p20) + plain line of p20    -> 200 goes, plain line stays
 *          customization 400 (p20) with in_cart 0  -> untouched
 *  cart 3: orphan 300 (p20) but has order #900     -> skipped
 *  cart 4: 500 (p30)                               -> orphan only if is_deleted exists
 *  cart 0: two phantom lines                       -> both go
 */
function freshDb($withIsDeleted)
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE ps_customization (id_customization INTEGER PRIMARY KEY, id_product_attribute INT DEFAULT 0, id_address_delivery INT DEFAULT 0, id_cart INT NOT NULL, id_product INT NOT NULL, quantity INT DEFAULT 1, in_cart INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE ps_customized_data (id_customization INT, type INT, `index` INT, value TEXT)');
    $pdo->exec('CREATE TABLE ps_customization_field (id_customization_field INTEGER PRIMARY KEY, id_product INT, type INT, required INT' . ($withIsDeleted ? ', is_deleted INT DEFAULT 0' : '') . ')');
    $pdo->exec('CREATE TABLE ps_cart_product (id_cart INT NOT NULL, id_product INT, id_address_delivery INT DEFAULT 0, id_shop INT DEFAULT 1, id_product_attribute INT DEFAULT 0, id_customization INT DEFAULT 0, quantity INT, date_add TEXT)');
    $pdo->exec('CREATE TABLE ps_orders (id_order INTEGER PRIMARY KEY, id_cart INT)');

    $pdo->exec('INSERT INTO ps_customization_field (id_customization_field, id_product, type, required) VALUES (1, 10, 1, 0), (2, 30, 1, 0)');
    if ($withIsDeleted) {
        $pdo->exec('UPDATE ps_customization_field SET is_deleted = 1 WHERE id_product = 30');
    }
    $pdo->exec('INSERT INTO ps_customization (id_customization, id_cart, id_product, in_cart) VALUES (100, 1, 10, 1), (200, 2, 20, 1), (300, 3, 20, 1), (400, 2, 20, 0), (500, 4, 30, 1)');
    $pdo->exec("INSERT INTO ps_customized_data (id_customization, type, `index`, value) VALUES (100, 1, 1, 'a'), (200, 1, 9, 'b'), (300, 1, 9, 'c'), (500, 1, 2, 'd')");
    $pdo->exec("INSERT INTO ps_cart_product (id_cart, id_product, id_product_attribute, id_customization, quantity, date_add) VALUES
        (1, 10, 0, 100, 1, '2026-10-01 10:00:00'),
        (2, 20, 0, 200, 1, '2026-10-02 10:00:00'),
        (2, 20, 0, 0, 3, '2026-10-02 10:05:00'),
        (3, 20, 0, 300, 1, '2026-10-03 10:00:00'),
        (4, 30, 0, 500, 1, '2026-10-04 10:00:00'),
        (0, 10, 0, 0, 2, '2026-10-05 10:00:00'),
        (0, 20, 5, 0, 1, '2026-10-06 10:00:00')");
    $pdo->exec('INSERT INTO ps_orders (id_order, id_cart) VALUES (900, 3)');
    Db::$pdo = $pdo;

    return $pdo;
}

function ids(array $rows) { return array_map('intval', array_column($rows, 'id_customization')); }
function scalar(PDO $pdo, $sql) { return (int) $pdo->query($sql)->fetchColumn(); }

echo "1) Tespit - is_deleted kolonu olan cekirdek\n";
$pdo = freshDb(true);
$found = PrestaCleaner::findCartOrphans();
ok(ids($found['customizations']) === [200, 500], 'yetim ozellestirmeler 200 ve 500 (tumu silinmis-isaretli alan da yetim)');
ok(ids($found['skipped']) === [300], 'siparisli sepet 3 atlandi');
ok($found['skipped'][0]['id_orders'] === [900], 'atlananin siparisi #900 olarak raporlaniyor');
ok($found['phantom_count'] === 2 && count($found['phantom']) === 2, 'sepet id 0 satirlari: 2');
ok($found['customizations'][0]['date_add'] === '2026-10-02 10:00:00', 'onizleme tarihi sepet satirindan geliyor');
ok(!in_array(400, ids($found['customizations']), true), 'in_cart = 0 olan 400 secilmiyor');
ok(!in_array(100, ids($found['customizations']), true), 'canli alanli 100 secilmiyor');

echo "\n2) Uygula (yedekli)\n";
$result = PrestaCleaner::runCartOrphanCleanup(true, 'manual');
ok($result['error'] === null, 'hata yok');
ok($result['removed_customizations'] === 2, '2 ozellestirme silindi');
ok($result['removed_phantom'] === 2, '2 hayalet satir silindi');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_customization WHERE id_customization IN (200, 500)') === 0, 'customization 200/500 yok');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_customized_data WHERE id_customization IN (200, 500)') === 0, 'customized_data 200/500 yok');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_cart_product WHERE id_customization IN (200, 500)') === 0, 'ozellestirmeli sepet satirlari yok');
ok(scalar($pdo, 'SELECT quantity FROM ps_cart_product WHERE id_cart = 2 AND id_product = 20 AND id_customization = 0') === 3, 'ayni urunun duz satiri sepet 2de duruyor');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_cart_product WHERE id_cart = 0') === 0, 'sepet id 0 satiri kalmadi');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_customization WHERE id_customization IN (100, 300, 400)') === 3, '100, 300 (siparisli), 400 dokunulmadi');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_cart_product WHERE id_customization = 300') === 1, 'siparisli sepetin satiri duruyor');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_customized_data WHERE id_customization = 300') === 1, 'siparisli sepetin verisi duruyor');

$backupPath = PrestaCleaner::getBackupDir() . $result['backup_file'];
$dump = $result['backup_file'] ? implode('', gzfile($backupPath)) : '';
ok($result['backup_file'] && strpos($result['backup_file'], 'prestacleaner_') === 0, 'yedek dosyasi olustu: ' . $result['backup_file']);
foreach (['ps_customized_data', 'ps_customization', 'ps_cart_product'] as $table) {
    ok(strpos($dump, 'INSERT INTO `' . $table . '`') !== false, "yedekte $table satirlari var");
}

echo "\n3) Gunluk\n";
$log = end(PrestaShopLogger::$logs);
ok(count(PrestaShopLogger::$logs) === 1, 'tek kayit yazildi');
ok(strpos($log['message'], 'manual run') !== false, 'tetikleyici yaziyor');
ok(strpos($log['message'], '2 orphaned customization(s) removed') !== false, 'A sayisi yaziyor');
ok(strpos($log['message'], '2 phantom cart line(s) with id_cart 0 removed') !== false, 'B sayisi yaziyor');
ok(strpos($log['message'], 'cart #3 (order #900)') !== false, 'atlanan sepet yaziyor');
ok(strpos($log['message'], $result['backup_file']) !== false, 'yedek adi yaziyor');
ok($log['severity'] === 1 && $log['allowDuplicate'] === true && $log['objectType'] === 'PrestaCleaner', 'bilgi seviyesi, silen kosu her seferinde yazilir');
ok(!preg_match('/[<>{}]/', $log['message']), 'mesaj isMessage karakterlerinden temiz');

echo "\n4) Tekrar calistirma - idempotent\n";
$again = PrestaCleaner::findCartOrphans();
ok(count($again['customizations']) === 0 && $again['phantom_count'] === 0, 'ikinci tarama: iki desen icin 0 yetim satir');
$second = PrestaCleaner::runCartOrphanCleanup(false, 'scheduled');
ok($second['removed_customizations'] === 0 && $second['removed_phantom'] === 0 && !$second['logs'], 'ikinci kosu hicbir sey silmiyor');
$log = end(PrestaShopLogger::$logs);
ok(count(PrestaShopLogger::$logs) === 2 && $log['allowDuplicate'] === false, 'sadece-atlanan kosu tekrar-yazilmaz bayragiyla kaydedildi');

echo "\n5) is_deleted kolonu olmayan cekirdek (1.7.2)\n";
freshDb(false);
$found = PrestaCleaner::findCartOrphans();
ok(ids($found['customizations']) === [200], 'sadece 200: alani olan 30 urunu yetim sayilmiyor');

echo "\n6) Bir adim basarisiz olursa hepsi geri aliniyor\n";
$pdo = freshDb(true);
$pdo->exec("CREATE TRIGGER boom BEFORE DELETE ON ps_cart_product BEGIN SELECT RAISE(ABORT, 'boom'); END");
PrestaShopLogger::$logs = [];
$failed = PrestaCleaner::runCartOrphanCleanup(false, 'manual');
ok($failed['error'] !== null && strpos($failed['error'], 'boom') !== false, 'hata raporlandi');
ok($failed['removed_customizations'] === 0 && !$failed['logs'], 'basarisiz kosu silme saymiyor');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_customization') === 5, 'customization silmeleri geri alindi');
ok(scalar($pdo, 'SELECT COUNT(*) FROM ps_customized_data') === 4, 'customized_data silmeleri geri alindi');
$log = end(PrestaShopLogger::$logs);
ok($log && $log['severity'] === 3 && strpos($log['message'], 'rolled back') !== false, 'hata seviyesinde kaydedildi');

echo "\n7) Zamanlayici ve ayarlar\n";
$src = (string) file_get_contents(dirname(__DIR__) . '/prestacleaner.php');
ok(preg_match('/Configuration::get\(self::CONF_AUTO_CART_ORPHANS\)\s+\? self::runCartOrphanCleanup\(/', $src) === 1, 'zamanlanmis kosu bayraga bagli');
ok(preg_match("/CONF_AUTO_CART_ORPHANS, 0\)/", $src) === 1, 'kurulumda zamanlama kapali');
ok(in_array('cart_orphans', PrestaCleaner::AUTO_RUN_ACTIONS, true), 'AUTO_RUN_ACTIONS listesinde');

array_map('unlink', glob(_PS_ADMIN_DIR_ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '{*,.htaccess}', GLOB_BRACE) ?: []);
@rmdir(_PS_ADMIN_DIR_ . DIRECTORY_SEPARATOR . 'backups');
@rmdir(_PS_ADMIN_DIR_);

echo "\n" . ($fail === 0 ? "OK - hepsi gecti\n" : "$fail test BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
