<?php

return [
    // Read the .env value while Laravel builds the config cache. Runtime
    // services must use config('firebase_compat.credentials'), not env().
    'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
];
