# Redirect Containment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the module handing its configured FHIR credentials to an arbitrary host when a terminology server (or an on-path attacker) answers with a redirect.

**Architecture:** Turn off curl's `CURLOPT_FOLLOWLOCATION` and PHP's stream `follow_location`, then follow redirects by hand in a bounded loop, validating every hop against the *originally configured* base with the existing `FhirRequestPolicy::isWithinBase()` before the request is issued. Because each hop is approved before it goes out, an `Authorization` header can only ever reach an in-base host — the ordering is the control, so no header-stripping logic is needed. All new decision logic is pure and lives in `FhirRequestPolicy`; the module keeps the I/O.

**Tech Stack:** PHP 8.0+ (framework 16 floor), PHPUnit 11.5, curl, REDCap External Modules framework 16.

**Spec:** `docs/superpowers/specs/2026-09-18-redirect-containment-design.md`

## Global Constraints

- **`$redirectBase` is fixed for the entire redirect loop.** Every hop is validated against the base the caller originally supplied, **never** against the URL that issued the redirect. Advancing the base per hop would let a chain of individually-in-base redirects walk progressively outside the configured server — the control would look correct and do nothing. This is the single most important invariant in the plan; Task 3 has a dedicated test for it.
- **`$redirectBase === null` means "refuse all redirects."** Used for endpoints admitted by exact match rather than containment (the OAuth token endpoint and the auth endpoint), where no containment base exists.
- **Hop budget is 10 redirects**, preserving the semantics of the `CURLOPT_MAXREDIRS = 10` being removed. That is 11 total requests (1 initial + 10 redirects).
- **POST follows only `307`/`308`.** `301`/`302`/`303` in response to a POST are refused, because following them converts the request to a GET and drops the body, changing a FHIR operation's semantics rather than relocating it.
- **The return contract of `curlGetWithTotalTimeout()`/`curlPostWithTotalTimeout()` must not change:** `string` body on success, `false` on definite failure (404/407/5xx *and now* a refused redirect), `null` when curl is unavailable or `http_code` is 0. Callers depend on `null` to trigger the `file_get_contents` fallback.
- **Existing tests must keep passing unchanged.** `FakeHttpTransport`'s new scripted queue must default to the current `['http_code' => 200]` + `$response` behaviour when no script is set.
- **`CURLOPT_SSL_VERIFYPEER` stays `false`.** Explicitly out of scope — see the spec's Non-goals. Do not "improve" it in passing; it would sink the review.
- Refusals log via the existing `urlForLogging()` (which strips embedded credentials) and return `false`. Callers already treat `false` as failure.
- Conventional-commit prefixes are mandatory (upstream runs a `pr-title-lint` workflow). Use `fix:` for the security commits, `test:` for test-only commits, `docs:` for documentation.

**User decisions (already made):**
- Approach B, "re-validate each hop", chosen over refusing all redirects or post-hoc `CURLINFO_EFFECTIVE_URL` checking.
- The `file_get_contents` fallback gets the **same loop via the pure resolver**, not a fail-closed shortcut — "Same loop, via pure resolver".
- Branch from `upstream/main` @ `a5e2b55`, never from release-please's `release-please--branches--main` (#21).
- `SSL_VERIFYPEER` is excluded from this change.
- Disclosure route is still open and blocks *opening the PR*, not implementation.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `FhirRequestPolicy.php` | Pure decision logic, no I/O | Add `MAX_REDIRECTS`, `resolveRedirectTarget()`, `mayFollowRedirect()`, `statusFromHeaders()`, `locationFromHeaders()`; correct `isWithinBase()`'s stale docblock |
| `FhirOntologyAutocompleteExternalModule.php` | REDCap integration + I/O | Add `approveRedirect()`; add redirect loops to `curlGetWithTotalTimeout()` (1132), `curlPostWithTotalTimeout()` (1268), and the `file_get_contents` fallbacks in `httpGet()` (1063) and `httpPost()` (1172) |
| `tests/support/FakeHttpTransport.php` | curl fake for tests | Add a scripted response queue supplying `http_code` and `CURLINFO_REDIRECT_URL` |
| `tests/FhirRequestPolicyTest.php` | Pure-logic tests, no fakes | Add cases for all four new statics |
| `tests/FhirOntologyAutocompleteExternalModuleTest.php` | Transport-level tests | Add redirect containment tests, including the credential-leak assertion |
| `README.md` | Admin-facing docs | Correct the "kept close to core's own curl option set" claim; document redirect containment |

---

### Task 0: Set up the branch and a green baseline

**Goal:** Working on `fix/redirect-containment` off `upstream/main` with dev dependencies installed and the existing suite passing, so later failures are attributable to our changes.

**Files:**
- No source changes.

**Acceptance Criteria:**
- [ ] On branch `fix/redirect-containment`, whose merge-base with `upstream/main` is `a5e2b55`
- [ ] `vendor/bin/phpunit` exists
- [ ] The full unit suite passes before any change is made, and the pass count is recorded for comparison

**Verify:** `vendor/bin/phpunit` → `OK` with 0 failures and 0 errors

**Steps:**

- [ ] **Step 1: Confirm the branch and its base**

```bash
cd /Users/Naufal/Dev/redcap_fhir_ontology_provider
git fetch upstream --quiet
git checkout fix/redirect-containment 2>/dev/null || git checkout -b fix/redirect-containment upstream/main
git merge-base fix/redirect-containment upstream/main
```

Expected: prints `a5e2b55...`. If it prints anything else, stop — the branch is not based where the plan assumes.

- [ ] **Step 2: Install dev dependencies**

`vendor/` is gitignored and not currently populated, so PHPUnit is absent.

```bash
composer install
```

Expected: installs `phpunit/phpunit ^11.5` and `vimeo/psalm ^6.16`.

- [ ] **Step 3: Record the green baseline**

```bash
vendor/bin/phpunit 2>&1 | tail -5
```

Expected: `OK (N tests, M assertions)`. Write N and M down — every later task must keep them passing and only add to them.

- [ ] **Step 4: No commit**

Nothing to commit; `vendor/` and `composer.lock` are already handled upstream.

```json:metadata
{"files": [], "verifyCommand": "vendor/bin/phpunit", "acceptanceCriteria": ["on fix/redirect-containment based at a5e2b55", "vendor/bin/phpunit exists", "full unit suite passes as a recorded baseline"], "modelTier": "mechanical"}
```

---

### Task 1: Pure redirect decision logic in FhirRequestPolicy

**Goal:** Add the four pure statics the redirect loops need — URL resolution, method-aware status rules, and response-header parsing — with no I/O, so they are testable without REDCap or a network.

**Files:**
- Modify: `FhirRequestPolicy.php` (add constants and methods inside `class FhirRequestPolicy`)
- Test: `tests/FhirRequestPolicyTest.php`

**Acceptance Criteria:**
- [ ] `resolveRedirectTarget()` resolves all four RFC 3986 reference forms and returns `false` for empty, non-string, or non-HTTP(S) results
- [ ] `mayFollowRedirect()` allows 301/302/303/307/308 for GET but only 307/308 for POST
- [ ] `statusFromHeaders()` and `locationFromHeaders()` parse a PHP `$http_response_header` array, case-insensitively for the header name
- [ ] No method performs containment judgement — that stays with `isWithinBase()`
- [ ] `MAX_REDIRECTS` is 10

**Verify:** `vendor/bin/phpunit --filter FhirRequestPolicyTest` → `OK`, with the new tests included

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append inside `final class FhirRequestPolicyTest` in `tests/FhirRequestPolicyTest.php`:

