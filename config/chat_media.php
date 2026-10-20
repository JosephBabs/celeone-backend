<?php

return [
    // Public/local VPS disk by default. Change this only if the media disk is
    // mounted elsewhere on the same server.
    'disk' => env('CHAT_MEDIA_DISK', 'public'),

    // If cdn.celeonetv.com serves Laravel's public storage path, set:
    // CHAT_MEDIA_PUBLIC_BASE_URL=https://cdn.celeonetv.com/storage
    // Leave blank to use Storage::disk(...)->url().
    'public_base_url' => env('CHAT_MEDIA_PUBLIC_BASE_URL', ''),
];
