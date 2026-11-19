<?php

// SocialMedia Manager settings. Values come from the .env file in this copy's data folder.
return [
    // local = on the owner's laptop with no login screen; hosted = online with a login (Phase 4).
    'mode' => env('SMM_MODE', 'local'),

    // live posts for real (only allowed in the App folder); dry-run never calls the networks' posting APIs.
    'publish_mode' => env('SMM_PUBLISH_MODE', 'dry-run'),

    'port' => (int) env('SMM_PORT', 8090),

    'backup_dir' => env('SMM_BACKUP_DIR'),
    'backup_keep' => 14,
    'usb_reminder_days' => 7,

    // The app stops after this many minutes without use (smm:serve).
    'idle_minutes' => 30,

    // The daily jobs (smm:daily) count as late after 26 hours.
    'heartbeat_stale_minutes' => 1560,
    'token_expiry_warning_days' => 7,

    'display_timezone' => 'Asia/Kolkata',

    // The owner's photos and videos (read only, never changed): Test send picks from here, the library later.
    'library_source' => env('SMM_LIBRARY_SOURCE', 'D:\\Pravin\\Moggi\\Markting Social Media'),

    // Meta's Graph API (Facebook Page and Instagram). v27.0 was the newest version on 19 Nov 2026.
    'meta' => [
        'graph_version' => 'v27.0',
        'graph_url' => 'https://graph.facebook.com',
        'video_url' => 'https://graph-video.facebook.com',
        // The Meta app "Moggies Social Manager" (developers.facebook.com).
        'app_id' => '2181731786035384',
    ],

    'required_extensions' => ['curl', 'fileinfo', 'ftp', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'sodium', 'zip'],
];