```php
    // --- resolveRedirectTarget ---------------------------------------------

    public function testResolveRedirectAbsoluteReference(): void
    {
        $this->assertSame(
            'https://other.example/x',
            FhirRequestPolicy::resolveRedirectTarget('https://other.example/x', 'https://ts.example/fhir/a')
        );
    }

    public function testResolveRedirectNetworkPathInheritsSchemeOnly(): void
    {
        $this->assertSame(
            'https://evil.example/x',
            FhirRequestPolicy::resolveRedirectTarget('//evil.example/x', 'https://ts.example/fhir/a')
        );
    }

    public function testResolveRedirectAbsolutePathInheritsOrigin(): void
    {
        $this->assertSame(
            'https://ts.example/other',
            FhirRequestPolicy::resolveRedirectTarget('/other', 'https://ts.example/fhir/a')
        );
    }

    public function testResolveRedirectRelativePathResolvesAgainstDirectory(): void
    {
        $this->assertSame(
            'https://ts.example/fhir/b',
            FhirRequestPolicy::resolveRedirectTarget('b', 'https://ts.example/fhir/a')
        );
    }

    public function testResolveRedirectAbsolutePathKeepsExplicitPort(): void
    {
        $this->assertSame(
            'https://ts.example:8443/other',
            FhirRequestPolicy::resolveRedirectTarget('/other', 'https://ts.example:8443/fhir/a')
        );
    }

    public function testResolveRedirectEmptyLocationRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::resolveRedirectTarget('', 'https://ts.example/fhir/a'));
    }

    public function testResolveRedirectWhitespaceOnlyLocationRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::resolveRedirectTarget('   ', 'https://ts.example/fhir/a'));
    }

    public function testResolveRedirectNonStringLocationRejected(): void
    {
        $this->assertFalse(FhirRequestPolicy::resolveRedirectTarget(null, 'https://ts.example/fhir/a'));
    }

    public function testResolveRedirectNonHttpSchemeRejected(): void
    {
        $this->assertFalse(
            FhirRequestPolicy::resolveRedirectTarget('file:///etc/passwd', 'https://ts.example/fhir/a')
        );
    }

    public function testResolveRedirectSchemeDowngradeResolvesButIsRefusedByContainment(): void
    {
        // resolveRedirectTarget() does not judge containment - it resolves. The
        // downgrade is caught by isWithinBase(), which compares schemes.
        $target = FhirRequestPolicy::resolveRedirectTarget('http://ts.example/fhir/a', 'https://ts.example/fhir/a');
        $this->assertSame('http://ts.example/fhir/a', $target);
        $this->assertFalse(FhirRequestPolicy::isWithinBase($target, 'https://ts.example/fhir'));
    }

    public function testResolveRedirectDotSegmentResolvesButIsRefusedByContainment(): void
    {
        // Dot-segment merging is deliberately not implemented: isWithinBase()
        // rejects any path containing a dot segment, so normalising here would
        // risk turning a refusable target into a passing one.
        $target = FhirRequestPolicy::resolveRedirectTarget('../../etc', 'https://ts.example/fhir/a');
        $this->assertFalse(FhirRequestPolicy::isWithinBase($target, 'https://ts.example/fhir'));
    }

    // --- mayFollowRedirect -------------------------------------------------

    public function testMayFollowRedirectGetAllowsAllRedirectCodes(): void
    {
        foreach ([301, 302, 303, 307, 308] as $code) {
            $this->assertTrue(FhirRequestPolicy::mayFollowRedirect($code, false), "GET $code");
        }
    }

    public function testMayFollowRedirectGetIgnoresNonRedirectCodes(): void
    {
        foreach ([200, 204, 304, 400, 404, 500, 0] as $code) {
            $this->assertFalse(FhirRequestPolicy::mayFollowRedirect($code, false), "GET $code");
        }
    }

    public function testMayFollowRedirectPostOnlyAllowsMethodPreservingCodes(): void
    {
        $this->assertTrue(FhirRequestPolicy::mayFollowRedirect(307, true));
        $this->assertTrue(FhirRequestPolicy::mayFollowRedirect(308, true));
        foreach ([301, 302, 303] as $code) {
            $this->assertFalse(
                FhirRequestPolicy::mayFollowRedirect($code, true),
                "POST $code must be refused rather than degraded to a GET"
            );
        }
    }

    // --- response header parsing -------------------------------------------

    public function testStatusFromHeadersReadsTheStatusLine(): void
    {
        $this->assertSame(302, FhirRequestPolicy::statusFromHeaders([
            'HTTP/1.1 302 Found',
            'Location: https://ts.example/fhir/b',
        ]));
    }

    public function testStatusFromHeadersUsesTheLastStatusLine(): void
    {
        // A proxy or an earlier hop can leave more than one status line behind.
        $this->assertSame(200, FhirRequestPolicy::statusFromHeaders([
            'HTTP/1.1 302 Found',
            'HTTP/1.1 200 OK',
        ]));
    }

    public function testStatusFromHeadersEmptyArrayIsZero(): void
    {
        $this->assertSame(0, FhirRequestPolicy::statusFromHeaders([]));
    }

    public function testLocationFromHeadersIsCaseInsensitive(): void
    {
        $this->assertSame('https://ts.example/fhir/b', FhirRequestPolicy::locationFromHeaders([
            'HTTP/1.1 302 Found',
            'LoCaTiOn:   https://ts.example/fhir/b   ',
        ]));
    }

    public function testLocationFromHeadersAbsentIsFalse(): void
    {
        $this->assertFalse(FhirRequestPolicy::locationFromHeaders(['HTTP/1.1 302 Found']));
    }

    public function testMaxRedirectsIsTen(): void
    {
        $this->assertSame(10, FhirRequestPolicy::MAX_REDIRECTS);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit --filter FhirRequestPolicyTest
```

Expected: FAIL — `Error: Call to undefined method ...::resolveRedirectTarget()` (and the other three).

- [ ] **Step 3: Implement the four statics**

In `FhirRequestPolicy.php`, add next to the existing constants:

```php
    /** Maximum redirects followed for one logical request, matching the CURLOPT_MAXREDIRS this replaces. */
    const MAX_REDIRECTS = 10;
```

Then add these methods to the class (place them after `isWithinBase()`, before the `private static function port()` helper):

```php
    /**
     * Resolves a Location header value against the URL that produced it,
     * covering the four RFC 3986 reference forms: absolute, network-path
     * (//host/p), absolute-path (/p) and relative (p).
     *
     * This method makes NO containment judgement - callers must pass the
     * result through isWithinBase(). It also deliberately does not merge dot
     * segments: isWithinBase() rejects any path containing one, so
     * normalising here could turn a target that should be refused into one
     * that passes.
     *
     * @return string|false Absolute http(s) URL, or false if unresolvable.
     */
    public static function resolveRedirectTarget($location, $currentUrl)
    {
        if (!is_string($location) || !is_string($currentUrl) || '' === $currentUrl) {
            return false;
        }
        $location = trim($location);
        if ('' === $location) {
            return false;
        }
        $base = parse_url($currentUrl);
        if (!is_array($base) || !isset($base['scheme']) || !isset($base['host'])) {
            return false;
        }
        $target = parse_url($location);
        if (!is_array($target)) {
            return false;
        }

        if (isset($target['scheme'])) {
            // Absolute reference - already complete.
            $resolved = $location;
        } elseif (0 === strpos($location, '//')) {
            // Network-path reference - inherits the scheme only, NOT the host.
            $resolved = $base['scheme'] . ':' . $location;
        } elseif (0 === strpos($location, '/')) {
            // Absolute-path reference - inherits scheme and authority.
            $resolved = self::origin($base) . $location;
        } else {
            // Relative-path reference - resolved against the current directory.
            $basePath = isset($base['path']) ? $base['path'] : '/';
            $slash = strrpos($basePath, '/');
            $dir = (false === $slash) ? '/' : substr($basePath, 0, $slash + 1);
            $resolved = self::origin($base) . $dir . $location;
        }

        $parts = parse_url($resolved);
        if (!is_array($parts) || !isset($parts['scheme'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            return false;
        }
        return $resolved;
    }

    /**
     * Whether a redirect status may be followed at all, given the method.
     *
     * GET follows every redirect status. POST follows only 307 and 308, which
     * preserve method and body; following a 301, 302 or 303 would silently
     * convert the POST to a GET and drop the body, changing the semantics of a
     * FHIR operation rather than relocating it. Refusing is the honest outcome.
     */
    public static function mayFollowRedirect($httpCode, $isPost)
    {
        $code = (int)$httpCode;
        if ($isPost) {
            return 307 === $code || 308 === $code;
        }
        return in_array($code, array(301, 302, 303, 307, 308), true);
    }

    /**
     * Status code from a PHP $http_response_header array. The last status line
     * wins, since a proxy or an earlier hop can leave more than one behind.
     *
     * @param array $headers
     * @return int 0 when no status line is present.
     */
    public static function statusFromHeaders($headers)
    {
        $status = 0;
        if (!is_array($headers)) {
            return $status;
        }
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) {
                $status = (int)$m[1];
            }
        }
        return $status;
    }

    /**
     * Location header value from a PHP $http_response_header array. Header
     * names are case-insensitive per RFC 7230, so the comparison is too.
     *
     * @param array $headers
     * @return string|false
     */
    public static function locationFromHeaders($headers)
    {
        if (!is_array($headers)) {
            return false;
        }
        $found = false;
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            $colon = strpos($header, ':');
            if (false === $colon) {
                continue;
            }
            if (0 === strcasecmp(trim(substr($header, 0, $colon)), 'location')) {
                $value = trim(substr($header, $colon + 1));
                if ('' !== $value) {
                    $found = $value;
                }
            }
        }
        return $found;
    }

    /** Scheme + userinfo + host + port of a parse_url() result. */
    private static function origin($parts)
    {
        $origin = $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $origin .= $parts['user'];
            if (isset($parts['pass'])) {
                $origin .= ':' . $parts['pass'];
            }
            $origin .= '@';
        }
        $origin .= $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit --filter FhirRequestPolicyTest
```

