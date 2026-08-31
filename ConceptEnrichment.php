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
}
