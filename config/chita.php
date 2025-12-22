<?php

return [
    'base_url' => env('CHITA_API_BASE_URL', 'https://chita-il.com/RunCom.Server/Request.aspx'),
    'token' => env('CHITA_API_TOKEN', ''),
    'app_name' => env('CHITA_API_APP', 'run'),
    'program' => env('CHITA_API_PROGRAM', 'ship_status_xml'),
    /**
     * Default argument prefix for shipment number (ARGUMENTS=-N{ship_no}).
     */
    'argument_prefix' => env('CHITA_API_ARGUMENT_PREFIX', '-N'),
];
