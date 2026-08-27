# FHIR Ontology Provider — Security & Performance Remediation

**Date:** 2026-08-27
**Status:** Approved for planning
**Module:** `redcap_fhir_ontology_provider` (currently v0.5)
**Target release:** v0.5.1, with v0.5.2 as a follow-on tranche

## Context

The module lets a FHIR terminology server act as a REDCap Ontology Provider via
`ValueSet/$expand`. A line-by-line review of all 1,194 lines (`FhirOntologyAutocompleteExternalModule.php`,
`FindValueSetService.php`, `config.json`) found ten issues. This spec covers the eight
being remediated; the other two are listed as deliberately out of scope. An eleventh
(Basic Auth over cleartext HTTP) was raised during review and resolved as not applicable
once the server URL was confirmed as HTTPS.

### Deployment assumptions

These shaped the priorities and must be re-checked if any of them change.

| Assumption | Value | Consequence |
|---|---|---|
| Terminology server | Self-hosted Snowstorm | S1 becomes a network-boundary crossing, not just licence abuse |
| Server URL | `https://api-test.mysnomed.com/fhir` | HTTPS confirmed — no cleartext-credential finding |
| Auth today | Basic Auth | S3 applies now; P3 is currently dead code |
| Auth planned | OAuth2 client credentials | P3 must be fixed *before* that migration |
| Threat model | Institutional REDCap, semi-trusted users | Privilege escalation and anonymous access treated as real |
| Non-production instance | **None** | Drives the two-tranche rollout and the settings-only test strategy |

`api-test.mysnomed.com` reads as a test endpoint; production is assumed to differ by
hostname only. Timeout tuning should be re-validated against production, where ECL
expansion timings may differ.

## Problem statement

Two problems dominate, and they compound each other.

**The terminology proxy is unauthenticated.** `config.json` declares
`"no-auth-pages": ["FindValueSetService"]`. The endpoint has no authorisation check of
any kind. Because Snowstorm is self-hosted and internal while REDCap is internet-facing
(surveys), this module is a supported route for anonymous users to query an internal-only
service and read its responses verbatim.

**Outbound calls are unbounded.** Every keystroke triggers a synchronous FHIR call with
`null` passed as the timeout. Self-hosted Snowstorm goes down *predictably* — restarts,
upgrades, Elasticsearch reindex, cold-start warm-up. During those windows every keystroke
parks a PHP-FPM worker until the system default expires. Workers are a shared, fixed pool,
so routine Snowstorm maintenance can take **all of REDCap** offline, including projects
that never use this module.

Together: an anonymous attacker can trigger unbounded outbound calls at will.

### Why the existing `return_no_result` setting does not address this

`return_no_result` is evaluated *after* `httpGet()` returns. It is a presentation fallback
that decides what the dropdown shows once a call has finished failing; it cannot shorten
the wait. Worker exhaustion is untouched. It is also actively misleading during an outage,
showing staff a tidy "No Results Found" while the real cause is an unreachable server.
The setting is retained for genuine no-match cases.

## Findings in scope

| ID | Severity | Issue | Location |
|---|---|---|---|
| S1 | Critical | Unauthenticated terminology proxy into internal network | `config.json:167-169` |
| P1 | Critical | Unbounded outbound calls; no failure backoff | `…Module.php:777, 816, 819` |
| P2 | Critical | Full data-dictionary load per keystroke | `…Module.php:295-315` |
| P3 | High | Token expiry off by 1000×; latent until OAuth2 | `…Module.php:916, 918` |
| S2 | High | Stored XSS in Show Details dialog | `…Module.php:425-426, 448` |
| P5 | Medium | Array offset read before `is_array()` guard | `…Module.php:262` |
| S5 | Medium | Unknown action falls through to undefined variable | `FindValueSetService.php:50-81` |
| S3 | Low | Credentials stored/displayed in plaintext | `config.json:141-163` |

**Out of scope, deliberately:**

- **S4** (unescaped reflection in the HTML error branch) — defused because
  `Content-type: application/json` is already queued when the closure runs, so browsers
  will not parse it as HTML. Hardening only.
- **P4** (module loads on every page) — correct given the Ontology Provider extension
  point. Documented for overhead accounting; no change.
- **Module-side response caching** — Snowstorm is self-hosted and caches expansions
  itself. Deliberately dropped after discussion.
- **Token storage relocation** (`$_SESSION` → server-wide) — user-deferred to the OAuth2
  migration project. The P3 *arithmetic* fix still lands now.

## Design

### S1 — Close the unauthenticated endpoint

Remove the `no-auth-pages` block. That is the whole change.

