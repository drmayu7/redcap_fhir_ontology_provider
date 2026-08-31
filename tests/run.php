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

// --- parseNormalForm --------------------------------------------------------

function nf($fixtureName)
{
    $p = ConceptEnrichment::extractProperties(fixture($fixtureName));
    return ConceptEnrichment::parseNormalForm($p['normalform']);
}

$a = nf('233604007');
assertSame(array('113255004|Structure of parenchyma of lung (body structure)'),
    $a['363698007'], 'parseNormalForm: pneumonia finding site');
assertSame(array('409774005|Inflammatory morphology (morphologic abnormality)'),
    $a['116676008'], 'parseNormalForm: pneumonia morphology');
assertCount(3, $a, 'parseNormalForm: pneumonia has 3 attributes');

$a = nf('174041007');
assertCount(3, $a['260686004'], 'parseNormalForm: lap appendectomy has 3 methods');
assertSame('129304002|Excision - action (qualifier value)', $a['260686004'][0],
    'parseNormalForm: first method in document order');
assertCount(3, $a['405813007'], 'parseNormalForm: lap appendectomy has 3 procedure sites');

$a = nf('322236009');
assertSame(array('500'), $a['1142135004'], 'parseNormalForm: concrete strength value');
assertSame(array('1'), $a['1142139005'], 'parseNormalForm: concrete count value');
assertSame(array('387517004|Paracetamol (substance)'), $a['762949000'],
    'parseNormalForm: precise active ingredient');

$a = nf('ontoserver-dialect');
assertSame(array('113255004|Structure of parenchyma of lung'), $a['363698007'],
    'parseNormalForm: nested refinement yields focus concept');
assertSame(array('182353008|Side'), $a['272741003'],
    'parseNormalForm: nested attribute surfaces independently');

assertSame(array(), ConceptEnrichment::parseNormalForm(null), 'parseNormalForm: null');
assertSame(array(), ConceptEnrichment::parseNormalForm('   '), 'parseNormalForm: blank');

// --- buildTargets -----------------------------------------------------------

$map = array('fsn' => 'dx_fsn', 'semtag' => 'dx_tag', 'status' => 'dx_status',
             '363698007' => 'dx_site', '260686004' => 'dx_method');

$t = ConceptEnrichment::buildTargets(fixture('233604007'), $map);
assertSame('Pneumonia (disorder)', $t['dx_fsn'], 'buildTargets: FSN written');
assertSame('disorder', $t['dx_tag'], 'buildTargets: semantic tag written');
assertSame('active', $t['dx_status'], 'buildTargets: status written');
assertSame('113255004|Structure of parenchyma of lung (body structure)', $t['dx_site'],
    'buildTargets: mapped attribute written');
assertSame('', $t['dx_method'], 'buildTargets: attribute absent on this concept is blanked');

$t = ConceptEnrichment::buildTargets(fixture('174041007'), $map);
assertSame('129304002|Excision - action (qualifier value); '
         . '129433002|Inspection - action (qualifier value); '
         . '129287005|Incision - action (qualifier value)', $t['dx_method'],
    'buildTargets: repeated attribute joined with separator');

$t = ConceptEnrichment::buildTargets(fixture('194848007'), $map);
assertSame('inactive', $t['dx_status'], 'buildTargets: inactive status written');
assertSame('Atherosclerosis (disorder)', $t['dx_fsn'], 'buildTargets: inactive FSN written');
assertTrue(!array_key_exists('dx_site', $t),
    'buildTargets: inactive concept leaves attribute targets untouched');
assertTrue(!array_key_exists('dx_method', $t),
    'buildTargets: inactive concept leaves all attribute targets untouched');

$t = ConceptEnrichment::buildTargets(fixture('racecar'), $map);
assertSame(array(), $t, 'buildTargets: not-found response writes nothing');

$t = ConceptEnrichment::buildTargets(fixture('233604007'), array('normalform' => 'dx_nf'));
assertTrue(false !== strpos($t['dx_nf'], '363698007'), 'buildTargets: normalform stored verbatim');

echo "\n";
if ($GLOBALS['tests_failed'] > 0) {
    echo "FAILED ({$GLOBALS['tests_failed']} failed, {$GLOBALS['tests_passed']} passed)\n";
    exit(1);
}
echo "OK ({$GLOBALS['tests_passed']} assertions)\n";
exit(0);
