<?php
/**
 * FILE PURPOSE: Low-level SQL backup helpers used by the admin backup screen.
 * DEBUGGING: Backups contain sensitive data. Keep path validation strict and storage outside public access.
 */

/**
 * Database backup helpers introduced in v1.0.36.
 * Backups are written as portable MySQL/MariaDB SQL files in a private
 * deployment folder outside the public web root by default and can only be downloaded through the admin portal.
 */

function database_backup_directory(): string
{
    $configured = defined('DB_BACKUP_DIR') ? trim((string)constant('DB_BACKUP_DIR')) : '';
    $directory = $configured !== ''
        ? $configured
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';

    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The database backup folder could not be created. Check the website folder permissions.');
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('The database backup folder is not writable. Check the website folder permissions.');
    }

    database_backup_protect_directory($directory);
    return $directory;
}

function database_backup_protect_directory(string $directory): void
{
    $rules = <<<'HTACCESS'
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTACCESS;

    $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, $rules . PHP_EOL, LOCK_EX);
    }

    $index = $directory . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($index)) {
        @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX);
    }
}

function database_backup_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function database_backup_valid_filename(string $filename): bool
{
    return (bool)preg_match('/^leisure_hub_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}(?:_[a-f0-9]{6})?\.sql$/', $filename);
}

function database_backup_path(string $filename): string
{
    $filename = basename($filename);
    if (!database_backup_valid_filename($filename)) {
        throw new RuntimeException('Invalid backup file name.');
    }

    $directory = database_backup_directory();
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        throw new RuntimeException('The selected backup file was not found.');
    }

    return $path;
}

function database_backup_write($handle, string $content): void
{
    $length = strlen($content);
    $written = 0;
    while ($written < $length) {
        $result = fwrite($handle, substr($content, $written));
        if ($result === false || $result === 0) {
            throw new RuntimeException('The database backup could not be written completely.');
        }
        $written += $result;
    }
}

function database_backup_sql_value(PDO $pdo, mixed $value, string $columnType): string
{
    if ($value === null) {
        return 'NULL';
    }

    $type = strtolower($columnType);
    if (preg_match('/(?:binary|blob|bit)/', $type)) {
        return '0x' . bin2hex((string)$value);
    }

    if (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|year)/', $type)
        && is_numeric($value)) {
        return (string)$value;
    }

    $quoted = $pdo->quote((string)$value);
    if ($quoted === false) {
        throw new RuntimeException('A database value could not be encoded for backup.');
    }
    return $quoted;
}

function database_backup_tables(PDO $pdo): array
{
    $tables = [];
    $stmt = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        if (isset($row[0])) {
            $tables[] = (string)$row[0];
        }
    }
    sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    return $tables;
}

