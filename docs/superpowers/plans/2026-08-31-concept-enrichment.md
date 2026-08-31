# Concept Enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a user picks a SNOMED concept in a REDCap ontology field, populate other nominated fields with its fully specified name, preferred term, semantic tag, status, and attribute relationships, fetched via `CodeSystem/$lookup`.

**Architecture:** All parsing logic goes into a new dependency-free class, `ConceptEnrichment`, which performs no I/O and touches no REDCap API — making it the first testable unit in this repository. The existing module file gains only thin wiring: an extracted annotation reader, an HTTP method that becomes the fourth circuit-breaker entry point, a `redcap_save_record` hook that is authoritative, and a JavaScript preview that is deliberately not load-bearing. A new authenticated AJAX service mirrors `FindValueSetService.php`.

**Tech Stack:** PHP (min 5.4), REDCap External Modules framework v1, jQuery/jQuery-UI (supplied by REDCap), FHIR R4 `CodeSystem/$lookup` against self-hosted Snowstorm (SNOMED CT International, release 2025-11-01).

**Spec:** `docs/superpowers/specs/2026-08-31-concept-enrichment-design.md`

## Global Constraints

Every task must respect these. They are binding.

1. **PHP 5.4 compatibility.** `config.json` declares `"php-version-min": "5.4.0"`. The null-coalescing operator `??` (PHP 7+) MUST NOT be used anywhere. Use `isset($x) ? $x : $default`. Short array syntax `[]` is PHP 5.4 and is fine.
2. **Do not bump `framework-version`.** It is `1`. If a change appears to require framework v2+, STOP and report rather than bumping it.
3. **`$lookup` contains a `$`.** In PHP, `"/CodeSystem/$lookup?"` in **double quotes** interpolates the undefined variable `$lookup` and silently yields `/CodeSystem/?`. Always use single quotes (`'/CodeSystem/$lookup?'`) or escape it (`"\$lookup"`), exactly as `searchOntology()` already does with `"\$expand"`. `php -l` will NOT catch this.
4. **Designer/data-entry JS lives inside PHP heredocs.** `<<<EOD ... EOD;` blocks interpolate `$name`. `$(` is safe (a `(` cannot begin a PHP identifier) but any new JS variable written as `$foo` WILL be eaten. Declare JS locals as `var foo`. Review every `$` you add — `php -l` will not catch it.
5. **Four tasks edit the same 1,106-line file.** `FhirOntologyAutocompleteExternalModule.php` is modified by Tasks 6, 7, 9, and 10. These are strictly serialized via `blockedBy`. Do NOT parallelize them — quoted "before" snippets assume all prior tasks have landed.
6. **No REDCap instance exists.** Nothing may be verified by "install it and see". Tasks 1–5 are fully verifiable locally via `php tests/run.php`. Tasks 6–11 are verifiable only by `php -l`, JSON validation, and code review. Say so plainly; do not claim runtime behaviour works.
7. **Degradation must not fabricate or erase.** A successful lookup rewrites every mapped target including blanking absent ones; a failed lookup writes nothing at all. This is the spec's governing rule and no task may weaken it.
8. **Report actual output.** Never claim a verification passed without pasting the command output. `php -l` output is required evidence for every task touching PHP.
9. **Commit per task.** Each task ends with its own commit on branch `concept-enrichment-v0.6.0`.

**User decisions (already made):**

- Enriched data **persists into other fields**, not the ontology field, and is intended to be `@READONLY` — "persisted data for analysis/export but not to be stored in the same field as snomed|system, but easily pipeable to other field (maybe with @readonly action tag)".
- Properties required: **FSN + preferred term, semantic tag / concept status, attribute relationships**. Parents / `is-a` explicitly NOT required.
- Attributes map **named attribute → named field**, not a single blob field.
- Attribute values stored as **`code|display`**.
- Trigger is **both** JS-on-select **and** a save-hook backstop — "Both: JS on select + save-hook backstop".
- Mapping declared via an **action tag on the ontology field**, following `@HIDECHOICE`.
- Repeated attributes: **join all distinct values** with `; `.
- Display text kept **verbatim**, including SNOMED semantic tags — "Keep verbatim".
- `normalform` is a mappable keyword; `; ` is the separator — "keep normalform, semicolon separator is fine".
- A `404 / not-found` **leaves targets untouched** rather than clearing them — "yes, leave it untouched".
- Terminology server is `https://api-test.mysnomed.com/fhir`, Basic auth, **SNOMED CT International only** — no MY extension exists yet.

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `tests/run.php` | Plain-PHP test runner: assertion helpers, fixture loading, exit code | 1, 2, 3, 4, 5 |
| `tests/fixtures/*.json` | 12 captured `$lookup` responses used as the regression corpus | 1 |
| `ConceptEnrichment.php` | **All parsing logic.** No HTTP, no REDCap, no framework. Mapping parse, property extraction, `normalForm` parse, target assembly | 2, 3, 4, 5 |
| `ConceptLookupService.php` | Authenticated AJAX entry point. Validates params, dispatches to the module, no logic of its own | 8 |
| `FhirOntologyAutocompleteExternalModule.php` | Thin wiring only: annotation reader, HTTP + breaker + cache, save hook, JS injection | 6, 7, 9, 10 |
| `config.json` | Manifest: adds `redcap_save_record` permission | 9 |
| `README.md`, `CLAUDE.md` | Admin reference and agent guidance | 11 |

---

### Task 1: Test harness and fixture corpus

**Goal:** Create the repository's first automated verification — a dependency-free PHP test runner and the 12 captured `$lookup` responses that later tasks assert against.

**Files:**
- Create: `tests/run.php`
- Create: `tests/fixtures/*.json` (12 files)

**Acceptance Criteria:**
- [ ] `tests/fixtures/` contains 9 `Parameters` responses, 2 `OperationOutcome` 404 responses, and 1 hand-written Ontoserver-dialect response
- [ ] `tests/run.php` provides `assertSame`, `assertTrue`, `assertCount` helpers that record pass/fail
- [ ] `tests/run.php` exits 0 when all assertions pass and 1 when any fail
- [ ] Running the suite prints a summary line with counts
- [ ] No composer, no PHPUnit, no external dependency is introduced

**Verify:** `php tests/run.php` → prints `12 fixtures loaded` and `OK (N assertions)`, exit code 0

**Steps:**

- [ ] **Step 1: Capture the fixtures from the live server**

Credentials come from the gitignored `.env`. Run from the repository root:

```bash
mkdir -p tests/fixtures
set -a && . .env && set +a
# the last two codes do not exist; they capture the 404 OperationOutcome fixtures
for code in 233604007 80146002 232717009 5913000 21522001 194848007 174041007 22298006 322236009 \
            428191000124101 racecar; do
  curl -sS -m 45 -u "$SNOWSTORM_BASIC_AUTH_USERNAME:$SNOWSTORM_BASIC_AUTH_PASSWORD" \
    -H 'Accept: application/fhir+json' -H 'User-Agent: Redcap' \
    "$SNOWSTORM_FHIR_URL/CodeSystem/\$lookup?system=http%3A%2F%2Fsnomed.info%2Fsct&code=$code&property=normalForm&property=inactive&property=designation" \
    -o "tests/fixtures/$code.json"
done
ls tests/fixtures/ | wc -l   # expect 11
```

