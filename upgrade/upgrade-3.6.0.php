<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 3.5.1 -> 3.6.0
 *
 * 3.6.0 adds the "Orphaned cart data" action and its two settings. Fresh
 * installs write them in install(); existing installations get the same
 * defaults here: back up first, and stay out of the scheduled run until the
 * merchant ticks it. A value that already exists is left untouched, so
 * running twice cannot flip a choice the merchant made.
 */
function upgrade_module_3_6_0($module)
{
    if (!class_exists('PrestaCleaner', false)) {
        require_once dirname(__FILE__).'/../prestacleaner.php';
    }

    $ok = true;
    foreach ([
        PrestaCleaner::CONF_BACKUP_BEFORE_CART_ORPHANS => 1,
        PrestaCleaner::CONF_AUTO_CART_ORPHANS => 0,
    ] as $key => $default) {
        if (Configuration::get($key) === false) {
            $ok = Configuration::updateGlobalValue($key, $default) && $ok;
        }
    }

    return $ok;
}
