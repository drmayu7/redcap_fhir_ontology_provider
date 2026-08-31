# FHIR Ontology Provider — Concept Enrichment via `CodeSystem/$lookup`

**Date:** 2026-08-31
**Status:** Approved for planning
**Module:** `redcap_fhir_ontology_provider` (currently v0.5.2)
**Target release:** v0.6.0

## Context

Today the module is one-way and stores one thing. `searchOntology()` calls
`ValueSet/$expand`, and the selected concept is persisted as `code|system` — nothing
more. The display string was deliberately dropped in v0.5 because `code|display|system`
exceeded REDCap's 100-character limit and broke label lookup.

That means a record holds `233604007|http://snomed.info/sct` and nothing a human or an
analyst can use without a second lookup. This spec adds a second FHIR operation,
`CodeSystem/$lookup`, to pull the fully specified name, preferred term, semantic tag,
concept status, and SNOMED attribute relationships for the selected concept, and to
persist them into **other** REDCap fields nominated by the project designer.

### The constraint that shapes everything

REDCap's `\OntologyProvider` contract is one-way and stateless. It offers exactly one
method on the data-entry path:

```php
searchOntology($valueset_id, $search_term, $result_limit)  // → array('code|system' => 'display')
```

There is no "user selected concept X" callback. `getLabelForValue()` fires on render, not
on selection. **No part of the provider contract is a natural home for `$lookup`.** The
feature must therefore attach to REDCap somewhere else — via the module's already-declared
page hooks and a new save hook.

Packing enrichment into the stored value is not an option; that is the mistake v0.5
already corrected.

### Deployment assumptions

These shaped the design and must be re-checked if any change.

| Assumption | Value | Consequence |
|---|---|---|
| Terminology server | Self-hosted Snowstorm, `https://api-test.mysnomed.com/fhir` | Verified reachable, Basic auth, HTTP 200 in 0.6s |
| SNOMED edition | **International only**, release 2025-11-01, module `900000000000207008` | No MY extension exists yet; drives the 404 handling decision |
| Auth today | Basic Auth | Reuses `getAuthHeader()` unchanged |
| Local REDCap instance | **None** | Several behaviours remain unverifiable; see Risks |
| PHP floor | 5.4 (`config.json`) | No `??`; prototype already conforms |
| Framework version | 1 | No custom tables; caching must use `$_SESSION` |

## Requirements

Settled during design:

1. Enriched data **persists in other fields** — not in the ontology field, not display-only.
   It must appear in exports and reports.
2. Properties needed: **FSN, preferred term, semantic tag, concept status, and attribute
   relationships**. Parents/`is-a` explicitly not required.
3. Attributes map **named attribute → named field** (not a single blob field).
4. Attribute values are stored as **`code|display`**.
5. Population fires **both** in the browser on selection **and** server-side on save.
6. Mapping is declared by an **action tag on the ontology field**, following the existing
   `@HIDECHOICE` precedent.
7. When an attribute occurs more than once on a concept, **join all distinct values**.
8. Display text is stored **verbatim**, including SNOMED semantic tags.

## Terminology-server findings

All verified by direct probe on 2026-08-31. These are the evidence base for the design,
not assumptions.

### `$lookup` is the right operation, and `normalForm` the right property

The FHIR R4 CodeSystem specification states that SNOMED's role-group and property
relationships *"[do] not surface in FHIR resources or operations."* There is therefore
**no portable structured route to SNOMED attributes**. Ontoserver's nested `subproperty`
structure is a vendor extension.

`normalForm` is the de facto route. Snowstorm documents it, and SNOMED International's own
URI-dereferencing configuration resolves a concept over FHIR with
`$lookup?code={SCTID}&property=normalForm`.

### Request

```
GET {base}/CodeSystem/$lookup
      ?system=http://snomed.info/sct
      &code={code}
      &property=normalForm&property=inactive&property=designation
```

One call returns everything needed: FSN, PT, `inactive`, and `normalForm` with attribute
display names inline. No follow-up calls and no display-name cache are required.

### Server dialect differences — both must be handled