Expected: PASS. Then run the whole suite to confirm nothing regressed:

```bash
vendor/bin/phpunit
```

Expected: `OK`, with more tests than Task 0's baseline and no failures.

- [ ] **Step 5: Commit**

```bash
git add FhirRequestPolicy.php tests/FhirRequestPolicyTest.php
git commit -m "feat: add pure redirect resolution and status policy

Adds the decision logic the redirect containment loops need, with no I/O:
resolveRedirectTarget() for the four RFC 3986 reference forms,
mayFollowRedirect() for the method-aware status rules, and header parsing
for the file_get_contents fallback. Containment judgement deliberately
stays with isWithinBase().

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

```json:metadata
{"files": ["FhirRequestPolicy.php", "tests/FhirRequestPolicyTest.php"], "verifyCommand": "vendor/bin/phpunit --filter FhirRequestPolicyTest", "acceptanceCriteria": ["resolveRedirectTarget handles all four RFC 3986 reference forms and rejects non-HTTP(S)", "mayFollowRedirect allows 301/302/303/307/308 for GET but only 307/308 for POST", "statusFromHeaders and locationFromHeaders parse $http_response_header case-insensitively", "no method performs containment judgement", "MAX_REDIRECTS is 10"], "modelTier": "mechanical"}
```

---

### Task 2: Scripted response queue in FakeHttpTransport

**Goal:** Let tests drive a multi-hop sequence such as `302 → 200`, supplying both `http_code` and `CURLINFO_REDIRECT_URL`, while leaving every existing test's behaviour untouched.

**Files:**
- Modify: `tests/support/FakeHttpTransport.php`
- Test: `tests/FhirOntologyAutocompleteExternalModuleTest.php` (one test proving the queue works and the default is unchanged)

**Acceptance Criteria:**
- [ ] `FakeHttpTransport::$script` accepts an ordered list of `['http_code' => int, 'redirect_url' => string|null, 'body' => string|false]` steps
- [ ] With no script set, `curl_getinfo()` still reports `['http_code' => 200]` and `curl_exec()` still returns `FakeHttpTransport::$response` — so existing tests pass unchanged
- [ ] `curl_getinfo($h, CURLINFO_REDIRECT_URL)` returns the current step's `redirect_url`
- [ ] `curl_getinfo()` does **not** advance the queue; only `curl_exec()` does
- [ ] `reset()` clears the script, the cursor and the current step

**Verify:** `vendor/bin/phpunit` → `OK`, with the Task 0 baseline count still passing

**Steps:**

- [ ] **Step 1: Write the failing test**

Append inside `final class FhirOntologyAutocompleteExternalModuleTest`:

```php
    // --- FakeHttpTransport scripting ----------------------------------------

    public function testFakeTransportWithoutScriptKeepsLegacyBehaviour(): void
    {
        FakeHttpTransport::$response = 'legacy';
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';

        $this->assertSame('legacy', $this->module->httpGet('https://ts.example.test/fhir/a', []));
        $this->assertCount(1, FakeHttpTransport::$calls);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit --filter FakeTransportWithoutScript
```

Expected: PASS already — this test guards behaviour that must not change. It is the regression net for this task, not a red test. The queue itself is exercised by Task 3, which is the first task with a consumer for it; adding a scripted test here would mean committing a knowingly-failing test.

- [ ] **Step 3: Implement the queue**

In `tests/support/FakeHttpTransport.php`, replace the `FakeHttpTransport` class body's state and `reset()` with:

```php
        /** @var list<array<string, mixed>> */
        public static array $calls = [];

        /** @var string|false */
        public static $response = false;

        /**
         * Ordered response steps, each
         * ['http_code' => int, 'redirect_url' => string|null, 'body' => string|false].
         * When empty, every call falls back to http_code 200 and self::$response,
         * which is exactly the behaviour every pre-existing test relies on.
         *
         * @var list<array<string, mixed>>
         */
        public static array $script = [];

        /** @var int Cursor into $script, advanced by curl_exec() only. */
        public static int $scriptIndex = 0;

        /** @var array<string, mixed>|null The step the last curl_exec() consumed. */
        public static ?array $current = null;

        public static function reset(): void
        {
            self::$calls = [];
            self::$response = false;
            self::$script = [];
            self::$scriptIndex = 0;
            self::$current = null;
        }

        /**
         * Consumes and returns the next scripted step. Called by curl_exec()
         * only - curl_getinfo() must be able to interrogate the same step
         * repeatedly without advancing the queue.
         *
         * @return array<string, mixed>
         */
        public static function nextStep(): array
        {
            $default = ['http_code' => 200, 'redirect_url' => null, 'body' => self::$response];
            $step = self::$script[self::$scriptIndex] ?? $default;
            $step += $default;
            self::$scriptIndex++;
            self::$current = $step;
            return $step;
        }
```

Then replace the `curl_exec()` and `curl_getinfo()` fakes:

```php
    function curl_exec($handle)
    {
        $isPost = array_key_exists(CURLOPT_POSTFIELDS, $handle->options);
        recordCall($handle, $isPost);
        $step = FakeHttpTransport::nextStep();
        return $step['body'];
    }

    function curl_getinfo($handle, $opt = null)
    {
        // Reports whatever step the last curl_exec() consumed, so a test can
        // drive a 302 -> 200 sequence. With no script set this is http_code 200
        // and no redirect, matching the original fake exactly.
        $step = FakeHttpTransport::$current
            ?? ['http_code' => 200, 'redirect_url' => null, 'body' => FakeHttpTransport::$response];
        if (CURLINFO_REDIRECT_URL === $opt) {
            return $step['redirect_url'];
        }
        if (null === $opt) {
            return ['http_code' => $step['http_code']];
        }
        return null;
    }
```

- [ ] **Step 4: Run to verify the legacy test passes**

```bash
vendor/bin/phpunit --filter FakeTransportWithoutScript
```

Expected: PASS.

```bash
vendor/bin/phpunit
```

Expected: `OK` — every pre-existing test still passes, at the Task 0 baseline count plus the one new legacy-guard test. The task is complete only when the suite is fully green.

- [ ] **Step 5: Commit**

```bash
git add tests/support/FakeHttpTransport.php tests/FhirOntologyAutocompleteExternalModuleTest.php
git commit -m "test: let FakeHttpTransport script multi-hop responses

curl_getinfo() previously hardcoded http_code 200, so no test could drive a
redirect. Adds an ordered step queue supplying http_code and
CURLINFO_REDIRECT_URL, advanced by curl_exec() only. With no script set the
fake behaves exactly as before, so existing tests are untouched.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

```json:metadata
{"files": ["tests/support/FakeHttpTransport.php", "tests/FhirOntologyAutocompleteExternalModuleTest.php"], "verifyCommand": "vendor/bin/phpunit --filter FakeTransportWithoutScript", "acceptanceCriteria": ["$script accepts ordered http_code/redirect_url/body steps", "no script set means http_code 200 plus $response, so existing tests pass unchanged", "curl_getinfo returns the current step's redirect_url for CURLINFO_REDIRECT_URL", "curl_getinfo does not advance the queue", "reset() clears script, cursor and current step"], "modelTier": "mechanical"}
```

---

### Task 3: Redirect containment on the curl GET path

**Goal:** `curlGetWithTotalTimeout()` stops letting curl follow redirects and instead approves each hop against the fixed original base, refusing and logging anything off-base — closing the credential leak on the GET path.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` — `httpGet()` (from line 1063), `curlGetWithTotalTimeout()` (from line 1132), and a new `approveRedirect()` helper
- Modify: `tests/support/FakeHttpTransport.php` — record `CURLOPT_FOLLOWLOCATION` in `recordCall()`
- Test: `tests/FhirOntologyAutocompleteExternalModuleTest.php`

**Acceptance Criteria:**
- [ ] After an off-base `302`, exactly **one** HTTP call is made and no call carries the `Authorization` header to the off-base host
- [ ] An in-base `302` is followed and the second call carries the same headers
- [ ] The base does not advance: `base/a → base/b → off-base/c` is refused at the third hop
- [ ] 11 chained in-base redirects are refused rather than followed indefinitely
- [ ] A refused redirect does not increment the circuit breaker's failure count
- [ ] `CURLOPT_FOLLOWLOCATION` is `false`; `CURLOPT_AUTOREFERER` and `CURLOPT_MAXREDIRS` are gone
- [ ] The `string|false|null` return contract is unchanged

**Verify:** `vendor/bin/phpunit --filter FhirOntologyAutocompleteExternalModuleTest` → `OK`

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append inside `final class FhirOntologyAutocompleteExternalModuleTest`:

```php
    // --- redirect containment: GET ------------------------------------------

    public function testOffBaseRedirectIsRefusedAndCredentialsNeverLeave(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$script = [
            ['http_code' => 302, 'redirect_url' => 'https://evil.example/collect', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'SHOULD NEVER BE FETCHED'],
        ];

        $result = $this->module->httpGet(
            'https://ts.example.test/fhir/ValueSet/$expand',
            ['Authorization: Basic c2VjcmV0']
        );

        $this->assertFalse($result, 'an off-base redirect must be refused');
        $this->assertCount(1, FakeHttpTransport::$calls, 'the off-base hop must never be issued');

        // The assertion that matters: no request carrying the credentials may
        // ever have been addressed to the redirect target.
        foreach (FakeHttpTransport::$calls as $call) {
            $this->assertStringNotContainsString(
                'evil.example',
                (string)$call['url'],
                'no request may be sent to the redirect target'
            );
        }
        $leaked = array_filter(
            FakeHttpTransport::$calls,
            static function (array $call): bool {
                $toEvil = str_contains((string)$call['url'], 'evil.example');
                $carriesAuth = (bool)array_filter(
                    $call['headers'],
                    static fn($h) => stripos((string)$h, 'Authorization:') === 0
                );
                return $toEvil && $carriesAuth;
            }
        );
        $this->assertSame([], $leaked, 'credentials must never accompany an off-base request');
    }

    public function testInBaseRedirectIsFollowedWithHeadersIntact(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$script = [
            ['http_code' => 302, 'redirect_url' => 'https://ts.example.test/fhir/moved', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'arrived'],
        ];

        $result = $this->module->httpGet(
            'https://ts.example.test/fhir/a',
            ['Authorization: Basic c2VjcmV0']
        );

        $this->assertSame('arrived', $result);
        $this->assertCount(2, FakeHttpTransport::$calls);
        $this->assertSame('https://ts.example.test/fhir/moved', FakeHttpTransport::$calls[1]['url']);
        $this->assertContains('Authorization: Basic c2VjcmV0', FakeHttpTransport::$calls[1]['headers']);
    }

    public function testRedirectBaseDoesNotAdvanceWithTheHops(): void
    {
        // Each hop is in-base relative to the PREVIOUS url, but the third is
        // outside the ORIGINAL configured base. If the base advanced per hop,
        // this would be followed - which is the bug this test exists to catch.
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$script = [
            ['http_code' => 302, 'redirect_url' => 'https://ts.example.test/fhir/b', 'body' => ''],
            ['http_code' => 302, 'redirect_url' => 'https://ts.example.test/elsewhere', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'SHOULD NEVER BE FETCHED'],
        ];

        $result = $this->module->httpGet('https://ts.example.test/fhir/a', []);

        $this->assertFalse($result);
        $this->assertCount(2, FakeHttpTransport::$calls, 'the hop leaving /fhir must be refused');
    }

    public function testRedirectChainIsCappedAtMaxRedirects(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        $script = [];
        for ($i = 0; $i < FhirRequestPolicy::MAX_REDIRECTS + 1; $i++) {
            $script[] = [
                'http_code' => 302,
                'redirect_url' => 'https://ts.example.test/fhir/hop' . $i,
                'body' => '',
            ];
        }
        FakeHttpTransport::$script = $script;

        $result = $this->module->httpGet('https://ts.example.test/fhir/a', []);

        $this->assertFalse($result, 'exhausting the hop budget must refuse, not loop');
        $this->assertCount(
            FhirRequestPolicy::MAX_REDIRECTS + 1,
            FakeHttpTransport::$calls,
            'one initial request plus MAX_REDIRECTS follows'
        );
    }

    public function testRefusedRedirectDoesNotOpenTheCircuitBreaker(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        for ($i = 0; $i < 3; $i++) {
            FakeHttpTransport::reset();
            FakeHttpTransport::$script = [
                ['http_code' => 302, 'redirect_url' => 'https://evil.example/x', 'body' => ''],
            ];
            $this->module->httpGet('https://ts.example.test/fhir/a', []);
        }

        $this->assertFalse(
            $this->module->isCircuitOpen(),
            'a refusal is not evidence of server ill-health and must not trip the breaker'
        );
    }

    public function testFollowLocationIsDisabledOnTheCurlHandle(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$response = 'ok';

        $this->module->httpGet('https://ts.example.test/fhir/a', []);

        $this->assertCount(1, FakeHttpTransport::$calls);
        $this->assertFalse(
            FakeHttpTransport::$calls[0]['follow_location'],
            'curl must not be allowed to follow redirects on its own'
        );
    }
```

Also extend `recordCall()` in `tests/support/FakeHttpTransport.php` so the last test can see the option:

```php
            'follow_location' => $handle->options[CURLOPT_FOLLOWLOCATION] ?? null,
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit --filter 'Redirect|FollowLocation'
```

Expected: FAIL — the off-base hop is currently issued because curl is told to follow redirects, and `follow_location` is `true`.

- [ ] **Step 3: Add the approveRedirect() helper**

In `FhirOntologyAutocompleteExternalModule.php`, add immediately after `urlForLogging()` (which ends around line 1061):

```php
    /**
     * Decides whether one redirect hop may be followed.
     *
     * $redirectBase is the base the ORIGINAL caller supplied and never changes
     * as the chain progresses - validating each hop against the previous url
     * instead would let a chain of individually-in-base redirects walk
     * progressively outside the configured server. A null $redirectBase means
     * the request was admitted by exact match rather than containment (the
     * OAuth token and auth endpoints), where no containment base exists and
     * every redirect is therefore refused.
     *
     * @return string|false Absolute, in-base target to request next, or false to refuse.
     */
    private function approveRedirect($location, $currentUrl, $redirectBase)
    {
        if (null === $redirectBase) {
            error_log('FhirOntologyAutocompleteExternalModule: refused redirect from an exactly-matched endpoint - from='
                . $this->urlForLogging($currentUrl));
            return false;
        }
        $target = FhirRequestPolicy::resolveRedirectTarget($location, $currentUrl);
        if (false === $target) {
            error_log('FhirOntologyAutocompleteExternalModule: refused unresolvable redirect - from='
                . $this->urlForLogging($currentUrl));
            return false;
        }
        if (!FhirRequestPolicy::isWithinBase($target, $redirectBase)) {
            error_log('FhirOntologyAutocompleteExternalModule: refused redirect outside configured FHIR server - from='
                . $this->urlForLogging($currentUrl) . ' to=' . $this->urlForLogging($target)
                . ' base=' . $this->urlForLogging($redirectBase));
            return false;
        }
        return $target;
    }
```

- [ ] **Step 4: Rewrite curlGetWithTotalTimeout() as a bounded loop**

Replace the whole of `curlGetWithTotalTimeout()` (from line 1132) with:

```php
    private function curlGetWithTotalTimeout($fullUrl, $headers, $timeout, $redirectBase)
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $url = $fullUrl;
        // One initial request plus at most MAX_REDIRECTS follows, matching the
        // CURLOPT_MAXREDIRS this loop replaces.
        for ($hop = 0; $hop <= FhirRequestPolicy::MAX_REDIRECTS; $hop++) {
            $curl = curl_init();
            // Redirects are followed by this loop, not by curl, so that every
            // hop is checked against the configured base BEFORE it is issued -
            // curl would otherwise resend CURLOPT_HTTPHEADER (including
            // Authorization) to whatever host the redirect names.
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_VERBOSE, 0);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_HTTPGET, true);
            if (!sameHostUrl($url)) {
                curl_setopt($curl, CURLOPT_PROXY, PROXY_HOSTNAME);
                curl_setopt($curl, CURLOPT_PROXYUSERPWD, PROXY_USERNAME_PASSWORD);
            }
            curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
            if (is_numeric($timeout)) {
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
                curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
            }
            if (!empty($headers) && is_array($headers)) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }
            $response = curl_exec($curl);
            $info = curl_getinfo($curl);
            $redirectUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            curl_close($curl);

            $code = isset($info['http_code']) ? (int)$info['http_code'] : 0;

            if (FhirRequestPolicy::mayFollowRedirect($code, false)) {
                $target = $this->approveRedirect($redirectUrl, $url, $redirectBase);
                if (false === $target) {
                    return false;
                }
                $url = $target;
                continue;
            }
            if (404 === $code || 407 === $code || $code >= 500) {
                return false;
            }
            if (0 !== $code) {
                return $response;
            }
            return null;
        }
        error_log('FhirOntologyAutocompleteExternalModule: refused redirect chain longer than '
            . FhirRequestPolicy::MAX_REDIRECTS . ' hops - from=' . $this->urlForLogging($fullUrl));
        return false;
    }
