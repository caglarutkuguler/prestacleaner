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
 * 3.1.0 -> 3.2.0
 *
 * Registers the new displayDashboardTop hook so shops already running an
 * earlier 3.x install get the store-health widget without a reinstall.
 */
function upgrade_module_3_2_0($module)
{
    if (!$module->isRegisteredInHook('displayDashboardTop')) {
        return $module->registerHook('displayDashboardTop');
    }

    return true;
}
