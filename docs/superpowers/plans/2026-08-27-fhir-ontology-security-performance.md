# FHIR Ontology Security & Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close an unauthenticated terminology proxy into the internal network and stop routine Snowstorm maintenance from taking all of REDCap offline, plus six smaller correctness and hardening fixes.

**Architecture:** A REDCap External Module (framework v1) with three source files and no build step. Changes are surgical edits to existing methods — no new files, no new classes, no dependencies. A circuit breaker is added using the External Modules settings store for state, integrated at the three FHIR entry points rather than centrally, so it does not also trap OAuth2 token negotiation against a different host.

**Tech Stack:** PHP (min 5.4), REDCap External Modules framework v1, jQuery/jQuery-UI (supplied by REDCap), FHIR R4 `ValueSet/$expand` against self-hosted Snowstorm.

**Spec:** `docs/superpowers/specs/2026-08-27-fhir-ontology-security-performance-design.md`

## Global Constraints

Every task must respect these. They are binding.

1. **PHP 5.4 compatibility.** `config.json` declares `"php-version-min": "5.4.0"`. The null-coalescing operator `??` (PHP 7+) MUST NOT be used anywhere. Use `isset($x) ? $x : $default`. Raising the declared minimum is out of scope.
2. **Do not bump `framework-version`.** It is `1`. If a change appears to require framework v2+, STOP and report rather than bumping it.
3. **Seven of nine tasks edit the same 938-line file.** `FhirOntologyAutocompleteExternalModule.php` is touched by Tasks 2–7 and read by 9. Tasks are strictly serialized via `blockedBy` for this reason. Do NOT parallelize them — quoted "before" snippets assume all prior tasks have landed.
4. **No test framework exists.** There is no PHPUnit, no CI, no fixtures. Automated verification is limited to `php -l` and JSON validation. Everything else is a manual step the user performs against their live instance.
5. **No staging environment.** The user has no non-production REDCap. Nothing may be verified by "deploy and see". Manual verification steps must be safe to run on production and fully reversible.
6. **JS lives inside a PHP heredoc.** The Online Designer JavaScript is inside a `<<<EOD ... EOD;` block. `$(` is safe (a `(` cannot begin an identifier) but any new `$name` usage WILL be interpolated by PHP. Review every `$` you add.
7. **Report actual output.** Never claim a verification passed without pasting the command output. `php -l` output is required evidence for every task touching PHP.
8. **Commit per task.** Each task ends with its own commit. Task 8 must land in a commit separate from Tasks 1–7 and 9.

**User decisions (already made):**

- Timeout is a **system setting**, not a hardcoded constant — "system setting for timeout".
- Circuit breaker is **in scope** — "breaker in".
- P3 token **storage relocation is deferred** to the future OAuth2 migration; only the arithmetic is fixed now — "defer p3 storage".
- **No non-production REDCap** exists to stage on — "i dont have non-production redcap to stage on".
- Server is self-hosted Snowstorm at `https://api-test.mysnomed.com/fhir`, currently Basic Auth, OAuth2 planned later.
- Findings S4 and P4 are deliberately NOT fixed (S4 is defused by content-type; P4 is correct design).
- Module-side response caching is deliberately NOT added (Snowstorm caches expansions itself).

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `config.json` | Module manifest: settings schema, hook permissions, page auth declarations | 1, 2, 8 |
| `FhirOntologyAutocompleteExternalModule.php` | Everything else: Ontology Provider implementation, designer UI markup + JS, FHIR HTTP layer, auth | 2, 3, 4, 5, 6, 7 |
| `FindValueSetService.php` | Thin HTTP entry point for designer AJAX; parameter validation and dispatch only | 6 |
| `README.md` | Admin-facing docs and per-version changelog | 9 |

No new files. The module file is large and mixes concerns (provider logic, HTTP, markup), but splitting it is unrelated refactoring and out of scope for this work.

## Traceability and execution order

Tasks appear in the document in **dependency order**, which is why Task 9 precedes Task 8 — Task 8 is a separate release tranche that ships only after everything else is proven in production.

| Order | Task | Spec finding | Severity | Release |
|---|---|---|---|---|
| 1 | Task 1 — Close no-auth on FindValueSetService | S1 | Critical | v0.5.1 |
| 2 | Task 2 — Add configurable FHIR timeout | P1a | Critical | v0.5.1 |
| 3 | Task 3 — Add FHIR circuit breaker | P1b | Critical | v0.5.1 |
| 4 | Task 4 — Fix per-keystroke dictionary load | P2 | Critical | v0.5.1 |
| 5 | Task 5 — Fix OAuth2 token expiry arithmetic | P3 | High | v0.5.1 |
| 6 | Task 6 — Null-safe parsing + unknown actions | P5, S5 | Medium | v0.5.1 |
| 7 | Task 7 — Escape Show Details dialog output | S2 | High | v0.5.1 |
| 8 | Task 9 — Document and prepare v0.5.1 | — | — | v0.5.1 |
| 9 | Task 8 — Mask credential settings | S3 | Low | **v0.5.2** |

Spec findings **S4** (unescaped reflection, defused by content-type) and **P4** (module loads on every page, correct design) have no task by design — see the spec's out-of-scope section.

**Strictly sequential.** Tasks 2-7 all edit `FhirOntologyAutocompleteExternalModule.php`, and each task's quoted "before" snippet assumes every earlier task has already landed. Running them out of order or in parallel will cause the snippet matches to fail.

---

### Task 1: Close no-auth on FindValueSetService

**Goal:** Stop anonymous users reaching the terminology proxy, which currently acts as a public gateway into the internal Snowstorm network and an anonymous DoS vector.

**Files:**
- Modify: `config.json:167-169`

**Acceptance Criteria:**
- [ ] The `no-auth-pages` block is removed from `config.json`
- [ ] `config.json` remains valid JSON
- [ ] Online Designer search, Select, and Show Details still function
- [ ] Requesting the endpoint with `&NOAUTH` appended no longer returns ValueSet JSON

**Verify:** `python3 -c "import json;json.load(open('config.json'));print('valid')"` → prints `valid`

**Steps:**

- [ ] **Step 1: Confirm the block is present and note its exact form**

```bash
grep -n -A2 '"no-auth-pages"' config.json
```

Expected output:

