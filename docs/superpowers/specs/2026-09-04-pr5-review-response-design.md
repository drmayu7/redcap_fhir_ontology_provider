# Responding to the upstream review of PR #5

**Date:** 2026-09-04
**Branch:** `pr5-review-response` (working) → `upstream-v0.5.2` (contribution)
**Upstream PR:** [aehrc/redcap_fhir_ontology_provider#5](https://github.com/aehrc/redcap_fhir_ontology_provider/pull/5)

## Background

PR #5 offers the fork's security and performance work back upstream. On 2 September
the maintainer (`dconlan`) posted an adversarial review raising three findings, and
offered a collaboration on his in-progress framework 16 migration.

All three findings were verified against the code before this design was written.
All three are real.

Two further problems, not raised in the review, also block the merge: the PR no
longer merges cleanly, and upstream's newly added CI rejects the PR as currently
titled and versioned.

## Scope

This design covers the three findings plus the merge blockers, on framework 1.

Out of scope: the framework 16 migration. It is a separate follow-on, agreed with
the maintainer. `framework-version`, `php-version-min: 5.4.0` and
`redcap-version-min: 8.8.1` are unchanged by this work.

### Why framework 16 is not bundled

The framework version is orthogonal to all three findings:

- Finding 1 requires the module to issue its own curl calls. The External Modules
  framework has no HTTP helper at any version, so this work is identical on
  framework 1 and 16.
- Finding 2 could use the framework's parameterised `query()` method (available from
  framework 2, with parameterised queries as specified from framework 4), but the
  chosen resolution does not need it (see below).
- Finding 3 deletes dead code.

Moving framework 1 → 16 raises the module's REDCap floor from 8.8.1 to 14.6.4
(15.0.9 LTS) for every institution running it. That is the maintainer's decision,
he already has local work for it, and bundling it would turn a reviewable security
diff into a version-floor change with settings-migration risk.

## Findings and resolutions

### Finding 1 (High) — the timeout does not bound the failure mode it advertises

**Verified.** `httpGet` (line 923) and `httpPost` (line 959) pass the timeout into
REDCap core's `http_get`/`http_post`. Per the reviewer, who read core directly,
those helpers set only `CURLOPT_CONNECTTIMEOUT` and never `CURLOPT_TIMEOUT` when
curl is available — which is the default on essentially every production install.
`CONNECTTIMEOUT` bounds only the TCP handshake, so a terminology server that
accepts the connection and then stalls still parks a PHP worker indefinitely. That
is precisely the scenario the PR description claims to fix.

The `file_get_contents` fallback, used only when curl is absent, does get a true
end-to-end timeout because the stream context `timeout` option is total-time.

**Assumption, unverified by us:** we have no REDCap source access, so the claim
about core's curl options is taken from the reviewer's reading. It is to be
confirmed by him against current REDCap before merge.

**Resolution: stop calling core for outbound FHIR requests.**

Since core's behaviour cannot be read, it cannot be faithfully matched. The module
takes ownership of the request instead, which replaces behaviour we cannot see with
behaviour we fully control.

A single private `fhirRequest()` becomes the one outbound path, called by both
`httpGet` and `httpPost`:

| Condition | Behaviour |
|---|---|
| curl available | Module-owned curl handle setting **both** `CURLOPT_CONNECTTIMEOUT` and `CURLOPT_TIMEOUT` from `getFhirTimeout()` |
| no curl, `allow_url_fopen` on | Existing `file_get_contents` path, unchanged — already correct |
| neither | Return `false`, as today |

Proxy support does not require core either. The module's own fallback path already
demonstrates the expected handling — `sameHostUrl()`, `PROXY_HOSTNAME`,
`PROXY_USERNAME_PASSWORD` — and that logic is mirrored into curl options rather
than reinvented.

Two simplifications fall out. The header workaround at lines 959–971, which exists
because core overwrites `Content-type` when other headers are present, is no longer
needed. And the two wrappers collapse onto one transport.

**Applies to all four call sites**, including the OAuth2 token endpoint (lines 180,
1066). A hung token endpoint parks a worker exactly as badly as a hung FHIR server.
This does not disturb the existing decision to wrap the circuit breaker around the
three FHIR entry points rather than the HTTP helpers; timeouts and breaker remain
separate concerns, so OAuth negotiation against a different host is still not
trapped by the breaker.

#### Open question for the maintainer: TLS verification

Core's TLS options cannot be read from here. If core disables peer verification and
the replacement enables it, every site whose terminology server presents an internal
CA or self-signed certificate breaks — and internally hosted terminology servers are
this module's primary use case. If the replacement disables it, a security release
has quietly weakened transport security.

The default position is to verify, and to raise the question explicitly with the
maintainer rather than guess. This is the one item that genuinely blocks
implementation of Finding 1.

#### Psalm SSRF

Upstream's `security-scan` workflow runs Psalm taint analysis on every PR with
`TaintedSSRF` pinned to `error`. Today the outbound call disappears into core, which
is not in the repository, so no sink is visible. Introducing `curl_setopt($ch,
CURLOPT_URL, …)` makes the sink visible, so the commit that fixes the review is
likely to be the commit that turns CI red.

The resolution is a URL validation helper constraining the request URL to the
configured FHIR base, not a `@psalm-taint-escape` annotation. A real constraint is
a genuine defence and reads correctly in a security PR; a suppression only silences
the scanner.

