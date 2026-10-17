<?php
/**
 * 2019-2026 MEG Venture
 *
 * Plain-PHP test. No PrestaShop bootstrap, no PHPUnit:
 *
 *     php tests/AdsWidgetUrlTest.php
 *
 * Pins the scheme allowlist the promo widget applies to every URL it takes
 * from the remote feed. The widget renders those URLs into href and src
 * attributes on the module's own back-office page, so a feed answering
 * "javascript:..." would put a script-running link in front of the merchant.
 * htmlspecialchars does not catch that - only a scheme check does.
 *
 * @author    MEG Venture & Consulting Ltd. <info@megventure.com>
 * @copyright 2019-2026 MEG Venture
 * @license   https://opensource.org/licenses/MIT MIT License
 */

// Only the CLI harness may run without the shop. These files ship inside
// the module package, so a plain web request to them would otherwise
// execute the harness and print its output to an anonymous visitor.
if (!defined('_PS_VERSION_')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('_PS_VERSION_', 'test');
}

require_once dirname(__FILE__) . '/../classes/MegVentureAdsWidget.php';

$tests = 0;
$failures = 0;

/**
 * @param bool   $condition
 * @param string $label
 */
function ok($condition, $label)
{
    global $tests, $failures;
    ++$tests;
    if (!$condition) {
        ++$failures;
        echo 'FAIL: ' . $label . PHP_EOL;
    }
}

$method = new ReflectionMethod('MegVentureAdsWidget', 'safeUrl');
$method->setAccessible(true);

/**
 * @param mixed $url
 *
 * @return string
 */
function safeUrl($url)
{
    global $method;

    return (string) $method->invoke(null, $url);
}

// What the real feed sends.
ok(safeUrl('https://megventure.com/en/12-modules') === 'https://megventure.com/en/12-modules', 'an https URL passes through unchanged');
ok(safeUrl('http://megventure.com/img/x.jpg') === 'http://megventure.com/img/x.jpg', 'an http URL passes through unchanged');
ok(safeUrl('https://cdn.example.com/a.png?v=2&x=1') === 'https://cdn.example.com/a.png?v=2&x=1', 'a query string is preserved');

// What a compromised or spoofed feed could send.
ok(safeUrl('javascript:alert(1)') === '', 'a javascript: URL is dropped');
ok(safeUrl('JaVaScript:alert(1)') === '', 'scheme matching is case insensitive');
ok(safeUrl("  javascript:alert(1)") === '', 'leading whitespace does not smuggle javascript: through');
ok(safeUrl("java\tscript:alert(1)") === '', 'a control character inside the scheme is dropped');
ok(safeUrl('data:text/html;base64,PHN2Zz4=') === '', 'a data: URL is dropped');
ok(safeUrl('vbscript:msgbox(1)') === '', 'a vbscript: URL is dropped');
ok(safeUrl('file:///etc/passwd') === '', 'a file: URL is dropped');

// No scheme at all is not renderable either: the widget lives in the back
// office, so a relative URL would resolve against the shop, not the feed.
ok(safeUrl('//evil.example/x.png') === '', 'a protocol-relative URL is dropped');
ok(safeUrl('/img/x.png') === '', 'a root-relative URL is dropped');

// Non-strings and emptiness must fail closed, not warn.
ok(safeUrl('') === '', 'an empty string stays empty');
ok(safeUrl(null) === '', 'null becomes an empty string');
ok(safeUrl(array('https://megventure.com')) === '', 'an array becomes an empty string');

echo ($failures === 0 ? 'OK' : 'FAILURES') . ': ' . ($tests - $failures) . '/' . $tests . ' assertions passed' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
