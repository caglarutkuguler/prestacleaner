<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Safely cleans up a PrestaShop database and its related files: fixes orphan
 * rows left behind by deleted products/orders/modules, clears abandoned carts
 * and dead cart rules, and (optionally) wipes the whole catalog or the whole
 * order/customer history to reset a store to a fresh state.
 *
 * Every action can create a scoped backup of only the rows it is about to
 * touch before it runs, every destructive action requires typing a literal
 * confirmation phrase (not just a checkbox), and every action has a
 * "Preview" dry-run that reports what would happen without changing anything.
 */
class PrestaCleaner extends Module
{
    /** @var string Prefix used for every backup file this module writes, so cleanup/listing never touches anyone else's backups. */
    const BACKUP_PREFIX = 'prestacleaner_';

    const CONF_CRON_TOKEN = 'PRESTACLEANER_CRON_TOKEN';
    const CONF_AUTO_ENABLED = 'PRESTACLEANER_AUTO_ENABLED';
    const CONF_AUTO_INTERVAL_DAYS = 'PRESTACLEANER_AUTO_INTERVAL_DAYS';
    const CONF_AUTO_LAST_RUN = 'PRESTACLEANER_AUTO_LAST_RUN';
    const CONF_AUTO_LAST_RESULT = 'PRESTACLEANER_AUTO_LAST_RESULT';
    const CONF_BACKUP_BEFORE_FIX = 'PRESTACLEANER_BACKUP_BEFORE_FIX';
    const CONF_BACKUP_BEFORE_OPTIMIZE = 'PRESTACLEANER_BACKUP_BEFORE_OPTIMIZE';
    const CONF_BACKUP_BEFORE_TRUNCATE = 'PRESTACLEANER_BACKUP_BEFORE_TRUNCATE';
    const CONF_BACKUP_BEFORE_DELETE_ORDERS = 'PRESTACLEANER_BACKUP_BEFORE_DELETE_ORDERS';
    const CONF_BACKUP_RETENTION_DAYS = 'PRESTACLEANER_BACKUP_RETENTION_DAYS';

    /** Auto (scheduled) runs only ever perform these two, always-reversible-by-nature actions. Truncation can never be scheduled. */
    const AUTO_RUN_ACTIONS = ['fix', 'optimize'];

    /** Typed, case-insensitive confirmation phrases required (server-side) before either truncation runs. */
    const CONFIRM_PHRASE_CATALOG = 'DELETE CATALOG';
    const CONFIRM_PHRASE_SALES = 'DELETE ORDERS';

    /** @var array Dry-run results gathered during this request, keyed by action, rendered inline under the matching panel. */
    protected $previewResults = [];