### Finding 2 (Medium) — breaker atomicity is weaker than documented

**Verified.** `isCircuitOpen()` (line 876) and `recordFhirFailure()` (line 892) both
read-then-write through `getSystemSetting`/`setSystemSetting` with no locking.
Concurrent callers can each observe the open window as expired before any writes the
re-arm, and concurrent failures can lose increments.

**Resolution: correct the claim, not the code.**

The defect is the docblock at line 870, which asserts that "exactly one concurrent
request is allowed through as a trial". The code does not guarantee this, so the
statement is false. Removing a false guarantee is the fix.

Locking was considered and rejected. The breaker exists to prevent a stampede of
hundreds of workers dialling a dead server; a handful of extra probes at a
60-second window boundary is harmless. A lost increment only delays opening from
three failures to four, and self-corrects. The available implementations — a
`GET_LOCK()` round-trip on the hot path of every autocomplete keystroke, or SQL
written directly against the framework-internal
`redcap_external_module_settings` table — both cost more than the property is worth.

The docblock is rewritten to describe actual behaviour: normally one trial request,
occasionally a few under concurrency, and failure counting that may undercount
under concurrent failures. The commit history and PR description carry the same
correction.

### Finding 3 (Low) — the `getHideChoice()` cache is dead

**Verified.** `getHideChoice()` (line 329) is called from exactly one place
(line 293), once per PHP process. The `static $cache` is written and never read,
because each autocomplete keystroke is a separate request.

**Resolution:** delete `static $cache`, the `$cacheKey` construction, the read and
the write, along with the misleading comment claiming a per-keystroke benefit.

`global $Proj` and the `$Proj->metadata[$field]` gating stay. That is the actual
performance fix and it is correct.

Note for the follow-on: on the `concept-enrichment-v0.6.0` branch this function was
extracted as `getFieldAnnotation()` and reused by the save path, where a
per-request cache would do something. Deleting it here does not prejudice that.

## Merge blockers not raised in the review

### The PR no longer merges

`mergeable: CONFLICTING`. Since the PR opened, upstream merged a CI pilot and added
`CHANGELOG.md`, `composer.json`, `psalm.xml`, `stubs/`, and README edits. The PR
touches `README.md` and `config.json`. The contribution branch is rebased onto
current `main`.

### The PR title fails lint

Upstream runs `amannn/action-semantic-pull-request@v6`, requiring conventional
commit titles. "Security and performance remediation (v0.5.1 / v0.5.2)" is
rejected. Retitle with a `fix:` prefix.

### Version numbering is no longer ours to assign

release-please (`release-type: simple`) now derives versions from commit types. The
manifest sits at `0.5.0` and a release PR for `0.5.1` is already queued for
upstream's own changes, so the PR's "v0.5.1 / v0.5.2" naming collides with it.

The README changelog headings stop asserting version numbers. The substantive point
survives as a deployment note rather than a version claim: changing a setting's type
to `password` does not migrate the stored credential, so the Basic Auth password
must be re-entered immediately after upgrading or lookups fail silently with an
empty dropdown.

## Verification

The original PR was verified by hand against a live instance, which the reviewer
flagged. There is no staging instance available and production runs REDCap 16.0.37,
so the riskiest change in this set cannot be exercised safely before merge.

**The plain-PHP test harness from `concept-enrichment-v0.6.0` is ported to this
branch.** No composer, no PHPUnit; runs as `php tests/run.php`. That harness works
because the logic under test lives in pure static functions, which drives one design
decision here: the pure decision-making is extracted from the REDCap-coupled I/O.

- **Breaker** — settings reads and writes stay in the existing methods; the decision
  (given failure count, open-until timestamp, and current time → open? re-arm?
  what count?) moves into pure functions driven directly by tests. These tests also
  demonstrate that the rewritten docblock is accurate.
- **Timeout resolution** — `getFhirTimeout()` (line 861) is nearly pure already.
  Cover blank, zero, negative, non-numeric and valid settings.
- **URL validation** — the helper constraining the URL to the configured FHIR base
  is pure, and belongs under test in a security change.

### Explicitly not covered

Neither the tests nor `php -l` can show that curl actually times out against a
genuinely stalled server, or that TLS verification does not break real deployments.
Both need a live environment. The PR states this boundary rather than implying the
tests prove more than they do.

## Reply to the maintainer

A single comment that:

1. Confirms all three findings are real, having been verified against the code.
2. States the resolution for each, including that the breaker claim is being
   softened rather than locked, with the reasoning.
3. Asks the two questions only he can answer: **what TLS options core sets**, and
   confirmation of the `CURLOPT_TIMEOUT` gap on current REDCap, since we have no
   source access.
4. Accepts the framework 16 collaboration as a separate follow-on, noting that
   local REDCap 16.0.37 supports framework 16 (though not 17, which needs 17.0.1)
   and is therefore useful for testing it.

The TLS question blocks implementation of Finding 1 and is asked in the same
message as the acknowledgement, rather than guessing and shipping a regression into
a security release.

## Branch strategy

Established by the previous round and followed here: the fork's branches carry the
design docs, the contribution branch carries module code only.

- `pr5-review-response` — working branch, holds this spec, the implementation plan
  and the code changes.
- `upstream-v0.5.2` — the branch PR #5 tracks. Receives code-only commits, rebased
  onto current upstream `main`. No `docs/superpowers/` content.