The designer builds its URL via `getUrl('FindValueSetService.php', false, true)` —
`$noAuth = false`, so the emitted URL carries no `&NOAUTH`. The `no-auth-pages` entry only
*permits* unauthenticated access when `&NOAUTH` is requested, which is how an attacker
bypasses auth today. Removing the entry should therefore leave designer traffic untouched,
which makes this both the highest-value and lowest-risk change in the set.

*Fallback:* if the designer does break, restore the entry and gate inside
`FindValueSetService.php` on an authenticated user with design rights on `pid`. Same
outcome, more code. Only reached if the simple removal fails verification.

### P1a — Configurable timeout

New `fhir_timeout` system setting, default 10s, threaded into all four outbound paths:
the `http_get` curl call, the `http_get` `file_get_contents` fallback, both `http_post`
curl calls, and the `http_post` fallback stream context.

A setting rather than a constant, so it can be tuned against production without a
redeploy — this matters given ECL expansion timings on self-hosted Snowstorm are unknown
and there is no staging instance.

Argument positions are confirmed by the module's own existing calls:
`http_get($url, $timeout, $basic_auth, $headers, $cookies)` and
`http_post($url, $params, $timeout, $content_type, $basic_auth, $headers)`.

Blank or invalid values fall back to `DEFAULT_TIMEOUT`.

### P1b — Circuit breaker

Consecutive-failure counter held in system settings (the framework accepts keys not
declared in `config.json`, so no UI entry is needed for breaker state).

- 3 consecutive failures opens the breaker for 60s.
- While open, the three FHIR entry points return immediately without dialling out.
- After the window, one trial request is allowed through (half-open). Success resets;
  failure reopens.

**Write discipline:** `recordFhirSuccess()` only writes when there is state to clear. A
healthy server therefore costs zero extra writes and one framework-cached read per request
— important, since this sits on the per-keystroke path.

**Scope boundary:** the breaker guards the FHIR server only, *not* the OAuth2 token
endpoint, which is a different host. It is therefore integrated at the three FHIR entry
points (`searchOntology`, `findValueSet`, `getValueSetInfo`) rather than centrally inside
`httpGet`/`httpPost`, which would also catch token negotiation.

`getValueSetInfo()` returns `false` on failure so the service layer can emit a 502 carrying
an `OperationOutcome` — a shape the dialog's existing error handler already parses.

### P2 — Stop the per-keystroke dictionary load

The method reads `$Proj->metadata[...]` without declaring `global $Proj`, so `$Proj` is
always null in function scope. The intended in-memory fast path has **never** executed;
every call has fallen through to `REDCap::getDataDictionary()`, which builds project
metadata structures across several queries. The `$field` argument narrows the returned
array, not the underlying work.

Four changes: declare `global $Proj`; add a `static` per-request cache keyed `pid|field`;
initialise `$annotations = null` (removing the undefined-variable read when `field` is set
but `pid` is absent); guard the dictionary result with `isset()` before indexing. The
`@HIDECHOICE` regex parsing is unchanged.

*Rejected:* skipping the lookup for fields without `@HIDECHOICE`. You cannot know whether
the tag is present without reading the annotation, so caching is the correct lever.

### P3 — Disarm the OAuth2 trap

`expires_in` is seconds per RFC 6749; `time()` is seconds. The `* 1000` is a milliseconds
conversion that does not belong, caching a 3600s token for ~41 days.

The failure shape is what makes this urgent: **everything works perfectly for the first
hour**, so deployment-window testing passes and the change is signed off. The token then
lapses upstream while the module keeps sending it, producing silent 401s with no retry and
no surfaced error — and `return_no_result` will tell whoever investigates "No Results Found".

Fix: remove `* 1000` from both the `expires_in` path and the fallback, and renew early by
an explicit rule: `margin = min(60, floor(lifetime / 2))`, cached expiry =
`now + lifetime - margin`. This renews a minute early for normal token lifetimes and
halfway through for very short ones, and can never produce an expiry beyond the real one.
The fallback when `expires_in` is absent uses a 3600s assumed lifetime under the same rule. Also guard `json_decode` output with `is_array()` before
`array_key_exists` — a `false` response yields null, and `array_key_exists(null)` is fatal
on PHP 8.

Fixed now, while inert, rather than debugged live after the migration.

### S2 — Escape the Show Details dialog

Two sites concatenate upstream JSON into HTML and inject it with `.append()`. Adjacent
fields already use `.text()` correctly and are left alone.

The error path is the exploitable one. v0.5 made the ValueSet URL manually editable
(required for ECL valuesets), so a designer can store arbitrary text as a field's ontology
category. Snowstorm returns parse errors containing the offending text in
`OperationOutcome.issue.diagnostics`. Any later viewer — including an admin — clicking
Show Details executes it. Designer → admin escalation.

