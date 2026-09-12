<?php

return [
    'base_url' => env('YANDEX_BASE_URL', 'https://yandex.com'),

    'review_cap' => (int) env('YANDEX_REVIEW_CAP', 0),

    'page_size' => 50,

    'page_delay_us' => (int) env('YANDEX_PAGE_DELAY_US', 100_000),

    'http_timeout' => (int) env('YANDEX_HTTP_TIMEOUT', 25),

    'user_agent' => env(
        'YANDEX_USER_AGENT',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    ),
];
