<?php

return [
    'base_url' => env('CASHCOW_BASE_URL', 'https://api.cashcow.co.il'),
    'token' => env('CASHCOW_TOKEN'),
    'store_id' => env('CASHCOW_STORE_ID'),
    'page_size' => env('CASHCOW_PAGE_SIZE', 20),
    'notify_email' => env('CASHCOW_NOTIFY_EMAIL'),
    'orders_site_url' => env('CASHCOW_ORDERS_SITE_URL', 'https://www.kfitzkfotz.co.il/'),
];
