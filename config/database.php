<?php
/**
 * FILE PURPOSE: Loads deployment configuration and creates the shared PDO connection.
 * LAN PRODUCTION DEFAULTS: HTTP remains allowed; MySQL time is pinned to UTC+08:00.
 *
 * Recommended deployment method:
 * 1. Copy config/local.example.php to config/local.php.
 * 2. Put the LAN server's database credentials in config/local.php.
 * 3. Keep config/local.php private. The config directory is denied by Apache rules.
 *
 * Environment variables prefixed with TLH_ override file/default values when set.
 */

$tlhConfig = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'leisure_hub',
    'db_user' => '',
    'db_pass' => '',
    'app_timezone' => 'Asia/Manila',
    'db_timezone' => '+08:00',
    'app_env' => 'lan',
    'backup_dir' => '',
];

$localConfigPath = __DIR__ . '/local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (!is_array($localConfig)) {
        throw new RuntimeException('config/local.php must return a PHP array.');
    }
    $tlhConfig = array_replace($tlhConfig, $localConfig);
}

$environmentMap = [
    'TLH_DB_HOST' => 'db_host',
    'TLH_DB_PORT' => 'db_port',
    'TLH_DB_NAME' => 'db_name',
    'TLH_DB_USER' => 'db_user',
    'TLH_DB_PASS' => 'db_pass',
    'TLH_APP_TIMEZONE' => 'app_timezone',
    'TLH_DB_TIMEZONE' => 'db_timezone',
    'TLH_APP_ENV' => 'app_env',
    'TLH_BACKUP_DIR' => 'backup_dir',
];
foreach ($environmentMap as $environmentName => $configKey) {
    $value = getenv($environmentName);
    if ($value !== false && $value !== '') {
        $tlhConfig[$configKey] = $value;
    }
}

function tlh_default_private_backup_directory(): string
{
    $appRoot = dirname(__DIR__);
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $resolvedDocumentRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    $resolvedAppRoot = realpath($appRoot) ?: $appRoot;

    if (is_string($resolvedDocumentRoot) && $resolvedDocumentRoot !== '') {
        $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $resolvedDocumentRoot), '/');
        $normalizedAppRoot = rtrim(str_replace('\\', '/', $resolvedAppRoot), '/');
        if ($normalizedAppRoot === $normalizedDocumentRoot || str_starts_with($normalizedAppRoot . '/', $normalizedDocumentRoot . '/')) {
            return dirname($resolvedDocumentRoot) . DIRECTORY_SEPARATOR . 'tlh-private' . DIRECTORY_SEPARATOR . 'backups';
        }
    }

    return dirname($appRoot) . DIRECTORY_SEPARATOR . 'tlh-private' . DIRECTORY_SEPARATOR . 'backups';
}

$dbTimezone = (string)($tlhConfig['db_timezone'] ?? '+08:00');
if (!preg_match('/^[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/', $dbTimezone)) {
    $dbTimezone = '+08:00';
}

$backupDirectory = trim((string)($tlhConfig['backup_dir'] ?? ''));
if ($backupDirectory === '') {
    $backupDirectory = tlh_default_private_backup_directory();
}

define('DB_HOST', (string)$tlhConfig['db_host']);
define('DB_PORT', (string)$tlhConfig['db_port']);
define('DB_NAME', (string)$tlhConfig['db_name']);
define('DB_USER', (string)$tlhConfig['db_user']);
define('DB_PASS', (string)$tlhConfig['db_pass']);
define('APP_TIMEZONE', (string)$tlhConfig['app_timezone']);
define('DB_TIMEZONE', $dbTimezone);
define('APP_ENV', (string)$tlhConfig['app_env']);
define('DB_BACKUP_DIR', $backupDirectory);

date_default_timezone_set(APP_TIMEZONE);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (trim(DB_USER) === '') {
        throw new RuntimeException('Database credentials are not configured. Copy config/local.example.php to config/local.php and enter the LAN database account.');
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // The Philippines does not observe DST. Pin every MySQL session to UTC+08:00
    // so NOW()/CURDATE() agree with PHP's Asia/Manila date/time reporting.
    $pdo->exec("SET time_zone = '" . DB_TIMEZONE . "'");

    return $pdo;
}
