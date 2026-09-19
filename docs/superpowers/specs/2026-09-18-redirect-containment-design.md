# Redirect containment for outbound FHIR requests

- **Date:** 2026-09-18
- **Status:** Approved, pending implementation plan
- **Upstream target:** `aehrc/redcap_fhir_ontology_provider`, branched from `upstream/main` @ `a5e2b55`
- **Local branch:** `fix/redirect-containment` (module code only; this spec lives on the fork's `master`)

## Problem

Every FHIR call path in the module passes an `Authorization` header into `httpGet()` /
`httpPost()` — `Basic` (base64 of the configured FHIR username and password) or `Bearer`.
Call sites: lines 315, 889, 931, and `validateSettings()` at 171 and 184.

Those headers are set with `CURLOPT_HTTPHEADER`, and the module's own curl handles set
`CURLOPT_FOLLOWLOCATION = true` with `CURLOPT_MAXREDIRS = 10`
(`curlGetWithTotalTimeout()`, `curlPostWithTotalTimeout()`). curl resends
`CURLOPT_HTTPHEADER` headers across redirects unconditionally —
`CURLOPT_UNRESTRICTED_AUTH` governs only `CURLOPT_USERPWD`-style auth, not custom
headers — and the module installs no `CURLOPT_HEADERFUNCTION` to strip them.

`FhirRequestPolicy::isWithinBase()` runs **once, pre-request** (lines 1074, 1184). It is
never re-evaluated after a redirect, and nothing anywhere reads
`CURLINFO_EFFECTIVE_URL`.

**Consequence:** a `302` response causes the module to send the configured FHIR
credentials to a host of the redirect's choosing, and to fetch from it. Because
`CURLOPT_SSL_VERIFYPEER` is `false` on both handles, an on-path attacker can inject that
`302` without controlling the terminology server. `Basic` auth means a recoverable
password, not merely a bearer token.

The `file_get_contents` fallback has the same exposure: PHP's `follow_location` stream
option defaults to `1`, and the module does not set it.

This defeats precisely the containment boundary added in PR #5 (`09a5bdd`).

## Why this is in scope now

`isWithinBase()`'s docblock already documents this gap, in wording from PR #5:

> It also does not see what happens after the request leaves this check: REDCap core's
> `http_get()`/`http_post()` follow HTTP redirects, so a terminology server that replies
> `302 -> http://169.254.169.254/latest/meta-data/` is followed there regardless of what
> this method decided. Redirect-following and DNS rebinding are both outside what this
> check covers.

That was accurate when the module delegated to core. Upstream #26 moved the curl call
**into the module** to add `CURLOPT_TIMEOUT`, so the caveat now describes the module's
own explicit `CURLOPT_FOLLOWLOCATION`. Nothing was overlooked; the boundary moved under
the comment. This change enforces the boundary and rewrites the stale docblock.

## Approach

Re-validate every redirect hop against the configured base before issuing it. Rejected
alternatives:

- **Refuse all redirects.** ~6 lines, but breaks benign `http -> https` upgrades and
  moved paths on otherwise-correct servers.
- **Follow, then check `CURLINFO_EFFECTIVE_URL`.** The credentials have already left.
  Detection, not prevention. Retained only as logging, never as the control.

Validating each hop before issuing it means an `Authorization` header can only ever
reach an in-base host. No header-stripping logic is required; the ordering is the
control.

## Design

### Pure decision logic

Add to `FhirRequestPolicy` (which is deliberately free of I/O, REDCap, and the EM
framework):

```php
public static function resolveRedirectTarget($location, $currentUrl)
```

Returns an absolute URL string, or `false`. Responsibilities:

- Resolve `$location` against `$currentUrl` for all four RFC 3986 reference forms:
  absolute (`https://h/p`), network-path (`//h/p`), absolute-path (`/p`), and relative
  (`p`).
- Reject a `$location` that is empty, not a string, or whose resolved scheme is not
  `http` or `https`.
- Perform **no** containment judgement. Callers pass the result through the existing
  `isWithinBase($target, $base)`.

Dot-segment merging is deliberately not implemented: `isWithinBase()` already rejects
any path containing a dot segment, in literal, percent-encoded, double-encoded,
backslash and `;`-parameter forms, so a resolved target carrying `..` is refused by the
containment check rather than normalised into something that passes it.

### Transport changes

In both `curlGetWithTotalTimeout()` and `curlPostWithTotalTimeout()`:

- Set `CURLOPT_FOLLOWLOCATION = false`.
- Remove `CURLOPT_AUTOREFERER` and `CURLOPT_MAXREDIRS`, which become inert.
- Wrap the request in a loop capped at **10 hops**, preserving the previous
  `MAXREDIRS 10` semantics. Exhausting the cap is a refusal, logged.
- On a `301`, `302`, `303`, `307` or `308`, read
  `curl_getinfo($curl, CURLINFO_REDIRECT_URL)`. curl populates this exactly when
  `FOLLOWLOCATION` is disabled, already fully resolved — so the curl path needs no
  URL resolution of its own and instead validates curl's own resolved target.
- Pass that target through `isWithinBase($target, $base)`. Off-base, or absent: log and
  return `false`. In-base: re-issue the request against it, carrying the same headers.

**`$base` is fixed for the whole loop.** Every hop is validated against the base the
caller originally supplied — the configured FHIR server, or a `$baseOverride` that is a
genuine containment boundary rather than the URL itself — and
**never** against the URL that issued the redirect.

Corrected after implementation review: an earlier draft of this spec claimed a base that
advanced with the chain would let redirects walk progressively outside the configured
server. That is wrong. `isWithinBase()` is a strict path-prefix check, so a base advanced
to the previous *target* can only ever narrow subsequent checks — it refuses one hop
earlier rather than permitting more. The invariant still matters, but for a different
failure: a base advanced to the target's *origin* drops the path constraint entirely
(`$basePath` becomes `''`, which `isWithinBase()` accepts unconditionally) and would
follow every hop. That is the case the guarding test must catch, and does.

On the `file_get_contents` fallback in both `httpGet()` and `httpPost()`:

- Set `'follow_location' => 0` in the stream context.
- Read `Location` from `$http_response_header`, resolve it with
  `resolveRedirectTarget()`, validate with `isWithinBase()`, and re-issue under the same
  10-hop cap.

This is the one path where `resolveRedirectTarget()` is genuinely required, since there
is no curl to resolve the reference. Behaviour is therefore identical on both
transports.

### Redirect status codes and the POST method

`httpPost()` has three call sites: the auth endpoint (line 193), the token endpoint
(line 1364), and a genuine FHIR POST at line 892 contained by `isWithinBase()`. Because
the loop re-issues requests by hand, it must decide explicitly what curl's
`FOLLOWLOCATION` used to decide implicitly.

- **GET:** follow `301`, `302`, `303`, `307`, `308` when in-base.
- **POST:** follow only `307` and `308`, which preserve method and body. A `301`, `302`
  or `303` in response to a POST is **refused and logged**, because following it would
  silently convert the request to a GET and drop the body — changing the semantics of a
  FHIR operation rather than relocating it. Refusing is the honest outcome.

### The OAuth token endpoint

`httpPost()` admits the token endpoint by **exact match** against the `cc_token_endpoint`
setting, not by containment — so no base exists for a redirect to be contained within.

**Rule: redirects originating from the token endpoint are refused unconditionally.**

This is the single place the design degenerates to "refuse all redirects", and it is
correct there: any redirect away from an exactly-matched endpoint is by definition
outside what was configured.

### Error handling

A refusal calls `error_log()` with the existing `urlForLogging()` helper — which strips
embedded credentials — matching the message shape already used by `httpGet()` and
`httpPost()` for off-base URLs, and returns `false`. Callers already treat `false` as
failure.

Refusals must not interact with the circuit breaker. `FhirRequestPolicy::countsAsFailure()`
is time-based (`SLOW_CALL_RATIO * timeout`), and a refusal returns fast, so a refused
redirect is correctly not counted as evidence of server ill-health. No breaker change is
needed; this is asserted by test, not assumed.

## Testing

`tests/support/FakeHttpTransport.php` shadows `curl_*` by namespace and already records
`url` and `headers` per call. Its `curl_getinfo()` currently hardcodes
`['http_code' => 200]` and must gain a scripted response queue so a test can drive a
`302 -> 200` sequence and supply `CURLINFO_REDIRECT_URL`.

Required coverage:

1. **The security assertion.** After an off-base `302`, `FakeHttpTransport::$calls`
   holds exactly one entry, and no recorded call carries the `Authorization` header to
   the off-base host.
2. In-base `302` is followed, and the second call carries the same headers.
3. Hop cap: 11 chained in-base redirects are refused, not followed indefinitely.
4. Token endpoint: an in-base-looking redirect from `cc_token_endpoint` is still refused.
5. A refused redirect does not increment the breaker's failure count.
6. **Base does not advance:** `base/a` -> `base/b` -> `off-base/c` is refused at the
   third hop, proving the base stayed fixed rather than following the chain.
7. A `302` in response to a POST is refused; a `307` in response to a POST is followed
   with method and body intact.
8. `resolveRedirectTarget()` unit cases: all four reference forms; `//evil.example/p`
   network-path; scheme downgrade `https -> http`; non-HTTP scheme; empty and absent
   `Location`; a target containing `..` (refused downstream by `isWithinBase`).
9. The `file_get_contents` fallback refuses an off-base redirect and follows an in-base
   one.

Tests are PHPUnit, matching what upstream adopted in #19/#20.

## Non-goals

Named here so the change does not overstate its reach:

- **`CURLOPT_SSL_VERIFYPEER = false`** stays as-is. It is a separate core-parity
  argument; dconlan confirmed core hard-codes it `false` on REDCap 16.0.32 and flagged
  newer versions as unverified. Settling it needs REDCap source access that neither
  maintainer nor contributor currently has. Bundling it would sink an otherwise narrow
  security review.
- **DNS rebinding.** No DNS resolution is performed; a configured host that resolves to
  a private address still passes.
- **`Referer` leakage.** Removing `CURLOPT_AUTOREFERER` incidentally stops cross-hop
  `Referer` propagation, but no guarantee is claimed.

## Delivery

- Branch `fix/redirect-containment` from `upstream/main` @ `a5e2b55` — **not** from
  release-please's `release-please--branches--main` (#21), which is force-regenerated on
  every push to `main`.
- Conventional-commit `fix:` prefix, required by upstream's `pr-title-lint` workflow.
- No overlap with #21, which touches only `CHANGELOG.md` and
  `.release-please-manifest.json`.
- **Timing matters.** dconlan will not submit to the official REDCap registry until at
  least 1.0.0. Landing before #21 merges ships the fix *in* the version he submits;
  landing after means 1.0.0 goes to the registry carrying the credential leak.
- Disclosure route is still open and must be settled before the PR is opened, given the
  finding is a live credential-exfiltration path against deployed installs.