function database_backup_create(): array
{
    $pdo = db();
    $directory = database_backup_directory();
    $base = 'leisure_hub_' . date('Y-m-d_His');
    $filename = $base . '.sql';
    if (is_file($directory . DIRECTORY_SEPARATOR . $filename)) {
        $filename = $base . '_' . bin2hex(random_bytes(3)) . '.sql';
    }

    $finalPath = $directory . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = $finalPath . '.part';
    $handle = @fopen($temporaryPath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('The database backup file could not be opened for writing.');
    }

    $tableCount = 0;
    $rowCount = 0;
    $started = microtime(true);

    try {
        $serverVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $appVersionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'VERSION.txt';
        $appVersion = is_file($appVersionPath) ? trim((string)file_get_contents($appVersionPath)) : 'unknown';

        database_backup_write($handle, "-- The Leisure Hub database backup\n");
        database_backup_write($handle, '-- Application version: ' . str_replace(["\r", "\n"], '', $appVersion) . "\n");
        database_backup_write($handle, '-- Database: ' . str_replace(["\r", "\n"], '', DB_NAME) . "\n");
        database_backup_write($handle, '-- Server version: ' . str_replace(["\r", "\n"], '', $serverVersion) . "\n");
        database_backup_write($handle, '-- Created: ' . date('Y-m-d H:i:s T') . "\n\n");
        database_backup_write($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach (database_backup_tables($pdo) as $table) {
            $tableCount++;
            $quotedTable = database_backup_identifier($table);

            $createStmt = $pdo->query('SHOW CREATE TABLE ' . $quotedTable);
            $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? array_values($createRow ?: [])[1] ?? null;
            if (!is_string($createSql) || $createSql === '') {
                throw new RuntimeException('The structure for table ' . $table . ' could not be read.');
            }

            database_backup_write($handle, "-- --------------------------------------------------------\n");
            database_backup_write($handle, '-- Table structure for ' . $quotedTable . "\n\n");
            database_backup_write($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
            database_backup_write($handle, $createSql . ";\n\n");

            $columnStmt = $pdo->query('SHOW FULL COLUMNS FROM ' . $quotedTable);
            $columns = [];
            $types = [];
            while ($column = $columnStmt->fetch(PDO::FETCH_ASSOC)) {
                $extra = strtolower((string)($column['Extra'] ?? ''));
                if (str_contains($extra, 'generated')) {
                    continue;
                }
                $name = (string)$column['Field'];
                $columns[] = $name;
                $types[$name] = (string)$column['Type'];
            }

            if ($columns === []) {
                continue;
            }

            $columnSql = implode(', ', array_map('database_backup_identifier', $columns));
            $selectSql = 'SELECT ' . $columnSql . ' FROM ' . $quotedTable;

            $bufferingChanged = false;
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                try {
                    $bufferingChanged = $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                } catch (Throwable $ignored) {
                    $bufferingChanged = false;
                }
            }

            try {
                $dataStmt = $pdo->query($selectSql);
                $tableRows = 0;
                while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach ($columns as $columnName) {
                        $values[] = database_backup_sql_value($pdo, $row[$columnName] ?? null, $types[$columnName] ?? 'text');
                    }
                    if ($tableRows === 0) {
                        database_backup_write($handle, '-- Data for ' . $quotedTable . "\n");
                    }
                    database_backup_write(
                        $handle,
                        'INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES (' . implode(', ', $values) . ");\n"
                    );
                    $tableRows++;
                    $rowCount++;
                }
                $dataStmt->closeCursor();
                if ($tableRows > 0) {
                    database_backup_write($handle, "\n");
                }
            } finally {
                if ($bufferingChanged) {
                    try {
                        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                    } catch (Throwable $ignored) {
                    }
                }
            }
        }

        database_backup_write($handle, "SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
        database_backup_write($handle, '-- Backup completed successfully. Tables: ' . $tableCount . '; rows: ' . $rowCount . ".\n");

        if (!fflush($handle)) {
            throw new RuntimeException('The database backup could not be finalized.');
        }
        fclose($handle);
        $handle = null;

        if (!@rename($temporaryPath, $finalPath)) {
            throw new RuntimeException('The completed database backup could not be saved.');
        }
        @chmod($finalPath, 0640);
        clearstatcache(true, $finalPath);

        return [
            'filename' => $filename,
            'path' => $finalPath,
            'size' => (int)(filesize($finalPath) ?: 0),
            'tables' => $tableCount,
            'rows' => $rowCount,
            'seconds' => round(microtime(true) - $started, 2),
        ];
    } catch (Throwable $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        @unlink($temporaryPath);
        throw $e;
    }
}

function database_backup_list(): array
{
    $directory = database_backup_directory();
    $items = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . 'leisure_hub_*.sql') ?: [] as $path) {
        $filename = basename($path);
        if (!database_backup_valid_filename($filename) || !is_file($path)) {
            continue;
        }
        $items[] = [
            'filename' => $filename,
            'size' => (int)(filesize($path) ?: 0),
            'created_at' => (int)(filemtime($path) ?: 0),
        ];
    }

    usort($items, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
    return $items;
}

function database_backup_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}
