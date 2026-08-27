<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';

function status_lights_main(): void
{
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    $system = new StatusLightsRealSystem();
    $response = status_lights_handle_legacy_request($system, $_SERVER);

    // @codeCoverageIgnoreStart
    // Justification: Interacts directly with the PHP SAPI and terminates the request.
    status_lights_emit_response($response);
    exit;
    // @codeCoverageIgnoreEnd
}

if (!defined('STATUS_LIGHTS_TESTING')) {
    // @codeCoverageIgnoreStart
    // Justification: HTTP front-controller execution is exercised by integration deployment, not PHPUnit.
    status_lights_main();
    // @codeCoverageIgnoreEnd
}