    public function __construct()
    {
        $this->name = 'prestacleaner';
        $this->tab = 'administration';
        $this->version = '3.1.0';
        $this->author = 'MEG Venture';
        $this->need_instance = 0;
        $this->multishop_context = Shop::CONTEXT_ALL;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Database Cleaner - Safe Cleanup, Backup & Scheduler', [], 'Modules.Prestacleaner.Admin');
        $this->description = $this->trans('Safely remove orphan database rows, reset your catalog or orders, and keep your store lean - with automatic backups, a dry-run preview, scheduled maintenance and a one-glance health score.', [], 'Modules.Prestacleaner.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall? Your saved settings will be removed; any backup files already on disk are kept untouched.', [], 'Modules.Prestacleaner.Admin');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionAdminControllerSetMedia')
            && Configuration::updateGlobalValue(self::CONF_CRON_TOKEN, Tools::passwdGen(32))
            && Configuration::updateGlobalValue(self::CONF_AUTO_ENABLED, 0)
            && Configuration::updateGlobalValue(self::CONF_AUTO_INTERVAL_DAYS, 30)
            && Configuration::updateGlobalValue(self::CONF_AUTO_LAST_RUN, 0)
            && Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_FIX, 1)
            && Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_OPTIMIZE, 1)
            && Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_TRUNCATE, 1)
            && Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_DELETE_ORDERS, 1)
            && Configuration::updateGlobalValue(self::CONF_BACKUP_RETENTION_DAYS, 14);
    }

    public function uninstall()
    {
        foreach ([
            self::CONF_CRON_TOKEN, self::CONF_AUTO_ENABLED, self::CONF_AUTO_INTERVAL_DAYS,
            self::CONF_AUTO_LAST_RUN, self::CONF_AUTO_LAST_RESULT, self::CONF_BACKUP_BEFORE_FIX,
            self::CONF_BACKUP_BEFORE_OPTIMIZE, self::CONF_BACKUP_BEFORE_TRUNCATE,
            self::CONF_BACKUP_BEFORE_DELETE_ORDERS, self::CONF_BACKUP_RETENTION_DAYS,
        ] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Best-effort scheduler for shops without real server-cron access: once
     * per admin page load we check whether a run is due, and if so run it
     * AFTER the response has already been sent to the browser so the
     * merchant never feels the extra query time.
     */
    public function hookActionAdminControllerSetMedia()
    {
        if (!Configuration::get(self::CONF_AUTO_ENABLED)) {
            return;
        }

        $intervalDays = max(1, (int) Configuration::get(self::CONF_AUTO_INTERVAL_DAYS));
        $lastRun = (int) Configuration::get(self::CONF_AUTO_LAST_RUN);
        if ((time() - $lastRun) < ($intervalDays * 86400)) {
            return;
        }

        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            self::runScheduledCleanup();
        });
    }

    /**
     * Runs the safe (never-destructive) maintenance actions and records the
     * outcome, whether triggered by the BO-visit fallback or by the cron
     * front controller. Never runs a catalog/sales truncation.
     */
    public static function runScheduledCleanup()
    {
        $backupBeforeOptimize = (bool) Configuration::get(self::CONF_BACKUP_BEFORE_OPTIMIZE);
        $result = [
            'fix' => self::runCheckAndFix((bool) Configuration::get(self::CONF_BACKUP_BEFORE_FIX)),
            'optimize' => self::runCleanAndOptimize($backupBeforeOptimize),
            'ran_at' => date('Y-m-d H:i:s'),
        ];

        Configuration::updateGlobalValue(self::CONF_AUTO_LAST_RUN, time());
        Configuration::updateGlobalValue(self::CONF_AUTO_LAST_RESULT, json_encode([
            'fix_queries' => count($result['fix']),
            'optimize_queries' => count($result['optimize']),
            'ran_at' => $result['ran_at'],
        ]));

        return $result;
    }

    public function getCronUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'cron', ['token' => Configuration::get(self::CONF_CRON_TOKEN)], null);
    }

    /*
     * ----------------------------------------------------------------
     * Backup engine
     *
     * These are lightweight, targeted SQL snapshots meant as a temporary
     * undo net for the module's own actions - NOT a replacement for a real
     * hosting/disaster-recovery backup (files, uploads and images are never
     * included). Dumps are written to the SAME folder PrestaShop's own
     * Advanced Parameters > DB Backup page reads from, so an admin who
     * needs to restore one uses that trusted, already-audited core screen
     * instead of a second restore engine this module would have to
     * maintain itself.
     * ----------------------------------------------------------------
     */

    protected static function t($table)
    {
        return _DB_PREFIX_.$table;
    }

    public static function getBackupDir()
    {
        $dir = rtrim(_PS_ADMIN_DIR_, '/\\').DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR;

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && !file_exists($dir.'.htaccess')) {
            @file_put_contents($dir.'.htaccess', "<Files ~ \"\.(sql|gz)$\">\nOrder Allow,Deny\nDeny from all\n</Files>\n");
        }
        if (is_dir($dir) && !file_exists($dir.'index.php')) {
            @file_put_contents($dir.'index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }

        return $dir;
    }

    /**
     * @return array<int,array{file:string,label:string,size:int,date:int}>
     */
    public static function listBackups()
    {
        $dir = self::getBackupDir();
        $out = [];

        foreach (glob($dir.self::BACKUP_PREFIX.'*.sql.gz') ?: [] as $path) {
            $file = basename($path);
            $label = preg_replace('/^'.preg_quote(self::BACKUP_PREFIX, '/').'/', '', $file);
            $label = preg_replace('/\.sql\.gz$/', '', $label);
            $out[] = [
                'file' => $file,
                'label' => $label,
                'size' => (int) @filesize($path),
                'date' => (int) @filemtime($path),
            ];
        }

        usort($out, function ($a, $b) {
            return $b['date'] <=> $a['date'];
        });

        return $out;
    }

    protected static function isOwnBackupFilename($filename)
    {
        $filename = basename($filename);

        return $filename !== '' && strpos($filename, self::BACKUP_PREFIX) === 0 && substr($filename, -7) === '.sql.gz';
    }

    public static function deleteBackup($filename)
    {
        if (!self::isOwnBackupFilename($filename)) {
            return false;
        }

        $path = self::getBackupDir().basename($filename);

        return file_exists($path) && @unlink($path);
    }

    public static function purgeOldBackups($retentionDays)
    {
        $retentionDays = max(0, (int) $retentionDays);
        if ($retentionDays === 0) {
            return 0;
        }

        $cutoff = time() - ($retentionDays * 86400);
        $deleted = 0;
        foreach (self::listBackups() as $backup) {
            if ($backup['date'] < $cutoff && self::deleteBackup($backup['file'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Opens a new gzipped SQL dump file and returns its handle plus path;
     * callers stream rows into it with writeTableRowsToBackup() and must
     * close it with gzclose().
     */
    protected static function openBackup($label)
    {
        $filename = self::BACKUP_PREFIX.date('Ymd-His').'-'.preg_replace('/[^a-z0-9\-]+/i', '', $label).'.sql.gz';
        $handle = @gzopen(self::getBackupDir().$filename, 'wb9');

        if (!$handle) {
            return [false, null];
        }

        gzwrite($handle, "-- PrestaCleaner backup\n-- Label: ".$label."\n-- Created: ".date('Y-m-d H:i:s')."\n\n");

        return [$filename, $handle];
    }

    /**
     * Dumps every row of a table matching an optional WHERE fragment into an
     * already-open backup handle, paginated so large tables never have to
     * fit in memory at once.
     */
    protected static function writeTableRowsToBackup($handle, $table, $whereSql = '1')
    {
        $physicalTable = self::t($table);
        $offset = 0;
        $pageSize = 500;
        $rowsWritten = 0;

        do {
            $rows = Db::getInstance()->executeS(
                'SELECT * FROM `'.bqSQL($physicalTable).'` WHERE '.$whereSql.' LIMIT '.$pageSize.' OFFSET '.(int) $offset
            );
            if (!is_array($rows) || !count($rows)) {
                break;
            }

            $columns = array_keys($rows[0]);
            $values = [];
            foreach ($rows as $row) {
                $escaped = array_map(function ($value) {
                    return $value === null ? 'NULL' : "'".pSQL($value, true)."'";
                }, $row);
                $values[] = '('.implode(',', $escaped).')';
            }

            gzwrite(
                $handle,
                'INSERT INTO `'.bqSQL($physicalTable).'` (`'.implode('`,`', $columns).'`) VALUES '.implode(',', $values).";\n"
            );

            $rowsWritten += count($rows);
            $offset += $pageSize;
        } while (count($rows) === $pageSize);

        return $rowsWritten;
    }

    /**
     * Full-table snapshot (structure + every row) used before a
     * catalog/sales truncation, where the whole table is about to be wiped.
     */
    public static function backupFullTables(array $tables, $label)
    {
        [$filename, $handle] = self::openBackup($label);
        if (!$handle) {
            return false;
        }

        foreach ($tables as $table) {
            $physicalTable = self::t($table);
            $create = Db::getInstance()->executeS('SHOW CREATE TABLE `'.bqSQL($physicalTable).'`');
            if (!$create) {
                continue;
            }
            $createSql = current($create[0]);
            gzwrite($handle, 'DROP TABLE IF EXISTS `'.bqSQL($physicalTable)."`;\n".$createSql.";\n");
            self::writeTableRowsToBackup($handle, $table);
        }

        gzclose($handle);

        return $filename;
    }

    /*
     * ----------------------------------------------------------------
     * Referential-integrity cleanup ("Check & fix")
     *
     * Every step below runs in BOTH 'apply' and 'preview' mode through the
     * same code path (applyOrCount), so the dry-run report can never drift
     * out of sync with what actually happens when the admin clicks Apply.
     * ----------------------------------------------------------------
     */

    public static function runCheckAndFix($backup = true)
    {
        $handle = null;
        $backupFile = false;
        if ($backup) {
            [$backupFile, $handle] = self::openBackup('check-and-fix');
        }

        $logs = self::processCheckAndFix('apply', $handle);

        if ($handle) {
            gzclose($handle);
        }

        Category::regenerateEntireNtree();
        Image::clearTmpDir();
        self::clearAllCaches();

        return ['logs' => $logs, 'backup_file' => $backupFile];
    }

    public static function previewCheckAndFix()
    {
        return self::processCheckAndFix('preview', null);
    }

    protected static function processCheckAndFix($mode, $handle)
    {
        $db = Db::getInstance();
        $logs = [];

        // Duplicate configuration rows (same group/shop/name stored more than once).
        $seen = [];
        $duplicateIds = [];
        foreach ($db->executeS('SELECT id_configuration, id_shop_group, id_shop, name FROM '.self::t('configuration')) as $row) {
            $key = $row['id_shop_group'].'-|-'.$row['id_shop'].'-|-'.$row['name'];
            if (isset($seen[$key])) {
                $duplicateIds[] = (int) $row['id_configuration'];
            } else {
                $seen[$key] = true;
            }
        }
        if ($duplicateIds) {
            self::applyOrCount(
                $mode, $logs, $handle, 'configuration', 'id_configuration IN ('.implode(',', $duplicateIds).')',
                'duplicate configuration setting(s)'
            );
        }

        // configuration_lang rows with no parent (or whose parent has an empty name).
        self::applyOrCount(
            $mode, $logs, $handle, 'configuration_lang',
            '`id_configuration` NOT IN (SELECT `id_configuration` FROM `'.bqSQL(self::t('configuration')).'`)
                OR `id_configuration` IN (SELECT `id_configuration` FROM `'.bqSQL(self::t('configuration')).'` WHERE name IS NULL OR name = "")',
            'orphan configuration translation(s)'
        );

        // ~110 known parent/child relationships across core (and a few module) tables.
        foreach (self::sortByDependency(self::getCheckAndFixQueries()) as $rule) {
            [$table, $column, $refTable, $refColumn] = $rule;
            if (isset($rule[4]) && !Module::isInstalled($rule[4])) {
                continue;
            }
            self::applyOrCount(
                $mode, $logs, $handle, $table,
                '`'.bqSQL($column).'` NOT IN (SELECT `'.bqSQL($refColumn).'` FROM `'.bqSQL(self::t($refTable)).'`)',
                'row(s) pointing at a deleted `'.$refTable.'`'
            );
        }

        // Any *_lang table: rows with no parent row, and rows for a deleted language.
        foreach ($db->executeS('SHOW TABLES LIKE "'.preg_replace('/([%_])/', '\\$1', _DB_PREFIX_).'%_\\_lang"') as $row) {
            $physicalLangTable = current($row);
            $table = preg_replace('/^'._DB_PREFIX_.'/', '', str_replace('_lang', '', $physicalLangTable));
            $idColumn = 'id_'.$table;

            self::applyOrCount(
                $mode, $logs, $handle, $table.'_lang',
                '`'.bqSQL($idColumn).'` NOT IN (SELECT `'.bqSQL($idColumn).'` FROM `'.bqSQL(self::t($table)).'`)',
                'translation row(s) with no matching `'.$table.'`'
            );
            self::applyOrCount(
                $mode, $logs, $handle, $table.'_lang',
                '`id_lang` NOT IN (SELECT `id_lang` FROM `'.bqSQL(self::t('lang')).'`)',
                'translation row(s) in a deleted language'
            );
        }

        // Any *_shop table: rows with no parent row, and rows for a deleted shop.
        foreach ($db->executeS('SHOW TABLES LIKE "'.preg_replace('/([%_])/', '\\$1', _DB_PREFIX_).'%_\\_shop"') as $row) {
            $physicalShopTable = current($row);
            if ($physicalShopTable === self::t('carrier_tax_rules_group_shop')) {
                continue;
            }
            $table = preg_replace('/^'._DB_PREFIX_.'/', '', str_replace('_shop', '', $physicalShopTable));
            $idColumn = 'id_'.$table;

            self::applyOrCount(
                $mode, $logs, $handle, $table.'_shop',
                '`'.bqSQL($idColumn).'` NOT IN (SELECT `'.bqSQL($idColumn).'` FROM `'.bqSQL(self::t($table)).'`)',
                'per-shop row(s) with no matching `'.$table.'`'
            );
            self::applyOrCount(
                $mode, $logs, $handle, $table.'_shop',
                '`id_shop` NOT IN (SELECT `id_shop` FROM `'.bqSQL(self::t('shop')).'`)',
                'row(s) belonging to a deleted shop'
            );
        }

        self::applyOrCount(
            $mode, $logs, $handle, 'stock_available',
            '`id_shop` NOT IN (SELECT `id_shop` FROM `'.bqSQL(self::t('shop')).'`)
                AND `id_shop_group` NOT IN (SELECT `id_shop_group` FROM `'.bqSQL(self::t('shop_group')).'`)',
            'stock row(s) belonging to a deleted shop'
        );

        return $logs;
    }

    /*
     * ----------------------------------------------------------------
     * Housekeeping ("Clean & optimize"): abandoned carts, dead cart rules,
     * and re-numbering admin-menu positions left with gaps or duplicates.
     * ----------------------------------------------------------------
     */

    public static function runCleanAndOptimize($backup = true)
    {
        $handle = null;
        $backupFile = false;
        if ($backup) {
            [$backupFile, $handle] = self::openBackup('clean-and-optimize');
        }

        $logs = self::processCleanAndOptimize('apply', $handle);

        if ($handle) {
            gzclose($handle);
        }

        self::clearAllCaches();

        return ['logs' => $logs, 'backup_file' => $backupFile];
    }

    public static function previewCleanAndOptimize()
    {
        return self::processCleanAndOptimize('preview', null);
    }

    protected static function processCleanAndOptimize($mode, $handle)
    {
        $logs = [];

        self::applyOrCount(
            $mode, $logs, $handle, 'cart',
            '`id_cart` NOT IN (SELECT `id_cart` FROM `'.bqSQL(self::t('orders')).'`)
                AND `date_add` < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"',
            'abandoned cart(s) older than 1 month with no order'
        );

        self::applyOrCount(
            $mode, $logs, $handle, 'cart_rule',
            '(`active` = 0 OR `quantity` = 0 OR `date_to` < "'.pSQL(date('Y-m-d')).'")
                AND `date_add` < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"',
            'inactive, exhausted or expired cart rule(s)'
        );

        if ($mode === 'apply') {
            $reordered = 0;
            $db = Db::getInstance();
            foreach ($db->executeS('SELECT DISTINCT id_parent FROM '.self::t('tab')) as $parent) {
                $children = $db->executeS(
                    'SELECT id_tab FROM '.self::t('tab').' WHERE id_parent = '.(int) $parent['id_parent'].'
                    ORDER BY IF(class_name IN ("AdminHome", "AdminDashboard"), 1, 2), position ASC'
                );
                $position = 1;
                foreach ($children as $child) {
                    $db->update('tab', ['position' => $position++], 'id_tab = '.(int) $child['id_tab'].' AND id_parent = '.(int) $parent['id_parent']);
                    $reordered++;
                }
            }
            if ($reordered) {
                $logs[] = ['table' => 'tab', 'description' => 'admin menu item(s) re-numbered into a gap-free order', 'count' => $reordered];
            }
        }

        return $logs;
    }

    /**
     * Runs a DELETE (optionally backing up the matching rows first) in
     * 'apply' mode, or a COUNT(*) of the same condition in 'preview' mode -
     * the two modes can never disagree because they share this one method.
     */
    protected static function applyOrCount($mode, array &$logs, $handle, $table, $whereSql, $description)
    {
        if ($mode === 'preview') {
            $count = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `'.bqSQL(self::t($table)).'` WHERE '.$whereSql
            );
            if ($count) {
                $logs[] = ['table' => $table, 'description' => $description, 'count' => $count];
            }

            return;
        }

        if ($handle) {
            self::writeTableRowsToBackup($handle, $table, $whereSql);
        }

        if (Db::getInstance()->execute('DELETE FROM `'.bqSQL(self::t($table)).'` WHERE '.$whereSql)) {
            $affected = Db::getInstance()->Affected_rows();
            if ($affected) {
                $logs[] = ['table' => $table, 'description' => $description, 'count' => $affected];
            }
        }
    }

    /**
     * Orders the ~110 known table relationships so a table is only cleaned
     * up AFTER every table it depends on has already been cleaned - a plain
     * bubble sort is plenty fast for a one-time, occasional, ~110-row list.
     * Capped so a future bad entry (an accidental dependency cycle) can
     * never turn into an infinite loop; it just stops sorting further.
     */
    protected static function sortByDependency(array $rules)
    {
        $size = count($rules);
        $maxPasses = $size * $size;
        $pass = 0;
        $sorted = false;

        while (!$sorted && $pass < $maxPasses) {
            $sorted = true;
            for ($i = 0; $i < $size - 1; ++$i) {
                for ($j = $i + 1; $j < $size; ++$j) {
                    if ($rules[$i][2] === $rules[$j][0]) {
                        [$rules[$i], $rules[$j]] = [$rules[$j], $rules[$i]];
                        $sorted = false;
                    }
                }
            }
            $pass++;
        }

        return $rules;
    }

    protected static function clearAllCaches()
    {
        $indexStub = file_exists(_PS_TMP_IMG_DIR_.'index.php') ? file_get_contents(_PS_TMP_IMG_DIR_.'index.php') : '';
        Tools::deleteDirectory(_PS_TMP_IMG_DIR_, false);
        file_put_contents(_PS_TMP_IMG_DIR_.'index.php', $indexStub);
        Context::getContext()->smarty->clearAllCache();
    }

    /**
     * 0 => DELETE FROM __table__, 1 => __column__, 2 => NOT IN __refTable__,
     * 3 => __refColumn__, 4 => optional module name (skipped if not installed).
     */
    protected static function getCheckAndFixQueries()
    {
        return [
            ['access', 'id_profile', 'profile', 'id_profile'],
            ['accessory', 'id_product_1', 'product', 'id_product'],
            ['accessory', 'id_product_2', 'product', 'id_product'],
            ['address_format', 'id_country', 'country', 'id_country'],
            ['attribute', 'id_attribute_group', 'attribute_group', 'id_attribute_group'],
            ['carrier_group', 'id_carrier', 'carrier', 'id_carrier'],
            ['carrier_group', 'id_group', 'group', 'id_group'],
            ['carrier_zone', 'id_carrier', 'carrier', 'id_carrier'],
            ['carrier_zone', 'id_zone', 'zone', 'id_zone'],
            ['cart_cart_rule', 'id_cart', 'cart', 'id_cart'],
            ['cart_product', 'id_cart', 'cart', 'id_cart'],
            ['cart_rule_carrier', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_carrier', 'id_carrier', 'carrier', 'id_carrier'],
            ['cart_rule_combination', 'id_cart_rule_1', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_combination', 'id_cart_rule_2', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_country', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_country', 'id_country', 'country', 'id_country'],
            ['cart_rule_group', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_group', 'id_group', 'group', 'id_group'],
            ['cart_rule_lang', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_lang', 'id_lang', 'lang', 'id_lang'],
            ['cart_rule_product_rule_group', 'id_cart_rule', 'cart_rule', 'id_cart_rule'],
            ['cart_rule_product_rule', 'id_product_rule_group', 'cart_rule_product_rule_group', 'id_product_rule_group'],
            ['cart_rule_product_rule_value', 'id_product_rule', 'cart_rule_product_rule', 'id_product_rule'],
            ['category_group', 'id_category', 'category', 'id_category'],
            ['category_group', 'id_group', 'group', 'id_group'],
            ['category_product', 'id_category', 'category', 'id_category'],
            ['category_product', 'id_product', 'product', 'id_product'],
            ['cms', 'id_cms_category', 'cms_category', 'id_cms_category'],
            ['cms_block', 'id_cms_category', 'cms_category', 'id_cms_category', 'blockcms'],
            ['cms_block_page', 'id_cms', 'cms', 'id_cms', 'blockcms'],
            ['cms_block_page', 'id_cms_block', 'cms_block', 'id_cms_block', 'blockcms'],
            ['connections', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['connections', 'id_shop', 'shop', 'id_shop'],
            ['connections_page', 'id_connections', 'connections', 'id_connections'],
            ['connections_page', 'id_page', 'page', 'id_page'],
            ['connections_source', 'id_connections', 'connections', 'id_connections'],
            ['customer', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['customer', 'id_shop', 'shop', 'id_shop'],
            ['customer_group', 'id_group', 'group', 'id_group'],
            ['customer_group', 'id_customer', 'customer', 'id_customer'],
            ['customer_message', 'id_customer_thread', 'customer_thread', 'id_customer_thread'],
            ['customer_thread', 'id_shop', 'shop', 'id_shop'],
            ['customization', 'id_cart', 'cart', 'id_cart'],
            ['customization_field', 'id_product', 'product', 'id_product'],
            ['customized_data', 'id_customization', 'customization', 'id_customization'],
            ['delivery', 'id_shop', 'shop', 'id_shop'],
            ['delivery', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['delivery', 'id_carrier', 'carrier', 'id_carrier'],
            ['delivery', 'id_zone', 'zone', 'id_zone'],
            ['editorial', 'id_shop', 'shop', 'id_shop', 'editorial'],
            ['favorite_product', 'id_product', 'product', 'id_product', 'favoriteproducts'],
            ['favorite_product', 'id_customer', 'customer', 'id_customer', 'favoriteproducts'],
            ['favorite_product', 'id_shop', 'shop', 'id_shop', 'favoriteproducts'],
            ['feature_product', 'id_feature', 'feature', 'id_feature'],
            ['feature_product', 'id_product', 'product', 'id_product'],
            ['feature_value', 'id_feature', 'feature', 'id_feature'],
            ['group_reduction', 'id_group', 'group', 'id_group'],
            ['group_reduction', 'id_category', 'category', 'id_category'],
            ['homeslider', 'id_shop', 'shop', 'id_shop', 'homeslider'],
            ['homeslider', 'id_homeslider_slides', 'homeslider_slides', 'id_homeslider_slides', 'homeslider'],
            ['hook_module', 'id_hook', 'hook', 'id_hook'],
            ['hook_module', 'id_module', 'module', 'id_module'],
            ['hook_module_exceptions', 'id_hook', 'hook', 'id_hook'],
            ['hook_module_exceptions', 'id_module', 'module', 'id_module'],
            ['hook_module_exceptions', 'id_shop', 'shop', 'id_shop'],
            ['image', 'id_product', 'product', 'id_product'],
            ['message', 'id_cart', 'cart', 'id_cart'],
            ['message_readed', 'id_message', 'message', 'id_message'],
            ['message_readed', 'id_employee', 'employee', 'id_employee'],
            ['module_access', 'id_profile', 'profile', 'id_profile'],
            ['module_country', 'id_module', 'module', 'id_module'],
            ['module_country', 'id_country', 'country', 'id_country'],
            ['module_country', 'id_shop', 'shop', 'id_shop'],
            ['module_currency', 'id_module', 'module', 'id_module'],
            ['module_currency', 'id_currency', 'currency', 'id_currency'],
            ['module_currency', 'id_shop', 'shop', 'id_shop'],
            ['module_group', 'id_module', 'module', 'id_module'],
            ['module_group', 'id_group', 'group', 'id_group'],
            ['module_group', 'id_shop', 'shop', 'id_shop'],
            ['module_preference', 'id_employee', 'employee', 'id_employee'],
            ['orders', 'id_shop', 'shop', 'id_shop'],
            ['orders', 'id_shop_group', 'group_shop', 'id_shop_group'],
            ['order_carrier', 'id_order', 'orders', 'id_order'],
            ['order_cart_rule', 'id_order', 'orders', 'id_order'],
            ['order_detail', 'id_order', 'orders', 'id_order'],
            ['order_detail_tax', 'id_order_detail', 'order_detail', 'id_order_detail'],
            ['order_history', 'id_order', 'orders', 'id_order'],
            ['order_invoice', 'id_order', 'orders', 'id_order'],
            ['order_invoice_payment', 'id_order', 'orders', 'id_order'],
            ['order_invoice_tax', 'id_order_invoice', 'order_invoice', 'id_order_invoice'],
            ['order_return', 'id_order', 'orders', 'id_order'],
            ['order_return_detail', 'id_order_return', 'order_return', 'id_order_return'],
            ['order_slip', 'id_order', 'orders', 'id_order'],
            ['order_slip_detail', 'id_order_slip', 'order_slip', 'id_order_slip'],
            ['pack', 'id_product_pack', 'product', 'id_product'],
            ['pack', 'id_product_item', 'product', 'id_product'],
            ['page', 'id_page_type', 'page_type', 'id_page_type'],
            ['page_viewed', 'id_shop', 'shop', 'id_shop'],
            ['page_viewed', 'id_shop_group', 'shop_group', 'id_shop_group'],
            ['page_viewed', 'id_date_range', 'date_range', 'id_date_range'],
            ['product_attachment', 'id_attachment', 'attachment', 'id_attachment'],
            ['product_attachment', 'id_product', 'product', 'id_product'],
            ['product_attribute', 'id_product', 'product', 'id_product'],
            ['product_attribute_combination', 'id_product_attribute', 'product_attribute', 'id_product_attribute'],
            ['product_attribute_combination', 'id_attribute', 'attribute', 'id_attribute'],
            ['product_attribute_image', 'id_image', 'image', 'id_image'],
            ['product_attribute_image', 'id_product_attribute', 'product_attribute', 'id_product_attribute'],
            ['product_carrier', 'id_product', 'product', 'id_product'],
            ['product_carrier', 'id_shop', 'shop', 'id_shop'],
            ['product_carrier', 'id_carrier_reference', 'carrier', 'id_reference'],
            ['product_country_tax', 'id_product', 'product', 'id_product'],
            ['product_country_tax', 'id_country', 'country', 'id_country'],
            ['product_country_tax', 'id_tax', 'tax', 'id_tax'],
            ['product_download', 'id_product', 'product', 'id_product'],
            ['product_group_reduction_cache', 'id_product', 'product', 'id_product'],
            ['product_group_reduction_cache', 'id_group', 'group', 'id_group'],
            ['product_sale', 'id_product', 'product', 'id_product'],
            ['product_supplier', 'id_product', 'product', 'id_product'],
            ['product_supplier', 'id_supplier', 'supplier', 'id_supplier'],
            ['product_tag', 'id_product', 'product', 'id_product'],
            ['product_tag', 'id_tag', 'tag', 'id_tag'],
            ['range_price', 'id_carrier', 'carrier', 'id_carrier'],
            ['range_weight', 'id_carrier', 'carrier', 'id_carrier'],
            ['referrer_cache', 'id_referrer', 'referrer', 'id_referrer'],
            ['referrer_cache', 'id_connections_source', 'connections_source', 'id_connections_source'],
            ['search_index', 'id_product', 'product', 'id_product'],
            ['search_word', 'id_lang', 'lang', 'id_lang'],
            ['search_word', 'id_shop', 'shop', 'id_shop'],
            ['shop_url', 'id_shop', 'shop', 'id_shop'],
            ['specific_price_priority', 'id_product', 'product', 'id_product'],
            ['stock', 'id_warehouse', 'warehouse', 'id_warehouse'],
            ['stock', 'id_product', 'product', 'id_product'],
            ['stock_available', 'id_product', 'product', 'id_product'],
            ['stock_mvt', 'id_stock', 'stock', 'id_stock'],
            ['tab_module_preference', 'id_employee', 'employee', 'id_employee'],
            ['tab_module_preference', 'id_tab', 'tab', 'id_tab'],
            ['tax_rule', 'id_country', 'country', 'id_country'],
            ['warehouse_carrier', 'id_warehouse', 'warehouse', 'id_warehouse'],
            ['warehouse_carrier', 'id_carrier', 'carrier', 'id_carrier'],
            ['warehouse_product_location', 'id_product', 'product', 'id_product'],
            ['warehouse_product_location', 'id_warehouse', 'warehouse', 'id_warehouse'],
        ];
    }

    /*
     * ----------------------------------------------------------------
     * Catalog / sales truncation - the only genuinely irreversible actions.
     * Never scheduled, never triggered from the cron endpoint; only ever
     * reachable through getContent() after the type-to-confirm check below.
     * ----------------------------------------------------------------
     */

    public static function previewTruncate($case)
    {
        $tables = $case === 'catalog' ? self::getCatalogRelatedTables() : self::getSalesRelatedTables();
        $rows = [];

        foreach ($tables as $table) {
            $count = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `'.bqSQL(self::t($table)).'`');
            if ($count) {
                $rows[] = ['table' => $table, 'count' => $count];
            }
        }

        return $rows;
    }

    public static function runTruncate($case, $backup = true)
    {
        $backupFile = false;
        if ($backup) {
            $tables = $case === 'catalog' ? self::getCatalogRelatedTables() : self::getSalesRelatedTables();
            $backupFile = self::backupFullTables($tables, 'truncate-'.$case);
        }

        $db = Db::getInstance();
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');

        if ($case === 'catalog') {
            self::truncateCatalog($db);
        } else {
            self::truncateSales($db);
        }

        $db->execute('SET FOREIGN_KEY_CHECKS = 1');
        self::clearAllCaches();

        return $backupFile;
    }

    protected static function truncateCatalog(Db $db)
    {
        $homeIds = array_map('intval', (array) Configuration::getMultiShopValues('PS_HOME_CATEGORY'));
        $rootIds = array_map('intval', (array) Configuration::getMultiShopValues('PS_ROOT_CATEGORY'));
        $keepIds = array_filter(array_unique(array_merge($homeIds, $rootIds)));
        $keepList = $keepIds ? implode(',', $keepIds) : '0';

        foreach (['category', 'category_lang', 'category_shop', 'category_group'] as $table) {
            $db->execute('DELETE FROM `'.bqSQL(self::t($table)).'` WHERE id_category NOT IN ('.$keepList.')');
        }
        if ($keepIds) {
            $db->execute('ALTER TABLE `'.bqSQL(self::t('category')).'` AUTO_INCREMENT = '.(1 + max($keepIds)));
        }

        foreach (self::getCatalogRelatedTables() as $table) {
            $db->execute('TRUNCATE TABLE `'.bqSQL(self::t($table)).'`');
        }
        $db->execute('DELETE FROM `'.bqSQL(self::t('address')).'` WHERE id_manufacturer > 0 OR id_supplier > 0 OR id_warehouse > 0');

        self::deleteImagesMatching(_PS_CAT_IMG_DIR_);
        Image::deleteAllImages(_PS_PROD_IMG_DIR_);
        if (!file_exists(_PS_PROD_IMG_DIR_)) {
            @mkdir(_PS_PROD_IMG_DIR_);
        }
        self::deleteImagesMatching(_PS_MANU_IMG_DIR_);
        self::deleteImagesMatching(_PS_SUPP_IMG_DIR_);
    }

    protected static function deleteImagesMatching($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $file) {
            if (preg_match('/^[0-9]+(-.*)?\.jpg$/', $file)) {
                @unlink($dir.$file);
            }
        }
    }

    protected static function truncateSales(Db $db)
    {
        $tables = self::getSalesRelatedTables();

        $moduleTables = [
            'sekeywords' => ['sekeyword'],
            'pagesnotfound' => ['pagenotfound'],
            'paypal' => ['paypal_customer', 'paypal_order'],
        ];
        foreach ($moduleTables as $module => $extraTables) {
            if (Module::isInstalled($module)) {
                $tables = array_merge($tables, $extraTables);
            }
        }

        foreach ($tables as $table) {
            $db->execute('TRUNCATE TABLE `'.bqSQL(self::t($table)).'`');
        }
        $db->execute('DELETE FROM `'.bqSQL(self::t('address')).'` WHERE id_customer > 0');
        $db->execute(
            'UPDATE `'.bqSQL(self::t('employee')).'` SET id_last_order = 0, id_last_customer_message = 0, id_last_customer = 0'
        );
    }

    protected static function getCatalogRelatedTables()
    {
        return [
            'product', 'product_shop', 'product_lang', 'category_product', 'product_tag', 'tag',
            'image', 'image_lang', 'image_shop', 'product_carrier', 'cart_product', 'product_attachment',
            'product_country_tax', 'product_download', 'product_group_reduction_cache', 'product_sale',
            'product_supplier', 'warehouse_product_location', 'supply_order_detail',
            'attribute', 'attribute_impact', 'attribute_lang', 'attribute_group', 'attribute_group_lang',
            'attribute_group_shop', 'attribute_shop', 'product_attribute', 'product_attribute_shop',
            'product_attribute_combination', 'product_attribute_image',
            'manufacturer', 'manufacturer_lang', 'manufacturer_shop', 'supplier', 'supplier_lang', 'supplier_shop',
            'customization', 'customization_field', 'customization_field_lang', 'customized_data',
            'feature', 'feature_lang', 'feature_product', 'feature_shop', 'feature_value', 'feature_value_lang',
            'pack', 'search_index', 'search_word', 'alias',
            'specific_price', 'specific_price_priority', 'specific_price_rule',
            'specific_price_rule_condition', 'specific_price_rule_condition_group',
            'stock', 'stock_available', 'stock_mvt', 'warehouse',
        ];
    }

    protected static function getSalesRelatedTables()
    {
        return [
            'customer', 'cart', 'cart_product', 'connections', 'connections_page', 'connections_source',
            'customer_group', 'customer_message', 'customer_message_sync_imap', 'customer_thread', 'guest',
            'mail', 'message', 'message_readed', 'orders', 'order_carrier', 'order_cart_rule', 'order_detail',
            'order_detail_tax', 'order_history', 'order_invoice', 'order_invoice_payment', 'order_invoice_tax',
            'order_message', 'order_message_lang', 'order_payment', 'order_return', 'order_return_detail',
            'order_slip', 'order_slip_detail', 'page', 'page_type', 'page_viewed', 'product_sale', 'referrer_cache',
        ];
    }

    /*
     * ----------------------------------------------------------------
     * Delete selected orders - a scoped alternative to resetting every
     * order: the admin searches/picks specific orders (stray test
     * transactions, for example) and only those are removed. Reuses the
     * exact same order/child-table relationships already known from
     * getCheckAndFixQueries(), cleaned up in child-first order, plus the
     * two things that table alone can't express (order_payment links to
     * orders by `order_reference`, not `id_order`; employee "last order"
     * shortcut columns).
     * ----------------------------------------------------------------
     */

    public static function sanitizeOrderIds($raw)
    {
        $ids = array_map('intval', (array) $raw);
        $ids = array_filter($ids, function ($id) {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    /**
     * @return array{rows:array,total:int,page:int,pages:int}
     */
    public static function queryFilteredOrders(array $filters, $page, $perPage = 20)
    {
        $where = ['1 = 1'];

        if ($filters['search'] !== '') {
            if (Validate::isUnsignedId($filters['search'])) {
                $where[] = 'o.id_order = '.(int) $filters['search'];
            } else {
                $where[] = 'o.reference LIKE "%'.pSQL($filters['search']).'%"';
            }
        }
        if ((int) $filters['status'] > 0) {
            $where[] = 'o.current_state = '.(int) $filters['status'];
        }
        if ($filters['date_from'] !== '' && Validate::isDate($filters['date_from'])) {
            $where[] = 'o.date_add >= "'.pSQL($filters['date_from']).' 00:00:00"';
        }
        if ($filters['date_to'] !== '' && Validate::isDate($filters['date_to'])) {
            $where[] = 'o.date_add <= "'.pSQL($filters['date_to']).' 23:59:59"';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `'.bqSQL(self::t('orders')).'` o WHERE '.$whereSql);

        $perPage = max(1, (int) $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $page, $pages));
        $offset = ($page - 1) * $perPage;
        $idLang = (int) Context::getContext()->language->id;

        $rows = Db::getInstance()->executeS(
            'SELECT o.id_order, o.reference, o.date_add, o.total_paid, o.id_currency, o.current_state,
                    osl.name AS status, CONCAT(c.firstname, " ", c.lastname) AS customer_name, c.email
             FROM `'.bqSQL(self::t('orders')).'` o
             LEFT JOIN `'.bqSQL(self::t('customer')).'` c ON c.id_customer = o.id_customer
             LEFT JOIN `'.bqSQL(self::t('order_state_lang')).'` osl ON osl.id_order_state = o.current_state AND osl.id_lang = '.$idLang.'
             WHERE '.$whereSql.'
             ORDER BY o.id_order DESC
             LIMIT '.$perPage.' OFFSET '.$offset
        );

        return ['rows' => $rows ?: [], 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function previewDeleteOrders(array $orderIds)
    {
        $orderIds = self::sanitizeOrderIds($orderIds);
        if (!$orderIds) {
            return [];
        }

        return self::processDeleteOrders('preview', implode(',', $orderIds), null);
    }

    public static function runDeleteOrders(array $orderIds, $backup = true)
    {
        $orderIds = self::sanitizeOrderIds($orderIds);
        if (!$orderIds) {
            return ['logs' => [], 'backup_file' => false, 'deleted' => 0];
        }
        $idList = implode(',', $orderIds);

        $references = array_column(
            Db::getInstance()->executeS('SELECT DISTINCT reference FROM `'.bqSQL(self::t('orders')).'` WHERE id_order IN ('.$idList.')'),
            'reference'
        );

        $handle = null;
        $backupFile = false;
        if ($backup) {
            [$backupFile, $handle] = self::openBackup('delete-orders');
            if ($handle && $references) {
                self::writeTableRowsToBackup($handle, 'order_payment', self::orderPaymentWhere($references));
            }
        }

        $logs = self::processDeleteOrders('apply', $idList, $handle);

        if ($handle) {
            gzclose($handle);
        }

        // Only once the orders themselves are gone can we tell whether a shared
        // payment reference (multi-package orders) still belongs to a survivor.
        if ($references) {
            Db::getInstance()->execute(
                'DELETE FROM `'.bqSQL(self::t('order_payment')).'` WHERE '.self::orderPaymentWhere($references).'
                    AND order_reference NOT IN (SELECT reference FROM `'.bqSQL(self::t('orders')).'`)'
            );
        }
        Db::getInstance()->execute('UPDATE `'.bqSQL(self::t('employee')).'` SET id_last_order = 0 WHERE id_last_order IN ('.$idList.')');

        return ['logs' => $logs, 'backup_file' => $backupFile, 'deleted' => count($orderIds)];
    }

    protected static function orderPaymentWhere(array $references)
    {
        if (!$references) {
            return '1 = 0';
        }
        $quoted = array_map(function ($ref) {
            return "'".pSQL($ref)."'";
        }, $references);

        return 'order_reference IN ('.implode(',', $quoted).')';
    }

    protected static function processDeleteOrders($mode, $idList, $handle)
    {
        $logs = [];
        $orderDetail = bqSQL(self::t('order_detail'));
        $orderInvoice = bqSQL(self::t('order_invoice'));
        $orderReturn = bqSQL(self::t('order_return'));
        $orderSlip = bqSQL(self::t('order_slip'));

        self::applyOrCount($mode, $logs, $handle, 'order_detail_tax',
            'id_order_detail IN (SELECT id_order_detail FROM `'.$orderDetail.'` WHERE id_order IN ('.$idList.'))',
            'order line tax row(s)');
        self::applyOrCount($mode, $logs, $handle, 'order_invoice_tax',
            'id_order_invoice IN (SELECT id_order_invoice FROM `'.$orderInvoice.'` WHERE id_order IN ('.$idList.'))',
            'invoice tax row(s)');
        self::applyOrCount($mode, $logs, $handle, 'order_return_detail',
            'id_order_return IN (SELECT id_order_return FROM `'.$orderReturn.'` WHERE id_order IN ('.$idList.'))',
            'merchandise return line(s)');
        self::applyOrCount($mode, $logs, $handle, 'order_slip_detail',
            'id_order_slip IN (SELECT id_order_slip FROM `'.$orderSlip.'` WHERE id_order IN ('.$idList.'))',
            'credit slip line(s)');

        foreach ([
            'order_detail' => 'order line(s)',
            'order_history' => 'status history entrie(s)',
            'order_carrier' => 'carrier/shipping row(s)',
            'order_cart_rule' => 'applied voucher(s)',
            'order_invoice' => 'invoice(s)',
            'order_invoice_payment' => 'invoice payment row(s)',
            'order_return' => 'merchandise return(s)',
            'order_slip' => 'credit slip(s)',
            'message' => 'order message(s)',
        ] as $table => $description) {
            self::applyOrCount($mode, $logs, $handle, $table, 'id_order IN ('.$idList.')', $description);
        }

        self::applyOrCount($mode, $logs, $handle, 'orders', 'id_order IN ('.$idList.')', 'order(s) permanently removed');

        return $logs;
    }

    /*
     * ----------------------------------------------------------------
     * Health score: a real, computed snapshot (never a static claim) shown
     * at the top of the configure page so the merchant sees at a glance
     * whether anything actually needs attention.
     * ----------------------------------------------------------------
     */

    public static function computeHealthReport()
    {
        $db = Db::getInstance();
        $score = 100;
        $reasons = [];

        $orphanRows = 0;
        foreach (self::previewCheckAndFix() as $row) {
            $orphanRows += $row['count'];
        }
        if ($orphanRows > 0) {
            $penalty = min(30, (int) ceil($orphanRows / 50));
            $score -= $penalty;
            $reasons[] = ['level' => 'warning', 'text' => sprintf('%d orphan database row(s) found - run "Check & fix".', $orphanRows)];
        }

        $abandonedCarts = (int) $db->getValue(
            'SELECT COUNT(*) FROM `'.bqSQL(self::t('cart')).'` WHERE id_cart NOT IN (SELECT id_cart FROM `'.bqSQL(self::t('orders')).'`)
                AND date_add < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"'
        );
        if ($abandonedCarts > 0) {
            $score -= min(15, (int) ceil($abandonedCarts / 100));
            $reasons[] = ['level' => 'info', 'text' => sprintf('%d abandoned cart(s) older than a month - "Clean & optimize" will remove them.', $abandonedCarts)];
        }

        $deadCartRules = (int) $db->getValue(
            'SELECT COUNT(*) FROM `'.bqSQL(self::t('cart_rule')).'`
                WHERE (active = 0 OR quantity = 0 OR date_to < "'.pSQL(date('Y-m-d')).'")
                AND date_add < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"'
        );
        if ($deadCartRules > 0) {
            $score -= min(10, (int) ceil($deadCartRules / 20));
            $reasons[] = ['level' => 'info', 'text' => sprintf('%d expired or exhausted cart rule(s) can be cleared.', $deadCartRules)];
        }

        $tmpImageBytes = 0;
        if (is_dir(_PS_TMP_IMG_DIR_)) {
            foreach (glob(_PS_TMP_IMG_DIR_.'*') ?: [] as $file) {
                if (is_file($file)) {
                    $tmpImageBytes += filesize($file);
                }
            }
        }
        if ($tmpImageBytes > 50 * 1024 * 1024) {
            $score -= 5;
            $reasons[] = ['level' => 'info', 'text' => sprintf('The image cache is using %s - "Check & fix" clears it.', self::formatBytes($tmpImageBytes))];
        }

        $backups = self::listBackups();
        if (!$backups) {
            $score -= 5;
            $reasons[] = ['level' => 'info', 'text' => 'No backup has been created yet - one is made automatically the next time you run an action with backup enabled.'];
        }

        if (!Configuration::get(self::CONF_AUTO_ENABLED)) {
            $reasons[] = ['level' => 'info', 'text' => 'Scheduled maintenance is off - housekeeping only happens when you click a button.'];
        }

        $engineWarning = (int) $db->getValue(
            'SELECT COUNT(*) FROM information_schema.TABLES
                WHERE table_schema = DATABASE() AND table_name IN ("'.bqSQL(self::t('product')).'", "'.bqSQL(self::t('orders')).'")
                AND ENGINE != "InnoDB"'
        );
        if ($engineWarning > 0) {
            $score -= 10;
            $reasons[] = ['level' => 'warning', 'text' => 'The product/orders tables are not using InnoDB - referential-integrity issues are more likely to appear.'];
        }

        $score = max(0, min(100, $score));
        if ($score >= 90) {
            $label = 'Excellent';
        } elseif ($score >= 70) {
            $label = 'Good';
        } elseif ($score >= 40) {
            $label = 'Needs attention';
        } else {
            $label = 'Poor';
        }

        if (!$reasons) {
            $reasons[] = ['level' => 'success', 'text' => 'Nothing found - your store is clean.'];
        }

        return ['score' => $score, 'label' => $label, 'reasons' => $reasons];
    }

    protected static function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }

    /*
     * ----------------------------------------------------------------
     * Configuration page
     * ----------------------------------------------------------------
     */

    public function getContent()
    {
        $banner = '';

        if (Tools::isSubmit('submitPrestacleanerSettings')) {
            $banner = $this->processSettings();
        } elseif (Tools::isSubmit('submitPrestacleanerRegenToken')) {
            $banner = $this->processRegenToken();
        } elseif (Tools::isSubmit('submitPrestacleanerDeleteBackup')) {
            $banner = $this->processDeleteBackup();
        } elseif (Tools::isSubmit('submitPreviewFix')) {
            $banner = $this->renderPreview('fix');
        } elseif (Tools::isSubmit('submitPreviewOptimize')) {
            $banner = $this->renderPreview('optimize');
        } elseif (Tools::isSubmit('submitPreviewTruncateCatalog')) {
            $banner = $this->renderPreview('truncate_catalog');
        } elseif (Tools::isSubmit('submitPreviewTruncateSales')) {
            $banner = $this->renderPreview('truncate_sales');
        } elseif (Tools::isSubmit('submitApplyFix')) {
            $banner = $this->processApplyFix();
        } elseif (Tools::isSubmit('submitApplyOptimize')) {
            $banner = $this->processApplyOptimize();
        } elseif (Tools::isSubmit('submitApplyTruncateCatalog')) {
            $banner = $this->processApplyTruncate('catalog');
        } elseif (Tools::isSubmit('submitApplyTruncateSales')) {
            $banner = $this->processApplyTruncate('sales');
        } elseif (Tools::isSubmit('submitPreviewDeleteOrders')) {
            $banner = $this->renderPreviewDeleteOrders();
        } elseif (Tools::isSubmit('submitApplyDeleteOrders')) {
            $banner = $this->processApplyDeleteOrders();
        }

        return $this->renderStyle().'<div class="prestacleaner-wrap">'.$banner.$this->renderTutorialPanel().$this->renderHealthPanel()
            .$this->renderSettingsForm().$this->renderBackupsPanel()
            .$this->renderActionPanel(
                'fix', $this->trans('Check & fix', [], 'Modules.Prestacleaner.Admin'),
                $this->trans('Scans core tables for rows that point at something already deleted (a product, an order, a language...) and removes only those orphan rows. Safe to run any time.', [], 'Modules.Prestacleaner.Admin'),
                'submitPreviewFix', 'submitApplyFix', 'backup_fix', self::CONF_BACKUP_BEFORE_FIX
            )
            .$this->renderActionPanel(
                'optimize', $this->trans('Clean & optimize', [], 'Modules.Prestacleaner.Admin'),
                $this->trans('Removes abandoned carts older than a month with no order, expired or exhausted cart rules, and re-numbers admin menu positions. Safe to run any time.', [], 'Modules.Prestacleaner.Admin'),
                'submitPreviewOptimize', 'submitApplyOptimize', 'backup_optimize', self::CONF_BACKUP_BEFORE_OPTIMIZE
            )
            .$this->renderTruncatePanel(
                'truncate_catalog', $this->trans('Reset the catalog', [], 'Modules.Prestacleaner.Admin'),
                $this->trans('Permanently deletes every product, category (except the root/home category), attribute, feature, manufacturer, supplier, and their images. There is no undo beyond the backup created below.', [], 'Modules.Prestacleaner.Admin'),
                'submitPreviewTruncateCatalog', 'submitApplyTruncateCatalog', 'confirm_phrase_catalog', self::CONFIRM_PHRASE_CATALOG
            )
            .$this->renderTruncatePanel(
                'truncate_sales', $this->trans('Reset orders & customers', [], 'Modules.Prestacleaner.Admin'),
                $this->trans('Permanently deletes every customer, cart, order, connection log and customer message. There is no undo beyond the backup created below. To remove only a few specific orders, use "Delete selected orders" below instead.', [], 'Modules.Prestacleaner.Admin'),
                'submitPreviewTruncateSales', 'submitApplyTruncateSales', 'confirm_phrase_sales', self::CONFIRM_PHRASE_SALES
            )
            .$this->renderDeleteOrdersPanel()
            .'</div>';
    }

    protected function getConfigureUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
    }

    protected function alert($type, $text)
    {
        return '<div class="alert alert-'.$type.'">'.$text.'</div>';
    }

    protected function processSettings()
    {
        $intervalRaw = Tools::getValue('auto_interval_days');
        $retentionRaw = Tools::getValue('backup_retention_days');

        if (!Validate::isInt($intervalRaw) || $intervalRaw < 1 || $intervalRaw > 365) {
            return $this->alert('danger', $this->trans('The scheduled-cleaning interval must be a whole number of days between 1 and 365. Nothing was saved.', [], 'Modules.Prestacleaner.Admin'));
        }
        if (!Validate::isInt($retentionRaw) || $retentionRaw < 0 || $retentionRaw > 365) {
            return $this->alert('danger', $this->trans('The backup retention must be a whole number of days between 0 and 365. Nothing was saved.', [], 'Modules.Prestacleaner.Admin'));
        }

        Configuration::updateGlobalValue(self::CONF_AUTO_ENABLED, (int) Tools::getValue('auto_enabled', 0) ? 1 : 0);
        Configuration::updateGlobalValue(self::CONF_AUTO_INTERVAL_DAYS, (int) $intervalRaw);
        Configuration::updateGlobalValue(self::CONF_BACKUP_RETENTION_DAYS, (int) $retentionRaw);
        Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_FIX, (int) Tools::getValue('default_backup_fix', 0) ? 1 : 0);
        Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_OPTIMIZE, (int) Tools::getValue('default_backup_optimize', 0) ? 1 : 0);
        Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_TRUNCATE, (int) Tools::getValue('default_backup_truncate', 0) ? 1 : 0);
        Configuration::updateGlobalValue(self::CONF_BACKUP_BEFORE_DELETE_ORDERS, (int) Tools::getValue('default_backup_delete_orders', 0) ? 1 : 0);

        $purged = self::purgeOldBackups((int) $retentionRaw);

        $message = $this->trans('Settings saved.', [], 'Modules.Prestacleaner.Admin');
        if ($purged > 0) {
            $message .= ' '.sprintf($this->trans('%d old backup file(s) past the retention period were also removed.', [], 'Modules.Prestacleaner.Admin'), $purged);
        }

        return $this->alert('success', $message);
    }

    protected function processRegenToken()
    {
        Configuration::updateGlobalValue(self::CONF_CRON_TOKEN, Tools::passwdGen(32));

        return $this->alert('success', $this->trans('A new scheduled-cleaning URL was generated below. Update it wherever your old one was configured.', [], 'Modules.Prestacleaner.Admin'));
    }

    protected function processDeleteBackup()
    {
        $file = (string) Tools::getValue('backup_file');
        if (self::deleteBackup($file)) {
            return $this->alert('success', $this->trans('Backup file deleted.', [], 'Modules.Prestacleaner.Admin'));
        }

        return $this->alert('danger', $this->trans('That backup file could not be found or deleted.', [], 'Modules.Prestacleaner.Admin'));
    }

    protected function renderPreview($key)
    {
        switch ($key) {
            case 'fix':
                $rows = self::previewCheckAndFix();
                break;
            case 'optimize':
                $rows = self::previewCleanAndOptimize();
                break;
            case 'truncate_catalog':
                $rows = self::previewTruncate('catalog');
                break;
            default:
                $rows = self::previewTruncate('sales');
        }

        $this->previewResults[$key] = $rows;
        $total = array_sum(array_column($rows, 'count'));

        if ($total === 0) {
            return $this->alert('success', $this->trans('Preview complete: nothing would change.', [], 'Modules.Prestacleaner.Admin'));
        }

        return $this->alert('info', sprintf($this->trans('Preview complete: %d row(s) across %d table(s) would be affected. Nothing has been changed yet - see the details below.', [], 'Modules.Prestacleaner.Admin'), $total, count($rows)));
    }

    protected function processApplyFix()
    {
        $backup = (bool) Tools::getValue('backup_fix', 0);
        $result = self::runCheckAndFix($backup);

        return $this->renderApplyResult($result['logs'], $result['backup_file']);
    }

    protected function processApplyOptimize()
    {
        $backup = (bool) Tools::getValue('backup_optimize', 0);
        $result = self::runCleanAndOptimize($backup);

        return $this->renderApplyResult($result['logs'], $result['backup_file']);
    }

    protected function renderApplyResult(array $logs, $backupFile)
    {
        $total = array_sum(array_column($logs, 'count'));
        $message = $total === 0
            ? $this->trans('Done - nothing needed to be changed.', [], 'Modules.Prestacleaner.Admin')
            : sprintf($this->trans('Done - %d row(s) fixed across %d table(s).', [], 'Modules.Prestacleaner.Admin'), $total, count($logs));

        if ($backupFile) {
            $message .= ' '.sprintf($this->trans('A backup was saved as %s (Advanced Parameters > DB Backup).', [], 'Modules.Prestacleaner.Admin'), '<code>'.Tools::safeOutput($backupFile).'</code>');
        }

        return $this->alert('success', $message);
    }

    protected function processApplyTruncate($case)
    {
        $required = $case === 'catalog' ? self::CONFIRM_PHRASE_CATALOG : self::CONFIRM_PHRASE_SALES;
        $typed = strtoupper(trim((string) Tools::getValue('confirm_phrase_'.$case)));

        if ($typed !== $required) {
            return $this->alert('danger', sprintf($this->trans('You must type "%s" exactly to confirm. Nothing was deleted.', [], 'Modules.Prestacleaner.Admin'), $required));
        }

        $backup = (bool) Tools::getValue('backup_'.$case, 0);
        $backupFile = self::runTruncate($case, $backup);

        $message = $case === 'catalog'
            ? $this->trans('The catalog has been reset.', [], 'Modules.Prestacleaner.Admin')
            : $this->trans('Orders and customers have been reset.', [], 'Modules.Prestacleaner.Admin');

        if ($backupFile) {
            $message .= ' '.sprintf($this->trans('A full backup was saved as %s before deleting anything (Advanced Parameters > DB Backup).', [], 'Modules.Prestacleaner.Admin'), '<code>'.Tools::safeOutput($backupFile).'</code>');
        } elseif ($backup) {
            $message .= ' '.$this->trans('The backup could not be created - please check disk space and permissions.', [], 'Modules.Prestacleaner.Admin');
        }

        return $this->alert('warning', $message);
    }

    protected function renderStyle()
    {
        return '<style>
            .prestacleaner-wrap .panel{margin-bottom:20px}
            .prestacleaner-wrap .prestacleaner-score{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
            .prestacleaner-wrap .prestacleaner-score-badge{font-size:32px;font-weight:700;border-radius:50%;width:88px;height:88px;display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 auto}
            .prestacleaner-wrap .prestacleaner-score-excellent{background:#4CAF50}
            .prestacleaner-wrap .prestacleaner-score-good{background:#8BC34A}
            .prestacleaner-wrap .prestacleaner-score-attention{background:#FF9800}
            .prestacleaner-wrap .prestacleaner-score-poor{background:#F44336}
            .prestacleaner-wrap .prestacleaner-reasons{list-style:none;margin:0;padding:0;flex:1 1 260px}
            .prestacleaner-wrap .prestacleaner-reasons li{padding:4px 0}
            .prestacleaner-wrap table.prestacleaner-table{width:100%;margin-top:10px}
            .prestacleaner-wrap .prestacleaner-cron-url{font-family:monospace;word-break:break-all;background:#f5f5f5;padding:6px 10px;border-radius:4px;display:inline-block}
            .prestacleaner-wrap .panel-danger .panel-heading{background:#f2dede;color:#a94442}
        </style>';
    }

    protected function renderTutorialPanel()
    {
        $steps = [
            $this->trans('The score below is computed live from your own database - it tells you if anything actually needs attention right now.', [], 'Modules.Prestacleaner.Admin'),
            $this->trans('Every action has a "Preview" button: it reports exactly what would change without touching anything, so you can check before you commit.', [], 'Modules.Prestacleaner.Admin'),
            $this->trans('"Check & fix" and "Clean & optimize" are always safe to run and can be scheduled automatically below.', [], 'Modules.Prestacleaner.Admin'),
            $this->trans('Resetting the catalog or orders is permanent. Tick "back up first", then type the confirmation phrase exactly as shown to proceed.', [], 'Modules.Prestacleaner.Admin'),
            $this->trans('Need to remove just a handful of orders instead of everything? Search for them in "Delete selected orders" and pick only the ones you want.', [], 'Modules.Prestacleaner.Admin'),
            $this->trans('Backups made here appear under Advanced Parameters > DB Backup, where they can be downloaded or restored using the standard PrestaShop tool.', [], 'Modules.Prestacleaner.Admin'),
        ];

        $html = '<div class="panel">
            <div class="panel-heading"><i class="icon-info-circle"></i> '.$this->trans('How this module works', [], 'Modules.Prestacleaner.Admin').'</div>
            <div class="panel-body"><ol>';
        foreach ($steps as $step) {
            $html .= '<li>'.$step.'</li>';
        }

        return $html.'</ol></div></div>';
    }

    protected function renderHealthPanel()
    {
        $report = self::computeHealthReport();
        $badgeClass = 'prestacleaner-score-poor';
        if ($report['score'] >= 90) {
            $badgeClass = 'prestacleaner-score-excellent';
        } elseif ($report['score'] >= 70) {
            $badgeClass = 'prestacleaner-score-good';
        } elseif ($report['score'] >= 40) {
            $badgeClass = 'prestacleaner-score-attention';
        }

        $icons = ['success' => 'icon-check', 'info' => 'icon-info-circle', 'warning' => 'icon-warning'];
        $items = '';
        foreach ($report['reasons'] as $reason) {
            $iconClass = isset($icons[$reason['level']]) ? $icons[$reason['level']] : 'icon-info-circle';
            $items .= '<li><i class="'.$iconClass.'"></i> '.$reason['text'].'</li>';
        }

        return '<div class="panel">
            <div class="panel-heading"><i class="icon-dashboard"></i> '.$this->trans('Store health', [], 'Modules.Prestacleaner.Admin').'</div>
            <div class="panel-body prestacleaner-score">
                <div class="prestacleaner-score-badge '.$badgeClass.'">'.(int) $report['score'].'</div>
                <div>
                    <strong>'.Tools::safeOutput($report['label']).'</strong>
                    <ul class="prestacleaner-reasons">'.$items.'</ul>
                </div>
            </div>
        </div>';
    }

    protected function renderSettingsForm()
    {
        $action = $this->getConfigureUrl();
        $autoEnabled = Configuration::get(self::CONF_AUTO_ENABLED) ? ' checked="checked"' : '';
        $interval = (int) Configuration::get(self::CONF_AUTO_INTERVAL_DAYS);
        $retention = (int) Configuration::get(self::CONF_BACKUP_RETENTION_DAYS);
        $lastRun = (int) Configuration::get(self::CONF_AUTO_LAST_RUN);
        $lastRunText = $lastRun ? date('Y-m-d H:i', $lastRun) : $this->trans('never yet', [], 'Modules.Prestacleaner.Admin');
        $cronUrl = $this->getCronUrl();

        $backupChecks = [
            'default_backup_fix' => self::CONF_BACKUP_BEFORE_FIX,
            'default_backup_optimize' => self::CONF_BACKUP_BEFORE_OPTIMIZE,
            'default_backup_truncate' => self::CONF_BACKUP_BEFORE_TRUNCATE,
            'default_backup_delete_orders' => self::CONF_BACKUP_BEFORE_DELETE_ORDERS,
        ];
        $backupLabels = [
            'default_backup_fix' => $this->trans('Back up by default before "Check & fix"', [], 'Modules.Prestacleaner.Admin'),
            'default_backup_optimize' => $this->trans('Back up by default before "Clean & optimize"', [], 'Modules.Prestacleaner.Admin'),
            'default_backup_truncate' => $this->trans('Back up by default before resetting the catalog or orders', [], 'Modules.Prestacleaner.Admin'),
            'default_backup_delete_orders' => $this->trans('Back up by default before deleting selected orders', [], 'Modules.Prestacleaner.Admin'),
        ];
        $backupHtml = '';
        foreach ($backupChecks as $field => $confKey) {
            $checked = Configuration::get($confKey) ? ' checked="checked"' : '';
            $backupHtml .= '<div class="checkbox"><label><input type="checkbox" name="'.$field.'" value="1"'.$checked.'> '.$backupLabels[$field].'</label></div>';
        }

        return '<div class="panel">
            <div class="panel-heading"><i class="icon-calendar"></i> '.$this->trans('Scheduled maintenance & backups', [], 'Modules.Prestacleaner.Admin').'</div>
            <div class="panel-body">
                <form method="post" action="'.$action.'">
                    <div class="checkbox"><label><input type="checkbox" name="auto_enabled" value="1"'.$autoEnabled.'> '.$this->trans('Automatically run "Check & fix" and "Clean & optimize"', [], 'Modules.Prestacleaner.Admin').'</label></div>
                    <div class="form-group">
                        <label>'.$this->trans('Every', [], 'Modules.Prestacleaner.Admin').'</label>
                        <input type="number" min="1" max="365" class="form-control" style="width:120px;display:inline-block" name="auto_interval_days" value="'.$interval.'"> '.$this->trans('day(s). Last run:', [], 'Modules.Prestacleaner.Admin').' '.$lastRunText.'
                    </div>
                    <div class="form-group">
                        <label>'.$this->trans('Keep backup files for', [], 'Modules.Prestacleaner.Admin').'</label>
                        <input type="number" min="0" max="365" class="form-control" style="width:120px;display:inline-block" name="backup_retention_days" value="'.$retention.'"> '.$this->trans('day(s) (0 = keep forever)', [], 'Modules.Prestacleaner.Admin').'
                    </div>
                    '.$backupHtml.'
                    <p>'.$this->trans('For automation that works even without an admin ever logging in, add this address to a real server cron job (once a day is enough):', [], 'Modules.Prestacleaner.Admin').'</p>
                    <p class="prestacleaner-cron-url">'.Tools::safeOutput($cronUrl).'</p>
                    <button type="submit" name="submitPrestacleanerSettings" value="1" class="btn btn-primary">'.$this->trans('Save', [], 'Modules.Prestacleaner.Admin').'</button>
                </form>
                <form method="post" action="'.$action.'" style="margin-top:10px">
                    <button type="submit" name="submitPrestacleanerRegenToken" value="1" class="btn btn-default" onclick="return confirm(\''.addslashes($this->trans('The old address will stop working immediately. Continue?', [], 'Modules.Prestacleaner.Admin')).'\');">'.$this->trans('Generate a new address', [], 'Modules.Prestacleaner.Admin').'</button>
                </form>
            </div>
        </div>';
    }

    protected function renderBackupsPanel()
    {
        $backups = self::listBackups();
        $action = $this->getConfigureUrl();

        if (!$backups) {
            $rows = '<tr><td colspan="4">'.$this->trans('No backup files yet.', [], 'Modules.Prestacleaner.Admin').'</td></tr>';
        } else {
            $rows = '';
            foreach ($backups as $backup) {
                $rows .= '<tr>
                    <td>'.Tools::safeOutput($backup['label']).'</td>
                    <td>'.date('Y-m-d H:i', $backup['date']).'</td>
                    <td>'.self::formatBytes($backup['size']).'</td>
                    <td><form method="post" action="'.$action.'" onclick="return confirm(\''.addslashes($this->trans('Delete this backup file?', [], 'Modules.Prestacleaner.Admin')).'\');">
                        <input type="hidden" name="backup_file" value="'.Tools::safeOutput($backup['file']).'">
                        <button type="submit" name="submitPrestacleanerDeleteBackup" value="1" class="btn btn-default btn-xs"><i class="icon-trash"></i></button>
                    </form></td>
                </tr>';
            }
        }

        return '<div class="panel">
            <div class="panel-heading"><i class="icon-archive"></i> '.$this->trans('Backup files', [], 'Modules.Prestacleaner.Admin').'</div>
            <div class="panel-body">
                <p>'.$this->trans('Database-only snapshots this module created before its own cleanup actions. Download or restore them from Advanced Parameters > DB Backup.', [], 'Modules.Prestacleaner.Admin').'</p>
                <table class="table prestacleaner-table">
                    <thead><tr>
                        <th>'.$this->trans('Action', [], 'Modules.Prestacleaner.Admin').'</th>
                        <th>'.$this->trans('Created', [], 'Modules.Prestacleaner.Admin').'</th>
                        <th>'.$this->trans('Size', [], 'Modules.Prestacleaner.Admin').'</th>
                        <th></th>
                    </tr></thead>
                    <tbody>'.$rows.'</tbody>
                </table>
            </div>
        </div>';
    }

    protected function renderPreviewTable($key)
    {
        if (!isset($this->previewResults[$key])) {
            return '';
        }

        $rows = $this->previewResults[$key];
        if (!$rows) {
            return '';
        }

        $hasDescription = isset($rows[0]['description']);
        $html = '<table class="table prestacleaner-table"><thead><tr><th>'.$this->trans('Table', [], 'Modules.Prestacleaner.Admin').'</th>';
        $html .= $hasDescription ? '<th>'.$this->trans('Reason', [], 'Modules.Prestacleaner.Admin').'</th>' : '';
        $html .= '<th>'.$this->trans('Row(s) affected', [], 'Modules.Prestacleaner.Admin').'</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td><code>'.Tools::safeOutput($row['table']).'</code></td>';
            $html .= $hasDescription ? '<td>'.Tools::safeOutput($row['description']).'</td>' : '';
            $html .= '<td>'.(int) $row['count'].'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    protected function renderActionPanel($key, $title, $description, $previewSubmit, $applySubmit, $backupField, $backupConfKey)
    {
        $action = $this->getConfigureUrl();
        $checked = Configuration::get($backupConfKey) ? ' checked="checked"' : '';

        return '<div class="panel">
            <div class="panel-heading"><i class="icon-cogs"></i> '.$title.'</div>
            <div class="panel-body">
                <p>'.$description.'</p>
                <form method="post" action="'.$action.'">
                    <div class="checkbox"><label><input type="checkbox" name="'.$backupField.'" value="1"'.$checked.'> '.$this->trans('Back up the affected rows first', [], 'Modules.Prestacleaner.Admin').'</label></div>
                    <button type="submit" name="'.$previewSubmit.'" value="1" class="btn btn-default">'.$this->trans('Preview (no changes)', [], 'Modules.Prestacleaner.Admin').'</button>
                    <button type="submit" name="'.$applySubmit.'" value="1" class="btn btn-primary">'.$this->trans('Apply', [], 'Modules.Prestacleaner.Admin').'</button>
                </form>
                '.$this->renderPreviewTable($key).'
            </div>
        </div>';
    }

    protected function renderTruncatePanel($key, $title, $description, $previewSubmit, $applySubmit, $confirmField, $requiredPhrase)
    {
        $action = $this->getConfigureUrl();
        $case = str_replace('confirm_phrase_', '', $confirmField);
        $backupField = 'backup_'.$case;
        $checked = Configuration::get(self::CONF_BACKUP_BEFORE_TRUNCATE) ? ' checked="checked"' : '';

        return '<div class="panel panel-danger">
            <div class="panel-heading"><i class="icon-warning"></i> '.$title.'</div>
            <div class="panel-body">
                <p>'.$description.'</p>
                <form method="post" action="'.$action.'">
                    <div class="checkbox"><label><input type="checkbox" name="'.$backupField.'" value="1"'.$checked.'> '.$this->trans('Back up everything first (recommended)', [], 'Modules.Prestacleaner.Admin').'</label></div>
                    <div class="form-group">
                        <label>'.sprintf($this->trans('Type %s to confirm:', [], 'Modules.Prestacleaner.Admin'), '<code>'.$requiredPhrase.'</code>').'</label>
                        <input type="text" class="form-control" style="max-width:260px" name="'.$confirmField.'" autocomplete="off" placeholder="'.$requiredPhrase.'">
                    </div>
                    <button type="submit" name="'.$previewSubmit.'" value="1" class="btn btn-default">'.$this->trans('Preview (no changes)', [], 'Modules.Prestacleaner.Admin').'</button>
                    <button type="submit" name="'.$applySubmit.'" value="1" class="btn btn-danger" onclick="return confirm(\''.addslashes($this->trans('This cannot be undone except from a backup. Continue?', [], 'Modules.Prestacleaner.Admin')).'\');">'.$this->trans('Delete permanently', [], 'Modules.Prestacleaner.Admin').'</button>
                </form>
                '.$this->renderPreviewTable($key).'
            </div>
        </div>';
    }

    /**
     * Tools::displayPrice() was removed in PrestaShop 9 (deprecated since
     * 1.7.6), so it can fatal there with "Call to undefined method" -
     * prefer the locale formatter and fall back only where it's absent.
     */
    protected function formatOrderPrice($amount, $idCurrency)
    {
        $isoCode = $this->context->currency->iso_code;
        if ($idCurrency) {
            try {
                $currency = new Currency((int) $idCurrency);
                if (Validate::isLoadedObject($currency)) {
                    $isoCode = $currency->iso_code;
                }
            } catch (Throwable $e) {
                // Keep the context currency's ISO code.
            }
        }

        if (method_exists($this->context, 'getCurrentLocale')) {
            return $this->context->getCurrentLocale()->formatPrice((float) $amount, $isoCode);
        }
        if (method_exists('Tools', 'displayPrice')) {
            return Tools::displayPrice($amount);
        }

        return Tools::safeOutput(number_format((float) $amount, 2).' '.$isoCode);
    }

    protected function renderDeleteOrdersPanel()
    {
        $filters = [
            'search' => trim((string) Tools::getValue('order_search', '')),
            'status' => (int) Tools::getValue('order_status', 0),
            'date_from' => (string) Tools::getValue('order_date_from', ''),
            'date_to' => (string) Tools::getValue('order_date_to', ''),
        ];
        $page = max(1, (int) Tools::getValue('order_page', 1));
        $action = $this->getConfigureUrl();

        $html = '<div class="panel panel-danger">
            <div class="panel-heading"><i class="icon-trash"></i> '.$this->trans('Delete selected orders', [], 'Modules.Prestacleaner.Admin').'</div>
            <div class="panel-body">
                <p>'.$this->trans('Search for specific orders - stray test transactions, for example - and permanently delete only the ones you pick. Everything else is left untouched.', [], 'Modules.Prestacleaner.Admin').'</p>
                <form method="get" action="'.$action.'" class="form-inline" style="margin-bottom:15px">
                    <input type="hidden" name="configure" value="'.Tools::safeOutput($this->name).'">
                    <input type="hidden" name="tab_module" value="'.Tools::safeOutput($this->tab).'">
                    <input type="hidden" name="module_name" value="'.Tools::safeOutput($this->name).'">
                    <input type="text" class="form-control" name="order_search" value="'.Tools::safeOutput($filters['search']).'" placeholder="'.$this->trans('Order ID or reference', [], 'Modules.Prestacleaner.Admin').'">
                    <select class="form-control" name="order_status">
                        <option value="0">'.$this->trans('Any status', [], 'Modules.Prestacleaner.Admin').'</option>';

        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $selected = ((int) $state['id_order_state'] === $filters['status']) ? ' selected="selected"' : '';
            $html .= '<option value="'.(int) $state['id_order_state'].'"'.$selected.'>'.Tools::safeOutput($state['name']).'</option>';
        }

        $html .= '</select>
                    <input type="date" class="form-control" name="order_date_from" value="'.Tools::safeOutput($filters['date_from']).'">
                    <input type="date" class="form-control" name="order_date_to" value="'.Tools::safeOutput($filters['date_to']).'">
                    <button type="submit" class="btn btn-default">'.$this->trans('Search', [], 'Modules.Prestacleaner.Admin').'</button>
                </form>';

        $result = self::queryFilteredOrders($filters, $page);

        if (!$result['rows']) {
            return $html.$this->alert('info', $this->trans('No orders match this search.', [], 'Modules.Prestacleaner.Admin')).'</div></div>';
        }

        $backupChecked = Configuration::get(self::CONF_BACKUP_BEFORE_DELETE_ORDERS) ? ' checked="checked"' : '';

        $html .= '<form method="post" action="'.$action.'">
            <input type="hidden" name="order_search" value="'.Tools::safeOutput($filters['search']).'">
            <input type="hidden" name="order_status" value="'.(int) $filters['status'].'">
            <input type="hidden" name="order_date_from" value="'.Tools::safeOutput($filters['date_from']).'">
            <input type="hidden" name="order_date_to" value="'.Tools::safeOutput($filters['date_to']).'">
            <input type="hidden" name="order_page" value="'.(int) $result['page'].'">
            <table class="table prestacleaner-table">
                <thead><tr>
                    <th><input type="checkbox" onclick="var b=this.form.querySelectorAll(\'.pc-order-cb\');for(var i=0;i<b.length;i++){b[i].checked=this.checked;}"></th>
                    <th>'.$this->trans('ID', [], 'Modules.Prestacleaner.Admin').'</th>
                    <th>'.$this->trans('Reference', [], 'Modules.Prestacleaner.Admin').'</th>
                    <th>'.$this->trans('Date', [], 'Modules.Prestacleaner.Admin').'</th>
                    <th>'.$this->trans('Customer', [], 'Modules.Prestacleaner.Admin').'</th>
                    <th>'.$this->trans('Status', [], 'Modules.Prestacleaner.Admin').'</th>
                    <th>'.$this->trans('Total', [], 'Modules.Prestacleaner.Admin').'</th>
                </tr></thead>
                <tbody>';

        foreach ($result['rows'] as $row) {
            $html .= '<tr>
                <td><input type="checkbox" class="pc-order-cb" name="order_ids[]" value="'.(int) $row['id_order'].'"></td>
                <td>#'.(int) $row['id_order'].'</td>
                <td>'.Tools::safeOutput($row['reference']).'</td>
                <td>'.Tools::safeOutput($row['date_add']).'</td>
                <td>'.Tools::safeOutput(trim((string) $row['customer_name'])).'<br><small>'.Tools::safeOutput((string) $row['email']).'</small></td>
                <td>'.Tools::safeOutput((string) $row['status']).'</td>
                <td>'.$this->formatOrderPrice($row['total_paid'], $row['id_currency']).'</td>
            </tr>';
        }

        $html .= '</tbody></table>'.$this->renderOrderPagination($result, $filters);

        $html .= '<div class="checkbox"><label><input type="checkbox" name="backup_delete_orders" value="1"'.$backupChecked.'> '.$this->trans('Back up the selected orders first', [], 'Modules.Prestacleaner.Admin').'</label></div>
            <div class="checkbox"><label><input type="checkbox" name="confirm_delete_orders" value="1"> '.$this->trans('I understand the checked orders will be permanently deleted', [], 'Modules.Prestacleaner.Admin').'</label></div>
            <button type="submit" name="submitPreviewDeleteOrders" value="1" class="btn btn-default">'.$this->trans('Preview selected (no changes)', [], 'Modules.Prestacleaner.Admin').'</button>
            <button type="submit" name="submitApplyDeleteOrders" value="1" class="btn btn-danger" onclick="return confirm(\''.addslashes($this->trans('Delete the checked orders permanently?', [], 'Modules.Prestacleaner.Admin')).'\');">'.$this->trans('Delete selected', [], 'Modules.Prestacleaner.Admin').'</button>
        </form>';

        $html .= $this->renderPreviewTable('delete_orders');

        return $html.'</div></div>';
    }

    protected function renderOrderPagination(array $result, array $filters)
    {
        if ($result['pages'] <= 1) {
            return '';
        }

        $base = $this->getConfigureUrl()
            .'&order_search='.urlencode($filters['search'])
            .'&order_status='.(int) $filters['status']
            .'&order_date_from='.urlencode($filters['date_from'])
            .'&order_date_to='.urlencode($filters['date_to']);

        $html = '<p>';
        if ($result['page'] > 1) {
            $html .= '<a class="btn btn-default btn-xs" href="'.$base.'&order_page='.($result['page'] - 1).'">'.$this->trans('Previous', [], 'Modules.Prestacleaner.Admin').'</a> ';
        }
        $html .= sprintf($this->trans('Page %d of %d', [], 'Modules.Prestacleaner.Admin'), $result['page'], $result['pages']);
        if ($result['page'] < $result['pages']) {
            $html .= ' <a class="btn btn-default btn-xs" href="'.$base.'&order_page='.($result['page'] + 1).'">'.$this->trans('Next', [], 'Modules.Prestacleaner.Admin').'</a>';
        }

        return $html.'</p>';
    }

    protected function renderPreviewDeleteOrders()
    {
        $orderIds = (array) Tools::getValue('order_ids', []);
        $sanitized = self::sanitizeOrderIds($orderIds);

        if (!$sanitized) {
            return $this->alert('danger', $this->trans('Select at least one order first.', [], 'Modules.Prestacleaner.Admin'));
        }

        $rows = self::previewDeleteOrders($orderIds);
        $this->previewResults['delete_orders'] = $rows;
        $total = array_sum(array_column($rows, 'count'));

        return $this->alert('info', sprintf(
            $this->trans('Preview complete: deleting %d order(s) would remove %d row(s) across %d table(s). Nothing has been changed yet.', [], 'Modules.Prestacleaner.Admin'),
            count($sanitized), $total, count($rows)
        ));
    }

    protected function processApplyDeleteOrders()
    {
        $orderIds = self::sanitizeOrderIds(Tools::getValue('order_ids', []));

        if (!$orderIds) {
            return $this->alert('danger', $this->trans('Select at least one order first. Nothing was deleted.', [], 'Modules.Prestacleaner.Admin'));
        }
        if (!Tools::getValue('confirm_delete_orders')) {
            return $this->alert('danger', $this->trans('Tick "I understand..." to confirm. Nothing was deleted.', [], 'Modules.Prestacleaner.Admin'));
        }

        $backup = (bool) Tools::getValue('backup_delete_orders', 0);
        $result = self::runDeleteOrders($orderIds, $backup);

        $message = sprintf($this->trans('%d order(s) permanently deleted.', [], 'Modules.Prestacleaner.Admin'), $result['deleted']);
        if ($result['backup_file']) {
            $message .= ' '.sprintf($this->trans('A backup was saved as %s (Advanced Parameters > DB Backup).', [], 'Modules.Prestacleaner.Admin'), '<code>'.Tools::safeOutput($result['backup_file']).'</code>');
        } elseif ($backup) {
            $message .= ' '.$this->trans('The backup could not be created - please check disk space and permissions.', [], 'Modules.Prestacleaner.Admin');
        }

        return $this->alert('warning', $message);
    }
}