```

Note the removals: `CURLOPT_AUTOREFERER` and `CURLOPT_MAXREDIRS` are gone, both inert once curl no longer follows redirects. `CURLOPT_SSL_VERIFYPEER` stays `false` — out of scope.

- [ ] **Step 5: Pass the base in from httpGet()**

In `httpGet()`, change the single call site (around line 1078):

```php
        $timeout = $this->getFhirTimeout();
        $curlResult = $this->curlGetWithTotalTimeout($fullUrl, $headers, $timeout, $base);
```

- [ ] **Step 6: Run to verify they pass**

```bash
vendor/bin/phpunit --filter 'Redirect|FollowLocation|FakeTransportScript'
```

Expected: PASS, including `testFakeTransportScriptDrivesSequentialResponses` from Task 2.

```bash
vendor/bin/phpunit
```

Expected: `OK`, no regressions against the Task 0 baseline.

- [ ] **Step 7: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php tests/support/FakeHttpTransport.php tests/FhirOntologyAutocompleteExternalModuleTest.php
git commit -m "fix: validate every redirect hop against the configured FHIR base

curl resends CURLOPT_HTTPHEADER across redirects, so with
CURLOPT_FOLLOWLOCATION enabled a 302 handed the configured FHIR
credentials to whatever host the redirect named, and isWithinBase() -
which runs once, pre-request - never saw it.

Redirects are now followed by the module in a bounded loop, each hop
approved against the base the caller originally supplied before the
request is issued, so an Authorization header can only reach an in-base
host. The base deliberately does not advance with the chain.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

```json:metadata
{"files": ["FhirOntologyAutocompleteExternalModule.php", "tests/support/FakeHttpTransport.php", "tests/FhirOntologyAutocompleteExternalModuleTest.php"], "verifyCommand": "vendor/bin/phpunit --filter FhirOntologyAutocompleteExternalModuleTest", "acceptanceCriteria": ["off-base 302 yields exactly one call and no Authorization header reaches the off-base host", "in-base 302 is followed with headers intact", "base does not advance with the hops", "chain capped at MAX_REDIRECTS", "refused redirect does not open the circuit breaker", "CURLOPT_FOLLOWLOCATION false and AUTOREFERER/MAXREDIRS removed", "string|false|null return contract unchanged"], "modelTier": "standard"}
```

---

### Task 4: Redirect containment on the curl POST path

**Goal:** Apply the same loop to `curlPostWithTotalTimeout()`, with POST's stricter status rules, and refuse redirects outright for the OAuth token and auth endpoints, which are admitted by exact match rather than containment.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` — `httpPost()` (from line 1172) and `curlPostWithTotalTimeout()` (from line 1268)
- Test: `tests/FhirOntologyAutocompleteExternalModuleTest.php`