Fix by DOM construction: `$('<td>').addClass('data').text(...)` and `createTextNode` for
the error path, with `<br>` elements preserving inter-issue breaks. This JS lives inside a
PHP heredoc; `$(` is safe from interpolation since `(` cannot begin an identifier, but any
new `$` usage needs review.

### P5 / S5 — Null-safety and unknown actions

`$expansion = $list['expansion']` executes *before* the `is_array()` guard, so every failed
request logs a PHP 8 warning. During a P1 outage every request takes that path, so logs and
disk I/O blow up during precisely the incident where that hurts most. `code`, `system`, and
`display` are not guaranteed present by FHIR. Entries with no code are skipped rather than
producing a bare `|system` value.

An action that is neither `find` nor `info` matches no branch and sends no error, falling
through to `getValueSetInfo()` with an undefined `$valueSet`. An `else` branch returns 400.

**Constraint:** `config.json` declares `php-version-min: 5.4.0`, so the null-coalescing
operator `??` (PHP 7+) must not be used. `isset()` ternaries throughout. Raising the
declared minimum is a larger decision than this work justifies.

### S3 — Mask credentials

Switch `cc_client_secret` and `basic_user_password` to `"type": "password"`.

**Scope limit, confirmed during implementation.** This masks the fields in the config
UI only. The External Modules documentation states plainly: *"Values saved with a password
setting are still stored as plain text. It is not encrypted."* The credential therefore
remains readable in `redcap_external_module_settings` and in database backups. S3 has two
halves — cleartext display and at-rest exposure — and this change closes only the first.
Closing the second needs a different mechanism and is not attempted here.

Two open risks, which is why this ships separately:

1. **Unverified.** No REDCap or External Modules framework source was available, so
   `"type": "password"` support under `framework-version: 1` could not be confirmed.
   Verify first. If unsupported, stop and report — do not unilaterally bump the framework
   version.
2. **Operational.** The stored value will not migrate. The Basic Auth password must be
   re-entered after deploying, or lookups will start failing.

## Rollout

No non-production REDCap exists, so risk is managed by tranching and by REDCap's
directory-based module versioning.

**v0.5.1** — S1, P1a, P1b, P2, P3, P5, S5, S2, plus README.
**v0.5.2** — S3 alone, only after v0.5.1 is proven in production.

Ship as `fhir-ontology-provider_v0.5.1` alongside the existing `_v0.5` rather than editing
in place: enabling is a version switch, and rollback is switching back.

S3 is isolated because it is the only change that can break authentication outright, and it
is the only one resting on an unverified prerequisite.

## Verification

There is no test framework in this repo; verification is manual against the live instance.
Order matters — S1 first, since a regression there blocks everything else.

1. **S1** — request the endpoint with `&NOAUTH` appended; expect auth failure, not JSON.
   Then exercise the designer fully: search by name, refset, and isa; Select; manual ECL
   URL entry; Show Details.
2. **P1** — set `fhir_timeout` to `1` in module settings. Confirm lookups fail fast rather
   than hanging, and that three failures trip the breaker so the fourth returns instantly
   without an outbound call. Confirm recovery after 60s. Restore to `10`.
   *This replaces blackholing the server — it exercises both P1a and P1b from the settings
   page, is fully reversible, and requires no DNS changes on production.*
3. **P2** — confirm autocomplete still returns results, and that a field annotated
   `@HIDECHOICE='code1,code2'` still suppresses exactly those codes.
4. **S2** — enter `<img src=x onerror=alert('xss')>` as the ValueSet URL and click Show
   Details. Must render as literal text. Running this *before* the fix also settles the
   review's open question about whether Snowstorm echoes input into `diagnostics`.
5. **S5** — request `action=bogus`; expect a 400 JSON error, not a warning.
6. **Regression** — data entry form and survey page render; the tooltip setting still works.
7. `php -l` on both PHP files; `json.load` on `config.json`.

Actual output is reported for each. No step is claimed as passing without it.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| S1 removal breaks the designer | Low — designer sends no `&NOAUTH` | Documented in-code fallback; caught by verification step 1 |
| Breaker writes add DB load | Low | Writes only on state transition; healthy path writes nothing |
| Timeout too aggressive for ECL | Medium — prod timings unknown | Setting, not constant; tunable without redeploy |
| `password` type unsupported on framework v1 | Unknown | Verify before editing; separate tranche; stop-and-report |
| Basic Auth breaks after S3 | High if re-entry forgotten | Called out in the task, the changelog, and here |
| No staging instance | Certain | Two tranches; settings-only tests; version-switch rollback |