- [ ] **Step 2: Add the hand-written Ontoserver-dialect fixture**

This exercises the alternative response shape: `"name":"value"` part naming, a `===` prefix, no spaces, and a nested refinement. It is NOT a content reference — `tx.ontoserver.csiro.au` serves the SNOMED CT Australian edition.

```bash
cat > tests/fixtures/ontoserver-dialect.json <<'JSON'
{
  "resourceType": "Parameters",
  "parameter": [
    {"name": "code", "valueCode": "233604007"},
    {"name": "display", "valueString": "Pneumonia"},
    {"name": "designation", "part": [
      {"name": "use", "valueCoding": {"system": "http://snomed.info/sct", "code": "900000000000003001", "display": "Fully specified name"}},
      {"name": "value", "valueString": "Pneumonia (disorder)"}
    ]},
    {"name": "property", "part": [
      {"name": "code", "valueCode": "inactive"},
      {"name": "value", "valueBoolean": false}
    ]},
    {"name": "property", "part": [
      {"name": "code", "valueCode": "normalForm"},
      {"name": "value", "valueString": "=== 128601007|Infectious disease of lung|+205237003|Pneumonitis|:{116676008|Associated morphology|=409774005|Inflammatory morphology|,363698007|Finding site|=(113255004|Structure of parenchyma of lung|:272741003|Laterality|=182353008|Side|),370135005|Pathological process|=441862004|Infectious process|}"}
    ]}
  ]
}
JSON
ls tests/fixtures/ | wc -l   # expect 12
```

- [ ] **Step 3: Write the test runner**

```php
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
```

- [ ] **Step 4: Create the placeholder class so the runner loads**

`tests/run.php` requires `ConceptEnrichment.php`, which Task 2 fills in. Create the shell now so the harness runs:

```php
<?php
namespace AEHRC\FhirOntologyAutocompleteExternalModule;

/**
 * Pure concept-enrichment logic: no HTTP, no REDCap, no External Modules
 * framework. Everything here is deterministic and unit-testable, which is
 * why it lives outside the module class.
 */
class ConceptEnrichment
{
    /** SNOMED CT designation type id for Fully Specified Name. */
    const FSN_DESIGNATION_CODE = '900000000000003001';

    /** Separator used when one attribute has several distinct values. */
    const MULTI_VALUE_SEPARATOR = '; ';

    /** Keywords accepted on the left-hand side of an @FHIR-LOOKUP entry. */
    private static $keywords = array('fsn', 'pt', 'semtag', 'status', 'normalform');
}
```

- [ ] **Step 5: Verify**

Run: `php -l ConceptEnrichment.php && php tests/run.php`
Expected: `No syntax errors detected`, then `12 fixtures loaded`, then `OK (0 assertions)`, exit code 0.

- [ ] **Step 6: Commit**

```bash
git add tests/ ConceptEnrichment.php
git commit -m "test: add PHP test harness and \$lookup fixture corpus"
```

---

### Task 2: `parseMapping()` — read the `@FHIR-LOOKUP` action tag

**Goal:** Turn a field annotation string into a `source => target_field` map, skipping malformed entries individually rather than voiding the whole mapping.

**Files:**
- Modify: `ConceptEnrichment.php`
- Modify: `tests/run.php`

**Acceptance Criteria:**
- [ ] `@FHIR-LOOKUP='fsn:dx_fsn, 363698007:dx_site'` yields `array('fsn' => 'dx_fsn', '363698007' => 'dx_site')`
- [ ] The tag is repeatable and entries from multiple tags merge
- [ ] Keywords are case-insensitive; unknown keywords are skipped
- [ ] A malformed entry is skipped individually, leaving valid siblings intact
- [ ] Duplicate sources resolve last-wins
- [ ] A target equal to the source field is refused
- [ ] An annotation containing only `@HIDECHOICE` yields an empty map

