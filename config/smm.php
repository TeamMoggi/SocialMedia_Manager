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

    // Photo thumbnails for the Library screen; outside media/, so the nightly backup leaves them out.
    'thumbs_dir' => env('SMM_THUMBS_DIR'),

    // The brand rules every post is checked against before approval (a brand can set its own list).
    'approval_checklist' => [
        "No real people's faces (hands, voice-overs and text on screen are fine)",
        "AI-made people are labelled (the app adds Instagram's AI label and 'Visuals made with AI' on Facebook)",
        'Only approved figures and wording: since 2021, 3 shipments every 2 months, ready to ship to any country (no list of countries); export figures checked against APEDA',
        "No buyer names, labels or documents without the buyer's OK, and never the EIA test report",
        'Export services: no promise to find buyers',
    ],

    // Meta's limits for posted media (Graph API v27.0 docs read 22 Nov 2026: ig-user/media, content-publishing,
    // page/photos, page/feed). Sizes in bytes (1 MB = 1,048,576), lengths in seconds, ratio = width / height.
    'limits' => [
        'instagram' => [
            'photo' => ['max_bytes' => 8 * 1048576, 'min_ratio' => 0.8, 'max_ratio' => 1.91, 'min_width' => 320, 'max_width' => 1440],
            'reel' => ['min_seconds' => 3, 'max_seconds' => 900, 'max_bytes' => 300 * 1048576, 'min_ratio' => 0.01, 'max_ratio' => 10],
            'story_video' => ['min_seconds' => 3, 'max_seconds' => 60, 'max_bytes' => 100 * 1048576, 'min_ratio' => 0.1, 'max_ratio' => 10],
            'video' => ['min_fps' => 23, 'max_fps' => 60, 'max_width' => 1920, 'codecs' => ['avc1', 'avc3', 'hvc1', 'hev1']],
            'carousel' => ['min_items' => 2, 'max_items' => 20],
            'caption' => ['max_chars' => 2200, 'max_hashtags' => 30, 'max_mentions' => 20, 'brand_hashtags' => 5],
            // 9:16 (upright) is what Reels and Stories recommend; between these ratios counts as 9:16.
            'upright' => [0.5, 0.6],
        ],
        'facebook' => [
            'photo' => ['max_bytes' => 10 * 1048576, 'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff']],
        ],
    ],

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
