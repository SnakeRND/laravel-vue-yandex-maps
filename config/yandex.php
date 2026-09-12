<?php

return [
    // yandex.ru often blocks datacenter IPs; yandex.com is more reliable for scraping.
    'base_url' => env('YANDEX_BASE_URL', 'https://yandex.com'),

    // Yandex usually exposes about the last 600 reviews via the card.
    'review_cap' => (int) env('YANDEX_REVIEW_CAP', 600),

    'page_size' => 50,

    // Pause between page requests (microseconds).
    'page_delay_us' => (int) env('YANDEX_PAGE_DELAY_US', 400_000),

    'http_timeout' => (int) env('YANDEX_HTTP_TIMEOUT', 25),

    'user_agent' => env(
        'YANDEX_USER_AGENT',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    ),
];
