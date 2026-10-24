<?php

return [
    // Hard limits for the ZIP validator. The per-server storage quota and the egg selection
    // are configured in the panel (setup menu / admin page) and stored in the database.
    'max_upload_mb' => (int) env('RESOURCE_PACK_HOSTING_MAX_UPLOAD_MB', 100),
    'max_uncompressed_mb' => (int) env('RESOURCE_PACK_HOSTING_MAX_UNCOMPRESSED_MB', 1024),
    'max_entries' => (int) env('RESOURCE_PACK_HOSTING_MAX_ENTRIES', 30000),
    'max_compression_ratio' => (int) env('RESOURCE_PACK_HOSTING_MAX_COMPRESSION_RATIO', 250),
    'downloads_per_minute' => (int) env('RESOURCE_PACK_HOSTING_DOWNLOADS_PER_MINUTE', 30),
];
