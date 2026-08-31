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
}