```
167:  "no-auth-pages": [
168-    "FindValueSetService"
169-  ],
```

- [ ] **Step 2: Remove the block**

```bash
python3 - <<'PYEOF'
import io
p = 'config.json'
s = io.open(p, encoding='utf-8').read()
old = '''  "no-auth-pages": [
    "FindValueSetService"
  ],
'''
assert s.count(old) == 1, "expected exactly 1 no-auth-pages block, found %d" % s.count(old)
io.open(p, 'w', encoding='utf-8').write(s.replace(old, ''))
print("removed")
PYEOF
```

Expected: `removed`

- [ ] **Step 3: Validate JSON and confirm the key is gone**

```bash
python3 -c "import json;json.load(open('config.json'));print('valid')"
grep -c 'no-auth-pages' config.json || echo "key absent (grep exit 1 is expected)"
```

Expected: `valid`, then `0` or the absent message.

- [ ] **Step 4: MANUAL — user verifies the designer still works**

This cannot be automated; there is no staging instance. Ask the user to deploy and confirm, in the Online Designer:
1. Search by ValueSet Name returns suggestions
2. Search by SNOMED CT Refset returns suggestions
3. Select moves the chosen valueset into the URL field
4. Show Details opens the dialog with populated fields

Then confirm the hole is closed, substituting their REDCap host:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  'https://REDCAP_HOST/redcap/api/?type=module&prefix=fhir-ontology-provider&page=FindValueSetService&NOAUTH&action=find&type=name&query=diabetes'
```

Expected: NOT a 200 carrying ValueSet JSON. A 401/403, a redirect to login, or a REDCap error page all indicate success.

**If the designer breaks:** restore the `no-auth-pages` block and implement the spec's documented fallback instead — gate inside `FindValueSetService.php` on an authenticated REDCap user with design rights on `pid`. Report this before proceeding.

- [ ] **Step 5: Commit**

```bash
git add config.json
git commit -m "Remove no-auth declaration from FindValueSetService endpoint

The endpoint had no authorisation check of any kind. Because Snowstorm is
self-hosted and internal while REDCap is internet-facing, this made the
module a supported route for anonymous users to query an internal-only
service and read its responses verbatim, and to trigger unbounded outbound
calls at will.

The designer builds its URL with getUrl(..., \$noAuth = false, ...), so it
never requests the no-auth path and is unaffected.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Add configurable FHIR timeout

**Goal:** Bound how long a web server process waits on Snowstorm, so a restart or reindex cannot exhaust the PHP-FPM worker pool and take all of REDCap offline.

**Files:**
- Modify: `config.json` (new `fhir_timeout` setting after `fhir_api_url_info`)
- Modify: `FhirOntologyAutocompleteExternalModule.php` (class constants, new `getFhirTimeout()`, `httpGet`, `httpPost`)

**Acceptance Criteria:**
- [ ] `fhir_timeout` system setting exists with default `10`
- [ ] `getFhirTimeout()` returns the setting when numeric and > 0, else `DEFAULT_TIMEOUT`
- [ ] Timeout is threaded into all four outbound paths: `http_get` curl, `http_get` stream fallback, both `http_post` curl calls, `http_post` stream fallback
- [ ] `config.json` valid; `php -l` clean

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && python3 -c "import json;json.load(open('config.json'));print('valid')"` → `No syntax errors detected` then `valid`

**Steps:**

- [ ] **Step 1: Add the setting to config.json**

```bash
python3 - <<'PYEOF'
import io
p = 'config.json'
s = io.open(p, encoding='utf-8').read()
anchor = '''    {
      "key": "fhir_api_url_info",
      "name": "The FHIR server url to use. eg. https://tx.ontoserver.csiro.au/fhir or https://snowstorm-fhir.snomedtools.org/fhir. Don't leave a trailing slash.",
      "type": "descriptive"
    },
'''
assert s.count(anchor) == 1, "anchor not found exactly once"
addition = anchor + '''    {
      "key": "fhir_timeout",
      "name": "FHIR request timeout (seconds)",
      "type": "text",
      "default": "10"
    },
    {
      "key": "fhir_timeout_info",
      "name": "Maximum number of seconds to wait for the FHIR server before giving up. Without a limit a slow or unavailable terminology server will tie up web server processes and can make all of REDCap unresponsive. Leave blank to use the default of 10 seconds.",
      "type": "descriptive"
    },
'''
io.open(p, 'w', encoding='utf-8').write(s.replace(anchor, addition))
print("setting added")
PYEOF
python3 -c "import json;json.load(open('config.json'));print('valid')"
```

Expected: `setting added` then `valid`

- [ ] **Step 2: Add class constants**

Replace the class opening:

```php
class FhirOntologyAutocompleteExternalModule extends AbstractExternalModule implements \OntologyProvider
{

    public function __construct()
```

with:

```php
class FhirOntologyAutocompleteExternalModule extends AbstractExternalModule implements \OntologyProvider
{
    /** Fallback timeout (seconds) used when the 'fhir_timeout' setting is blank or invalid. */
    const DEFAULT_TIMEOUT = 10;

