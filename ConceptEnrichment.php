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
     * 'found' is false for anything that is not a Parameters resource - the
     * resourceType must literally be "Parameters" and a parameter list must be
     * present, so the 404 OperationOutcome returned for an unknown code is
     * rejected on both counts. Callers must treat found=false as "write
     * nothing", never as "the concept has no properties".
     *
     * A property that the response does not mention is left null. Null means
     * "the server said nothing about this", which is NOT the same as "the
     * concept does not have it" - see buildTargets().
     *
     * @param array $decoded json_decode($response, true)
     * @return array with keys found, fsn, pt, semtag, status, normalform
     */
    public static function extractProperties($decoded)
    {
        $out = array('found' => false, 'fsn' => null, 'pt' => null,
                     'semtag' => null, 'status' => null, 'normalform' => null);
        if (!is_array($decoded) || !isset($decoded['resourceType'])
                || 'Parameters' !== $decoded['resourceType']
                || !isset($decoded['parameter']) || !is_array($decoded['parameter'])) {
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

    /**
     * Build the set of field writes for a concept.
     *
     * Governing rule: a successful lookup rewrites every mapped target,
     * INCLUDING blanking ones the concept does not have - otherwise changing a
     * field from Pneumonia to Appendectomy would leave a lung structure behind.
     *
     * That blanking is only ever done from evidence. Two cases are therefore
     * omitted from the result entirely, leaving whatever is already stored
     * alone:
     *
     *  - An inactive concept carries no normal form. Absence of a normal form
     *    is not evidence of absent attributes, so every attribute target is
     *    omitted. When a normal form IS present, a mapped attribute missing
     *    from it is genuinely absent and IS blanked.
     *  - A scalar source (fsn, pt, semtag, status, normalform) that the
     *    response did not carry is omitted rather than blanked - a well-formed
     *    but incomplete 200 must not erase good data.
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
                if (null === $value) {
                    // Absence of a datum in the response is not evidence that
                    // the concept lacks it: a well-formed but incomplete 200
                    // (no designations, no inactive property) would otherwise
                    // blank fsn/semtag/status across the database with no
                    // outage involved. Every real SNOMED concept has an FSN,
                    // and therefore a semantic tag, and a display, so skipping
                    // loses nothing real while blanking is an authoritative
                    // erasure. Attribute sources above are guarded the same way
                    // by $hasNormalForm.
                    continue;
                }
                $targets[$field] = $value;
            }
        }
        return $targets;
    }
}
