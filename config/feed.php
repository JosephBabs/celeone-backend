<?php
return [
    'candidate_pool' => 200,
    'max_consecutive_author' => 2,
    'author_page_cap' => 3,
    'suggestion_interval' => 4,
    'cursor_ttl_minutes' => 120,
    'cache_store' => env('FEED_CACHE_STORE', 'redis'),
    'object_ttl_seconds' => 86400,
];
