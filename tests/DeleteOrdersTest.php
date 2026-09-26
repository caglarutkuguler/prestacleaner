<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Guards the 3.1.0 "delete selected orders" cascade.
 *
 * Runs without PrestaShop: Db records every statement instead of running it,
 * and backups go to a throwaway temp folder, so the whole file is
 * `php tests/DeleteOrdersTest.php` and nothing else.
 *
 * What it is here to catch: only positive integer ids ever reach the SQL;
 * every child table is emptied before its parent and `orders` itself last;
 * order_payment (linked by reference, not id) is looked up before the orders
 * go and cleaned up only after, and only where no surviving order still uses
 * that reference; the preview counts exactly the rows the apply deletes; the
 * backup reads exactly those rows before each delete; and nothing outside the
 * order tables (support threads, carts, customers) is ever touched.
 */
if (!defined('_PS_VERSION_')) {
    // Only the CLI harness may run without the shop; a web hit exits here.
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('_PS_VERSION_', '8.1.0');
}
define('_PS_MODULE_DIR_', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR);
define('_DB_PREFIX_', 'ps_');
define('_PS_ADMIN_DIR_', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prestacleaner-test-' . uniqid());

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

class Tools
{
    public static function getValue($k, $d = false) { return $d; }
    public static function isSubmit($k) { return false; }
    public static function safeOutput($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
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
}

/** Records every statement; answers just enough for the cascade to run. */
class Db
{
    private static $instance;
    public $log = [];
    public $references = [['reference' => 'KHWLILZLL'], ['reference' => "O'BRIEN"]];
    public $count = 2;

    public static function getInstance() { return self::$instance; }
    public static function reset()
    {
        self::$instance = new self();

        return self::$instance;
    }

    public function execute($sql) { $this->log[] = self::flat($sql); return true; }
    public function getValue($sql) { $this->log[] = self::flat($sql); return $this->count; }
    public function Affected_rows() { return 1; }

    public function executeS($sql)
    {
        $sql = self::flat($sql);
        $this->log[] = $sql;
        if (strpos($sql, 'SELECT DISTINCT reference') === 0) {
            return $this->references;
        }
        if (strpos($sql, 'SELECT * FROM') === 0 && strpos($sql, 'OFFSET 0') !== false) {
            // One row per backed-up table: a quote to escape and a NULL to keep.
            return [['id' => '1', 'note' => "it's", 'gone' => null]];
        }

        return [];
    }

    private static function flat($sql) { return trim(preg_replace('/\s+/', ' ', $sql)); }
}

require dirname(__DIR__) . '/prestacleaner.php';

$fail = 0;
function ok($cond, $label) {
    global $fail;
    if ($cond) { echo "  ok   $label\n"; } else { echo "  FAIL $label\n"; $fail++; }
}

/** @return array<int,array{0:int,1:string,2:string}> [log index, table, WHERE] of every statement matching $pattern */
function statements(array $log, $pattern)
{
    $out = [];
    foreach ($log as $i => $sql) {
        if (preg_match($pattern, $sql, $m)) {
            $out[] = [$i, $m[1], $m[2]];
        }
    }

    return $out;
}
function deletes(array $log) { return statements($log, '/^DELETE FROM `ps_(\w+)` WHERE (.*)$/'); }
function firstIndex(array $stmts, $table)
{
    foreach ($stmts as $s) {
        if ($s[1] === $table) {
            return $s[0];
        }
    }

    return -1;
}
function rmTree($dir)
{
    foreach (glob($dir . DIRECTORY_SEPARATOR . '{,.}[!.]*', GLOB_BRACE) ?: [] as $path) {
        is_dir($path) ? rmTree($path) : unlink($path);
    }
    @rmdir($dir);
}

$cascade = [
    'order_detail_tax', 'order_invoice_tax', 'order_return_detail', 'order_slip_detail',
    'order_detail', 'order_history', 'order_carrier', 'order_cart_rule', 'order_invoice',
    'order_invoice_payment', 'order_return', 'order_slip', 'message', 'orders',
];

echo "1) Only positive integer ids survive sanitizing\n";
$ids = PrestaCleaner::sanitizeOrderIds(['12', 12, '7', '0', '-3', 'abc', '5 OR 1=1', null, 7]);
ok($ids === [12, 7, 5], 'deduped, zero/negative/garbage dropped, reindexed (' . implode(',', $ids) . ')');
ok(preg_match('/^[0-9,]+$/', implode(',', $ids)) === 1, 'the imploded id list is digits and commas only');
ok(PrestaCleaner::sanitizeOrderIds('9') === [9], 'a single scalar id is accepted');

echo "\n2) Nothing valid selected: no SQL at all\n";
$db = Db::reset();
$result = PrestaCleaner::runDeleteOrders(['0', 'abc', '-1'], true);
ok($result === ['logs' => [], 'backup_file' => false, 'deleted' => 0], 'apply returns an empty result');
ok(PrestaCleaner::previewDeleteOrders([]) === [], 'preview returns an empty result');
ok($db->log === [], 'not a single statement was sent');

echo "\n3) Apply: child tables first, orders last, payments after\n";
$db = Db::reset();
$result = PrestaCleaner::runDeleteOrders(['12', '7', '12'], false);
$del = deletes($db->log);
$deletedTables = array_column($del, 1);
ok($result['deleted'] === 2, 'reports 2 orders deleted (duplicate id counted once)');
ok(count($del) === count($cascade) + 1, 'one DELETE per cascade table plus order_payment (' . count($del) . ')');
ok(array_diff($cascade, $deletedTables) === [], 'every cascade table was cleaned');
ok(count(array_unique($deletedTables)) === count($deletedTables), 'no table was deleted from twice');
foreach ([
    'order_detail_tax' => 'order_detail',
    'order_invoice_tax' => 'order_invoice',
    'order_return_detail' => 'order_return',
    'order_slip_detail' => 'order_slip',
] as $child => $parent) {
    ok(firstIndex($del, $child) < firstIndex($del, $parent), "$child is emptied before $parent (its subquery still finds the ids)");
}
$ordersAt = firstIndex($del, 'orders');
$lastCascadeAt = max(array_map(function ($t) use ($del) { return firstIndex($del, $t); }, array_diff($cascade, ['orders'])));
ok($ordersAt > $lastCascadeAt, 'orders itself goes after every table that points at it');
foreach ($del as $d) {
    if ($d[1] !== 'order_payment' && strpos($d[2], 'IN (12,7)') === false) {
        ok(false, "{$d[1]} is scoped to the selected ids");
    }
}
ok(count(array_filter($del, function ($d) { return strpos($d[2], 'IN (12,7)') !== false; })) === count($cascade),
   'every cascade DELETE is scoped to IN (12,7)');

$refLookupAt = -1;
foreach ($db->log as $i => $sql) {
    if (strpos($sql, 'SELECT DISTINCT reference') === 0) {
        $refLookupAt = $i;
    }
}
ok($refLookupAt !== -1 && $refLookupAt < $ordersAt, 'payment references are read BEFORE the orders are gone');
$paymentAt = firstIndex($del, 'order_payment');
ok($paymentAt > $ordersAt, 'order_payment is cleaned only AFTER the orders delete');
$paymentWhere = $del[array_search('order_payment', $deletedTables)][2];
ok(strpos($paymentWhere, "order_reference IN ('KHWLILZLL','O\\'BRIEN')") !== false, 'payment WHERE lists the references, quote escaped');
ok(strpos($paymentWhere, 'order_reference NOT IN (SELECT reference FROM `ps_orders`)') !== false,
   'a reference still used by a surviving order keeps its payment');
ok(in_array('UPDATE `ps_employee` SET id_last_order = 0 WHERE id_last_order IN (12,7)', $db->log, true),
   'employee "last order" shortcuts pointing at a deleted order are reset');
$all = implode("\n", $db->log);
ok(strpos($all, 'customer_thread') === false && strpos($all, 'customer_message') === false,
   'support threads are never touched');
ok(!array_intersect($deletedTables, ['cart', 'customer', 'address', 'cart_product']), 'carts, customers and addresses are never deleted');
ok(stripos($all, 'TRUNCATE') === false && stripos($all, 'DROP') === false, 'no TRUNCATE or DROP');
ok(strpos($all, 'SELECT * FROM') === false, 'backup off: no rows read for a dump');
ok(count($result['logs']) === count($cascade) && end($result['logs'])['table'] === 'orders',
   'one log line per cascade table, orders last');

echo "\n4) Preview counts exactly what apply deletes, and changes nothing\n";
$applyPairs = array_map(function ($d) { return $d[1] . ' | ' . $d[2]; },
    array_values(array_filter($del, function ($d) { return $d[1] !== 'order_payment'; })));
$db = Db::reset();
$logs = PrestaCleaner::previewDeleteOrders(['12', '7']);
$counts = statements($db->log, '/^SELECT COUNT\(\*\) FROM `ps_(\w+)` WHERE (.*)$/');
ok(count($counts) === count($db->log), 'preview sent nothing but COUNT(*) queries');
ok(array_map(function ($c) { return $c[1] . ' | ' . $c[2]; }, $counts) === $applyPairs,
   'same tables, same order, byte-identical WHERE as the apply DELETEs');
ok(count($logs) === count($cascade) && $logs[0]['count'] === 2, 'each non-empty table is reported with its count');
$db = Db::reset();
$db->count = 0;
ok(PrestaCleaner::previewDeleteOrders(['12']) === [], 'tables with nothing to delete are left out of the report');

echo "\n5) Backup on: each DELETE is preceded by a dump of exactly its rows\n";
$db = Db::reset();
$result = PrestaCleaner::runDeleteOrders(['12', '7'], true);
$file = (string) $result['backup_file'];
ok(preg_match('/^prestacleaner_\d{8}-\d{6}-delete-orders\.sql\.gz$/', $file) === 1, "backup named for the action ($file)");
$del = deletes($db->log);
$reads = statements($db->log, '/^SELECT \* FROM `ps_(\w+)` WHERE (.*) LIMIT 500 OFFSET 0$/');
foreach ($del as $d) {
    if ($d[1] === 'order_payment') {
        continue;
    }
    $readAt = -1;
    foreach ($reads as $r) {
        if ($r[1] === $d[1] && $r[2] === $d[2]) {
            $readAt = $r[0];
        }
    }
    if (!($readAt !== -1 && $readAt < $d[0])) {
        ok(false, "{$d[1]} was dumped with the same WHERE before being deleted");
    }
}
ok(count($reads) === count($cascade) + 1, 'one dump per cascade table plus order_payment');
$paymentRead = firstIndex($reads, 'order_payment');
ok($paymentRead !== -1 && $paymentRead < firstIndex($del, 'order_payment'), 'payments are dumped before being deleted');

$path = _PS_ADMIN_DIR_ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $file;
$dump = is_file($path) ? implode('', gzfile($path)) : '';
ok($dump !== '', 'the dump file exists and gunzips');
ok(strpos($dump, '-- Label: delete-orders') !== false, 'dump header carries the label');
ok(strpos($dump, "INSERT INTO `ps_orders` (`id`,`note`,`gone`) VALUES ('1','it\\'s',NULL);") !== false,
   'orders rows are restorable: quote escaped, NULL kept as NULL');
ok(strpos($dump, 'INSERT INTO `ps_order_payment`') !== false, 'payment rows are in the dump');
ok(strpos((string) @file_get_contents(dirname($path) . DIRECTORY_SEPARATOR . '.htaccess'), 'Deny from all') !== false,
   'backups folder is shielded from the web');

rmTree(_PS_ADMIN_DIR_);

echo "\n" . ($fail === 0 ? "OK - all passed\n" : "$fail test(s) FAILED\n");
exit($fail === 0 ? 0 : 1);