**Acceptance Criteria:**
- [ ] A `307` on the FHIR POST path is followed in-base with method and body intact
- [ ] A `302` on the FHIR POST path is refused rather than degraded to a GET
- [ ] An off-base `307` on the FHIR POST path is refused, and no call reaches the off-base host
- [ ] A redirect from the token endpoint is refused even when it would look in-base
- [ ] A redirect from the auth endpoint (`$baseOverride` supplied) is refused
- [ ] `string|false|null` return contract unchanged

**Verify:** `vendor/bin/phpunit --filter 'PostRedirect|TokenEndpointRedirect'` → `OK`

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append inside `final class FhirOntologyAutocompleteExternalModuleTest`:

```php
    // --- redirect containment: POST -----------------------------------------

    public function testPostRedirect307IsFollowedInBaseWithBodyIntact(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$script = [
            ['http_code' => 307, 'redirect_url' => 'https://ts.example.test/fhir/moved', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'arrived'],
        ];

        $result = $this->module->httpPost(
            'https://ts.example.test/fhir/ValueSet/$expand',
            '{"a":1}',
            'application/json',
            ['Authorization: Basic c2VjcmV0']
        );

        $this->assertSame('arrived', $result);
        $this->assertCount(2, FakeHttpTransport::$calls);
        $this->assertSame('POST', FakeHttpTransport::$calls[1]['method'], '307 must preserve the method');
        $this->assertSame('{"a":1}', FakeHttpTransport::$calls[1]['params'], '307 must preserve the body');
    }

    public function testPostRedirect302IsRefusedRatherThanDegradedToGet(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$script = [
            ['http_code' => 302, 'redirect_url' => 'https://ts.example.test/fhir/moved', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'SHOULD NEVER BE FETCHED'],
        ];

        $result = $this->module->httpPost(
            'https://ts.example.test/fhir/ValueSet/$expand',
            '{"a":1}',
            'application/json',
            []
        );

        $this->assertFalse($result, '302 on a POST must be refused, not converted to a GET');
        $this->assertCount(1, FakeHttpTransport::$calls);
    }

    public function testPostOffBaseRedirectIsRefused(): void
    {
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$script = [
            ['http_code' => 307, 'redirect_url' => 'https://evil.example/collect', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'SHOULD NEVER BE FETCHED'],
        ];

        $result = $this->module->httpPost(
            'https://ts.example.test/fhir/ValueSet/$expand',
            '{"a":1}',
            'application/json',
            ['Authorization: Basic c2VjcmV0']
        );

        $this->assertFalse($result);
        $this->assertCount(1, FakeHttpTransport::$calls);
        $this->assertStringNotContainsString('evil.example', (string)FakeHttpTransport::$calls[0]['url']);
    }

    public function testTokenEndpointRedirectIsRefusedEvenWhenItLooksInBase(): void
    {
        // The token endpoint is admitted by exact match, not containment, so
        // there is no base for a redirect to be contained within.
        $this->module->systemSettings['cc_token_endpoint'] = 'https://auth.example.test/token';
        FakeHttpTransport::$script = [
            ['http_code' => 307, 'redirect_url' => 'https://auth.example.test/token/v2', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'SHOULD NEVER BE FETCHED'],
        ];

        $result = $this->module->httpPost(
            'https://auth.example.test/token',
            ['grant_type' => 'client_credentials'],
            'application/x-www-form-urlencoded',
            ['Authorization: Basic Y2xpZW50']
        );

        $this->assertFalse($result, 'redirects from an exactly-matched endpoint must be refused');
        $this->assertCount(1, FakeHttpTransport::$calls);
    }

    public function testAuthEndpointRedirectWithBaseOverrideIsRefused(): void
    {
        FakeHttpTransport::$script = [
            ['http_code' => 307, 'redirect_url' => 'https://auth.example.test/token/v2', 'body' => ''],
            ['http_code' => 200, 'redirect_url' => null, 'body' => 'SHOULD NEVER BE FETCHED'],
        ];

        $result = $this->module->httpPost(
            'https://auth.example.test/token',
            ['grant_type' => 'client_credentials'],
            'application/x-www-form-urlencoded',
            [],
            'https://auth.example.test/token'
        );

        $this->assertFalse($result);
        $this->assertCount(1, FakeHttpTransport::$calls);
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit --filter 'PostRedirect|PostOffBase|TokenEndpointRedirect|AuthEndpointRedirect'
```

