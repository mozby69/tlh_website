<?php
/**
 * TLH LAN deployment configuration example.
 *
 * Copy this file to config/local.php and edit the values for your LAN server.
 * Never share config/local.php publicly or place a copy in downloadable folders.
 */
return [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'leisure_hub',
    'db_user' => 'root',
    'db_pass' => '',

    // TLH reports and MySQL NOW()/CURDATE() should use Philippine time.
    'app_timezone' => 'Asia/Manila',
    'db_timezone' => '+08:00',

    // LAN mode intentionally allows normal HTTP on the trusted local network.
    'app_env' => 'lan',

    // Optional. Leave blank to store backups in a private folder outside the
    // Apache document root. Set an absolute writable path if preferred.
    'backup_dir' => '',
];
