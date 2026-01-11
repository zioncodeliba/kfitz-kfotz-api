<?php

return [
    'base_url' => env('CASHCOW_BASE_URL', 'https://api.cashcow.co.il'),
    'token' => env('CASHCOW_TOKEN'),
    'store_id' => env('CASHCOW_STORE_ID'),
    'page_size' => env('CASHCOW_PAGE_SIZE', 20),
    'notify_email' => env('CASHCOW_NOTIFY_EMAIL'),
    'orders_site_url' => env('CASHCOW_ORDERS_SITE_URL', 'https://www.kfitzkfotz.co.il/'),
    'price_site_id' => env('CASHCOW_PRICE_SITE_ID'),
    'price_site_url' => env('CASHCOW_PRICE_SITE_URL'),
    'price_field' => env('CASHCOW_PRICE_FIELD', 'prices.sell_price'),
    'image_field' => env('CASHCOW_IMAGE_FIELD', 'image_url'),
    'timezone' => env('CASHCOW_TIMEZONE', 'Asia/Jerusalem'),
];
