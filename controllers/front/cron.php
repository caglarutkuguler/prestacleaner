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
 * Scheduled-cleaning endpoint for shops with real server-cron access.
 *
 * Called with the token shown on the module's configure page. Only ever
 * runs "Check & fix" and "Clean & optimize" - the two actions that are
 * always safe to run unattended - and never a catalog/sales reset.
 */
class PrestacleanerCronModuleFrontController extends ModuleFrontController
{
    /**
     * Not forcing SSL: on a shop without HTTPS configured, the redirect
     * this would trigger could silently break the merchant's cron job.
     *
     * @var bool
     */
    public $ssl = false;

    const LOCK_KEY = 'PRESTACLEANER_CRON_LOCK';
    const LOCK_TTL = 900;

    public function postProcess()
    {
        $token = Tools::getValue('token');
        $expected = (string) Configuration::get(PrestaCleaner::CONF_CRON_TOKEN);

        if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
            $this->respond(403, ['success' => false, 'error' => 'Invalid token.']);
        }

        if ($this->isLocked()) {
            $this->respond(200, ['success' => false, 'error' => 'A run is already in progress. Skipping.']);
        }

        $this->lock();

        try {
            @set_time_limit(0);
            $result = PrestaCleaner::runScheduledCleanup();
            $this->unlock();

            $this->respond(200, [
                'success' => true,
                'fix_queries' => count($result['fix']),
                'optimize_queries' => count($result['optimize']),
                'ran_at' => $result['ran_at'],
            ]);
        } catch (Throwable $e) {
            $this->unlock();
            PrestaShopLogger::addLog('PrestaCleaner cron: '.$e->getMessage(), 3);
            $this->respond(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function isLocked()
    {
        $lockedAt = (int) Configuration::getGlobalValue(self::LOCK_KEY);

        return $lockedAt > 0 && (time() - $lockedAt) < self::LOCK_TTL;
    }

    private function lock()
    {
        Configuration::updateGlobalValue(self::LOCK_KEY, time());
    }

    private function unlock()
    {
        Configuration::updateGlobalValue(self::LOCK_KEY, 0);
    }

    private function respond($httpCode, array $payload)
    {
        header('HTTP/1.1 '.(int) $httpCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');

        echo json_encode($payload);
        exit;
    }
}
