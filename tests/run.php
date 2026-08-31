<?php
/**
 * Plain-PHP test runner. No composer, no PHPUnit - the repository has no
 * dependency management and this must stay runnable with only `php`.
 *
 * Usage: php tests/run.php
 */

require_once __DIR__ . '/../ConceptEnrichment.php';

use AEHRC\FhirOntologyAutocompleteExternalModule\ConceptEnrichment;

$GLOBALS['tests_passed'] = 0;
$GLOBALS['tests_failed'] = 0;

function fail_test($label, $detail)
{
    $GLOBALS['tests_failed']++;
    echo "FAIL  $label\n      $detail\n";
}

function pass_test()
{
    $GLOBALS['tests_passed']++;
}

function assertSame($expected, $actual, $label)
{
    if ($expected === $actual) {
        pass_test();
        return;
    }
    fail_test($label, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function assertTrue($actual, $label)
{
    assertSame(true, $actual, $label);
}

function assertCount($expected, $actual, $label)
{
    assertSame($expected, is_array($actual) ? count($actual) : -1, $label);
}

/** Load a captured $lookup response as a decoded array. */
function fixture($name)
{
    $path = __DIR__ . '/fixtures/' . $name . '.json';
    if (!file_exists($path)) {
        throw new \Exception("Missing fixture: $path");
    }
    return json_decode(file_get_contents($path), true);
}

$fixtureCount = count(glob(__DIR__ . '/fixtures/*.json'));
echo "$fixtureCount fixtures loaded\n";
if (12 !== $fixtureCount) {
    echo "FAIL  expected 12 fixtures, found $fixtureCount\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Test cases are appended below by later tasks.
// ---------------------------------------------------------------------------

echo "\n";
if ($GLOBALS['tests_failed'] > 0) {
    echo "FAILED ({$GLOBALS['tests_failed']} failed, {$GLOBALS['tests_passed']} passed)\n";
    exit(1);
}
echo "OK ({$GLOBALS['tests_passed']} assertions)\n";
exit(0);