Expected: FAIL — `curlPostWithTotalTimeout()` still takes five arguments and lets curl follow redirects.

- [ ] **Step 3: Compute the redirect base in httpPost()**

In `httpPost()`, replace the admission block (the `if (null !== $baseOverride) { ... } else { ... }` around lines 1183–1196) with a version that also records whether containment — as opposed to exact match — admitted the request:

```php
        if (null !== $baseOverride) {
            $allowed = FhirRequestPolicy::isWithinBase($fullUrl, $baseOverride);
            $baseForLog = $baseOverride;
            // A $baseOverride is a candidate-validation or exact-endpoint check
            // (isWithinBase(x, x) is trivially true), not a containment
            // boundary, so it is not a base a redirect may be judged against.
            $redirectBase = null;
        } else {
            $tokenEndpoint = $this->getSystemSetting('cc_token_endpoint');
            $baseForLog = $this->getFhirServerUri();
            $withinBase = FhirRequestPolicy::isWithinBase($fullUrl, $baseForLog);
            $allowed = ($tokenEndpoint && $fullUrl === $tokenEndpoint) || $withinBase;
            // Only genuine containment against the FHIR base gives a redirect
            // something to be contained within. A request admitted purely by
            // the token-endpoint exact match gets null, refusing all redirects.
            $redirectBase = $withinBase ? $baseForLog : null;
        }
```

Then pass it to the curl call (around line 1198):

```php
        $timeout = $this->getFhirTimeout();
        $curlResult = $this->curlPostWithTotalTimeout($fullUrl, $postData, $contentType, $headers, $timeout, $redirectBase);
```

- [ ] **Step 4: Rewrite curlPostWithTotalTimeout() as a bounded loop**

Replace the whole of `curlPostWithTotalTimeout()` (from line 1268) with:

```php
    private function curlPostWithTotalTimeout($fullUrl, $postData, $contentType, $headers, $timeout, $redirectBase)
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        if ($contentType == 'application/json') {
            $paramString = (is_array($postData)) ? json_encode($postData) : $postData;
        } elseif ($contentType == 'application/x-www-form-urlencoded') {
            $paramString = (is_array($postData)) ? http_build_query($postData, '', '&') : $postData;
        } else {
            $paramString = $postData;
        }
        $finalHeaders = ($contentType && $contentType !== 'application/x-www-form-urlencoded')
            ? ["Content-Type: $contentType", "Content-Length: " . strlen($paramString)]
            : [];
        if (!empty($headers) && is_array($headers)) {
            $finalHeaders = array_merge($finalHeaders, $headers);
        }
        $url = $fullUrl;
        for ($hop = 0; $hop <= FhirRequestPolicy::MAX_REDIRECTS; $hop++) {
            $curl = curl_init();
            // See curlGetWithTotalTimeout() - redirects are this loop's job, not
            // curl's, so no hop is issued before it is checked against the base.
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_VERBOSE, 0);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $paramString);
            if (!sameHostUrl($url)) {
                curl_setopt($curl, CURLOPT_PROXY, PROXY_HOSTNAME);
                curl_setopt($curl, CURLOPT_PROXYUSERPWD, PROXY_USERNAME_PASSWORD);
            }
            curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
            if (is_numeric($timeout)) {
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
                curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
            }
            if (!empty($finalHeaders)) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $finalHeaders);
            }
            $response = curl_exec($curl);
            $info = curl_getinfo($curl);
            $redirectUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            curl_close($curl);

            $code = isset($info['http_code']) ? (int)$info['http_code'] : 0;

            // POST follows only 307/308. A 301/302/303 is refused rather than
            // silently converted to a GET with the body dropped.
            if (FhirRequestPolicy::mayFollowRedirect($code, true)) {
                $target = $this->approveRedirect($redirectUrl, $url, $redirectBase);
                if (false === $target) {
                    return false;
                }
                $url = $target;
                continue;
            }
            if (in_array($code, array(301, 302, 303), true)) {
                error_log('FhirOntologyAutocompleteExternalModule: refused ' . $code
                    . ' redirect on a POST, which would drop the request body - from='
                    . $this->urlForLogging($url));
                return false;
            }
            if (404 === $code || 407 === $code || $code >= 500) {
                return false;
            }
            if (0 !== $code) {
                return $response;
            }
            return null;
        }
        error_log('FhirOntologyAutocompleteExternalModule: refused redirect chain longer than '
            . FhirRequestPolicy::MAX_REDIRECTS . ' hops - from=' . $this->urlForLogging($fullUrl));
        return false;
    }
```

Note that `$paramString` and `$finalHeaders` are now built **once, before** the loop, so a followed 307 resends the identical body and headers.

- [ ] **Step 5: Run to verify they pass**

```bash
vendor/bin/phpunit --filter 'PostRedirect|PostOffBase|TokenEndpointRedirect|AuthEndpointRedirect'
```

Expected: PASS.

```bash
vendor/bin/phpunit
```

Expected: `OK`, no regressions.

- [ ] **Step 6: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php tests/FhirOntologyAutocompleteExternalModuleTest.php
git commit -m "fix: contain redirects on the FHIR POST path

Applies the same per-hop containment to curlPostWithTotalTimeout(). POST
follows only 307 and 308, which preserve method and body; a 301, 302 or
303 is refused rather than silently converted to a GET with the body
dropped.

The OAuth token and auth endpoints are admitted by exact match rather
than containment, so no base exists for a redirect to be judged against
and every redirect from them is refused.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

```json:metadata
{"files": ["FhirOntologyAutocompleteExternalModule.php", "tests/FhirOntologyAutocompleteExternalModuleTest.php"], "verifyCommand": "vendor/bin/phpunit --filter 'PostRedirect|TokenEndpointRedirect'", "acceptanceCriteria": ["307 followed in-base with method and body intact", "302 on POST refused rather than degraded to GET", "off-base 307 refused with no call to the off-base host", "token endpoint redirect refused even when it looks in-base", "auth endpoint redirect with baseOverride refused", "string|false|null return contract unchanged"], "modelTier": "standard"}
```

---

### Task 5: Redirect containment on both file_get_contents fallbacks

**Goal:** Close the same leak on the curl-less path, where PHP's `follow_location` defaults to `1`, using the pure resolver and header parsers from Task 1 so behaviour matches the curl path.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` — the `file_get_contents` fallback in `httpGet()` (from line 1088) and in `httpPost()` (from line 1210)
- Test: `tests/FhirOntologyAutocompleteExternalModuleTest.php`

**Acceptance Criteria:**
- [ ] Both fallbacks set `'follow_location' => 0` in the stream context (asserted by test)
- [ ] Both fallbacks route their redirect decision through the same
      `FhirRequestPolicy::mayFollowRedirect()` + `approveRedirect()` pair used by the curl
      paths, with the same fixed `$redirectBase` — so the token-endpoint rule, the
      base-does-not-advance invariant and the `MAX_REDIRECTS` budget hold by construction
      (asserted by code review, not by an end-to-end test — see the coverage note)
