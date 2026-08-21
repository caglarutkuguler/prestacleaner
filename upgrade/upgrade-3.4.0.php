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
 * 3.3.1 -> 3.4.0
 *
 * 3.4.0 adds the review-request line on the configure page, timed from an
 * installed-at value that only fresh installs write. Existing installations
 * have no install date and never will, so their clock starts here, at the
 * upgrade: they get asked 21 days from now, which is the honest reading of
 * "we do not know when they installed". Keeps an existing value untouched,
 * so running twice cannot reset anyone's clock.
 */
function upgrade_module_3_4_0($module)
{
    require_once dirname(__FILE__) . '/../classes/MegVentureReviewNudge.php';

    return MegVentureReviewNudge::ensureInstalledAt();
}
