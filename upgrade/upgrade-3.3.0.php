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
 * 3.2.0 -> 3.3.0
 *
 * 3.2.0 hooked `displayDashboardTop`, believing it to be specific to the
 * Dashboard page - it's actually rendered by the shared page-header toolbar
 * included on every back-office controller, so the widget showed up
 * everywhere. Moves it to `dashboardZoneOne`, the hook AdminDashboardController
 * actually renders into its own left-hand column, and unregisters the old one.
 */
function upgrade_module_3_3_0($module)
{
    if ($module->isRegisteredInHook('displayDashboardTop')) {
        $module->unregisterHook('displayDashboardTop');
    }

    if (!$module->isRegisteredInHook('dashboardZoneOne')) {
        return $module->registerHook('dashboardZoneOne');
    }

    return true;
}
