<?php
// Copy to config.php outside version control. Use the destination host's values.
return [
    'timezone' => 'Asia/Yekaterinburg',
    'db_timezone' => '+05:00',
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'profisport',
    'db_user' => 'profisport',
    'db_pass' => '',
    'import_token' => '',
    // Server-side keys used only for the owner-confirmed hosting migration worker.
    'migration_key' => '',
    'migration_tick_token' => '',
    // Keep empty to disable the one-time bootstrap recovery endpoint.
    'recovery_bootstrap_hash' => '',
];
