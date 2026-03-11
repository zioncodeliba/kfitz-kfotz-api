<?php

return [
    'use_test_pdf' => filter_var(env('YPAY_USE_TEST_PDF', false), FILTER_VALIDATE_BOOLEAN),
    'base_url' => env('YPAY_BASE_URL', 'https://ypay.co.il/api/v1'),
    'access_token_path' => env('YPAY_ACCESS_TOKEN_PATH', '/accessToken'),
    'document_generator_path' => env('YPAY_DOCUMENT_GENERATOR_PATH', '/documentGenerator'),
    'client_id' => env('YPAY_CLIENT_ID'),
    'client_secret' => env('YPAY_CLIENT_SECRET'),
    'timeout' => (int) env('YPAY_TIMEOUT', 30),
];
