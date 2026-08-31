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

// --- parseMapping -----------------------------------------------------------

$m = ConceptEnrichment::parseMapping("@FHIR-LOOKUP='fsn:dx_fsn, 363698007:dx_site'", 'dx');
assertSame(array('fsn' => 'dx_fsn', '363698007' => 'dx_site'), $m, 'parseMapping: basic');

$m = ConceptEnrichment::parseMapping("@FHIR-LOOKUP='FSN:a' @FHIR-LOOKUP='pt:b'", 'dx');
assertSame(array('fsn' => 'a', 'pt' => 'b'), $m, 'parseMapping: repeatable tag, case-insensitive');

$m = ConceptEnrichment::parseMapping("@FHIR-LOOKUP='fsn:a, garbage, bogus:b, :c, d:'", 'dx');
assertSame(array('fsn' => 'a'), $m, 'parseMapping: malformed entries skipped individually');

$m = ConceptEnrichment::parseMapping("@FHIR-LOOKUP='fsn:first, fsn:second'", 'dx');
assertSame(array('fsn' => 'second'), $m, 'parseMapping: duplicate source, last wins');

$m = ConceptEnrichment::parseMapping("@FHIR-LOOKUP='fsn:dx'", 'dx');
assertSame(array(), $m, 'parseMapping: refuses target equal to source field');

$m = ConceptEnrichment::parseMapping("@HIDECHOICE='123,456'", 'dx');
assertSame(array(), $m, 'parseMapping: ignores unrelated annotations');

$m = ConceptEnrichment::parseMapping("@FHIR-LOOKUP='  fsn : dx_fsn  ,  116676008 : dx_morph '", 'dx');
assertSame(array('fsn' => 'dx_fsn', '116676008' => 'dx_morph'), $m, 'parseMapping: whitespace tolerated');

assertSame(array(), ConceptEnrichment::parseMapping(null, 'dx'), 'parseMapping: null annotation');

// --- extractProperties ------------------------------------------------------

$p = ConceptEnrichment::extractProperties(fixture('233604007'));
assertTrue($p['found'], 'extractProperties: pneumonia found');
assertSame('Pneumonia (disorder)', $p['fsn'], 'extractProperties: FSN');
assertSame('Pneumonia', $p['pt'], 'extractProperties: preferred term');
assertSame('disorder', $p['semtag'], 'extractProperties: semantic tag');
assertSame('active', $p['status'], 'extractProperties: status active');
assertTrue(false !== strpos($p['normalform'], '363698007'), 'extractProperties: normalForm present');

$p = ConceptEnrichment::extractProperties(fixture('ontoserver-dialect'));
assertSame('Pneumonia (disorder)', $p['fsn'], 'extractProperties: Ontoserver FSN');
assertSame('active', $p['status'], 'extractProperties: Ontoserver status');
assertTrue(false !== strpos($p['normalform'], '=== 128601007'), 'extractProperties: Ontoserver normalForm via "value" part');

$p = ConceptEnrichment::extractProperties(fixture('194848007'));
assertSame('inactive', $p['status'], 'extractProperties: inactive concept');
assertSame(null, $p['normalform'], 'extractProperties: inactive concept has no normalForm');
assertSame('Atherosclerosis (disorder)', $p['fsn'], 'extractProperties: inactive concept still has FSN');

$p = ConceptEnrichment::extractProperties(fixture('racecar'));
assertSame(false, $p['found'], 'extractProperties: OperationOutcome is not found');

$p = ConceptEnrichment::extractProperties(array('nonsense' => 1));
assertSame(false, $p['found'], 'extractProperties: malformed input');

echo "\n";
if ($GLOBALS['tests_failed'] > 0) {
    echo "FAILED ({$GLOBALS['tests_failed']} failed, {$GLOBALS['tests_passed']} passed)\n";
    exit(1);
}
echo "OK ({$GLOBALS['tests_passed']} assertions)\n";
exit(0);
