<?php

return [
    // Values are captured when `php artisan config:cache` runs. Runtime services
    // must read config(), never env() directly.
    'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS', env('FIREBASE_SERVICE_ACCOUNT')),
    'project_id' => env('FIREBASE_PROJECT_ID', env('GOOGLE_CLOUD_PROJECT')),
    'android_package' => env('FCM_ANDROID_PACKAGE', 'com.celeoneapp'),
    'connect_timeout' => (int) env('FCM_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('FCM_TIMEOUT', 20),
];
