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
}
