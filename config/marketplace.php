<?php

return [
    'currency' => env('MARKETPLACE_CURRENCY', 'XOF'),

    // Platform commission is calculated server-side from the paid merchandise
    // subtotal. Keep values in environment/config, never in the mobile app.
    'platform_fee_percent' => (float) env('MARKETPLACE_FEE_PERCENT', 5),
    'platform_fee_flat' => (int) env('MARKETPLACE_FEE_FLAT', 0),

    // Stock is reserved while the customer is at FedaPay checkout.
    'reservation_minutes' => (int) env('MARKETPLACE_RESERVATION_MINUTES', 20),

    // Informational settlement schedule shown to sellers. Payouts still require
    // an explicit admin action.
    'payout_weekday' => env('MARKETPLACE_PAYOUT_WEEKDAY', 'friday'),
    'payout_time' => env('MARKETPLACE_PAYOUT_TIME', '17:00'),

    'business_types' => [
        'fashion', 'beauty', 'food', 'electronics', 'home', 'construction',
        'automotive', 'digital', 'education', 'events', 'professional',
        'agriculture', 'health_wellness', 'travel', 'religious', 'other',
    ],

    'fedapay' => [
        // Reuse the same key names as the subscription integration when present.
        'secret_key' => env('FEDAPAY_SECRET_KEY', env('FEDAPAY_API_KEY')),
        'environment' => env('FEDAPAY_ENVIRONMENT', env('FEDAPAY_MODE', 'live')),
        'webhook_secret' => env('FEDAPAY_WEBHOOK_SECRET'),
        'callback_url' => env('MARKETPLACE_FEDAPAY_CALLBACK_URL', 'celeone://market/payment'),
    ],
];