- [ ] Proxy handling is preserved byte-for-byte through the extracted helper, with no
      pre-existing test regressing

**Coverage note — state this honestly in the PR.** The end-to-end fallback redirect
behaviour is **not** covered by an automated test. The suite fakes curl by shadowing
`curl_*` in the module's namespace, but the fallback goes through PHP's http stream
wrapper and reads the magic `$http_response_header`, which a shadowed function cannot
populate in the caller's scope. What *is* proven: `follow_location => 0` is set, and every
decision the loop makes is made by functions covered in Tasks 1 and 3. Do not claim this
path is test-covered — this module was reviewed once already for overstating what its
tests demonstrate.

**Verify:** `vendor/bin/phpunit --filter Fallback` → `OK`, and `vendor/bin/phpunit` → `OK` with no pre-existing test regressed

**Steps:**

- [ ] **Step 1: Write the failing tests**

The fallback runs only when `curlGetWithTotalTimeout()` returns `null`, which the fake produces via `http_code` 0. Append inside `final class FhirOntologyAutocompleteExternalModuleTest`:

```php
    // --- redirect containment: file_get_contents fallback -------------------

    public function testFallbackSetsFollowLocationOff(): void
    {
        // http_code 0 makes the curl path return null, which is exactly when
        // the module falls through to file_get_contents.
        $this->module->systemSettings['fhir_api_url'] = 'https://ts.example.test/fhir';
        FakeHttpTransport::$script = [
            ['http_code' => 0, 'redirect_url' => null, 'body' => false],
        ];

        $context = $this->module->fallbackStreamContextForTests(
            'GET',
            ['Authorization: Basic c2VjcmV0'],
            10
        );

        $this->assertSame(0, $context['follow_location'], 'PHP follows redirects by default');
    }
```

Because the fallback calls `file_get_contents()` directly against the network, the loop's decision logic is what needs testing, not PHP's transport. Extract the context builder so it is reachable, and drive the decision through `approveRedirect()`, which Task 3 already tests. Add this small accessor to the module, next to `approveRedirect()`:

```php
    /**
     * Stream context 'http' array used by the file_get_contents fallback.
     * Extracted so the follow_location default - which PHP sets to 1, and which
     * would otherwise follow a redirect off-base with the Authorization header
     * attached - is directly assertable in tests.
     *
     * @return array<string, mixed>
     */
    public function fallbackStreamContextForTests($method, $headers, $timeout, $content = null, $contentType = null)
    {
        return $this->fallbackStreamContext($method, $headers, $timeout, $content, $contentType);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit --filter Fallback
```

Expected: FAIL — `Call to undefined method ...::fallbackStreamContextForTests()`.

- [ ] **Step 3: Extract the shared context builder**

Add to `FhirOntologyAutocompleteExternalModule.php`, immediately after `approveRedirect()`:

```php
    /**
     * Builds the stream context 'http' array shared by both file_get_contents
     * fallbacks, including the proxy handling they both had.
     *
     * follow_location is pinned to 0: PHP defaults it to 1, which would follow
     * a redirect with the Authorization header attached and without any
     * containment check - the same defect this change fixes on the curl path.
     *
     * @return array<string, mixed>
     */
    private function fallbackStreamContext($method, $headers, $timeout, $content = null, $contentType = null)
    {
        $headerText = '';
        if (is_array($headers)) {
            foreach ($headers as $hvalue) {
                $headerText .= $hvalue . "\r\n";
            }
        }
        if (null !== $contentType) {
            $headerText = "Content-type: $contentType" . "\r\n" . $headerText
                . 'Content-Length: ' . strlen((string)$content) . "\r\n";
        }
        $http = array(
            'method' => $method,
            'header' => $headerText,
            'timeout' => $timeout,
            'follow_location' => 0,
        );
        if (null !== $content) {
            $http['content'] = $content;
        }
        return $http;
    }

    /**
     * Adds proxy settings to a stream context 'http' array, mirroring what both
     * fallbacks did inline.
     *
     * @return array<string, mixed>
     */
    private function withFallbackProxy($http, $url)
    {
        if (sameHostUrl($url) || PROXY_HOSTNAME == '') {
            return $http;
        }
        $http['proxy'] = str_replace(array('http://', 'https://'), array('tcp://', 'tcp://'), PROXY_HOSTNAME);
        $http['request_fulluri'] = true;
        if (PROXY_USERNAME_PASSWORD != '') {
            $http['header'] = (isset($http['header']) ? $http['header'] : '')
                . 'Proxy-Authorization: Basic ' . base64_encode(PROXY_USERNAME_PASSWORD) . "\r\n";
        }
        return $http;
    }
```

- [ ] **Step 4: Replace the httpGet() fallback with a bounded loop**

In `httpGet()`, replace the `if (ini_get('allow_url_fopen')) { ... } else { $content = false; } return $content;` block with:

```php
        if (!ini_get('allow_url_fopen')) {
            return false;
        }
        $url = $fullUrl;
        for ($hop = 0; $hop <= FhirRequestPolicy::MAX_REDIRECTS; $hop++) {
            $http = $this->withFallbackProxy(
                $this->fallbackStreamContext('GET', $headers, $timeout),
                $url
            );
            $http_response_header = array();
            $content = @file_get_contents($url, false, stream_context_create(array('http' => $http)));
            $code = FhirRequestPolicy::statusFromHeaders($http_response_header);
            if (FhirRequestPolicy::mayFollowRedirect($code, false)) {
                $target = $this->approveRedirect(
                    FhirRequestPolicy::locationFromHeaders($http_response_header),
                    $url,
                    $base
                );
                if (false === $target) {
                    return false;
                }
                $url = $target;
                continue;
            }
            return $content;
        }
        error_log('FhirOntologyAutocompleteExternalModule: refused redirect chain longer than '
            . FhirRequestPolicy::MAX_REDIRECTS . ' hops on the file_get_contents fallback - from='
            . $this->urlForLogging($fullUrl));
        return false;
```

- [ ] **Step 5: Replace the httpPost() fallback with the same loop**

In `httpPost()`, replace its `if (ini_get('allow_url_fopen')) { ... } return false;` block with:

```php
        if (!ini_get('allow_url_fopen')) {
            return false;
        }
        $url = $fullUrl;
        for ($hop = 0; $hop <= FhirRequestPolicy::MAX_REDIRECTS; $hop++) {
            $http = $this->withFallbackProxy(
                $this->fallbackStreamContext('POST', $headers, $timeout, $param_string, $contentType),
                $url
            );
            $http_response_header = array();
            $content = @file_get_contents($url, false, stream_context_create(array('http' => $http)));
            $code = FhirRequestPolicy::statusFromHeaders($http_response_header);
            if (FhirRequestPolicy::mayFollowRedirect($code, true)) {
                $target = $this->approveRedirect(
                    FhirRequestPolicy::locationFromHeaders($http_response_header),
                    $url,
                    $redirectBase
                );
                if (false === $target) {
                    return false;
                }
                $url = $target;
                continue;
            }
            if (in_array($code, array(301, 302, 303), true)) {
                error_log('FhirOntologyAutocompleteExternalModule: refused ' . $code
                    . ' redirect on a POST, which would drop the request body - from='
                    . $this->urlForLogging($url));
                return false;
            }
            if ($content !== false) {
                return $content;
            }
            // Some servers put the payload in the headers rather than the body.
            $joined = implode('', $http_response_header);
            return (substr($joined, 0, 5) == 'HTTP/') ? false : $joined;
        }
        error_log('FhirOntologyAutocompleteExternalModule: refused redirect chain longer than '
            . FhirRequestPolicy::MAX_REDIRECTS . ' hops on the file_get_contents fallback - from='
            . $this->urlForLogging($fullUrl));
        return false;
```

- [ ] **Step 6: Run to verify**

```bash
vendor/bin/phpunit --filter Fallback
```

Expected: PASS.

```bash
vendor/bin/phpunit
```

