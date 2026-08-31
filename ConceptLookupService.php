<?php
/**
 * AJAX entry point for concept enrichment from the data entry / survey pages.
 *
 * Deliberately NOT listed in config.json "no-auth-pages" - it proxies to an
 * internal terminology server and must require an authenticated REDCap session,
 * for the same reason FindValueSetService.php does.
 *
 * Requests go browser -> REDCap -> FHIR server, never browser -> FHIR server,
 * which keeps credentials server-side and works behind a proxy.
 */

$sendErrorResponse = function ($error, $error_description) {
    $errorArr = ['error' => $error, 'error_description' => $error_description];
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode($errorArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
};

header('Content-type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ('POST' === $method) {
    $params = $_POST;
} elseif ('GET' === $method) {
    $params = $_GET;
} else {
    $sendErrorResponse('Invalid Method', 'Request method must be GET or POST!');
}

if (!isset($params['field']) || '' === $params['field']) {
    $sendErrorResponse('Invalid Request', 'Missing required parameter "field".');
}
if (!isset($params['value']) || '' === $params['value']) {
    $sendErrorResponse('Invalid Request', 'Missing required parameter "value".');
}
if (!is_string($params['field'])) {
    $sendErrorResponse('Invalid Request', 'Parameter "field" must be a string.');
}
if (!is_string($params['value'])) {
    $sendErrorResponse('Invalid Request', 'Parameter "value" must be a string.');
}

$field = $params['field'];
$value = $params['value'];

// stored values are code|system - display was dropped in v0.5 because
// code|display|system exceeded REDCap's 100 character limit
$parts = explode('|', $value);
if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
    $sendErrorResponse('Invalid Request', 'Parameter "value" must be of the form code|system.');
}

// The project is REDCap's own authenticated context for this request, never a
// request parameter. Passing null confines getFieldAnnotation() to the
// in-memory $Proj and never reaches the getDataDictionary() fallback, so a
// caller cannot name another project and read its annotations.
$targets = $module->getEnrichmentTargets(null, $field, $parts[0], $parts[1]);

if ($targets === false) {
    // breaker open, server did not answer, or the code is unknown. Report it
    // as an OperationOutcome so the caller can distinguish this from an
    // empty-but-successful result and leave existing values alone.
    http_response_code(502);
    echo json_encode(['resourceType' => 'OperationOutcome',
        'issue' => [['severity' => 'error', 'code' => 'timeout',
            'diagnostics' => 'The terminology server did not return concept details.']]],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

exit();