    public function __construct()
```

- [ ] **Step 3: Add getFhirTimeout() immediately before httpGet()**

Insert this method directly above `public function httpGet($fullUrl, $headers)`:

```php
    /**
     * Maximum number of seconds to wait on the FHIR server. Without a limit a slow
     * or unavailable server holds a web server process open for the system default,
     * which can exhaust the pool and take all of REDCap down with it.
     */
    public function getFhirTimeout()
    {
        $timeout = $this->getSystemSetting('fhir_timeout');
        if (is_numeric($timeout) && (int)$timeout > 0) {
            return (int)$timeout;
        }
        return self::DEFAULT_TIMEOUT;
    }

```

- [ ] **Step 4: Thread the timeout through httpGet**

Change the opening of `httpGet` from:

```php
    public function httpGet($fullUrl, $headers)
    {
        // if curl isn't install the default version of http_get in init_functions doesn't include the headers.
```

to:

```php
    public function httpGet($fullUrl, $headers)
    {
        $timeout = $this->getFhirTimeout();
        // if curl isn't install the default version of http_get in init_functions doesn't include the headers.
```

Then change the curl call from:

```php
            return http_get($fullUrl, null, '', $headers, null);
```

to:

```php
            return http_get($fullUrl, $timeout, '', $headers, null);
```

And the stream fallback from:

```php
            $http_array = array('method' => 'GET', 'header' => $headerText);
```

to:

```php
            $http_array = array('method' => 'GET', 'header' => $headerText, 'timeout' => $timeout);
```

Argument position is confirmed by the existing call shape: `http_get($url, $timeout, $basic_auth, $headers, $cookies)`.

- [ ] **Step 5: Thread the timeout through httpPost**

Change the opening of `httpPost` from:

```php
    public function httpPost($fullUrl, $postData, $contentType, $headers)
    {
        // if curl isn't install the default version of http_post in init_functions doesn't include the headers.
```

to:

```php
    public function httpPost($fullUrl, $postData, $contentType, $headers)
    {
        $timeout = $this->getFhirTimeout();
        // if curl isn't install the default version of http_post in init_functions doesn't include the headers.
```

Then both curl calls, from:

```php
            return http_post($fullUrl, $postData, null, $contentType, '', $fullHeaders);
```

to:

```php
            return http_post($fullUrl, $postData, $timeout, $contentType, '', $fullHeaders);
```

and from:

```php
            return http_post($fullUrl, $postData, null, $contentType, '', $headers);
```

to:

```php
            return http_post($fullUrl, $postData, $timeout, $contentType, '', $headers);
```

Argument position confirmed by the existing call shape: `http_post($url, $params, $timeout, $content_type, $basic_auth, $headers)`.

Finally the stream fallback, from:

```php
            $http_array = array('method' => 'POST',
                'header' => "Content-type: $contentType" . "\r\n" . $headerText . "Content-Length: " . strlen($param_string) . "\r\n",
                'content' => $param_string
            );
```

to:

```php
            $http_array = array('method' => 'POST',
                'header' => "Content-type: $contentType" . "\r\n" . $headerText . "Content-Length: " . strlen($param_string) . "\r\n",
                'content' => $param_string,
                'timeout' => $timeout
            );
```

- [ ] **Step 6: Verify no timeout path was missed**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
echo "--- remaining null timeouts (must be empty) ---"
grep -n "http_get(\$fullUrl, null\|http_post(\$fullUrl, \$postData, null" FhirOntologyAutocompleteExternalModule.php || echo "none - good"
echo "--- timeout wired in (expect 8 or more lines) ---"
grep -c "\$timeout" FhirOntologyAutocompleteExternalModule.php
```

Expected: `No syntax errors detected`, then `none - good`, then a count of at least 8 (declaration + 2 assignments + 5 uses).

- [ ] **Step 7: Commit**

```bash
git add config.json FhirOntologyAutocompleteExternalModule.php
git commit -m "Bound FHIR requests with a configurable timeout

Every autocomplete keystroke made a synchronous outbound call with null
passed as the timeout, so a slow or unreachable Snowstorm parked a
PHP-FPM worker for the system default. Workers are a shared fixed pool,
so routine Snowstorm maintenance could take all of REDCap offline.

Exposed as a setting rather than a constant so it can be tuned against
production without a redeploy - ECL expansion timings on the self-hosted
server are not yet known and there is no staging instance to measure on.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Add FHIR circuit breaker

**Goal:** Convert a multi-minute site-wide REDCap outage during routine Snowstorm restarts into degraded autocomplete on one field type.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (constants, three new breaker methods, integration at `searchOntology`, `findValueSet`, `getValueSetInfo`)

**Acceptance Criteria:**
- [ ] `BREAKER_FAILURE_THRESHOLD = 3` and `BREAKER_OPEN_SECONDS = 60` constants exist
- [ ] `isCircuitOpen()`, `recordFhirFailure()`, `recordFhirSuccess()` implemented
- [ ] Breaker integrated at exactly the three FHIR entry points, NOT inside `httpGet`/`httpPost` and NOT around token negotiation
- [ ] A healthy server performs zero `setSystemSetting` writes
- [ ] `getValueSetInfo()` returns `false` on failure so the service layer can emit a 502
- [ ] `php -l` clean

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php` → `No syntax errors detected`

**Steps:**

- [ ] **Step 1: Add breaker constants**

Extend the constants added in Task 2 so the block reads:

```php
    /** Fallback timeout (seconds) used when the 'fhir_timeout' setting is blank or invalid. */
    const DEFAULT_TIMEOUT = 10;
    /** Consecutive failures required before the circuit breaker opens. */
    const BREAKER_FAILURE_THRESHOLD = 3;
    /** How long (seconds) the breaker stays open before allowing a trial request. */
    const BREAKER_OPEN_SECONDS = 60;
```

- [ ] **Step 2: Add the three breaker methods immediately after getFhirTimeout()**

```php
    /**
     * True while the breaker is open, i.e. the FHIR server has failed repeatedly and
     * we should fail fast instead of dialing out again. Once the open window elapses
     * a single trial request is allowed through to see if the server has recovered.
     */
    public function isCircuitOpen()
    {
        $openUntil = $this->getSystemSetting('fhir_breaker_open_until');
        return $openUntil && time() < (int)$openUntil;
    }

    public function recordFhirFailure()
    {
        $failures = (int)$this->getSystemSetting('fhir_breaker_failures') + 1;
        $this->setSystemSetting('fhir_breaker_failures', $failures);
        if ($failures >= self::BREAKER_FAILURE_THRESHOLD) {
            $this->setSystemSetting('fhir_breaker_open_until', time() + self::BREAKER_OPEN_SECONDS);
        }
    }

    public function recordFhirSuccess()
    {
        // only write when there is state to clear, so a healthy server costs no writes
        if ($this->getSystemSetting('fhir_breaker_failures')) {
            $this->setSystemSetting('fhir_breaker_failures', 0);
            $this->setSystemSetting('fhir_breaker_open_until', 0);
        }
    }

```

These keys are not declared in `config.json`. That is intentional — the framework accepts arbitrary keys via `setSystemSetting`, and breaker state is internal, not an admin-facing setting.

- [ ] **Step 3: Integrate into searchOntology**

Replace:

```php
        $json = $this->httpGet($url, $headers);
        // Parse the JSON into an array
```

with:

```php
        if ($this->isCircuitOpen()) {
            // Server has failed repeatedly - fail fast rather than tying up a web
            // server process on a request we already expect to time out.
            $json = false;
        }
        else {
            $json = $this->httpGet($url, $headers);
            if ($json === false) {
                $this->recordFhirFailure();
            }
            else {
                $this->recordFhirSuccess();
            }
        }
        // Parse the JSON into an array
```

Leave the parsing lines below untouched — Task 6 fixes those.

- [ ] **Step 4: Integrate into findValueSet**

Replace:

```php
        if ('GET' === $method) {
            $fullUrl = $this->getFhirServerUri() . $url . '?' . http_build_query($params);
            $result_json = $this->httpGet($fullUrl, $headers);
        } else {
            $fullUrl = $this->getFhirServerUri() . $url;
            $result_json = $this->httpPost($fullUrl, $postData, $contentType, $headers);
        }
        if ($result_json === false) {
            return [];
        }
        return $processFunction(json_decode($result_json, true));
```

with:

```php
        if ($this->isCircuitOpen()) {
            // Server has failed repeatedly - fail fast.
            return [];
        }
        if ('GET' === $method) {
            $fullUrl = $this->getFhirServerUri() . $url . '?' . http_build_query($params);
            $result_json = $this->httpGet($fullUrl, $headers);
        } else {
            $fullUrl = $this->getFhirServerUri() . $url;
            $result_json = $this->httpPost($fullUrl, $postData, $contentType, $headers);
        }
        if ($result_json === false) {
            $this->recordFhirFailure();
            return [];
        }
        $this->recordFhirSuccess();
        return $processFunction(json_decode($result_json, true));
```

- [ ] **Step 5: Integrate into getValueSetInfo**

Replace:

```php
        return $this->httpGet($fullUrl, $headers);
    }
```

with:

```php
        if ($this->isCircuitOpen()) {
            // Server has failed repeatedly - fail fast. false tells the service
            // layer to emit a 502 rather than a misleading empty success.
            return false;
        }
        $response = $this->httpGet($fullUrl, $headers);
        if ($response === false) {
            $this->recordFhirFailure();
            return false;
        }
        $this->recordFhirSuccess();
        return $response;
    }
```

Note this changes the contract of `getValueSetInfo()` from "string" to "string or false". Task 6 updates the only caller.

- [ ] **Step 6: Verify integration points and write discipline**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
echo "--- isCircuitOpen call sites (expect exactly 3) ---"
grep -c 'isCircuitOpen()' FhirOntologyAutocompleteExternalModule.php
echo "--- breaker must NOT be inside the http helpers or token negotiation ---"
sed -n '/public function httpGet/,/^    }/p;/public function httpPost/,/^    }/p;/public function getClientCredentialsToken/,/^    }/p' \
  FhirOntologyAutocompleteExternalModule.php | grep -n 'isCircuitOpen\|recordFhir' || echo "clean - breaker correctly scoped to FHIR entry points only"
```

Expected: `No syntax errors detected`; then `4` (3 call sites + 1 declaration); then `clean - ...`.

- [ ] **Step 7: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "Add circuit breaker for repeated FHIR server failures

Three consecutive failures open the breaker for 60 seconds, during which
the FHIR entry points return immediately instead of dialing out. After the
window one trial request is allowed through; success resets, failure
reopens. This turns a self-hosted Snowstorm restart from a site-wide
REDCap outage into degraded autocomplete on one field type.

recordFhirSuccess only writes when there is state to clear, so a healthy
server costs zero extra writes on the per-keystroke path.

Scoped to the three FHIR entry points rather than the shared http helpers
so it does not also trap OAuth2 token negotiation, which targets a
different host.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---
### Task 4: Fix per-keystroke dictionary load

**Goal:** Eliminate a full `REDCap::getDataDictionary()` call on every autocomplete keystroke, currently incurred even by projects that never use `@HIDECHOICE`.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (`getHideChoice`, ~lines 295-315)

**Acceptance Criteria:**
- [ ] `global $Proj;` declared so the in-memory fast path can actually execute
- [ ] `static` per-request cache keyed `pid|field`
- [ ] `$annotations` initialised to `null` before the branches
- [ ] Dictionary result guarded with `isset()` before indexing
- [ ] `@HIDECHOICE` regex parsing logic byte-for-byte unchanged
- [ ] No `??` operator used
- [ ] `php -l` clean

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php` → `No syntax errors detected`

**Root cause note for the implementer:** the existing code reads `$Proj->metadata[...]` but never declares `global $Proj`, so inside the method `$Proj` is always null. The intended in-memory path has never executed in production — every call has fallen through to `getDataDictionary()`, which builds project metadata structures across several queries. The `$field` argument narrows the returned array, not the underlying work.

**Steps:**

- [ ] **Step 1: Replace the whole method**

Replace:

```php
    function getHideChoice()
    {
        $codesToHide=[];
        if (isset($_GET['field'])){
            $field = $_GET['field'];
            if (isset($Proj->metadata[$_GET['field']])) {
                $annotations = $Proj->metadata[$field]['field_annotation'];
            }
            else if (isset($_GET['pid'])){
                $project_id = $_GET['pid'];
                $dd_array = \REDCap::getDataDictionary($project_id, 'array', false, array($field));
                $annotations = $dd_array[$field]['field_annotation'];
            }
            if ($annotations) {
```

with:

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
            if (isset($Proj->metadata[$field])) {
                $annotations = $Proj->metadata[$field]['field_annotation'];
            }
            else if ($project_id !== null){
                $dd_array = \REDCap::getDataDictionary($project_id, 'array', false, array($field));
                $annotations = isset($dd_array[$field]['field_annotation'])
                    ? $dd_array[$field]['field_annotation']
                    : null;
            }
            if ($annotations) {
```

- [ ] **Step 2: Populate the cache before returning**

Replace the tail of the method:

```php
                    $offset = $matches[0][1] + strlen($matches[0][0]);
                }
            }
        }

        return $codesToHide;
    }
```

with:

```php
                    $offset = $matches[0][1] + strlen($matches[0][0]);
                }
            }
            $cache[$cacheKey] = $codesToHide;
        }

        return $codesToHide;
    }