Expected: `OK`, no regressions. If any pre-existing fallback test breaks, the extraction changed behaviour — fix the extraction, not the test.

- [ ] **Step 7: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php tests/FhirOntologyAutocompleteExternalModuleTest.php
git commit -m "fix: contain redirects on the file_get_contents fallback

PHP's follow_location stream option defaults to 1, so the curl-less path
followed redirects with the Authorization header attached and no
containment check - the same defect as the curl path. Both fallbacks now
pin follow_location to 0 and run the same bounded, per-hop approval,
sharing one context builder rather than duplicating the proxy handling.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

```json:metadata
{"files": ["FhirOntologyAutocompleteExternalModule.php", "tests/FhirOntologyAutocompleteExternalModuleTest.php"], "verifyCommand": "vendor/bin/phpunit --filter Fallback", "acceptanceCriteria": ["both fallbacks set follow_location => 0, asserted by test", "both fallbacks route decisions through mayFollowRedirect() and approveRedirect() with the same fixed redirectBase", "proxy handling preserved through the extracted helper with no pre-existing test regressing", "PR states plainly that end-to-end fallback redirect behaviour is not test-covered and why"], "modelTier": "standard"}
```

---

### Task 6: Correct the stale docblock and README claims

**Goal:** Make the documentation describe what the code now does — the `isWithinBase()` caveat that attributes redirect-following to REDCap core, and the README claim that the module's curl copy is kept close to core's option set.

**Files:**
- Modify: `FhirRequestPolicy.php` — `isWithinBase()` docblock
- Modify: `README.md` — the "Security and performance fixes" section

**Acceptance Criteria:**
- [ ] `isWithinBase()`'s docblock no longer claims redirect-following is outside what the module checks
- [ ] It still names DNS rebinding as genuinely out of scope
- [ ] README no longer claims the curl copy is kept close to core's option set without qualification
- [ ] README documents that off-base redirects are refused, and that `SSL_VERIFYPEER` remains core's behaviour
- [ ] No claim is made about DNS rebinding or TLS verification being fixed

**Verify:** `vendor/bin/phpunit && grep -c 'follow HTTP redirects' FhirRequestPolicy.php` → `OK` then `0`

**Steps:**

- [ ] **Step 1: Replace the stale paragraph in the isWithinBase() docblock**

In `FhirRequestPolicy.php`, replace this paragraph:

```
     * This is NOT a general SSRF guard. It performs no DNS resolution, so a
     * configured host that resolves (now or later) to a link-local or private
     * address passes unchanged. It also does not see what happens after the
     * request leaves this check: REDCap core's http_get()/http_post() follow
     * HTTP redirects, so a terminology server that replies
     * 302 -> http://169.254.169.254/latest/meta-data/ is followed there
     * regardless of what this method decided. Redirect-following and DNS
     * rebinding are both outside what this check covers.
```

with:

```
     * This is NOT a general SSRF guard. It performs no DNS resolution, so a
     * configured host that resolves (now or later) to a link-local or private
     * address passes unchanged, and DNS rebinding is outside what it covers.
     *
     * Redirects used to be outside it too: this check runs once, before the
     * request, and REDCap core's http_get()/http_post() followed redirects
     * afterwards. That stopped being true once the module began making its own
     * curl calls, so the module now follows redirects itself in a bounded loop
     * and calls this method again for every hop, against the base originally
     * configured rather than the redirecting url. See resolveRedirectTarget()
     * and the module's own approveRedirect().
```

- [ ] **Step 2: Correct the README timeout bullet**

In `README.md`, in the "Security and performance fixes" section, replace:

```
this module now makes its own curl calls (`curlGetWithTotalTimeout()`/`curlPostWithTotalTimeout()`), deliberately
kept close to core's own curl option set, with `CURLOPT_TIMEOUT` added on top. The `file_get_contents` fallback
used when curl is unavailable already had a true end-to-end limit via its stream context `timeout` option, and is
unaffected by this change.
```

with:

```
this module now makes its own curl calls (`curlGetWithTotalTimeout()`/`curlPostWithTotalTimeout()`), kept close to
core's own curl option set apart from two deliberate departures: `CURLOPT_TIMEOUT` is added on top, and redirect
following is handled by the module rather than by curl (see the redirect bullet below). The `file_get_contents`
fallback used when curl is unavailable already had a true end-to-end limit via its stream context `timeout` option,
and is unaffected by this timeout change.
```

- [ ] **Step 3: Extend the containment bullet**

In `README.md`, replace:

```
- ***Outbound FHIR requests are now constrained to the configured server***
Every URL this module builds before sending a request is checked against the configured `FHIR API URL`: it must
address the same origin and sit at or below its path. A request that would fall outside that (for example, one
built from a malformed or hostile setting) is refused rather than sent. See `FhirRequestPolicy::isWithinBase()`
for exactly what this does and does not cover.
```

with:

```
- ***Outbound FHIR requests are now constrained to the configured server, redirects included***
Every URL this module builds before sending a request is checked against the configured `FHIR API URL`: it must
address the same origin and sit at or below its path. A request that would fall outside that (for example, one
built from a malformed or hostile setting) is refused rather than sent.

  That check is also applied to every redirect. Previously the module let curl follow redirects, and curl resends
request headers - including `Authorization` - to whatever host a redirect names, so a terminology server replying
`302` could collect the configured FHIR credentials. The module now follows redirects itself, at most 10 per
request, checking each hop against the originally configured `FHIR API URL` *before* issuing it; a redirect that
leaves the configured server is refused and logged. Redirects away from the OAuth token endpoint are always
refused, since that endpoint is matched exactly rather than by path containment, and `POST` requests follow only
`307`/`308`, which preserve the request body.

  **If your terminology server relies on a redirect that leaves the configured `FHIR API URL`, point the setting
at the final destination instead.** Redirects *within* the configured server continue to work.

  Peer certificate verification is unchanged - the module still mirrors REDCap core, which does not verify peer
certificates. See `FhirRequestPolicy::isWithinBase()` for exactly what the containment check does and does not
cover.
```

- [ ] **Step 4: Verify**

```bash
vendor/bin/phpunit
grep -c 'follow HTTP redirects' FhirRequestPolicy.php
```

Expected: `OK`, then `0` — the stale sentence is gone.

- [ ] **Step 5: Commit**

```bash
git add FhirRequestPolicy.php README.md
git commit -m "docs: describe redirect containment and drop the stale caveat

isWithinBase()'s docblock attributed redirect-following to REDCap core,
which stopped being true when the module took over its own curl calls.
The README's claim that the curl copy is kept close to core's option set
is likewise now qualified, and the admin-facing note says what to do if a
server relies on a redirect that leaves the configured base.

No claim is made about DNS rebinding or peer certificate verification,
both of which remain out of scope.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

```json:metadata
{"files": ["FhirRequestPolicy.php", "README.md"], "verifyCommand": "vendor/bin/phpunit && grep -c 'follow HTTP redirects' FhirRequestPolicy.php", "acceptanceCriteria": ["isWithinBase docblock no longer claims redirects are unchecked", "DNS rebinding still named as out of scope", "README no longer claims unqualified core option-set parity", "README documents off-base redirect refusal and unchanged SSL_VERIFYPEER", "no claim that DNS rebinding or TLS verification is fixed"], "modelTier": "mechanical"}
```

---

## Before opening the PR

Implementation does not depend on these, but opening the PR does.

- [ ] **Run the full suite plus static analysis.** `vendor/bin/phpunit` and `vendor/bin/psalm`. Psalm runs taint analysis upstream; the new curl call sites are already visible to it, and `approveRedirect()` constrains the URL via `isWithinBase()` rather than suppressing the finding, which is the approach agreed with dconlan on PR #5.
- [ ] **Settle the disclosure route.** The finding is a live credential-exfiltration path against deployed installs, so a public issue or an unannounced public PR publishes it. Timing interacts: release-please's #21 cuts 1.0.0, and dconlan will not submit to the official REDCap registry before then — landing this first means the version he submits is fixed.
- [ ] **Do not bundle `SSL_VERIFYPEER`.** It is a separate argument requiring REDCap source access neither party has.
