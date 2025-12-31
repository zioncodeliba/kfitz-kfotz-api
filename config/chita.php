<?php

return [
    'base_url' => env('CHITA_API_BASE_URL', 'https://webhook.site/f2b136a1-cc56-42ae-a235-2f237da393e6'),
    'token' => env('CHITA_API_TOKEN', ''),
    'app_name' => env('CHITA_API_APP', 'run'),
    'program' => env('CHITA_API_PROGRAM', 'ship_status_xml'),
    'create_program' => env('CHITA_API_CREATE_PROGRAM', 'ship_create_anonymous'),
    'customer_number' => env('CHITA_CUSTOMER_NUMBER', ''),
    'shipment_type' => env('CHITA_SHIPMENT_TYPE', ''),
    'shipment_stage' => env('CHITA_SHIPMENT_STAGE', ''),
    'company_name' => env('CHITA_COMPANY_NAME', ''),
    'response_type' => env('CHITA_API_RESPONSE_TYPE', 'XML'),
    'cod_type_code' => env('CHITA_COD_TYPE_CODE', ''),
    'pickup_point_assign' => env('CHITA_PICKUP_POINT_ASSIGN', 'N'),
    /**
     * Default argument prefix for shipment number (ARGUMENTS=-N{ship_no}).
     */
    'argument_prefix' => env('CHITA_API_ARGUMENT_PREFIX', '-N'),
];