```

- [ ] **Step 3: Verify**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
echo "--- global \$Proj declared? ---"
grep -n 'global \$Proj;' FhirOntologyAutocompleteExternalModule.php
echo "--- no ?? operator anywhere (must be empty) ---"
grep -n '??' FhirOntologyAutocompleteExternalModule.php || echo "none - good"
```

Expected: `No syntax errors detected`; a line number for `global $Proj;`; `none - good`.

- [ ] **Step 4: MANUAL — user confirms @HIDECHOICE still works**

On a field using this ontology provider, set the annotation `@HIDECHOICE='<code1>,<code2>'` using two codes the user knows appear in their valueset. Type a search that would normally return them. Expected: both codes absent from suggestions, everything else present.

- [ ] **Step 5: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "Stop reloading the data dictionary on every keystroke

getHideChoice read \$Proj->metadata without declaring global \$Proj, so
\$Proj was always null in function scope and the intended in-memory path
never executed. Every autocomplete keystroke therefore fell through to
REDCap::getDataDictionary(), which builds project metadata across several
queries - paid even by the majority of projects that never use
@HIDECHOICE.

Declares the global, adds a per-request cache keyed pid|field, and fixes
an undefined-variable read when field is set but pid is absent. The
@HIDECHOICE parsing itself is unchanged.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Fix OAuth2 token expiry arithmetic

