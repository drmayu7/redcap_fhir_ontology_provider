# PR #5 Review Response Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve the three findings from the maintainer's adversarial review of upstream PR #5, unblock the merge, and make the riskiest change verifiable without a REDCap instance.

**Architecture:** The pure decision logic behind the timeout, the circuit breaker, and URL validation moves into a new dependency-free class, `FhirRequestPolicy`, making it the first testable unit on this branch — the module keeps only the settings I/O and calls into it. The module then stops delegating outbound FHIR requests to REDCap core's `http_get`/`http_post`, which cannot be given a real `CURLOPT_TIMEOUT`, and issues its own curl requests instead.

**Tech Stack:** PHP (min 5.4), REDCap External Modules framework v1, curl, plain-PHP test runner (no composer, no PHPUnit), Psalm (upstream dev dependency, used locally to pre-empt CI).

**Spec:** `docs/superpowers/specs/2026-09-04-pr5-review-response-design.md`

## Global Constraints

Every task must respect these. They are binding.

1. **PHP 5.4 compatibility.** `config.json` declares `"php-version-min": "5.4.0"`. The null-coalescing operator `??` (PHP 7+), `::class` (5.5+), short closures `fn()` (7.4+), typed properties, and spread operators MUST NOT be used. Use `isset($x) ? $x : $default`. Short array syntax `[]` is 5.4 and is fine. **Local PHP is 8.4, so `php -l` will NOT catch a 5.4 violation** — every task touching PHP must eyeball its own diff for these.
2. **Do not bump `framework-version`.** It stays `1`. `php-version-min` stays `5.4.0`, `redcap-version-min` stays `8.8.1`. Framework 16 is a separate follow-on and is out of scope. If a change appears to require framework v2+, STOP and report rather than bumping it.
3. **`$expand` and `$lookup` contain a `$`.** In PHP, `"/ValueSet/$expand?"` in **double quotes** interpolates an undefined variable and silently yields `/ValueSet/?`. Use single quotes or escape it (`"\$expand"`), exactly as `searchOntology()` already does. `php -l` will NOT catch this.
4. **Designer/data-entry JS lives inside PHP heredocs.** `<<<EOD ... EOD;` interpolates `$name`. Any new JS variable written as `$foo` WILL be eaten. Declare JS locals as `var foo`.
5. **No REDCap instance, no staging, no REDCap core source.** Nothing may be verified by "install it and see". Never claim runtime behaviour works. Tasks 2, 4 and 5 are fully verifiable locally via `php tests/run.php`; the rest are verifiable only by `php -l`, Psalm, JSON validation and code review. Say so plainly.
6. **`docs/superpowers/` must never reach `upstream-v0.5.2`.** The fork's branches carry design docs; the branch PR #5 tracks carries module code only. Task 8 enforces this.
7. **Conventional commits.** Upstream lints the PR title with `amannn/action-semantic-pull-request` and derives versions with release-please. Every commit message uses a conventional prefix (`fix:`, `test:`, `docs:`, `refactor:`, `chore:`).
8. **Commit per task** on branch `pr5-review-response`.
9. **Report actual output.** Never claim a verification passed without pasting the command output.

**User decisions (already made):**