**Verify:** `php tests/run.php` → `OK`, exit code 0

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php` above the summary block:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL with `Call to undefined method ... ::parseMapping()`

- [ ] **Step 3: Implement**

Add to `ConceptEnrichment`:

```php
    /**
     * Parse @FHIR-LOOKUP entries out of a field annotation.
     *
     * The tag mirrors @HIDECHOICE: single-quoted, comma separated, repeatable.
     * A malformed entry is skipped on its own - one typo must not silently
     * void an entire mapping.
     *
     * @param string $annotation the field_annotation text
     * @param string $sourceField the ontology field the tag is written on
     * @return array source => target field name
     */
    public static function parseMapping($annotation, $sourceField)
    {
        $mapping = array();
        if (!is_string($annotation) || '' === trim($annotation)) {
            return $mapping;
        }
        $offset = 0;
        while (preg_match("/@FHIR-LOOKUP='([^']*)'/i", $annotation, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            foreach (explode(',', $matches[1][0]) as $entry) {
                $parts = explode(':', $entry);
                if (2 !== count($parts)) {
                    continue;
                }
                $source = trim($parts[0]);
                $target = trim($parts[1]);
                if ('' === $source || '' === $target) {
                    continue;
                }
                // REDCap field names are lowercase alphanumeric plus underscore
                if (!preg_match('/^[a-z0-9_]+$/', $target)) {
                    continue;
                }
                // never let a mapping clobber the stored code|system value
                if ($target === $sourceField) {
                    continue;
                }
                // (string) cast is required: PHP converts numeric array keys to
                // int, and ctype_digit() reads an int in 48..255 as an ASCII
                // codepoint rather than as digits. It also emits a deprecation
                // notice on PHP 8 for every int argument.
                if (ctype_digit((string)$source)) {
                    $key = $source;
                } else {
                    $key = strtolower($source);
                    if (!in_array($key, self::$keywords, true)) {
                        continue;
                    }
                }
                $mapping[$key] = $target;   // last wins
            }
            $offset = $matches[0][1] + strlen($matches[0][0]);
        }
        return $mapping;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -l ConceptEnrichment.php && php tests/run.php`
Expected: `No syntax errors detected`, `OK (8 assertions)`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add ConceptEnrichment.php tests/run.php
git commit -m "feat: parse @FHIR-LOOKUP action tag into a field mapping"
```

---

### Task 3: `extractProperties()` — read FSN, PT, semantic tag, status, normalForm

**Goal:** Pull the scalar properties out of a decoded `$lookup` response, handling both server dialects and treating FSN as optional.

**Files:**
- Modify: `ConceptEnrichment.php`
- Modify: `tests/run.php`

**Acceptance Criteria:**
- [ ] Snowstorm fixtures yield the correct FSN, PT, semantic tag, and `active` status
- [ ] The Ontoserver-dialect fixture yields the same values despite `"name":"value"` part naming
- [ ] `194848007` (Atherosclerosis) yields status `inactive` and a null `normalform`
- [ ] Semantic tag is derived from the FSN's trailing parenthesis (`Pneumonia (disorder)` → `disorder`)
- [ ] A response with no `parameter` key sets `found` to false
- [ ] A 404 `OperationOutcome` fixture sets `found` to false

**Verify:** `php tests/run.php` → `OK`, exit code 0

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL with `Call to undefined method ... ::extractProperties()`

- [ ] **Step 3: Implement**

Add to `ConceptEnrichment`:

```php
    /**
     * Return the first value* member of a Parameters part, whatever its type.
     * Servers disagree here: Snowstorm names the member "valueString" and the
     * part "valueString"; Ontoserver names the part "value". Accept both.
     */
    private static function partValue($part)
    {
        foreach ($part as $key => $value) {
            if (0 === strpos($key, 'value')) {
                return $value;
            }
        }
        return null;
    }

    /** SNOMED semantic tag is the final parenthesised group of the FSN. */
    private static function semanticTag($fsn)
    {
        if (is_string($fsn) && preg_match('/\(([^()]*)\)\s*$/', $fsn, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extract the scalar properties from a decoded CodeSystem/$lookup response.
     *
     * 'found' is false for anything that is not a Parameters resource - notably
     * the 404 OperationOutcome returned for an unknown code. Callers must treat
     * found=false as "write nothing", never as "the concept has no properties".
     *
     * @param array $decoded json_decode($response, true)
     * @return array with keys found, fsn, pt, semtag, status, normalform
     */
    public static function extractProperties($decoded)
    {
        $out = array('found' => false, 'fsn' => null, 'pt' => null,
                     'semtag' => null, 'status' => null, 'normalform' => null);
        if (!is_array($decoded) || !isset($decoded['parameter']) || !is_array($decoded['parameter'])) {
            return $out;
        }
        $out['found'] = true;
        $inactive = null;
        foreach ($decoded['parameter'] as $param) {
            if (!isset($param['name'])) {
                continue;
            }
            $name = $param['name'];
            if ('display' === $name && isset($param['valueString'])) {
                $out['pt'] = $param['valueString'];
            } elseif ('inactive' === $name) {
                // Snowstorm also reports this as a top-level parameter
                $inactive = self::partValue($param);
            } elseif ('designation' === $name && isset($param['part'])) {
                $use = null;
                $value = null;
                foreach ($param['part'] as $sub) {
                    if (!isset($sub['name'])) {
                        continue;
                    }
                    if ('use' === $sub['name'] && isset($sub['valueCoding']['code'])) {
                        $use = $sub['valueCoding']['code'];
                    } elseif ('value' === $sub['name'] && isset($sub['valueString'])) {
                        $value = $sub['valueString'];
                    }
                }
                if (self::FSN_DESIGNATION_CODE === $use && null !== $value) {
                    $out['fsn'] = $value;
                }
            } elseif ('property' === $name && isset($param['part'])) {
                $code = null;
                $value = null;
                foreach ($param['part'] as $sub) {
                    if (!isset($sub['name'])) {
                        continue;
                    }
                    if ('code' === $sub['name']) {
                        $code = self::partValue($sub);
                    } elseif (0 === strpos($sub['name'], 'value')) {
                        $value = self::partValue($sub);
                    }
                }
                if ('normalForm' === $code && is_string($value)) {
                    $out['normalform'] = $value;
                } elseif ('inactive' === $code && null === $inactive) {
                    $inactive = $value;
                }
            }
        }
        $out['semtag'] = self::semanticTag($out['fsn']);
        if (null !== $inactive) {
            $truthy = (true === $inactive || 1 === $inactive || 'true' === $inactive || '1' === $inactive);
            $out['status'] = $truthy ? 'inactive' : 'active';
        }
        return $out;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -l ConceptEnrichment.php && php tests/run.php`
Expected: `No syntax errors detected`, `OK (22 assertions)`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add ConceptEnrichment.php tests/run.php
git commit -m "feat: extract FSN, PT, semantic tag and status from \$lookup responses"
```

---

### Task 4: `parseNormalForm()` — extract attribute relationships

**Goal:** Pull `attribute => values` out of a SNOMED normal form, covering multiple role groups, ungrouped attributes, concrete values, and nested refinements.

**Files:**
- Modify: `ConceptEnrichment.php`
- Modify: `tests/run.php`

**Acceptance Criteria:**
- [ ] Pneumonia yields Finding site, Associated morphology, and Pathological process as `code|display` pairs
- [ ] Laparoscopic emergency appendectomy yields three distinct values for `260686004` (Method), in document order
- [ ] Paracetamol yields concrete values `500` and `1` for the strength attributes
- [ ] The nested Ontoserver refinement yields the focus concept for Finding site AND surfaces `272741003` (Laterality) as its own attribute
- [ ] Null or empty input yields an empty array
- [ ] Values are deduplicated within an attribute

**Verify:** `php tests/run.php` → `OK`, exit code 0

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL with `Call to undefined method ... ::parseNormalForm()`

- [ ] **Step 3: Implement**

Add to `ConceptEnrichment`:

```php
    /**
     * Extract attribute relationships from a SNOMED normal form.
     *
     * This is a targeted extractor, NOT a Compositional Grammar parser - the
     * only question asked is "for attribute X, what are its values". Role group
     * structure is deliberately flattened; the requirement is to join all
     * distinct values of an attribute, not to preserve which group each came
     * from.
     *
     * Handles both server dialects (Ontoserver's "===" prefix and unspaced
     * operators, Snowstorm's spaced form) and three value shapes: a concept
     * reference, a nested refinement (the focus concept is taken), and a
     * concrete value (#500 or "text"). Concrete values matter: dropping them
     * silently makes a drug concept look like it has no strength.
     *
     * @param string $normalForm
     * @return array attribute SCTID => array of distinct values, document order
     */
    public static function parseNormalForm($normalForm)
    {
        $out = array();
        if (!is_string($normalForm) || '' === trim($normalForm)) {
            return $out;
        }
        $pattern = '/(\d+)\s*\|[^|]*\|\s*=\s*(?:\(?\s*(\d+)\s*\|([^|]*)\||#([0-9.]+)|"([^"]*)")/';
        if (!preg_match_all($pattern, $normalForm, $matches, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($matches as $hit) {
            $attribute = $hit[1];
            if (isset($hit[2]) && '' !== $hit[2]) {
                $value = $hit[2] . '|' . trim($hit[3]);
            } elseif (isset($hit[4]) && '' !== $hit[4]) {
                $value = $hit[4];          // concrete number, e.g. 500
            } elseif (isset($hit[5])) {
                $value = $hit[5];          // concrete string
            } else {
                continue;
            }
            if (!isset($out[$attribute])) {
                $out[$attribute] = array();
            }
            if (!in_array($value, $out[$attribute], true)) {
                $out[$attribute][] = $value;
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -l ConceptEnrichment.php && php tests/run.php`
Expected: `No syntax errors detected`, `OK (35 assertions)`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add ConceptEnrichment.php tests/run.php
git commit -m "feat: extract SNOMED attribute relationships from normalForm"
```

---

### Task 5: `buildTargets()` — assemble the field writes

**Goal:** Combine a mapping and a `$lookup` response into `target_field => value`, enforcing the governing rule that success blanks absent values while inactive concepts leave attribute targets untouched.

**Files:**
- Modify: `ConceptEnrichment.php`
- Modify: `tests/run.php`

**Acceptance Criteria:**
- [ ] A pneumonia mapping produces FSN, semantic tag, status, and the mapped attribute values
- [ ] An attribute the concept does not have is written as an empty string (blanked, not skipped)
- [ ] For an inactive concept with no `normalForm`, attribute targets are **absent from the result** while `fsn`/`pt`/`semtag`/`status` are present
- [ ] Repeated attributes are joined with `; `
- [ ] A `found=false` response yields an empty array so the caller writes nothing
- [ ] `normalform` is mappable and stored verbatim

**Verify:** `php tests/run.php` → `OK`, exit code 0

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL with `Call to undefined method ... ::buildTargets()`

- [ ] **Step 3: Implement**

Add to `ConceptEnrichment`:

```php
    /**
     * Build the set of field writes for a concept.
     *
     * Governing rule: a successful lookup rewrites every mapped target,
     * INCLUDING blanking ones the concept does not have - otherwise changing a
     * field from Pneumonia to Appendectomy would leave a lung structure behind.
     *
     * The one exception is an inactive concept, which carries no normal form.
     * Absence of a normal form is not evidence of absent attributes, so
     * attribute targets are omitted from the result entirely and the caller
     * leaves whatever is already stored alone.
     *
     * An empty result means "write nothing" and is returned for any response
     * that is not a Parameters resource.
     *
     * @param array $decoded json_decode($response, true)
     * @param array $mapping from parseMapping()
     * @return array target field name => value to write
     */
    public static function buildTargets($decoded, $mapping)
    {
        $targets = array();
        $props = self::extractProperties($decoded);
        if (!$props['found']) {
            return $targets;
        }
        $hasNormalForm = (is_string($props['normalform']) && '' !== trim($props['normalform']));
        $attributes = $hasNormalForm ? self::parseNormalForm($props['normalform']) : array();
        foreach ($mapping as $source => $field) {
            // (string) cast is required here too - $source arrives as an int
            // because PHP converts numeric array keys. See parseMapping().
            if (ctype_digit((string)$source)) {
                if (!$hasNormalForm) {
                    // inactive or undefined concept - leave the target as it is
                    continue;
                }
                $targets[$field] = isset($attributes[$source])
                    ? implode(self::MULTI_VALUE_SEPARATOR, $attributes[$source])
                    : '';
            } else {
                $value = isset($props[$source]) ? $props[$source] : null;
                $targets[$field] = (null === $value) ? '' : $value;
            }
        }
        return $targets;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -l ConceptEnrichment.php && php tests/run.php`
Expected: `No syntax errors detected`, `OK (47 assertions)`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add ConceptEnrichment.php tests/run.php
git commit -m "feat: assemble target field writes from mapping and lookup response"
```

---

### Task 6: Extract `getFieldAnnotation()` from `getHideChoice()`

**Goal:** Make the annotation reader reusable by the save-hook path, which has no `$_GET`, without disturbing the two v0.5.1 performance fixes it contains.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php:329-376`

**Acceptance Criteria:**
- [ ] A new `getFieldAnnotation($project_id, $field)` returns the annotation string or null
- [ ] It retains `global $Proj` — without it every call falls through to a full `getDataDictionary()`
- [ ] It retains the `isset($Proj->metadata[$field])` gate, NOT a gate on `field_annotation` existing
- [ ] It retains the `$Proj->project_id === $project_id` trust check
- [ ] `getHideChoice()` produces identical results and keeps its per-request static cache
- [ ] `php -l` passes

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php` → `No syntax errors detected`

**Steps:**

- [ ] **Step 1: Read the current implementation**

Run: `sed -n '329,376p' FhirOntologyAutocompleteExternalModule.php`

The two performance fixes that MUST survive are documented in `CLAUDE.md`: the `global $Proj` declaration, and gating the in-memory fast path on `isset($Proj->metadata[$field])` rather than on `field_annotation` being non-null (it is NULL for un-annotated fields, which is the common case).

- [ ] **Step 2: Add the extracted method**

Insert immediately before `getHideChoice()`:

```php
    /**
     * Fetch a field's annotation text, preferring the in-memory project object.
     *
     * Extracted from getHideChoice() so the save-record path can reuse it -
     * that path has no $_GET to read the field name from.
     *
     * @param int|string|null $project_id
     * @param string $field
     * @return string|null the annotation, or null when unavailable
     */
    public function getFieldAnnotation($project_id, $field)
    {
        // $Proj must be pulled in explicitly. Without this it is always null
        // inside the method, so the in-memory path below never runs and every
        // single keystroke falls through to a full getDataDictionary() call.
        global $Proj;
        // one lookup per request per field - autocomplete fires on every keystroke
        static $cache = array();

        $cacheKey = $project_id . '|' . $field;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }
        $annotation = null;
        if (($project_id === null || (isset($Proj->project_id) && (string)$Proj->project_id === (string)$project_id))
                && isset($Proj->metadata[$field])) {
            // field_annotation is NULL for un-annotated fields, which is the
            // common case - take the in-memory path on field presence, not on
            // the annotation existing, or every un-annotated field falls back
            // to a full dictionary load
            $annotation = isset($Proj->metadata[$field]['field_annotation'])
                ? $Proj->metadata[$field]['field_annotation']
                : null;
        } elseif ($project_id !== null) {
            $dd_array = \REDCap::getDataDictionary($project_id, 'array', false, array($field));
            $annotation = isset($dd_array[$field]['field_annotation'])
                ? $dd_array[$field]['field_annotation']
                : null;
        }
        $cache[$cacheKey] = $annotation;
        return $annotation;
    }
```

- [ ] **Step 3: Rewrite `getHideChoice()` to use it**

Replace the whole body of `getHideChoice()` with:

```php
    function getHideChoice()
    {
        static $cache = array();

        $codesToHide = [];
        if (isset($_GET['field'])) {
            $field = $_GET['field'];
            $project_id = isset($_GET['pid']) ? $_GET['pid'] : null;
            $cacheKey = $project_id . '|' . $field;
            if (isset($cache[$cacheKey])) {
                return $cache[$cacheKey];
            }
            $annotations = $this->getFieldAnnotation($project_id, $field);
            if ($annotations) {
                $offset = 0;
                while (preg_match("/@HIDECHOICE='([^']*)'/", $annotations, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
                    $listedCodesStr = $matches[1][0];
                    $listedCodes = explode(',', $listedCodesStr);
                    foreach ($listedCodes as $code) {
                        array_push($codesToHide, trim($code));
                    }
                    $offset = $matches[0][1] + strlen($matches[0][0]);
                }
            }
            $cache[$cacheKey] = $codesToHide;
        }

        return $codesToHide;
    }
```

- [ ] **Step 4: Verify**

Run: `php -l FhirOntologyAutocompleteExternalModule.php`
Expected: `No syntax errors detected in FhirOntologyAutocompleteExternalModule.php`

Then confirm by inspection that `global $Proj` appears exactly once (now in `getFieldAnnotation`), and that the metadata gate is on `isset($Proj->metadata[$field])`:

```bash
grep -n 'global \$Proj' FhirOntologyAutocompleteExternalModule.php
grep -n 'isset($Proj->metadata\[$field\])' FhirOntologyAutocompleteExternalModule.php
```
Expected: one hit each.

- [ ] **Step 5: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "refactor: extract getFieldAnnotation() for reuse by the save path"
```

---

### Task 7: `lookupConcept()` — HTTP, breaker, and session cache

**Goal:** Add the fourth FHIR entry point: fetch a concept's `$lookup` response, guarded by the circuit breaker and cached per-session.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (add method near `getValueSetInfo()`, ~line 855; add `require_once` after the `use` statements, ~line 29)

**Acceptance Criteria:**
- [ ] `ConceptEnrichment.php` is loaded via `require_once` (framework v1 does not autoload module classes)
- [ ] The URL uses a **single-quoted** `'/CodeSystem/$lookup?'` so PHP does not interpolate `$lookup`
- [ ] `property=normalForm`, `property=inactive`, and `property=designation` are all requested
- [ ] Returns `false` when the breaker is open, without making an HTTP call
- [ ] Calls `recordFhirFailureIfSlow()` on failure and `recordFhirSuccess()` on success, mirroring `getValueSetInfo()`
- [ ] A response that is not a `Parameters` resource (e.g. the 404 `OperationOutcome`) returns `false`
- [ ] Successful responses are cached in `$_SESSION` keyed by `code|system`
- [ ] `php -l` passes

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php` → `No syntax errors detected`, plus the grep checks in Step 4

**Steps:**

- [ ] **Step 1: Load the new class**

After the existing `use` statements (currently line 27–28), add:

```php
require_once __DIR__ . '/ConceptEnrichment.php';
```

- [ ] **Step 2: Add the session cache key constant**

Alongside the existing constants (after `BREAKER_OPEN_SECONDS`, line 39):

```php
    /** $_SESSION key holding per-user cached $lookup responses. */
    const LOOKUP_CACHE_SESSION_KEY = 'fhir_concept_lookup_cache';
```

- [ ] **Step 3: Add `lookupConcept()` immediately after `getValueSetInfo()`**

```php
    /**
     * Fetch a concept's properties via CodeSystem/$lookup.
     *
     * This is the fourth FHIR entry point and is wrapped by the circuit breaker
     * here rather than inside httpGet(), so OAuth2 token negotiation against a
     * different host is not trapped by it.
     *
     * Returns false for every failure mode - breaker open, timeout, transport
     * error, and a 404 OperationOutcome for an unknown code. Callers MUST treat
     * false as "write nothing"; it never means "the concept has no properties".
     *
     * @param string $code   e.g. 233604007
     * @param string $system e.g. http://snomed.info/sct
     * @return array|false decoded Parameters resource, or false
     */
    public function lookupConcept($code, $system)
    {
        if (!is_string($code) || '' === $code || !is_string($system) || '' === $system) {
            return false;
        }
        $cacheKey = $code . '|' . $system;
        if (isset($_SESSION[self::LOOKUP_CACHE_SESSION_KEY][$cacheKey])) {
            return $_SESSION[self::LOOKUP_CACHE_SESSION_KEY][$cacheKey];
        }
        if ($this->isCircuitOpen()) {
            // Server has failed repeatedly - fail fast rather than tying up a
            // web server process on a request we already expect to time out.
            return false;
        }
        // NOTE: single quotes are required. In double quotes PHP would
        // interpolate the undefined variable $lookup and silently produce
        // "/CodeSystem/?". php -l does not catch this.
        $url = $this->getFhirServerUri() . '/CodeSystem/$lookup?'
             . http_build_query(array('system' => $system, 'code' => $code))
             . '&property=normalForm&property=inactive&property=designation';

        $headers = ['User-Agent: Redcap'];
        $authHeader = $this->getAuthHeader();
        if ($authHeader !== false) {
            $headers[] = $authHeader;
        }
        $startedAt = microtime(true);
        $response = $this->httpGet($url, $headers);
        if ($response === false) {
            $this->recordFhirFailureIfSlow(microtime(true) - $startedAt);
            return false;
        }
        $this->recordFhirSuccess();

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['parameter'])) {
            // an OperationOutcome (unknown code) or unparseable body - a fast,
            // definitive answer, so recordFhirFailureIfSlow above correctly
            // leaves the breaker alone
            return false;
        }
        if (!isset($_SESSION[self::LOOKUP_CACHE_SESSION_KEY])) {
            $_SESSION[self::LOOKUP_CACHE_SESSION_KEY] = array();
        }
        $_SESSION[self::LOOKUP_CACHE_SESSION_KEY][$cacheKey] = $decoded;
        return $decoded;
    }
```

- [ ] **Step 4: Verify**

Run: `php -l FhirOntologyAutocompleteExternalModule.php`
Expected: `No syntax errors detected in FhirOntologyAutocompleteExternalModule.php`

Then verify the `$lookup` interpolation trap was avoided:

```bash
grep -n 'CodeSystem/\$lookup' FhirOntologyAutocompleteExternalModule.php
```
Expected: one hit, and the surrounding quotes must be **single** quotes. If the line uses double quotes, this is a bug `php -l` cannot see — fix it before committing.

- [ ] **Step 5: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "feat: add lookupConcept() as the fourth FHIR entry point"
```

---

### Task 8: `ConceptLookupService.php` — authenticated AJAX endpoint

**Goal:** Give the browser a way to request enrichment values for a selected concept, mirroring `FindValueSetService.php` exactly.

**Files:**
- Create: `ConceptLookupService.php`

**Depends on Task 9.** This service calls `$module->getEnrichmentTargets()`, which Task 9
adds to the module class. Task 9 must land first even though it is numbered later — it is
sequenced with the other module-file tasks (6, 7, 9, 10), which are serialized among
themselves. See the dependency graph below.

**Acceptance Criteria:**
- [ ] The file is NOT added to `no-auth-pages` in `config.json` — authentication is required
- [ ] Requires `field` and `value` parameters; missing ones produce the same 400 error shape as `FindValueSetService.php`
- [ ] A `value` not matching `code|system` produces a 400
- [ ] On lookup failure it returns HTTP 502 with an `OperationOutcome`, matching the existing error contract
- [ ] On success it returns a JSON object of `target_field => value`
- [ ] `php -l` passes

**Verify:** `php -l ConceptLookupService.php` → `No syntax errors detected`

**Steps:**

- [ ] **Step 1: Create the service**

The `$module` variable is injected by the External Modules framework, exactly as in `FindValueSetService.php`.

```php
<?php
/**
 * AJAX entry point for concept enrichment from the data entry / survey pages.
 *
 * Deliberately NOT listed in config.json "no-auth-pages" - it proxies to an
 * internal terminology server and must require an authenticated REDCap session,
 * for the same reason FindValueSetService.php does.
 *
 * Requests go browser -> REDCap -> FHIR server, never browser -> FHIR server,
 * which keeps credentials server-side and works behind a proxy.
 */

$sendErrorResponse = function ($error, $error_description) {
    $errorArr = ['error' => $error, 'error_description' => $error_description];
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode($errorArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
};

header('Content-type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ('POST' === $method) {
    $params = $_POST;
} elseif ('GET' === $method) {
    $params = $_GET;
} else {
    $sendErrorResponse('Invalid Method', 'Request method must be GET or POST!');
}

if (!isset($params['field']) || '' === $params['field']) {
    $sendErrorResponse('Invalid Request', 'Missing required parameter "field".');
}
if (!isset($params['value']) || '' === $params['value']) {
    $sendErrorResponse('Invalid Request', 'Missing required parameter "value".');
}

$field = $params['field'];
$value = $params['value'];
$project_id = isset($params['pid']) ? $params['pid'] : (isset($_GET['pid']) ? $_GET['pid'] : null);

// stored values are code|system - display was dropped in v0.5 because
// code|display|system exceeded REDCap's 100 character limit
$parts = explode('|', $value);
if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
    $sendErrorResponse('Invalid Request', 'Parameter "value" must be of the form code|system.');
}

$targets = $module->getEnrichmentTargets($project_id, $field, $parts[0], $parts[1]);

if ($targets === false) {
    // breaker open, server did not answer, or the code is unknown. Report it
    // as an OperationOutcome so the caller can distinguish this from an
    // empty-but-successful result and leave existing values alone.
    http_response_code(502);
    echo json_encode(['resourceType' => 'OperationOutcome',
        'issue' => [['severity' => 'error', 'code' => 'timeout',
            'diagnostics' => 'The terminology server did not return concept details.']]],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

exit();
```

- [ ] **Step 2: Verify**

Run: `php -l ConceptLookupService.php`
Expected: `No syntax errors detected in ConceptLookupService.php`

Confirm the endpoint is not exempted from auth:

```bash
grep -n 'no-auth-pages' config.json || echo "no no-auth-pages block: correct"
```
Expected: `no no-auth-pages block: correct` (v0.5.1 removed it).

- [ ] **Step 3: Commit**

```bash
git add ConceptLookupService.php
git commit -m "feat: add authenticated concept lookup AJAX service"
```

---

### Task 9: `getEnrichmentTargets()`, `redcap_save_record`, and the manifest

**Goal:** Add the authoritative server-side path that computes and writes target fields on every save, covering data entry, imports, API writes, and surveys.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (add methods after `lookupConcept()`; add hook after `redcap_survey_page`, ~line 121)
- Modify: `config.json:13-17`

**Acceptance Criteria:**
- [ ] `redcap_save_record` is added to the `permissions` array and `config.json` remains valid JSON
- [ ] `getEnrichmentTargets()` returns `false` on lookup failure and an array on success
- [ ] A `static` re-entry guard prevents `saveData()` from re-triggering the hook
- [ ] Only target fields are written — never the source ontology field
- [ ] Nothing is written when the target set is empty
- [ ] `$event_id` and `$repeat_instance` are passed through so longitudinal and repeating instruments work
- [ ] `php -l` passes and `config.json` validates

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && python3 -c "import json;json.load(open('config.json'));print('valid')"` → `No syntax errors detected`, then `valid`

**Steps:**

- [ ] **Step 1: Add the permission**

Edit `config.json` lines 13–17 to:

```json
  "permissions": [
    "redcap_every_page_before_render",
    "redcap_data_entry_form",
    "redcap_survey_page",
    "redcap_save_record"
  ],
```

- [ ] **Step 2: Add `getEnrichmentTargets()` after `lookupConcept()`**

```php
    /**
     * Compute the field writes for one selected concept.
     *
     * @param int|string|null $project_id
     * @param string $field the ontology field carrying the @FHIR-LOOKUP tag
     * @param string $code
     * @param string $system
     * @return array|false target field => value, or false when nothing may be written
     */
    public function getEnrichmentTargets($project_id, $field, $code, $system)
    {
        $annotation = $this->getFieldAnnotation($project_id, $field);
        $mapping = ConceptEnrichment::parseMapping($annotation, $field);
        if (!$mapping) {
            return array();
        }
        $decoded = $this->lookupConcept($code, $system);
        if ($decoded === false) {
            // breaker open, server down, or unknown code. Returning false rather
            // than an empty array is what stops an outage from blanking
            // previously correct enrichment.
            return false;
        }
        return ConceptEnrichment::buildTargets($decoded, $mapping);
    }
```

- [ ] **Step 3: Add the field discovery helper**

```php
    /**
     * Find every field on an instrument that carries an @FHIR-LOOKUP tag.
     *
     * @param int|string $project_id
     * @param string $instrument
     * @return array field name => mapping array
     */
    public function getEnrichmentFields($project_id, $instrument)
    {
        global $Proj;
        $found = array();
        $metadata = null;
        if (isset($Proj->project_id) && (string)$Proj->project_id === (string)$project_id
                && isset($Proj->metadata)) {
            $metadata = $Proj->metadata;
        }
        if (null === $metadata) {
            $metadata = \REDCap::getDataDictionary($project_id, 'array', false, null, array($instrument));
        }
        foreach ($metadata as $fieldName => $attrs) {
            if (isset($attrs['form_name']) && $attrs['form_name'] !== $instrument) {
                continue;
            }
            $annotation = isset($attrs['field_annotation']) ? $attrs['field_annotation'] : null;
            if (!$annotation || false === stripos($annotation, '@FHIR-LOOKUP')) {
                continue;
            }
            $mapping = ConceptEnrichment::parseMapping($annotation, $fieldName);
            if ($mapping) {
                $found[$fieldName] = $mapping;
            }
        }
        return $found;
    }
```

- [ ] **Step 4: Add the hook after `redcap_survey_page()`**

```php
    /**
     * Authoritative enrichment path.
     *
     * The browser-side preview added to the data entry and survey pages is
     * deliberately NOT load-bearing: @READONLY fields may render as disabled,
     * and disabled inputs are not submitted. Recomputing here means the stored
     * values are correct regardless of what the DOM did, and it also covers
     * data imports and API writes, which run no JavaScript at all.
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id,
                                       $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        // saveData() below can re-enter this hook. Guard rather than recurse.
        static $inProgress = false;
        if ($inProgress) {
            return;
        }

        $fields = $this->getEnrichmentFields($project_id, $instrument);
        if (!$fields) {
            return;
        }

        $sourceFields = array_keys($fields);
        $data = \REDCap::getData(array(
            'project_id' => $project_id,
            'records' => $record,
            'events' => $event_id,
            'fields' => $sourceFields,
            'return_format' => 'array'
        ));

        $writes = array();
        foreach ($fields as $field => $mapping) {
            $stored = $this->extractSavedValue($data, $record, $event_id, $field, $repeat_instance);
            if (null === $stored || '' === $stored) {
                // concept cleared - blank every target it maps to
                foreach ($mapping as $target) {
                    $writes[$target] = '';
                }
                continue;
            }
            $parts = explode('|', $stored);
            if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                continue;
            }
            $targets = $this->getEnrichmentTargets($project_id, $field, $parts[0], $parts[1]);
            if ($targets === false) {
                // must not fabricate and must not erase - leave everything alone
                continue;
            }
            foreach ($targets as $target => $value) {
                $writes[$target] = $value;
            }
        }

        if (!$writes) {
            return;
        }

        // REDCap's 'array' format is [record][event_id][field], with repeating
        // instruments nested under a 'repeat_instances' key instead.
        if ($repeat_instance) {
            $payload = array($record => array(
                'repeat_instances' => array(
                    $event_id => array(
                        $instrument => array($repeat_instance => $writes)
                    )
                )
            ));
        } else {
            $payload = array($record => array($event_id => $writes));
        }

        $inProgress = true;
        $result = \REDCap::saveData($project_id, 'array', $payload);
        $inProgress = false;

        return $result;
    }

    /** Pull one field's saved value out of a REDCap::getData() array result. */
    private function extractSavedValue($data, $record, $event_id, $field, $repeat_instance)
    {
        if (!isset($data[$record])) {
            return null;
        }
        $recordData = $data[$record];
        if ($repeat_instance && isset($recordData['repeat_instances'][$event_id])) {
            foreach ($recordData['repeat_instances'][$event_id] as $instances) {
                if (isset($instances[$repeat_instance][$field])) {
                    return $instances[$repeat_instance][$field];
                }
            }
        }
        if (isset($recordData[$event_id][$field])) {
            return $recordData[$event_id][$field];
        }
        return null;
    }
```

- [ ] **Step 5: Verify**

Run:
```bash
php -l FhirOntologyAutocompleteExternalModule.php
python3 -c "import json;json.load(open('config.json'));print('valid')"
grep -n 'redcap_save_record' config.json
```
Expected: `No syntax errors detected`, `valid`, and one hit in the permissions array.

**Report honestly:** `php -l` proves syntax only. The `getData()` array shape for repeating instruments, the `saveData()` payload shape, and whether `saveData()` actually re-enters this hook are all **unverified** — there is no REDCap instance. Say so in the commit and to the user rather than claiming the hook works.

- [ ] **Step 6: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php config.json
git commit -m "feat: add authoritative redcap_save_record enrichment path

Syntax verified only; getData/saveData shapes are unverified without a
REDCap instance."
```

---

### Task 10: Browser-side preview

**Goal:** Fill target fields immediately when a user picks a concept, so enrichment is visible before save — while remaining safe to fail.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php:55-121` (both display hooks)

**Acceptance Criteria:**
- [ ] The JS is added to both `redcap_data_entry_form` and `redcap_survey_page`
- [ ] It binds to BOTH `autocompleteselect` and `change` on `input.autosug-ont-field`
- [ ] It POSTs to `ConceptLookupService.php` via `$module->getUrl()`
- [ ] A non-200 response is ignored — existing field values are left alone
- [ ] Every JS local is declared `var`, never `$foo`, because the code sits in a PHP heredoc
- [ ] The existing tooltip behaviour is preserved unchanged
- [ ] `php -l` passes

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php` → `No syntax errors detected`, plus the heredoc grep in Step 3

**Steps:**

- [ ] **Step 1: Add a shared JS builder method**

Both hooks currently duplicate the same heredoc. Add one private method and have both call it. Insert before `redcap_data_entry_form()`:

```php
    /**
     * JavaScript shared by the data entry and survey pages.
     *
     * CAUTION: this string is built with a heredoc, so PHP interpolates $name.
     * Every JavaScript local below is declared with `var` for that reason - a
     * variable written as $foo would be silently eaten by PHP and php -l would
     * not report it.
     */
    private function getDataEntryJavascript()
    {
        $serviceUrl = $this->getUrl('ConceptLookupService.php');
        $tooltip = $this->getSystemSetting('add_value_tooltip') ? 'true' : 'false';
        return <<<EOD
<script type="text/javascript">
      // IIFE - Immediately Invoked Function Expression
      (function($, window, document) {
          // The $ is now locally scoped
          var serviceUrl = '{$serviceUrl}';
          var showTooltip = {$tooltip};

          if (showTooltip) {
              // this is a bit of a hack, if redcap change their code it will break
              // it looks for all input fields tagged as autosug-ont-field
              // which should mean they are an ontology lookup and adds
              // a hover function which will set the fields title to match
              // its value.
              $('input.autosug-ont-field').each(function(){
                  $( this ).hover(function(){
                      $( this ).attr('title', $( this ).val());
                      return true;
                  });
              });
          }

          function applyEnrichment(field, value) {
              if (!value || value.indexOf('|') < 0) {
                  return;
              }
              $.ajax({
                  url: serviceUrl,
                  type: 'POST',
                  dataType: 'json',
                  data: { field: field, value: value }
              }).done(function(targets) {
                  if (!targets) { return; }
                  // a 502 OperationOutcome must never overwrite stored values
                  if (targets.resourceType === 'OperationOutcome') { return; }
                  for (var name in targets) {
                      if (!targets.hasOwnProperty(name)) { continue; }
                      var input = $('[name="' + name + '"]');
                      if (input.length) {
                          input.val(targets[name]).trigger('change');
                      }
                  }
              });
              // a failed request deliberately does nothing - the save hook is
              // authoritative and will recompute server-side
          }

          function fieldNameOf(el) {
              var raw = $(el).attr('name') || $(el).attr('id') || '';
              return raw.replace(/^__/, '').replace(/-autosuggest$/, '');
          }

          // Bind to both events: jQuery UI fires autocompleteselect, but the
          // field can also be set by paste or by browser autofill. A missed
          // event is harmless because the save hook recomputes anyway.
          $('input.autosug-ont-field')
              .on('autocompleteselect', function(event, ui) {
                  var self = this;
                  var picked = (ui && ui.item) ? ui.item.value : $(this).val();
                  window.setTimeout(function() {
                      applyEnrichment(fieldNameOf(self), picked);
                  }, 0);
              })
              .on('change', function() {
                  applyEnrichment(fieldNameOf(this), $(this).val());
              });

      }(window.jQuery, window, document));
      // The global jQuery object is passed as a parameter
</script>
EOD;
    }
```

- [ ] **Step 2: Replace both hook bodies**

`redcap_data_entry_form()` becomes:

```php
    public function redcap_data_entry_form($project_id, $record,
                                           $instrument, $event_id, $group_id, $repeat_instance)
    {
        print($this->getDataEntryJavascript());
    }
```

`redcap_survey_page()` becomes:

```php
    public function redcap_survey_page($project_id, $record,
                                       $instrument, $event_id, $group_id, $survey_hash, $response_id,
                                       $repeat_instance)
    {
        print($this->getDataEntryJavascript());
    }
```

Note the behaviour change: the tooltip is now gated *inside* the JS rather than by skipping output entirely, because the enrichment binding must run whether or not tooltips are enabled.

- [ ] **Step 3: Verify**

Run: `php -l FhirOntologyAutocompleteExternalModule.php`
Expected: `No syntax errors detected in FhirOntologyAutocompleteExternalModule.php`

Then check for the heredoc interpolation trap — every `$` in the new block must be either `$(` (safe) or inside `{$php_var}` (intentional):

```bash
sed -n '/private function getDataEntryJavascript/,/^EOD;/p' FhirOntologyAutocompleteExternalModule.php \
  | grep -nE '\$[A-Za-z_]' | grep -v '{\$'
```
Expected: **no output.** Any hit is a JavaScript variable PHP will eat — fix it before committing.

**Report honestly:** the `autocompleteselect` event name, the `autosug-ont-field` class, and the input `name` shape are all inferred from the existing tooltip hack, which itself carries the comment *"if redcap change their code it will break"*. None of this is verified.

- [ ] **Step 4: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "feat: add browser-side enrichment preview on concept selection

Deliberately non-load-bearing; the save hook remains authoritative.
Event binding is unverified without a REDCap instance."
```

---

### Task 11: Documentation

**Goal:** Make the feature usable by an administrator and keep `CLAUDE.md` honest about what is now testable.

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`

**Acceptance Criteria:**
- [ ] `README.md` documents `@FHIR-LOOKUP` with a worked example and the full source table
- [ ] `README.md` recommends Notes Box for attribute targets and `@READONLY` for all targets
- [ ] `README.md` gains a v0.6.0 changelog section
- [ ] `README.md` states that a failed lookup leaves fields untouched
- [ ] `CLAUDE.md`'s "No build, no tests" section is rewritten — tests now exist for `ConceptEnrichment` only
- [ ] `CLAUDE.md` documents the `$lookup` double-quote interpolation trap
- [ ] Neither file claims runtime behaviour is verified

**Verify:** `grep -c 'FHIR-LOOKUP' README.md` → at least 3; `grep -c 'tests/run.php' CLAUDE.md` → at least 1

**Steps:**

- [ ] **Step 1: Add the `@FHIR-LOOKUP` section to `README.md`**

Place it immediately after the existing `@HIDECHOICE` documentation:

````markdown
### `@FHIR-LOOKUP` — populate other fields from the selected concept

Add this to the **Field Annotation** of an ontology field to copy details of the
selected concept into other fields on the same instrument.

```
@FHIR-LOOKUP='fsn:dx_fsn, semtag:dx_semtag, status:dx_status,
              363698007:dx_finding_site, 116676008:dx_morphology'
```

Each entry is `source:target_field`.

| Source | What is written | Example |
|---|---|---|
| `fsn` | Fully specified name | `Pneumonia (disorder)` |
| `pt` | Preferred term | `Pneumonia` |
| `semtag` | Semantic tag from the FSN | `disorder` |
| `status` | Concept status | `active`, `inactive`, `unknown` |
| `normalform` | The SNOMED normal form, verbatim | `128601007\|Infectious disease of lung\|…` |
| a SNOMED attribute id | That attribute's value as `code\|display` | `113255004\|Structure of parenchyma of lung (body structure)` |

Notes:

- The tag is repeatable, so long mappings can be split across several tags.
- A malformed entry is skipped on its own; valid entries around it still work.
- Target fields should carry `@READONLY` so users cannot edit values the
  terminology server supplied.
- Use a **Notes Box** for attribute targets. When a concept has an attribute
  more than once — most surgical procedures do — every distinct value is stored,
  joined with `; `, which can exceed 200 characters.
- When a concept is **inactive** the terminology server returns no normal form.
  `fsn`, `pt`, `semtag` and `status` are still written; attribute targets are
  left exactly as they were.
- If the terminology server is unreachable, or the code is not in the server's
  edition, **nothing is written and existing values are left alone.** Enrichment
  never blanks a field because of an outage.
````

- [ ] **Step 2: Add the v0.6.0 changelog section to `README.md`**

Above the existing v0.5.2 section:

```markdown
### v0.6.0

- **New: concept enrichment via `CodeSystem/$lookup`.** The `@FHIR-LOOKUP` field
  annotation copies the fully specified name, preferred term, semantic tag,
  concept status, normal form, and named SNOMED attribute relationships of the
  selected concept into other fields.
- Values are written both in the browser on selection and again server-side on
  save. The server-side write is authoritative, so imports and API writes are
  enriched too.
- A failed or unavailable lookup writes nothing and never clears existing values.
- Adds the `redcap_save_record` permission.
- Adds `ConceptEnrichment.php`, `ConceptLookupService.php`, and the repository's
  first automated tests.
```

- [ ] **Step 3: Rewrite the `CLAUDE.md` verification section**

Replace the "No build, no tests, no dependencies" section body with:

````markdown
There is no composer, npm, Makefile, or CI. There IS now a small test suite, but
it covers `ConceptEnrichment.php` only — the pure parsing logic with no HTTP and
no REDCap dependency. Everything else still requires a running REDCap instance.

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php -l FindValueSetService.php
php -l ConceptEnrichment.php
php -l ConceptLookupService.php
python3 -c "import json;json.load(open('config.json'));print('valid')"
php tests/run.php
```

`tests/run.php` is plain PHP with hand-rolled assertions — no PHPUnit, no
composer. Fixtures in `tests/fixtures/` are real `$lookup` responses; the
capture command is in the v0.6.0 spec.

Treat everything not covered by `php tests/run.php` as unverifiable locally and
say so rather than asserting it works.
````

- [ ] **Step 4: Add the interpolation trap to `CLAUDE.md`**

In the "Hard constraints" section, after the heredoc bullet:

```markdown
- **`$lookup` and `$expand` contain a `$`.** Inside double quotes PHP
  interpolates them as undefined variables, silently producing `/CodeSystem/?`.
  Use single quotes or `\$`. `php -l` will not catch this.
```

- [ ] **Step 5: Verify**

```bash
grep -c 'FHIR-LOOKUP' README.md
grep -c 'tests/run.php' CLAUDE.md
php tests/run.php
```
Expected: at least 3, at least 1, and `OK (47 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: document @FHIR-LOOKUP, v0.6.0 changelog and the new test suite"
```

---

## Dependency graph

Execution order. Note that **Task 9 runs before Task 8** — the service calls a method the
save-hook task adds. Tasks 6, 7, 9, and 10 all edit the same 1,106-line module file and are
strictly serialized; do not parallelize them.

```
1  Test harness + fixtures
├─ 2  parseMapping()          ─→ 3  extractProperties() ─→ 4  parseNormalForm() ─→ 5  buildTargets()
└─ 6  getFieldAnnotation()                                                             │
                                        7  lookupConcept()  ←──────────────────────────┘
                                        │   (blocked by 5 and 6)
                                        └─→ 9  save hook + manifest
                                            └─→ 8  ConceptLookupService.php
                                                └─→ 10  browser preview
                                                    └─→ 11  documentation
```

Tasks 1–5 are fully verifiable locally. Tasks 6–11 are verifiable only by `php -l`, JSON
validation, and code review.

## Deployment

The version lives in the **directory name**, not in git or `config.json`. To deploy:

```
<redcap-root>/modules/fhir-ontology-provider_v0.6.0/
```

`v0.5.2` stays installed alongside it and remains the rollback — switch between them in
Control Center → External Modules. Unlike the v0.5.1 → v0.5.2 step, no setting changes type,
so **no credential re-entry is required**.

## Post-deployment verification (requires a REDCap instance)

None of this can be done before then. Run in order:

1. Create a test project with an ontology field `dx` bound to a SNOMED value set, plus
   `dx_fsn` (Text), `dx_semtag` (Text), `dx_status` (Text) and `dx_site` (Notes Box), each
   carrying `@READONLY`.
2. Annotate `dx` with `@FHIR-LOOKUP='fsn:dx_fsn, semtag:dx_semtag, status:dx_status, 363698007:dx_site'`.
3. Select **Pneumonia**. Confirm the four fields populate in the browser, then save and
   reload — confirm they survived. This distinguishes a working save hook from a
   preview-only success.
4. Change the concept to **Fracture of neck of femur**. Confirm `dx_site` changes rather
   than retaining the lung structure.
5. Clear `dx`. Confirm all four targets blank.
6. Select **Atherosclerosis** (`194848007`, inactive). Confirm `dx_status` reads `inactive`
   and `dx_site` is left as it was.
7. Point `fhir_api_url` at an unreachable host, save an existing record, and confirm the
   targets are **unchanged** — not blanked. Restore the URL afterwards.
8. Import a record via Data Import Tool with `dx` set. Confirm the targets populate without
   any browser involvement.
9. Select a procedure such as **Laparoscopic emergency appendectomy** with `260686004`
   mapped, and confirm three joined method values are stored intact — this is the check for
   REDCap's field length behaviour.