**Goal:** Disarm a latent bug that goes live the moment `authentication_type` switches from `basic` to `cc`.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (`getClientCredentialsToken`, ~lines 905-935)

**Acceptance Criteria:**
- [ ] `* 1000` removed from both the `expires_in` path and the fallback
- [ ] Expiry rule implemented exactly as: `margin = min(60, floor(lifetime / 2))`, expiry = `now + lifetime - margin`
- [ ] Absent `expires_in` uses an assumed 3600s lifetime under the same rule
- [ ] `json_decode` result guarded with `is_array()` before any `array_key_exists`
- [ ] `$_SESSION` remains the storage mechanism — relocation is explicitly deferred
- [ ] `php -l` clean

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && (grep -n '\* 1000' FhirOntologyAutocompleteExternalModule.php | grep -v '//' || echo 'no executable occurrences')` → `No syntax errors detected` then `no executable occurrences`. NOTE: the prescribed replacement deliberately retains `* 1000` inside an explanatory comment, so a raw string count will be 1, not 0 — the assertion is about executable code.

**Why now, while it is dead code:** under Basic Auth this method never runs. Once OAuth2 lands, the bug caches a 3600s token for ~41 days — but everything works perfectly for the first hour, so deployment-window testing passes and it is signed off. It then surfaces days later as silent 401s with an empty dropdown, which `return_no_result` will label "No Results Found" for whoever investigates.

**Steps:**

- [ ] **Step 1: Replace the token-caching block**

Replace:

```php
            $response = $this->httpPost($tokenEndpoint, $params, 'application/x-www-form-urlencoded', $headers);
            $responseJson = json_decode($response, true);
            if (array_key_exists('access_token', $responseJson)) {
                $clear = false;
                $_SESSION['FHIR_ONTOLOGY_TOKEN'] = $responseJson['access_token'];
                if (array_key_exists('expires_in', $responseJson)) {
                    $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + ($responseJson['expires_in'] * 1000);
                } else {
                    $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + (60 * 60 * 1000);
                }
            } elseif (array_key_exists('error', $responseJson)) {
```

with:

```php
            $response = $this->httpPost($tokenEndpoint, $params, 'application/x-www-form-urlencoded', $headers);
            // a false or unparseable response decodes to null, and array_key_exists(null)
            // is a fatal TypeError on PHP 8
            $responseJson = is_string($response) ? json_decode($response, true) : null;
            if (!is_array($responseJson)) {
                error_log("Failed to negotiate auth token : no parseable response from " . $tokenEndpoint);
            } elseif (array_key_exists('access_token', $responseJson)) {
                $clear = false;
                $_SESSION['FHIR_ONTOLOGY_TOKEN'] = $responseJson['access_token'];
                // expires_in is SECONDS (RFC 6749) and $now is seconds - the previous
                // * 1000 cached a 3600s token for roughly 41 days. Renew early by
                // margin = min(60, floor(lifetime / 2)): a minute early for normal
                // lifetimes, halfway through for very short ones, and never an expiry
                // beyond the real one.
                $lifetime = array_key_exists('expires_in', $responseJson)
                    ? (int)$responseJson['expires_in']
                    : 3600;
                if ($lifetime < 1) {
                    $lifetime = 1;
                }
                $margin = (int)min(60, floor($lifetime / 2));
                $_SESSION['FHIR_ONTOLOGY_TOKEN_EXPIRES'] = $now + $lifetime - $margin;
            } elseif (array_key_exists('error', $responseJson)) {
```

- [ ] **Step 2: Verify**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
echo "--- millisecond bug gone from executable code (expect: no executable occurrences) ---"
grep -n '\* 1000' FhirOntologyAutocompleteExternalModule.php | grep -v '//' || echo "no executable occurrences"
echo "--- session storage retained, per user decision to defer relocation (expect 4) ---"
grep -c 'FHIR_ONTOLOGY_TOKEN' FhirOntologyAutocompleteExternalModule.php
```

Expected: `No syntax errors detected`, then `0`, then a non-zero count confirming `$_SESSION` storage is still in place.

- [ ] **Step 3: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "Fix OAuth2 token expiry arithmetic before the OAuth2 migration

expires_in is seconds per RFC 6749 and time() is seconds, but the value
was multiplied by 1000 - caching a 3600s token for roughly 41 days. The
module would keep sending a token the IdP had already expired, producing
silent 401s with no retry and no surfaced error.

The failure is latent for the first hour, so no deployment-window test
would have caught it. Fixed now while the code path is still inert under
Basic Auth.

Also guards json_decode output with is_array before array_key_exists; a
false response decodes to null, which is fatal on PHP 8.

Token storage stays in \$_SESSION - relocating it to server-wide storage
was explicitly deferred to the OAuth2 migration.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Null-safe parsing + unknown actions

**Goal:** Stop a log flood during exactly the outages Task 3 handles, and stop unauthenticated callers triggering PHP warnings and pointless upstream calls.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (`searchOntology` parsing block)
- Modify: `FindValueSetService.php` (action dispatch and the info branch)

**Acceptance Criteria:**
- [ ] `$list['expansion']` is never read before the `is_array()` guard
- [ ] `code`, `system`, `display` accessed via `isset()` ternaries, no `??`
- [ ] Entries with no `code` are skipped rather than producing a bare `|system` value
- [ ] An action that is neither `find` nor `info` returns a 400
- [ ] A `false` from `getValueSetInfo()` surfaces as a 502 carrying an `OperationOutcome`
- [ ] `php -l` clean on both files

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php && php -l FindValueSetService.php` → `No syntax errors detected` twice

**Steps:**

- [ ] **Step 1: Make the expansion parsing null-safe**

This block reflects Task 3 having already landed. Replace:

```php
        // Parse the JSON into an array
        $list = json_decode($json, true);
        $expansion = $list['expansion'];
        $results = array();
        if (is_array($list) && isset($expansion['contains'])) {
            // Loop through results
            $hideChoice = $this->getHideChoice();
            foreach ($expansion['contains'] as $this_item) {

                if (in_array($this_item['code'], $hideChoice)){
                    // in hide choice list
                    continue;
                }
                // Determine the value
                // need to add the system as codes are not unique in SCT
                $this_value = $this_item['code'] . "|" . $this_item['system'];

                // Add to array
                $results[$this_value] = $this_item['display'];
            }
        }
```

with:

```php
        // Parse the JSON into an array
        $list = is_string($json) ? json_decode($json, true) : null;
        $results = array();
        if (is_array($list) && isset($list['expansion']['contains'])) {
            $expansion = $list['expansion'];
            // Loop through results
            $hideChoice = $this->getHideChoice();
            foreach ($expansion['contains'] as $this_item) {

                // code and system are not guaranteed present by FHIR
                $code = isset($this_item['code']) ? $this_item['code'] : '';
                $system = isset($this_item['system']) ? $this_item['system'] : '';
                if ('' === $code) {
                    // nothing storable without a code - skip rather than emitting "|system"
                    continue;
                }
                if (in_array($code, $hideChoice)){
                    // in hide choice list
                    continue;
                }
                // Determine the value
                // need to add the system as codes are not unique in SCT
                $this_value = $code . "|" . $system;

                // Add to array
                $results[$this_value] = isset($this_item['display']) ? $this_item['display'] : $code;
            }
        }
```

- [ ] **Step 2: Reject unknown actions in FindValueSetService.php**

Replace:

```php
    elseif ('info' === $action){
        if (!isset($params['valueSet'])){
            $sendErrorResponse('Invalid Request', 'Missing required parameter "valueSet" for find info.');
        }
        $valueSet = $params['valueSet'];
    }
}
```

with:

```php
    elseif ('info' === $action){
        if (!isset($params['valueSet'])){
            $sendErrorResponse('Invalid Request', 'Missing required parameter "valueSet" for find info.');
        }
        $valueSet = $params['valueSet'];
    }
    else {
        $sendErrorResponse('Invalid Request', 'Unknown action.');
    }
}
```

- [ ] **Step 3: Handle the new false return from getValueSetInfo**

Replace:

```php
else {
    echo $module->getValueSetInfo($valueSet);
}
```

with:

```php
else {
    $info = $module->getValueSetInfo($valueSet);
    if ($info === false) {
        // breaker open, or the server did not answer. Report it as an
        // OperationOutcome so the designer dialog shows a real message
        // instead of a silently empty result.
        http_response_code(502);
        echo json_encode(['resourceType' => 'OperationOutcome',
            'issue' => [['severity' => 'error', 'code' => 'timeout',
                'diagnostics' => 'The terminology server is not responding. Please try again shortly.']]],
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    }
    else {
        echo $info;
    }
}
```

- [ ] **Step 4: Verify**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php -l FindValueSetService.php
echo "--- offset must not precede the guard (expect no match) ---"
grep -n "expansion = \$list\['expansion'\];" FhirOntologyAutocompleteExternalModule.php
echo "--- still no ?? anywhere ---"
grep -n '??' FhirOntologyAutocompleteExternalModule.php FindValueSetService.php || echo "none - good"
```

Expected: two `No syntax errors detected`; the `$expansion` assignment now appears only INSIDE the guard (one match, indented deeper); `none - good`.

- [ ] **Step 5: MANUAL — user confirms unknown action is rejected**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  -d 'action=bogus' 'https://REDCAP_HOST/redcap/external_modules/?prefix=fhir-ontology-provider&page=FindValueSetService'
```

Expected: `400`. Before this task it produced a PHP warning and an upstream call.

- [ ] **Step 6: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php FindValueSetService.php
git commit -m "Make expansion parsing null-safe and reject unknown actions

\$list['expansion'] was read before the is_array guard, so every failed
request logged a PHP 8 warning. During a terminology server outage every
request takes that path, so logs and disk I/O blew up during precisely
the incident where that hurts most. code/system/display are also not
guaranteed present by FHIR.

Separately, an action that was neither find nor info matched no branch
and sent no error, falling through to getValueSetInfo with an undefined
\$valueSet. It now returns 400, and a failed lookup returns 502 with an
OperationOutcome the designer dialog already knows how to render.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Escape Show Details dialog output

**Goal:** Close a stored XSS path that escalates from project designer to admin.

**Files:**
- Modify: `FhirOntologyAutocompleteExternalModule.php` (`getOnlineDesignerSection` JS, success and error handlers)

**Acceptance Criteria:**
- [ ] Success path builds rows via DOM/`.text()`, with no HTML concatenation of `v.display`/`v.code`/`v.system`
- [ ] Error path builds its cell via `createTextNode`, with no concatenation of `issue.diagnostics`
- [ ] Line breaks between issues preserved using appended `<br>` elements
- [ ] The already-safe `.text()` calls above (url/name/version/status/count) are left untouched
- [ ] `php -l` clean

**Verify:** `php -l FhirOntologyAutocompleteExternalModule.php` → `No syntax errors detected`

**Attack path for the implementer:** v0.5 made the ValueSet URL manually editable, which is required for ECL valuesets. A designer can therefore store arbitrary text as a field's ontology category. Snowstorm returns parse errors containing the offending text in `OperationOutcome.issue.diagnostics`. The dialog appends that as raw HTML, so any later viewer — including an admin — who clicks Show Details executes it.

**Heredoc caution:** this JS is inside a PHP `<<<EOD` heredoc. `$('<tr>')` is safe because `(` cannot begin a PHP identifier, but any `$name` you introduce WILL be interpolated. The variables added here are all `var`-declared JS locals, which is safe.

**Steps:**

- [ ] **Step 1: Rewrite the success handler rows**

Replace:

```php
              if (data.expansion && data.expansion.contains){
                for (v of data.expansion.contains){
                  r = "<tr><td class='data'>" + v.display + "</td><td class='data'>" + v.code + "</td><td class='data'>"+v.system+"</td></tr>"
                  $('#fhirValueSet_contains').append(r);
                }
              }
```

with:

```php
              if (data.expansion && data.expansion.contains){
                for (v of data.expansion.contains){
                  // build via DOM so server supplied text can never be parsed as markup
                  var row = $('<tr>');
                  row.append($('<td>').addClass('data').text(v.display));
                  row.append($('<td>').addClass('data').text(v.code));
                  row.append($('<td>').addClass('data').text(v.system));
                  $('#fhirValueSet_contains').append(row);
                }
              }
```

- [ ] **Step 2: Rewrite the error handler cell**

Replace:

```php
              var errorMessage = "Failed to load Valueset - Status : " + xhr.status + "<br>\\n"
              if (errorObject && errorObject.issue){
                for (issue of errorObject.issue){
                  errorMessage += issue.severity + " : " + issue.diagnostics + "<br>\\n";
                }
              }
              $('#fhirValueSet_contains').append("<tr class='error'><td class='data' colspan='3'>" + errorMessage + "</td></tr>");
```

with:

```php
              // build via DOM - diagnostics echoes back text the user supplied as the
              // valueset url, so it must never be concatenated into markup
              var errorCell = $('<td>').addClass('data').attr('colspan', '3');
              errorCell.append(document.createTextNode("Failed to load Valueset - Status : " + xhr.status));
              if (errorObject && errorObject.issue){
                for (issue of errorObject.issue){
                  errorCell.append($('<br>'));
                  errorCell.append(document.createTextNode(issue.severity + " : " + issue.diagnostics));
                }
              }
              $('#fhirValueSet_contains').append($('<tr>').addClass('error').append(errorCell));
```

- [ ] **Step 3: Verify no HTML concatenation of untrusted values remains**

```bash
php -l FhirOntologyAutocompleteExternalModule.php
echo "--- untrusted values concatenated INTO HTML passed to append()/html() (must be empty) ---"
# NOTE: test the real security property, not the presence of '+'. Concatenating inside
# createTextNode(...) is safe; only string-built HTML reaching append()/html() is not.
grep -nE 'append\("|append\('"'"'|\.html\(' FhirOntologyAutocompleteExternalModule.php || echo "none - good"
echo "--- pre-existing safe .text() calls still present (expect 7) ---"
grep -c "fhirValueSet_url').text(data.url)\|fhirValueSet_name').text(data.name)\|fhirValueSet_version').text(data.version)\|fhirValueSet_status').text(data.status)\|fhirValueSet_expansion_count')" FhirOntologyAutocompleteExternalModule.php
```

Expected: `No syntax errors detected`, then `none - good`, then a non-zero count showing the previously-safe fields were not disturbed.

- [ ] **Step 4: MANUAL — user runs the XSS probe**

In the Online Designer, enter this as the ValueSet URL and click **Show Details**:

```
<img src=x onerror=alert('xss')>
```

Expected AFTER the fix: the string renders as literal text inside the dialog; no alert fires.

If the user runs this BEFORE deploying the fix and an alert fires, that also settles the review's open question about whether Snowstorm echoes input into `diagnostics`. Either result is fine — the fix is correct regardless.

- [ ] **Step 5: Commit**

```bash
git add FhirOntologyAutocompleteExternalModule.php
git commit -m "Escape untrusted values in the Show Details dialog

The dialog concatenated FHIR server responses into HTML strings and
appended them. Since v0.5 the valueset url is manually editable, so a
project designer can store arbitrary text as a field's ontology category;
Snowstorm echoes that text back in OperationOutcome.issue.diagnostics,
and any later viewer clicking Show Details executed it - including an
admin, making this a privilege escalation path.

Rows and error cells are now built through the DOM. The adjacent fields
that already used .text() are unchanged.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 9: Document and prepare v0.5.1

**Goal:** Record the shipped changes in the README's existing per-version style and state the deployment and rollback path.

**Files:**
- Modify: `README.md`

**Acceptance Criteria:**
- [ ] A `### Version 0.5.1 changes` section exists, matching the formatting of the existing 0.5 section
- [ ] The new `FHIR request timeout (seconds)` setting is documented in the site-wide settings list
- [ ] Circuit breaker behaviour is documented so an admin seeing 60s of dead autocomplete understands why
- [ ] Deployment-as-new-directory and the rollback path are stated
- [ ] It is stated explicitly that credential masking (S3) is NOT in this release

**Verify:** `grep -c 'Version 0.5.1 changes' README.md && grep -c 'fhir_timeout\|FHIR request timeout' README.md` → both ≥ 1

**Steps:**

- [ ] **Step 1: Document the new setting in the settings list**

Insert this bullet immediately before the existing `  * \`SNOMEDCT Support\`` bullet:

```markdown
  * `FHIR request timeout (seconds)` - the maximum time to wait for a response from the FHIR server before giving up. Defaults to 10 seconds if left blank. Without a limit, a slow or unavailable terminology server holds REDCap web server processes open, which can make the whole of REDCap unresponsive - not just this module. Increase it if large ECL expansions legitimately take longer; decrease it to fail faster.
```

- [ ] **Step 2: Add the changelog section**

Insert immediately before the existing `### Version 0.5 changes` line:

```markdown
### Version 0.5.1 changes

This is a security and performance release. There are no new features.

- ***Terminology lookup web service now requires authentication***
The `FindValueSetService` page was previously declared as a no-auth page, meaning it could be called without logging
in to REDCap. Because the FHIR server is typically on an internal network while REDCap is internet facing, this
allowed anonymous users to query the terminology server through REDCap and read its responses. The no-auth
declaration has been removed; the online designer is unaffected because it never used the no-auth route.
- ***Requests to the FHIR server now time out***
A new `FHIR request timeout (seconds)` setting (default 10) bounds how long REDCap waits for the terminology server.
Previously there was no limit, so a slow or restarting FHIR server could hold web server processes open until the
system default expired and make all of REDCap unresponsive.
- ***Circuit breaker for terminology server failures***
After 3 consecutive failed requests the module stops calling the FHIR server for 60 seconds and returns no results
immediately, then allows a single trial request through to check for recovery. If ontology autocomplete appears dead
for up to a minute after a terminology server restart, this is why. It protects REDCap as a whole from being taken
down by terminology server downtime.
- ***Fixed a data dictionary reload on every keystroke***
`@HIDECHOICE` support was reloading the project data dictionary on every autocomplete keystroke, for every project,
whether or not the field used `@HIDECHOICE`. The lookup is now cached per request and uses the already loaded project
metadata where available.
- ***Fixed OAuth2 token expiry calculation***
Token lifetimes were treated as milliseconds rather than seconds, so an expired token could be reused for weeks,
causing lookups to fail silently. This did not affect Basic Auth or unauthenticated servers, but would have affected
any site using OAuth2 client credentials.
- ***Fixed cross site scripting in the ValueSet details dialog***
The Show Details dialog inserted values from the FHIR server into the page as HTML. Values are now inserted as text.
- ***More robust error handling***
Responses that are not valid JSON, expansions missing `code`, `system` or `display`, and unknown web service actions
are now handled explicitly instead of producing PHP warnings.

**Note:** masking of the stored Basic Auth password and OAuth2 client secret is *not* included in this release. It is
planned for 0.5.2 as it requires the credential to be re-entered after upgrading.

**Deploying:** place this version in a new directory `modules/fhir-ontology-provider_v0.5.1` alongside the existing
version rather than overwriting it. Enable the new version from the External Modules page. To roll back, switch the
enabled version back to the previous one.

```

- [ ] **Step 3: Verify**

```bash
grep -n 'Version 0.5.1 changes' README.md
grep -n 'FHIR request timeout' README.md
grep -n 'fhir-ontology-provider_v0.5.1' README.md
```

Expected: at least one line number from each.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Document v0.5.1 security and performance changes

Records the shipped fixes in the existing per-version changelog style,
documents the new timeout setting, and explains the circuit breaker so an
admin who sees a minute of dead autocomplete after a terminology server
restart knows why.

States the deploy-as-new-directory and rollback path explicitly, since
there is no non-production instance to stage on, and notes that
credential masking is deferred to 0.5.2 because it requires re-entering
the credential.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: Mask credential settings

**Goal:** Stop the Basic Auth password and OAuth2 client secret rendering in cleartext in the module config UI and sitting plaintext in `redcap_external_module_settings`.

> **USER-ORDERED GATE — NON-SKIPPABLE.** This task was requested by the user in the current conversation. It MUST NOT be closed by walking around it, by declaring it "verified inline", or by substituting a cheaper check. Close only after every item in `acceptanceCriteria` has been re-validated independently, with output captured.

**Files:**
- Modify: `config.json` (`cc_client_secret`, `basic_user_password`)

**Acceptance Criteria:**
- [ ] `"type": "password"` support under `framework-version: 1` is CONFIRMED before any edit is made
- [ ] Both settings changed from `"type": "text"` to `"type": "password"`
- [ ] `config.json` remains valid JSON
- [ ] Committed separately from Tasks 1-7 and 9, as its own deployable tranche
- [ ] After deployment, both fields render masked in the module system settings UI
- [ ] After re-entering the Basic Auth password, ontology lookups still return results

**Verify:** `python3 -c "import json;d=json.load(open('config.json'));print([s['type'] for s in d['system-settings'] if s['key'] in ('cc_client_secret','basic_user_password')])"` → `['password', 'password']`

**This ships as v0.5.2, AFTER v0.5.1 is proven in production.** It is isolated because it is the only change that can break authentication outright and the only one resting on an unverified prerequisite. The user has no staging instance.

**Steps:**

- [ ] **Step 1: GATE — confirm framework v1 supports the password type**

No REDCap or External Modules framework source was available when this plan was written, so this could not be checked statically. Confirm it before editing, by whichever is available:

```bash
# if a REDCap install is reachable on this machine, look for the type in the framework
find / -name "ExternalModules.php" -path "*external_modules*" 2>/dev/null | head -3
grep -rn "'password'" <path-to>/external_modules/classes/ExternalModules.php | head
```

Otherwise confirm from the External Modules documentation for the framework version in use.

**If password type is NOT supported under framework-version 1: STOP. Report to the user. Do NOT bump `framework-version`** — that is a larger change than this task justifies and would need its own design.

- [ ] **Step 2: Change both setting types**

```bash
python3 - <<'PYEOF'
import io, json
p = 'config.json'
s = io.open(p, encoding='utf-8').read()
for key in ('cc_client_secret', 'basic_user_password'):
    name = {'cc_client_secret': 'Client Secret', 'basic_user_password': 'Basic Auth User Password'}[key]
    old = '''      "key": "%s",
      "name": "%s",
      "type": "text",''' % (key, name)
    assert s.count(old) == 1, "%s block not found exactly once" % key
    s = s.replace(old, old.replace('"type": "text",', '"type": "password",'))
io.open(p, 'w', encoding='utf-8').write(s)
d = json.loads(s)
print([x['type'] for x in d['system-settings'] if x['key'] in ('cc_client_secret', 'basic_user_password')])
PYEOF
```

Expected: `['password', 'password']`

- [ ] **Step 3: MANUAL — user deploys and verifies both evidence axes**

Both must be observed and reported, not assumed:

1. **masked** — open the module's system settings page. Both `Client Secret` and `Basic Auth User Password` render as masked inputs, not cleartext.
2. **lookup-works** — re-enter the Basic Auth password (the stored value does NOT migrate) and save. Then confirm ontology autocomplete still returns results on a data entry form.

If lookups fail after this change, the cause is almost certainly the un-migrated password. Re-enter it before investigating anything else.

- [ ] **Step 4: Commit separately**

```bash
git add config.json
git commit -m "Mask stored FHIR credentials as password-type settings

The Basic Auth password and OAuth2 client secret were declared as text
settings, so they rendered in cleartext to any admin opening the module
config page and were stored unencrypted.

Shipped as its own tranche (v0.5.2) because it is the only change that
can break authentication outright - the stored value does not migrate and
must be re-entered after upgrading - and because there is no
non-production REDCap available to stage it on.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```