- Fix all three findings properly, on framework 1 — "Fix all 3 properly".
- Framework 16 stays a separate follow-on, not bundled into this PR.
- Circuit breaker: correct the overstated claim, do NOT add locking — "Soften the claim".
- Port the plain-PHP test harness onto this PR — "Yes, port it".
- The reply to the maintainer was reviewed and posted on 2026-09-04 as [comment 5538545851](https://github.com/aehrc/redcap_fhir_ontology_provider/pull/5#issuecomment-5538545851).

## Blocked work

**Task 7 is blocked on an external answer** and is the only task that is. The reply asks the maintainer what TLS options REDCap core sets on its curl handles. Guessing wrong breaks every site whose terminology server presents an internal CA certificate, or silently weakens transport security inside a security release.

Tasks 1–6 and 8 do not depend on that answer. If the answer has not arrived by the time Task 6 completes, stop and ask the user to choose between waiting and shipping Tasks 1–6 with the timeout claim narrowed — do not guess the TLS setting.

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `FhirRequestPolicy.php` | **New.** All pure decisions: timeout resolution, breaker state transitions, URL containment. No I/O, no REDCap, no framework. The only testable unit. | 2, 5 |
| `tests/run.php` | **New.** Plain-PHP test runner: assertion helpers, exit code | 2, 5 |
| `FhirOntologyAutocompleteExternalModule.php` | Settings I/O and wiring only. Breaker methods delegate to the policy class; outbound transport becomes module-owned curl | 3, 4, 7 |
| `README.md` | Changelog reframed for release-please; corrected claims | 6, 7 |

---

### Task 1: Rebase onto current upstream main

**Goal:** Make the working branch merge cleanly against upstream, and get Psalm runnable locally so later tasks can pre-empt the CI security scan.

**Files:**
- Modify: `README.md` (conflict resolution only)
- Modify: `config.json` (conflict resolution only)
- Modify: `.gitignore` (conflict resolution only)

**Acceptance Criteria:**
- [ ] An `upstream` remote pointing at `https://github.com/aehrc/redcap_fhir_ontology_provider.git` exists
- [ ] `pr5-review-response` contains every commit from `upstream/main`, including `composer.json`, `psalm.xml`, `stubs/`, `CHANGELOG.md`
- [ ] No conflict markers (`<<<<<<<`, `>>>>>>>`) remain in any file
- [ ] `config.json` still declares `"framework-version": 1`, `"php-version-min": "5.4.0"`, `"redcap-version-min": "8.8.1"`
- [ ] `php -l` is clean on both PHP files and `config.json` parses
- [ ] `vendor/bin/psalm` runs and its findings are recorded as the pre-change baseline

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && php -l FindValueSetService.php && python3 -c "import json;json.load(open('config.json'))" && git log --oneline -1 upstream/main` → all clean, no conflict markers

**Steps:**

- [ ] **Step 1: Add the upstream remote and fetch**

```bash
git remote add upstream https://github.com/aehrc/redcap_fhir_ontology_provider.git
git fetch upstream main
git log --oneline -5 upstream/main
```

Expected: the five most recent upstream commits, newest being the README typo fix (`#17`).

- [ ] **Step 2: Rebase**

```bash
git rebase upstream/main
```

Expected: conflicts in `README.md` (upstream added a CHANGELOG link and fixed typos; we added changelog sections) and possibly `.gitignore` / `config.json`.

- [ ] **Step 3: Resolve conflicts**

Resolution rules, applied per file:

- `README.md` — keep **both** sides. Upstream's CHANGELOG link and typo fixes are kept verbatim; our version changelog sections are kept but will be reworked in Task 6. Do not delete upstream's new content to make the conflict go away.
- `config.json` — keep our settings additions (`fhir_timeout`, the `password` type changes). Confirm `framework-version`, `php-version-min` and `redcap-version-min` are unchanged from upstream's values.
- `.gitignore` — union of both sides.

```bash
grep -rn '<<<<<<<\|>>>>>>>\|=======' README.md config.json .gitignore || echo "no conflict markers"
git add -A
git rebase --continue
```

- [ ] **Step 4: Verify the tree**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php -l FindValueSetService.php
python3 -c "import json;json.load(open('config.json'));print('config.json OK')"
python3 -c "import json;d=json.load(open('config.json'));print(d['framework-version'], d['compatibility'])"
```

Expected: `No syntax errors detected` twice, `config.json OK`, and `1 {'php-version-min': '5.4.0', 'redcap-version-min': '8.8.1'}`.

- [ ] **Step 5: Install dev dependencies and record the Psalm baseline**

```bash
composer install --no-interaction --no-progress
vendor/bin/psalm --taint-analysis --no-progress 2>&1 | tail -30
```

Record the output. This is the **baseline** — Task 7 must not add new `TaintedSSRF` findings relative to it. If Psalm reports pre-existing findings, note them; do not fix them here (out of scope).

- [ ] **Step 6: Commit**

A rebase produces no new commit of its own. Do not amend the spec commit to carry conflict resolutions — the resolutions belong to the commits being replayed. Confirm the tree is clean and correctly based:

```bash
git status --short
git log --oneline -3
```

Expected: clean tree, spec commit sitting on top of upstream's latest.

---

### Task 2: Test harness and the breaker/timeout policy class

**Goal:** Create this branch's first automated verification — a dependency-free PHP test runner — and extract the timeout and circuit-breaker decisions into pure, testable functions.

**Files:**
- Create: `FhirRequestPolicy.php`
- Create: `tests/run.php`

**Acceptance Criteria:**
- [ ] `tests/run.php` provides `assertSame`, `assertTrue`, `assertFalse` helpers that record pass/fail
- [ ] `tests/run.php` exits 0 when all assertions pass and 1 when any fail
- [ ] Running the suite prints a summary line with counts
- [ ] `FhirRequestPolicy::resolveTimeout()` returns the setting when numeric and positive, and the default for blank, zero, negative and non-numeric input
- [ ] `FhirRequestPolicy::countsAsFailure()` is true at and above 80% of the timeout and false below it
- [ ] `FhirRequestPolicy::isOpen()` is false when `openUntil` is 0, true before the window elapses, false at or after it
- [ ] `FhirRequestPolicy::needsRearm()` is true only when a non-zero window has elapsed
- [ ] `FhirRequestPolicy::opensBreaker()` is true at and above the threshold
- [ ] No composer, no PHPUnit, no external dependency is introduced by the harness
- [ ] The file contains no `??`, no `::class`, no `fn()`

**Verify:** `php -l FhirRequestPolicy.php && php tests/run.php` → prints `OK (N assertions)`, exit code 0

**Steps:**

- [ ] **Step 1: Write the failing test**

Create `tests/run.php`:

```php
<?php
/**
 * Plain-PHP test runner. No composer, no PHPUnit - the module must stay
 * installable by copying a directory, so tests carry no dependencies.
 *
 * Usage: php tests/run.php
 */

require_once __DIR__ . '/../FhirRequestPolicy.php';

use AEHRC\FhirOntologyAutocompleteExternalModule\FhirRequestPolicy;

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

function assertFalse($actual, $label)
{
    assertSame(false, $actual, $label);
}

// --- resolveTimeout -------------------------------------------------------

assertSame(30, FhirRequestPolicy::resolveTimeout('30'), 'resolveTimeout: numeric string');
assertSame(30, FhirRequestPolicy::resolveTimeout(30), 'resolveTimeout: integer');
assertSame(10, FhirRequestPolicy::resolveTimeout(''), 'resolveTimeout: blank falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout(null), 'resolveTimeout: null falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout('0'), 'resolveTimeout: zero falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout('-5'), 'resolveTimeout: negative falls back');
assertSame(10, FhirRequestPolicy::resolveTimeout('abc'), 'resolveTimeout: non-numeric falls back');
assertSame(7, FhirRequestPolicy::resolveTimeout('7.9'), 'resolveTimeout: truncates to int');

// --- countsAsFailure ------------------------------------------------------

assertTrue(FhirRequestPolicy::countsAsFailure(8.0, 10), 'countsAsFailure: exactly 80% counts');
assertTrue(FhirRequestPolicy::countsAsFailure(12.0, 10), 'countsAsFailure: over timeout counts');
assertFalse(FhirRequestPolicy::countsAsFailure(0.2, 10), 'countsAsFailure: fast 4xx does not count');
assertFalse(FhirRequestPolicy::countsAsFailure(7.9, 10), 'countsAsFailure: just under threshold');

// --- breaker state --------------------------------------------------------

assertFalse(FhirRequestPolicy::isOpen(0, 1000), 'isOpen: never-opened breaker is closed');
assertTrue(FhirRequestPolicy::isOpen(1060, 1000), 'isOpen: inside the window');
assertFalse(FhirRequestPolicy::isOpen(1000, 1000), 'isOpen: at expiry is not open');
assertFalse(FhirRequestPolicy::isOpen(900, 1000), 'isOpen: after expiry is not open');

assertFalse(FhirRequestPolicy::needsRearm(0, 1000), 'needsRearm: never-opened needs no re-arm');
assertFalse(FhirRequestPolicy::needsRearm(1060, 1000), 'needsRearm: still inside window');
assertTrue(FhirRequestPolicy::needsRearm(1000, 1000), 'needsRearm: at expiry');
assertTrue(FhirRequestPolicy::needsRearm(900, 1000), 'needsRearm: after expiry');

assertSame(1, FhirRequestPolicy::nextFailureCount(0), 'nextFailureCount: from zero');
assertSame(1, FhirRequestPolicy::nextFailureCount(''), 'nextFailureCount: from blank setting');
assertSame(4, FhirRequestPolicy::nextFailureCount(3), 'nextFailureCount: increments');

assertFalse(FhirRequestPolicy::opensBreaker(2), 'opensBreaker: below threshold');
assertTrue(FhirRequestPolicy::opensBreaker(3), 'opensBreaker: at threshold');
assertTrue(FhirRequestPolicy::opensBreaker(4), 'opensBreaker: above threshold');

// --- summary --------------------------------------------------------------

$passed = $GLOBALS['tests_passed'];
$failed = $GLOBALS['tests_failed'];
if ($failed > 0) {
    echo "\nFAILED ($failed failed, $passed passed)\n";
    exit(1);
}
echo "OK ($passed assertions)\n";
exit(0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Failed opening required '.../FhirRequestPolicy.php'`

- [ ] **Step 3: Write minimal implementation**

Create `FhirRequestPolicy.php`. Reuse the licence header from `FhirOntologyAutocompleteExternalModule.php` verbatim (CSIRO BSD/MIT variation) so the new file carries the same licence as the rest of the module.

```php
<?php
/* ... CSIRO licence header copied verbatim from
   FhirOntologyAutocompleteExternalModule.php ... */

namespace AEHRC\FhirOntologyAutocompleteExternalModule;

/**
 * Pure decision logic for outbound FHIR requests. Deliberately free of I/O,
 * REDCap and the External Modules framework, so it can be exercised by
 * tests/run.php without a REDCap instance - the module has no other
 * automated verification.
 *
 * The module keeps the settings reads and writes; this class only decides.
 */
class FhirRequestPolicy
{
    /** Fallback timeout (seconds) used when the 'fhir_timeout' setting is blank or invalid. */
    const DEFAULT_TIMEOUT = 10;
    /** Consecutive failures required before the circuit breaker opens. */
    const BREAKER_FAILURE_THRESHOLD = 3;
    /** How long (seconds) the breaker stays open before allowing a trial request. */
    const BREAKER_OPEN_SECONDS = 60;
    /**
     * Fraction of the timeout a call must consume before it is treated as evidence
     * of server health. A fast rejection (e.g. a malformed valueset url returning
     * 4xx) must not trip a system-wide breaker.
     */
    const SLOW_CALL_RATIO = 0.8;

    public static function resolveTimeout($setting, $default = self::DEFAULT_TIMEOUT)
    {
        if (is_numeric($setting) && (int)$setting > 0) {
            return (int)$setting;
        }
        return $default;
    }

    public static function countsAsFailure($elapsedSeconds, $timeout)
    {
        return $elapsedSeconds >= (self::SLOW_CALL_RATIO * $timeout);
    }

    /** True while the breaker is open and callers should fail fast. */
    public static function isOpen($openUntil, $now)
    {
        return (int)$openUntil > 0 && $now < (int)$openUntil;
    }

    /** True when a window was set but has elapsed, so the caller should re-arm and probe. */
    public static function needsRearm($openUntil, $now)
    {
        return (int)$openUntil > 0 && $now >= (int)$openUntil;
    }

    public static function nextFailureCount($current)
    {
        return (int)$current + 1;
    }

    public static function opensBreaker($failureCount, $threshold = self::BREAKER_FAILURE_THRESHOLD)
    {
        return (int)$failureCount >= $threshold;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -l FhirRequestPolicy.php && php tests/run.php`
Expected: `No syntax errors detected`, then `OK (26 assertions)`, exit code 0

- [ ] **Step 5: Check PHP 5.4 compatibility by eye**

```bash
grep -n '??\|::class\|fn(\|\.\.\.\$' FhirRequestPolicy.php tests/run.php || echo "no PHP 7+ constructs"
```

Expected: `no PHP 7+ constructs`

- [ ] **Step 6: Commit**

```bash
git add FhirRequestPolicy.php tests/run.php
git commit -m "test: add plain-PHP harness and extract FHIR request policy

The module had no automated verification, which the upstream review
called out. FhirRequestPolicy holds the timeout and circuit-breaker
decisions as pure functions so they can be exercised without REDCap."
```

---

### Task 3: Delegate the breaker to the policy class and correct its false claim

**Goal:** Replace the circuit breaker's inline decisions with calls into `FhirRequestPolicy`, and rewrite the docblock that asserts a concurrency guarantee the code does not provide.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (constants near line 34; `getFhirTimeout` ~line 861; `isCircuitOpen` ~line 876; `recordFhirFailure` ~line 892; `recordFhirFailureIfSlow` ~line 906)

**Acceptance Criteria:**
- [ ] `isCircuitOpen()`, `recordFhirFailure()`, `recordFhirFailureIfSlow()` and `getFhirTimeout()` contain no inline arithmetic or comparisons — every decision is a `FhirRequestPolicy::` call
- [ ] The settings reads and writes stay in the module; the policy class performs no I/O
- [ ] The docblock on `isCircuitOpen()` no longer claims "exactly one concurrent request is allowed through"
- [ ] The replacement docblock states that concurrent callers may each admit a probe, and that concurrent failures may undercount
- [ ] External behaviour is unchanged — same thresholds, same window, same 80% rule
- [ ] The module `require_once`s `FhirRequestPolicy.php`
- [ ] `php -l` clean; `php tests/run.php` still exits 0

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && php tests/run.php && grep -c 'exactly one' FhirOntologyAutocompleteExternalModule.php` → no syntax errors, `OK (26 assertions)`, grep count `0`

**Steps:**

- [ ] **Step 1: Require the new class**

Immediately after the `use ExternalModules\ExternalModules;` line near the top of the file, add:

```php
require_once __DIR__ . '/FhirRequestPolicy.php';
```

- [ ] **Step 2: Replace the four methods**

Replace the existing bodies. Before (abridged, current code):

```php
    public function getFhirTimeout()
    {
        $timeout = $this->getSystemSetting('fhir_timeout');
        if (is_numeric($timeout) && (int)$timeout > 0) {
            return (int)$timeout;
        }
        return self::DEFAULT_TIMEOUT;
    }
```

After:

```php
    /**
     * Maximum number of seconds to wait on the FHIR server. Without a limit a slow
     * or unavailable server holds a web server process open for the system default,
     * which can exhaust the pool and take all of REDCap down with it.
     */
    public function getFhirTimeout()
    {
        return FhirRequestPolicy::resolveTimeout($this->getSystemSetting('fhir_timeout'));
    }

    /**
     * True while the breaker is open, i.e. the FHIR server has failed repeatedly and
     * we should fail fast instead of dialing out again.
     *
     * Once the open window elapses a caller that observes it re-arms the window before
     * returning false, so that callers arriving behind it keep failing fast while it
     * probes the server.
     *
     * This is best-effort, not a guarantee. The read and the write are separate
     * round trips to the settings table with no lock between them, so two requests
     * arriving together at the window boundary can both observe the window as expired
     * and both probe. In practice that means normally one probe per window and
     * occasionally a few - which is sufficient, because the breaker exists to prevent
     * a stampede of every worker dialing a dead server, not to serialise probes.
     */
    public function isCircuitOpen()
    {
        $openUntil = $this->getSystemSetting('fhir_breaker_open_until');
        $now = time();
        if (FhirRequestPolicy::isOpen($openUntil, $now)) {
            return true;
        }
        if (FhirRequestPolicy::needsRearm($openUntil, $now)) {
            // Window elapsed. Re-arm before returning so callers behind this one keep
            // failing fast while it probes. recordFhirSuccess() clears both keys when
            // the probe succeeds, so the re-arm costs nothing on recovery.
            $this->setSystemSetting('fhir_breaker_open_until', $now + FhirRequestPolicy::BREAKER_OPEN_SECONDS);
        }
        return false;
    }

    /**
     * Counts a failure and opens the breaker once enough have accumulated.
     *
     * The increment is a read followed by a write with no lock between them, so
     * simultaneous failures can overwrite one another and the count can lag the
     * true number of failures. The effect is that the breaker may open after
     * slightly more than BREAKER_FAILURE_THRESHOLD failures rather than exactly
     * that many. It self-corrects as further failures arrive.
     */
    public function recordFhirFailure()
    {
        $failures = FhirRequestPolicy::nextFailureCount($this->getSystemSetting('fhir_breaker_failures'));
        $this->setSystemSetting('fhir_breaker_failures', $failures);
        if (FhirRequestPolicy::opensBreaker($failures)) {
            $this->setSystemSetting('fhir_breaker_open_until', time() + FhirRequestPolicy::BREAKER_OPEN_SECONDS);
        }
    }

    /**
     * A failure only indicates server health if the call actually hung. A fast
     * rejection (e.g. a malformed valueset url returning 4xx) must not trip the
     * breaker for every other project on the system.
     */
    public function recordFhirFailureIfSlow($elapsedSeconds)
    {
        if (FhirRequestPolicy::countsAsFailure($elapsedSeconds, $this->getFhirTimeout())) {
            $this->recordFhirFailure();
        }
    }
```

- [ ] **Step 3: Remove the now-duplicated constants**

The three constants near line 34 (`DEFAULT_TIMEOUT`, `BREAKER_FAILURE_THRESHOLD`, `BREAKER_OPEN_SECONDS`) now live on `FhirRequestPolicy`. Delete them from the module class and confirm nothing else references them:

```bash
grep -n 'self::DEFAULT_TIMEOUT\|self::BREAKER_' FhirOntologyAutocompleteExternalModule.php || echo "no stale references"
```

Expected: `no stale references`

- [ ] **Step 4: Verify**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php tests/run.php
grep -n 'exactly one' FhirOntologyAutocompleteExternalModule.php || echo "false claim removed"
grep -n '??\|::class\|fn(' FhirOntologyAutocompleteExternalModule.php || echo "no PHP 7+ constructs"
```

Expected: no syntax errors; `OK (26 assertions)`; `false claim removed`; `no PHP 7+ constructs`

- [ ] **Step 5: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "fix: correct the circuit breaker's overstated atomicity claim

isCircuitOpen() documented a guarantee of exactly one trial request per
window. The read and write are separate settings round trips with no
lock, so concurrent callers can each probe, and concurrent failures can
undercount. Behaviour is unchanged; the documentation now matches it.

Decisions move to FhirRequestPolicy so they are covered by tests."
```

---

### Task 4: Remove the dead `getHideChoice()` cache

**Goal:** Delete a `static` cache that is written and never read, and the comment claiming a per-keystroke benefit it cannot deliver.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (`getHideChoice`, ~line 329)

**Acceptance Criteria:**
- [ ] `static $cache`, `$cacheKey`, the cache read and the cache write are gone from `getHideChoice()`
- [ ] `global $Proj` is retained
- [ ] The `$Proj->metadata[$field]` fast path and the `getDataDictionary()` fallback are unchanged
- [ ] The misleading "one lookup per request per field" comment is gone
- [ ] `php -l` clean

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && grep -c 'static $cache' FhirOntologyAutocompleteExternalModule.php` → no syntax errors, grep count `0`

**Steps:**

- [ ] **Step 1: Apply the edit**

Before:

```php
    function getHideChoice()
    {
        // $Proj must be pulled in explicitly. Without this it is always null inside
        // the method, so the in-memory fast path below never runs and every single
        // keystroke falls through to a full getDataDictionary() call.
        global $Proj;
        // one lookup per request per field - autocomplete fires this on every keystroke
        static $cache = array();

        $codesToHide=[];
        if (isset($_GET['field'])){
            $field = $_GET['field'];
            $project_id = isset($_GET['pid']) ? $_GET['pid'] : null;
            $cacheKey = $project_id . '|' . $field;
            if (isset($cache[$cacheKey])) {
                return $cache[$cacheKey];
            }
            $annotations = null;
```

After:

```php
    function getHideChoice()
    {
        // $Proj must be pulled in explicitly. Without this it is always null inside
        // the method, so the in-memory fast path below never runs and every single
        // keystroke falls through to a full getDataDictionary() call.
        global $Proj;

        $codesToHide=[];
        if (isset($_GET['field'])){
            $field = $_GET['field'];
            $project_id = isset($_GET['pid']) ? $_GET['pid'] : null;
            $annotations = null;
```

Then remove the write further down. Before:

```php
            $cache[$cacheKey] = $codesToHide;
        }
```

After:

```php
        }
```

- [ ] **Step 2: Verify**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
grep -n 'cacheKey\|static \$cache' FhirOntologyAutocompleteExternalModule.php || echo "cache fully removed"
grep -n 'global \$Proj' FhirOntologyAutocompleteExternalModule.php
```

Expected: no syntax errors; `cache fully removed`; `global $Proj` still present in `getHideChoice`.

- [ ] **Step 3: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "fix: drop dead static cache in getHideChoice()

getHideChoice() is called once per request, and each autocomplete
keystroke is its own PHP process, so the static was written and never
read. The global \$Proj fast path - the change that actually mattered -
is retained."
```

---

### Task 5: URL containment check

**Goal:** Add a pure function that decides whether a request URL stays within the configured FHIR base, so the module-owned curl in Task 7 has a real defence against SSRF rather than a taint suppression.

**Files:**
- Modify: `FhirRequestPolicy.php`
- Modify: `tests/run.php`

**Acceptance Criteria:**
- [ ] A URL under the configured base is accepted
- [ ] A different host is rejected
- [ ] A scheme change (`https` base, `http` request) is rejected
- [ ] A different port is rejected, including an explicit port that differs from the base's implicit default
- [ ] A URL carrying embedded credentials (`user:pass@`) is rejected
- [ ] A path outside the base path is rejected, including the sibling-prefix case (`/fhirX` against base `/fhir`)
- [ ] Non-string, empty, and unparseable input is rejected
- [ ] `php tests/run.php` exits 0

**Verify:** `php -l FhirRequestPolicy.php && php tests/run.php` → `OK (N assertions)`, exit code 0

**Steps:**

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`, before the summary block:

```php
// --- isWithinBase ---------------------------------------------------------

$base = 'https://ts.example.org/fhir';

assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/ValueSet/$expand?url=x', $base),
    'isWithinBase: expand under base');
assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir', $base),
    'isWithinBase: the base itself');
assertTrue(FhirRequestPolicy::isWithinBase('https://TS.EXAMPLE.ORG/fhir/metadata', $base),
    'isWithinBase: host comparison is case-insensitive');

assertFalse(FhirRequestPolicy::isWithinBase('https://evil.example.com/fhir/metadata', $base),
    'isWithinBase: different host rejected');
assertFalse(FhirRequestPolicy::isWithinBase('http://ts.example.org/fhir/metadata', $base),
    'isWithinBase: scheme downgrade rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org:8443/fhir/metadata', $base),
    'isWithinBase: differing explicit port rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://user:pass@ts.example.org/fhir/metadata', $base),
    'isWithinBase: embedded credentials rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/other/metadata', $base),
    'isWithinBase: path outside base rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhirX/metadata', $base),
    'isWithinBase: sibling prefix rejected');
assertFalse(FhirRequestPolicy::isWithinBase('', $base), 'isWithinBase: empty url rejected');
assertFalse(FhirRequestPolicy::isWithinBase(null, $base), 'isWithinBase: null url rejected');
assertFalse(FhirRequestPolicy::isWithinBase('https://ts.example.org/fhir/metadata', ''),
    'isWithinBase: empty base rejected');
assertFalse(FhirRequestPolicy::isWithinBase('not a url', $base), 'isWithinBase: garbage rejected');

assertTrue(FhirRequestPolicy::isWithinBase('https://ts.example.org:443/fhir/metadata', $base),
    'isWithinBase: explicit default port matches implicit');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Call to undefined method ... ::isWithinBase()`

- [ ] **Step 3: Write minimal implementation**

Append to `FhirRequestPolicy.php`:

```php
    /**
     * True when $url addresses the same origin as $baseUri and sits at or below its
     * path. Every outbound request is checked against the configured FHIR server so
     * that a malformed or hostile setting cannot turn the module into a proxy for
     * arbitrary hosts on the REDCap server's network.
     */
    public static function isWithinBase($url, $baseUri)
    {
        if (!is_string($url) || !is_string($baseUri) || '' === $url || '' === $baseUri) {
            return false;
        }
        $u = parse_url($url);
        $b = parse_url($baseUri);
        if (!is_array($u) || !is_array($b)) {
            return false;
        }
        // Credentials in the URL are never legitimate here and can disguise the host.
        if (isset($u['user']) || isset($u['pass'])) {
            return false;
        }
        foreach (array('scheme', 'host') as $part) {
            if (!isset($u[$part]) || !isset($b[$part])) {
                return false;
            }
            if (strtolower($u[$part]) !== strtolower($b[$part])) {
                return false;
            }
        }
        if (self::port($u) !== self::port($b)) {
            return false;
        }
        $urlPath = isset($u['path']) ? $u['path'] : '/';
        $basePath = isset($b['path']) ? rtrim($b['path'], '/') : '';
        if ('' === $basePath) {
            return true;
        }
        // Exact match, or a descendant - "/fhirX" must not pass against base "/fhir".
        return $urlPath === $basePath || 0 === strpos($urlPath, $basePath . '/');
    }

    private static function port($parts)
    {
        if (isset($parts['port'])) {
            return (int)$parts['port'];
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ('https' === $scheme) {
            return 443;
        }
        if ('http' === $scheme) {
            return 80;
        }
        return 0;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -l FhirRequestPolicy.php && php tests/run.php`
Expected: `OK (40 assertions)`, exit code 0

- [ ] **Step 5: Commit**

```bash
git add FhirRequestPolicy.php tests/run.php
git commit -m "feat: add URL containment check for outbound FHIR requests

Constrains every outbound request to the configured FHIR base so the
module cannot be pointed at arbitrary hosts on the REDCap server's
network. Groundwork for the module-owned curl transport."
```

---

### Task 6: Rework the README for release-please and correct the claims

**Goal:** Stop the README asserting version numbers that release-please now owns, and correct the two claims the review showed to be wrong.

**Files:**
- Modify: `README.md`

**Acceptance Criteria:**
- [ ] The "Version 0.5.2 changes" and "Version 0.5.1 changes" headings no longer assert version numbers; content is reorganised under headings describing the change, not the release
- [ ] The instruction to deploy into `modules/fhir-ontology-provider_v0.5.2` is replaced by a version-agnostic deployment note
- [ ] The credential-masking caveat survives in full: the stored password does not migrate, must be re-entered immediately after upgrading, and failure is silent (empty dropdown, no error)
- [ ] The circuit-breaker paragraph no longer implies a single guaranteed probe
- [ ] The `@HIDECHOICE` paragraph does not claim a per-keystroke cache
- [ ] Upstream's `CHANGELOG.md` link and typo fixes from the rebase are preserved
- [ ] No claim about the timeout is strengthened in this task — Task 7 owns that wording

**Verify:** `grep -n 'v0\.5\.2\|v0\.5\.1\|Version 0\.5' README.md` → no version-asserting changelog headings remain; manual read confirms the masking caveat is intact

**Steps:**

- [ ] **Step 1: Restructure the changelog sections**

Replace the two version headings with change-describing ones, e.g. `### Security and performance fixes` and `### Credential masking in the configuration page`. Keep every bullet's substance.

- [ ] **Step 2: Rewrite the deployment note**

Before:

```markdown
**Deploying:** place this version in a new directory `modules/fhir-ontology-provider_v0.5.2` alongside the existing
version, and only enable it once version 0.5.1 has been proven in production.
```

After:

```markdown
**Deploying the credential masking change:** changing a setting's type does not migrate the value already
stored for it. After enabling a version that includes this change, the Basic Auth password must be re-entered
before any lookup will succeed. The failure is silent - the dropdown returns empty with no error shown - so
re-enter it immediately, and consider deploying this change in its own step rather than alongside others.
```

- [ ] **Step 3: Correct the breaker paragraph**

Before:

```markdown
After 3 consecutive failed requests the module stops calling the FHIR server for 60 seconds and returns no results
immediately, then allows a single trial request through to check for recovery.
```

After:

```markdown
After 3 consecutive failed requests the module stops calling the FHIR server for 60 seconds and returns no results
immediately, then lets a trial request through to check for recovery. The count and the window are held in module
settings without locking, so under concurrent load the breaker may admit more than one trial request per window and
may open after slightly more than 3 failures. It is a stampede guard, not a precise counter.
```

- [ ] **Step 4: Correct the @HIDECHOICE paragraph**

Ensure the text credits the `global $Proj` in-memory fast path and does not claim a per-request cache. Replace any "now cached per request" wording with "now uses the already loaded project metadata where available".

- [ ] **Step 5: Verify**

```bash
grep -n 'Version 0\.5\|fhir-ontology-provider_v0' README.md || echo "no version-asserting headings"
grep -n 'CHANGELOG' README.md
grep -n 'single trial request' README.md || echo "breaker claim corrected"
```

Expected: `no version-asserting headings`; the CHANGELOG link from upstream still present; `breaker claim corrected`.

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "docs: drop version-asserting changelog headings and correct claims

release-please now derives version numbers from commit types, so the
README no longer names releases. Also corrects the circuit breaker's
single-probe claim and the @HIDECHOICE caching claim."
```

---

### Task 7: Module-owned curl transport — **BLOCKED on the maintainer's TLS answer**

**Goal:** Give outbound FHIR requests a real end-to-end timeout by issuing curl requests from the module instead of delegating to REDCap core, which sets only `CURLOPT_CONNECTTIMEOUT`.

> **This task must not begin until the TLS question is answered.** Check
> [PR #5](https://github.com/aehrc/redcap_fhir_ontology_provider/pull/5) for the maintainer's reply first.
> If it has not arrived, STOP and ask the user whether to wait or to ship Tasks 1–6 with the timeout claim
> narrowed to "protects against unreachable hosts". Do not guess the TLS setting: enabling verification when
> core disables it breaks every site whose terminology server uses an internal CA; disabling it when core
> verifies silently weakens transport security inside a security release.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (`httpGet` ~line 923, `httpPost` ~line 959)
- Modify: `README.md`

**Acceptance Criteria:**
- [ ] The maintainer's answer on TLS is recorded in the commit message, and the `CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST` values match it
- [ ] A single private `fhirRequest()` performs every outbound request; `httpGet` and `httpPost` are thin wrappers
- [ ] Both `CURLOPT_CONNECTTIMEOUT` and `CURLOPT_TIMEOUT` are set from `getFhirTimeout()`
- [ ] `CURLOPT_FOLLOWLOCATION` is false, so a redirect cannot escape the containment check
- [ ] Every request is checked with `FhirRequestPolicy::isWithinBase()` against the configured FHIR server URI before dispatch, except the OAuth2 token endpoint, which is checked against the configured token endpoint
- [ ] A request failing containment returns `false` without dialling out
- [ ] Proxy support mirrors the existing fallback: `sameHostUrl()`, `PROXY_HOSTNAME`, `PROXY_USERNAME_PASSWORD`
- [ ] The `file_get_contents` fallback for hosts without curl is retained unchanged
- [ ] The `Content-type` header workaround in the old `httpPost` is removed, since headers are now set directly
- [ ] `curl_close()` runs on every path, including failure
- [ ] Psalm reports no new `TaintedSSRF` finding relative to the Task 1 baseline
- [ ] The README timeout paragraph is updated to describe a true end-to-end bound
- [ ] `php -l` clean; `php tests/run.php` exits 0

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && php tests/run.php && vendor/bin/psalm --taint-analysis --no-progress` → no syntax errors, `OK (40 assertions)`, no new TaintedSSRF versus baseline

**Steps:**

- [ ] **Step 1: Read the maintainer's reply**

```bash
gh api repos/aehrc/redcap_fhir_ontology_provider/issues/5/comments \
  --jq '.[] | "--- \(.user.login) at \(.created_at)\n\(.body)"' | tail -60
```

Record the TLS answer. If absent, STOP per the note above.

- [ ] **Step 2: Add the transport**

```php
    /**
     * Issues one outbound request with a real end-to-end timeout.
     *
     * REDCap core's http_get()/http_post() set CURLOPT_CONNECTTIMEOUT but not
     * CURLOPT_TIMEOUT, so a terminology server that accepts the connection and then
     * stalls holds a web server process open indefinitely - the exact failure this
     * module needs to bound. The request is therefore issued here rather than
     * delegated, so both limits can be applied.
     *
     * @param string      $fullUrl
     * @param string[]    $headers
     * @param string|null $postData    null for GET
     * @param string|null $contentType
     * @return string|false
     */
    private function fhirRequest($fullUrl, $headers, $postData = null, $contentType = null)
    {
        $timeout = $this->getFhirTimeout();

        if (!function_exists('curl_init')) {
            return $this->streamRequest($fullUrl, $headers, $postData, $contentType, $timeout);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        // The fix: bounds the whole exchange, not just the handshake.
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        // A redirect would leave the origin already validated by isWithinBase().
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // TLS: value set per the maintainer's answer, recorded in the commit message.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $sendHeaders = $headers;
        if (null !== $postData) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,
                is_array($postData) ? http_build_query($postData, '', '&') : $postData);
            if ($contentType) {
                $sendHeaders[] = 'Content-type: ' . $contentType;
            }
        }
        if (!empty($sendHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $sendHeaders);
        }

        if (!sameHostUrl($fullUrl) && PROXY_HOSTNAME != '') {
            curl_setopt($ch, CURLOPT_PROXY, PROXY_HOSTNAME);
            if (PROXY_USERNAME_PASSWORD != '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, PROXY_USERNAME_PASSWORD);
            }
        }

        $content = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $content || $status >= 400) {
            return false;
        }
        return $content;
    }
```

- [ ] **Step 3: Reduce `httpGet` and `httpPost` to wrappers with containment checks**

```php
    public function httpGet($fullUrl, $headers)
    {
        // getFhirServerUri() already strips any trailing slash from the setting.
        if (!FhirRequestPolicy::isWithinBase($fullUrl, $this->getFhirServerUri())) {
            return false;
        }
        return $this->fhirRequest($fullUrl, $headers);
    }

    public function httpPost($fullUrl, $postData, $contentType, $headers)
    {
        // The OAuth2 token endpoint is a different origin from the FHIR server by
        // design, so it is allowed on an exact match against its own setting.
        $tokenEndpoint = $this->getSystemSetting('cc_token_endpoint');
        $allowed = ($tokenEndpoint && $fullUrl === $tokenEndpoint)
            || FhirRequestPolicy::isWithinBase($fullUrl, $this->getFhirServerUri());
        if (!$allowed) {
            return false;
        }
        return $this->fhirRequest($fullUrl, $headers, $postData, $contentType);
    }
```

The base URL setting key is `fhir_api_url`, and it must be read through the existing `getFhirServerUri()` accessor (line 220), which strips a trailing slash. Reading the setting directly would make a configured `https://host/fhir/` fail containment against `https://host/fhir`.

Then extract the stream fallback. Both old methods end with near-identical `file_get_contents` blocks; move them into one private method:

```php
    /**
     * Fallback for hosts without curl. The stream context 'timeout' option is a
     * total-time limit, so this path was already correctly bounded - its behaviour
     * is preserved unchanged, including the $http_response_header recovery that
     * the original code relied on.
     *
     * @return string|false
     */
    private function streamRequest($fullUrl, $headers, $postData, $contentType, $timeout)
```

Move the bodies verbatim, branching on `null === $postData` to choose GET or POST assembly: the `$headerText` loop, the `$http_array` construction (`method`, `header`, `timeout`, plus `content` and `Content-Length` for POST), the `sameHostUrl()` / `PROXY_HOSTNAME` / `PROXY_USERNAME_PASSWORD` proxy block, the `@file_get_contents(...)` call, and the `$http_response_header` fallback that returns the header text when it is not a real `HTTP/` status line. Do not change this logic — it is not what the review found fault with, and it is the only path that was already correct.

- [ ] **Step 4: Update the README timeout paragraph**

Replace the current wording with a claim that is now true — that the setting bounds the whole exchange, not just connection establishment — and state that the module issues its own request rather than using REDCap's helpers, and why.

- [ ] **Step 5: Verify**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php tests/run.php
grep -n 'http_get\|http_post' FhirOntologyAutocompleteExternalModule.php || echo "core helpers no longer called"
grep -n 'CURLOPT_TIMEOUT\|CURLOPT_FOLLOWLOCATION\|curl_close' FhirOntologyAutocompleteExternalModule.php
vendor/bin/psalm --taint-analysis --no-progress 2>&1 | tail -30
```

Expected: no syntax errors; `OK (40 assertions)`; core helpers gone; all three curl options present; Psalm shows no new `TaintedSSRF` versus the Task 1 baseline.

- [ ] **Step 6: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php README.md
git commit -m "fix: bound FHIR requests with a real end-to-end timeout

REDCap core's http_get()/http_post() set CURLOPT_CONNECTTIMEOUT but never
CURLOPT_TIMEOUT, so the fhir_timeout setting bounded only the TCP
handshake. A terminology server that accepted the connection and then
stalled still held a worker open - the failure the setting was added to
prevent. The module now issues its own curl request and sets both.

Every request is constrained to the configured base first, so making the
curl sink visible does not open an SSRF path.

TLS verification follows the maintainer's confirmation of core behaviour."
```

---

### Task 8: Publish to the contribution branch and update PR #5

**Goal:** Put the code-only changes onto `upstream-v0.5.2` without any design docs, and bring PR #5 into a mergeable, CI-passing state.

**Files:**
- No source changes; branch and PR mechanics only

**Acceptance Criteria:**
- [ ] `upstream-v0.5.2` contains every code commit from `pr5-review-response` and **no** `docs/superpowers/` content
- [ ] `git diff upstream/main..upstream-v0.5.2 --stat` lists no file under `docs/superpowers/`
- [ ] The branch is based on current `upstream/main`, so PR #5 reports mergeable
- [ ] The PR title is a valid conventional commit and passes the title lint
- [ ] The PR description is updated to drop the v0.5.1/v0.5.2 framing and describe the three fixes
- [ ] CI on PR #5 is green, or any red is explained and attributable to a known fork-permissions limitation rather than a real finding

**Verify:** `gh pr view 5 --repo aehrc/redcap_fhir_ontology_provider --json mergeable,statusCheckRollup` → `MERGEABLE`, checks passing

**Steps:**

- [ ] **Step 1: Build the code-only branch**

```bash
git log --oneline upstream/main..pr5-review-response
```

Note each commit hash and whether it touches `docs/superpowers/`. Then:

```bash
git checkout -B upstream-v0.5.2 upstream/main
git cherry-pick <each code commit hash in order>
git status --short
git diff upstream/main..upstream-v0.5.2 --stat
```

Expected: the stat lists `FhirOntologyAutocompleteExternalModule.php`, `FhirRequestPolicy.php`, `FindValueSetService.php`, `README.md`, `config.json`, `tests/run.php` — and nothing under `docs/`.

- [ ] **Step 2: Confirm no docs leaked**

```bash
git diff upstream/main..upstream-v0.5.2 --name-only | grep '^docs/' && echo "LEAK - stop and fix" || echo "no docs in contribution branch"
```

Expected: `no docs in contribution branch`

- [ ] **Step 3: Verify the branch standalone**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php -l FhirRequestPolicy.php
php -l FindValueSetService.php
php tests/run.php
python3 -c "import json;json.load(open('config.json'));print('config.json OK')"
```

Expected: all clean, `OK (40 assertions)`.

- [ ] **Step 4: Push and retitle — CONFIRM WITH THE USER FIRST**

This force-pushes over the branch an open upstream PR tracks. Show the user the diff stat and the intended title, and get explicit approval before running:

```bash
git push --force-with-lease origin upstream-v0.5.2
gh pr edit 5 --repo aehrc/redcap_fhir_ontology_provider \
  --title "fix: bound FHIR requests, correct breaker claims, and drop dead cache"
```

- [ ] **Step 5: Update the PR description**

Draft the replacement body in the scratchpad, show it to the user, and only then apply with `gh pr edit 5 --body-file <path>`. It must drop the v0.5.1/v0.5.2 split framing, describe the three fixes, and state plainly what the tests do and do not cover.

- [ ] **Step 6: Confirm CI and mergeability**

```bash
gh pr view 5 --repo aehrc/redcap_fhir_ontology_provider --json mergeable,mergeStateStatus,title
gh pr checks 5 --repo aehrc/redcap_fhir_ontology_provider
```

Expected: `MERGEABLE`; title lint passing. Note that the SARIF upload step is expected to fail on fork PRs for permissions reasons — upstream's workflow documents this. Distinguish that from a real Psalm finding before reporting green.

---

## Notes on release-please

Commit prefixes drive upstream's version bump: `fix:` yields a patch. Task 2's `test:` and Task 6's `docs:` do not bump. This is deliberate — the plan does not assert a version number anywhere, which is the point of Task 6.
