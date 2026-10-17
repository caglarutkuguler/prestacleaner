<?php
/**
 * Upgrade to 3.5.1 — rotate weak cron tokens to CSPRNG secrets.
 *
 * Merchants who still use a Tools::passwdGen-era cron URL must update the
 * scheduled job after this upgrade (configure page shows the new URL).
 *
 * @param PrestaCleaner $module
 *
 * @return bool
 */
function upgrade_module_3_5_1($module)
{
    if (!class_exists('PrestaCleaner', false)) {
        require_once dirname(__FILE__).'/../prestacleaner.php';
    }

    // Token is stored with updateGlobalValue (CONTEXT_ALL); one rotation covers
    // every shop context that reads the same global key.
    PrestaCleaner::ensureStrongCronToken();

    return true;
}