| | Ontoserver | MOH Snowstorm |
|---|---|---|
| Property value part name | `{"name":"value", …}` | `{"name":"valueString", …}` — and `"value"` for `moduleId` |
| `normalForm` prefix | `=== 128601007\|…` | `128601007\|…` (no `===`) |
| Inline display names | `\|Structure of parenchyma of lung\|` | `\|Structure of parenchyma of lung (body structure)\|` |
| Structured `subproperty` | Present | **Absent** |
| `property=` filter honoured | Yes (7 KB → 2 KB) | **No** — returns full default set regardless |

Consequences: the response parser must accept part names `value`, `valueString`, and
`valueCode`; `normalForm` is the **only** attribute route on the deployment target, so
parsing is mandatory, not preferred; and payload size cannot be reduced on Snowstorm —
budget ~7 KB per lookup.

The spec also notes that `property=designation` filtering is **not defined by the standard**
("if a client does not specify which properties to return, the server determines the default
set"). FSN must be treated as optional, with top-level `display` as fallback and the semantic
tag simply unavailable when FSN is absent.

### Error shape

An unknown code returns **HTTP 404** with an `OperationOutcome`, `code=not-found`, and
useful `diagnostics`. This is a *fast* failure, so the existing
`recordFhirFailureIfSlow()` 80%-of-timeout rule already prevents it from tripping the
circuit breaker. No change needed there.

### Unrelated pre-existing defect (not fixed here)

`config.json` advertises `https://snowstorm-fhir.snomedtools.org/fhir` as an example FHIR
API URL. That endpoint now bot-blocks non-interactive clients — it returned
`302 → "SNOMED International Access Denied (reason=browser)"` for both `User-Agent: Redcap`
and a browser UA. Any admin copying the example will fail, on the **existing** `$expand`
path as well as this feature. Tracked separately; out of scope.

## Design

### Approach

Enrichment logic is extracted into a new dependency-free collaborator rather than added to
the already-overloaded 1,100-line module file. The module keeps only thin wiring.

Two approaches were rejected. **Adding it all to the module file** with structured
`subproperty` parsing: rejected because that structure is absent on the deployment server,
is non-standard, is lossy for nested refinements, and would need 4 HTTP calls where 1
suffices. **A generalised property-mapping pipeline** for any code system: rejected as
YAGNI; `ConceptEnrichment` is the seam where a second extractor slots in later if LOINC
property mapping is ever actually requested.

### The `@FHIR-LOOKUP` action tag

Placed in the Field Annotation of the ontology field:

```
@FHIR-LOOKUP='fsn:dx_fsn, pt:dx_pt, semtag:dx_semtag, status:dx_status,
              363698007:dx_finding_site, 116676008:dx_morphology'
```

Each entry is `source:target_field`. Sources are a keyword or a numeric attribute SCTID:

| Source | Derived from | Example value |
|---|---|---|
| `fsn` | designation with `use.code=900000000000003001` | `Pneumonia (disorder)` |
| `pt` | top-level `display` | `Pneumonia` |
| `semtag` | trailing parenthesis of the FSN | `disorder` |
| `status` | `inactive` | `active` / `inactive` / `unknown` |
| `normalform` | `normalForm` property, verbatim | `128601007\|Infectious disease of lung…` |
| *digits* | that attribute within `normalForm` | `113255004\|Structure of parenchyma of lung (body structure)` |

Parse rules mirror `getHideChoice()`:

- Same regex shape and single-quote delimiter; the tag is **repeatable** and entries merge.
- Entries and both halves are `trim()`ed; keywords are case-insensitive.
- A malformed entry is skipped **individually** — one typo must not void the whole mapping.
- Duplicate source: last wins.
- A target not present in the project's metadata is skipped.
- A target equal to the ontology field itself is **refused**, so a bad mapping cannot
  clobber the stored code.

Multi-value separator is `; ` (semicolon-space). `|` is unavailable — it already separates
`code` from `display`, and is SNOMED's expression delimiter so terms cannot contain it.
Semicolons in FSNs are believed absent but unproven; confirm against the release during
implementation.

### Supporting refactor

`getHideChoice()` reads `$_GET['field']` directly, because it only ever runs during the
autocomplete AJAX call. The save-hook path has no such `$_GET`. Extract the
annotation-fetching half into `getFieldAnnotation($project_id, $field)`, preserving both
v0.5.1 performance fixes verbatim — the `global $Proj` declaration and the
`isset($Proj->metadata[$field])` gate (**not** gated on `field_annotation`, which is NULL
for un-annotated fields). Both `getHideChoice()` and the new mapping parser then call it,
so the two paths cannot drift.

### Trigger paths

**The save hook is authoritative; the JavaScript is a preview.** This asymmetry is
deliberate: `@READONLY` fields may render as `disabled`, and disabled inputs are not
submitted by the browser. Making the server the source of truth means whatever `@READONLY`
does to the DOM cannot cause data loss.

**Path A — browser, on selection.** Extends the JS already injected by
`redcap_data_entry_form` / `redcap_survey_page`. Binds to `input.autosug-ont-field`, reads
the selected `code|system`, POSTs it with `pid` and `field` to `ConceptLookupService.php`,
and fills target inputs so the user sees results immediately. Binds to **both** jQuery UI's
`autocompleteselect` and a `change` fallback; a miss is harmless because Path B covers it.

**Path B — `redcap_save_record`, authoritative.** New permission in `config.json`. Reads
the saved ontology value, recomputes every mapped target, writes via `REDCap::saveData()`
carrying `$event_id` and `$repeat_instance` so longitudinal and repeating instruments work.
Covers data imports, API writes, surveys, and any JS failure.

Two hazards handled explicitly:

- **Re-entry.** `saveData()` inside a save hook risks re-triggering it. A `static`
  in-progress guard plus writing only target fields (never the source) bounds it.
- **Cost.** Naively one `$lookup` per save per ontology field.

**Caching.** The parsed `$lookup` result is cached in `$_SESSION` keyed by `code|system`.
Path A runs first and warms the cache; Path B, a later request from the same user, usually
hits it and makes **no HTTP call at all**. Same mechanism and same known limitation as the
existing OAuth2 token cache — per-user, not server-wide — so no new architectural debt.
Imports miss the cache and pay the ~0.6s.

**Circuit breaker.** The new lookup becomes a **fourth FHIR entry point**, wrapped as
`searchOntology` / `findValueSet` / `getValueSetInfo` are — *not* inside `httpGet`,
preserving the boundary documented in CLAUDE.md (which exists so OAuth2 token negotiation
against a different host is not trapped by the breaker).

### `normalForm` parsing

A targeted extractor, not a full SNOMED Compositional Grammar parser. The only question
asked is "for attribute *X*, what are its values":

```php
'/(\d+)\s*\|[^|]*\|\s*=\s*(?:\(?\s*(\d+)\s*\|([^|]*)\||#([0-9.]+)|"([^"]*)")/'
```

Attribute id, then either a `code|display|` value or a SNOMED **concrete value** (`#500`,
`"string"`). Values are deduplicated, kept in document order, joined with `; `.

Verified against 9 responses from the deployment server plus the Ontoserver dialect —
29 attribute extractions, all correct:

| Fixture | Exercises |
|---|---|
| Pneumonia, MI, fracture of neck of femur, abdominal pain | Single role group, single-valued |
| Laparoscopic emergency appendectomy (174041007) | 4 role groups; `Method` → 3 values, `Procedure site` → 3 |
| Paracetamol 500mg tablet (322236009) | Ungrouped + grouped attributes; concrete values `500`, `1` |
| Atherosclerosis (194848007) | **Inactive — no `normalForm` at all** |
| Ontoserver variant | `===` prefix, no spaces, nested refinement |

Two deliberate behaviours:

- **Nested refinements** — `Finding site = (Structure of parenchyma of lung : Laterality =
  Side)` yields `Finding site → Structure of parenchyma of lung`, and `Laterality → Side`
  becomes independently mappable. The focus concept is the correct answer for the outer
  attribute.
- **Role-group provenance is flattened** — a direct consequence of requirement 7. The data
  shows *that* appendectomy has three methods, not which site each pairs with.

**Concrete values are a correctness trap.** The first prototype silently dropped `#500` and
`#1` from the paracetamol concept. Silent omission is the worst failure mode available here:
a blank field reads as "this concept has no strength" rather than "the parser could not read
it". Hence the explicit branch and the regression fixture.

**Value length is significant.** `Procedure site` on laparoscopic appendectomy joins to 239
characters; abdominal pain's finding site is 132 on its own. Guidance: **Notes Box for
attribute targets, Text for `fsn` / `pt` / `semtag` / `status`.**

### Failure semantics

The governing rule:

> **A successful lookup rewrites every mapped target authoritatively — including blanking
> ones the concept does not have. A failed lookup writes nothing at all.**

Both halves are load-bearing. The first prevents stale data: changing a field from
*Pneumonia* to *Appendectomy* must clear `dx_finding_site`, not leave it holding a lung
structure. The second extends v0.5.1's "degradation must not fabricate data" principle,
because enrichment can do worse than fabricate — it can erase. An outage must never blank
correct enrichment during the next save of an unrelated field.

| Situation | Behaviour |
|---|---|
| Breaker open | Skip entirely. No HTTP, no writes. |
| Timeout / connection failure | No writes. `recordFhirFailureIfSlow()` as today. |
| 200, active concept | Full authoritative rewrite of all mapped targets. |
| 200, inactive concept (no `normalForm`) | Write `fsn` / `pt` / `semtag`; `status` → `inactive`; **attribute targets untouched** — absence of a normal form is not evidence of absent attributes. |
| 200, attribute genuinely absent | Blank that target. A real answer, not a failure. |
| Source field emptied | Blank all targets. No HTTP needed. |
| 404 / `not-found` | **No writes.** `status` → `unknown` if mapped. |

**Why 404 does not clear fields.** The deployment server is International-only. If MOH later
builds an MY extension and a project stores an MY-namespace code while the module still
points at an International endpoint, every such code returns `not-found`. Treating that as
"concept does not exist, blank the targets" would let a configuration change silently wipe
enrichment across the database. Leaving targets untouched makes that scenario visibly stale
rather than destructively empty.

**`@READONLY`.** The module never applies it; the designer does, and README documents it as
recommended for target fields. Because the save hook is authoritative, whether REDCap renders
`@READONLY` as `readonly` or `disabled` does not affect correctness. Targets should carry
plain `@READONLY` rather than the `-FORM` / `-SURVEY` variants so both contexts are covered.

**Diagnostics.** Avoid depending on `$this->log()` — its availability under
`framework-version: 1` cannot be verified without REDCap. The optional `status` target is
the in-band channel: `active` / `inactive` / `unknown`.

## Components

```
ConceptEnrichment.php          pure functions — no HTTP, no REDCap, no framework
  parseMapping($annotation)    → array(source => target_field)
  extractProperties($decoded)  → fsn, pt, semtag, status, normalForm
  parseNormalForm($nf)         → array(attrSctid => array of values)
  buildTargets($decoded, $map) → array(target_field => value)

ConceptLookupService.php       authenticated AJAX entry point; validates params and
                               dispatches to the module. No logic of its own.
                               Mirrors FindValueSetService.php. Deliberately NOT in
                               no-auth-pages.

FhirOntologyAutocompleteExternalModule.php   thin additions only
  getFieldAnnotation($pid, $field)   extracted from getHideChoice()
  lookupConcept($code, $system)      HTTP + breaker + $_SESSION cache
  enrichRecord(...)                  orchestration + REDCap::saveData()
  redcap_save_record(...)            new hook
  + JS extension in the two existing display hooks
```

`ConceptEnrichment` requires none of the six REDCap symbols the module depends on and opens
no sockets, so it is fully testable offline.

`config.json`: one line — `redcap_save_record` added to `permissions`. No new settings; the
presence of `@FHIR-LOOKUP` is the switch. `framework-version` stays `1`.

## Testing

This is a deliberate departure from a documented constraint. CLAUDE.md currently states
*"no tests… its absence is expected, not a broken setup."* This feature adds the first logic
in the module worth testing and the first testable without a REDCap instance.

```
tests/fixtures/*.json     12 fixtures: 9 captured Parameters responses,
                          2 captured 404 OperationOutcomes, 1 hand-maintained
                          Ontoserver-dialect response
tests/run.php             plain PHP asserts, exit code, no composer
```

No framework and no dependencies, consistent with the repo's constraints. Coverage:

- **Mapping parse** — valid, malformed entry, duplicate source, repeated tag,
  self-referencing target, unknown target.
- **Response parse** — both server dialects; `value` vs `valueString` vs `valueCode` part
  names; FSN present and absent.
- **`normalForm`** — every fixture: multi-role-group, ungrouped, concrete values, nested
  refinement, absent.
- **Failure paths** — 404 `OperationOutcome` leaves targets untouched; malformed and
  non-JSON responses write nothing.

Verification becomes:

```bash
php -l FhirOntologyAutocompleteExternalModule.php
php -l FindValueSetService.php
php -l ConceptEnrichment.php
php -l ConceptLookupService.php
python3 -c "import json;json.load(open('config.json'));print('valid')"
php tests/run.php
```

CLAUDE.md's "no build, no tests" section must be rewritten to say tests exist for
`ConceptEnrichment` only, and that everything else remains unverifiable locally.

### Fixture capture

Fixtures are reproducible; credentials come from the gitignored `.env`:

```bash
set -a && . .env && set +a
# the last two codes do not exist and capture the 404 OperationOutcome fixtures
for code in 233604007 80146002 232717009 5913000 21522001 194848007 174041007 22298006 322236009 \
            428191000124101 racecar; do
  curl -sS -m 45 -u "$SNOWSTORM_BASIC_AUTH_USERNAME:$SNOWSTORM_BASIC_AUTH_PASSWORD" \
    -H 'Accept: application/fhir+json' -H 'User-Agent: Redcap' \
    "$SNOWSTORM_FHIR_URL/CodeSystem/\$lookup?system=http%3A%2F%2Fsnomed.info%2Fsct&code=$code&property=normalForm&property=inactive&property=designation" \
    -o "tests/fixtures/$code.json"
done
```

The Ontoserver-dialect fixture is hand-maintained; `tx.ontoserver.csiro.au` serves the
SNOMED CT **Australian** edition and is used only to exercise the alternative response
shape, never as a content reference.

## Deployment

`fhir-ontology-provider_v0.6.0/`, coexisting with `v0.5.2` in Control Center — which remains
the rollback mechanism. Unlike the v0.5.1 → v0.5.2 step, no setting changes type, so no
credential re-entry is required.

README additions: the `@FHIR-LOOKUP` reference, Notes Box guidance for attribute targets, the
`@READONLY` recommendation, and a v0.6.0 changelog section.

## Risks and limitations

Carried forward as stated risks, not resolved.

| Risk | Impact | Mitigation |
|---|---|---|
| Autocomplete selection event/DOM unverified | Path A may not fire | Path B is authoritative; bind to two events; a miss is harmless |
| `saveData()` may re-enter `redcap_save_record` | Infinite loop | `static` in-progress guard; write only target fields |
| `@READONLY` rendering (`readonly` vs `disabled`) unknown | JS preview may not submit | Path B authority makes this irrelevant to correctness |
| REDCap field length limits unknown | 239-char values may truncate | Notes Box guidance; confirm on first REDCap access |
| `$_SESSION` cache is per-user | Cache misses across users | Accepted; matches existing OAuth2 token cache limitation |
| Semicolons in FSNs unproven absent | Separator collision | Confirm against the release during implementation |
| `normalForm` is SNOMED-specific | No LOINC/ICD support | Accepted; out of scope by requirement |
| Role-group provenance lost | Cannot pair method with site | Accepted; direct consequence of requirement 7 |
| Snowstorm ignores `property=` filter | ~7 KB per lookup | Accepted; `$_SESSION` cache limits frequency |

## Out of scope

- **Parents / `is-a` / ancestors** — explicitly not required.
- **Bulk back-fill of existing records** — no tool to re-run enrichment across a project
  after adding a target field or a SNOMED release. Worth revisiting once the feature is in
  use.
- **A mapping validation report page** — considered and dropped. Typos in `@FHIR-LOOKUP`
  fail silently. Reconsider if that proves painful in practice.
- **Non-SNOMED code systems** — LOINC property mapping is not requested.
- **Server-wide caching** — blocked by `framework-version: 1`; would need a framework bump,
  which is its own decision.
- **The `snowstorm-fhir.snomedtools.org` example URL defect** in `config.json` — real, but a
  separate pre-existing bug on the `$expand` path.
