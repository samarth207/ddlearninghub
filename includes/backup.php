<?php
declare(strict_types=1);

function backup_temp_file(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ddhub-backup-');
    if ($path === false) {
        throw new RuntimeException('Cannot create a temporary backup file.');
    }
    register_shutdown_function(static function () use ($path): void {
        if (is_file($path) && !unlink($path)) {
            error_log('Learning Hub could not remove a temporary backup file.');
        }
    });
    $resolved = realpath($path);
    $roots = [realpath(dirname(__DIR__))];
    if (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])) {
        $roots[] = realpath($_SERVER['DOCUMENT_ROOT']);
    }
    if ($resolved === false) {
        throw new RuntimeException('Cannot verify the temporary backup location.');
    }
    foreach ($roots as $root) {
        if ($root === false) { continue; }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $inside = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($resolved, $prefix, strlen($prefix)) === 0
            : strncmp($resolved, $prefix, strlen($prefix)) === 0;
        if ($inside) {
            throw new RuntimeException('The server temporary directory must be outside the web root.');
        }
    }
    if (!chmod($path, 0600)) {
        throw new RuntimeException('Cannot protect the temporary backup file.');
    }
    return $path;
}

function backup_write($file, string $text): void
{
    $length = strlen($text);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($file, substr($text, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write the backup file.');
        }
        $offset += $written;
    }
}

function backup_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function create_database_backup(PDO $connection, string $path): void
{
    $file = fopen($path, 'wb');
    if ($file === false) { throw new RuntimeException('Cannot open the temporary backup file.'); }
    try {
        $connection->exec("SET time_zone = '+00:00'");
        $connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        backup_write($file, "-- DegreeDrishti Learning Hub database backup\nSET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET @BACKUP_OLD_SQL_MODE = @@SQL_MODE;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET @BACKUP_OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $connection->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $tableRow) {
            $tableName = (string) $tableRow[0];
            $table = backup_identifier($tableName);
            $create = $connection->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM);
            if ($create === false || !isset($create[1])) {
                throw new RuntimeException('Cannot read a table definition.');
            }
            $columns = $connection->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll();
            $names = [];
            $selectColumns = [];
            $kinds = [];
            foreach ($columns as $column) {
                if (preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', (string) $column['Extra']) === 1) { continue; }
                $type = strtolower((string) $column['Type']);
                $name = backup_identifier((string) $column['Field']);
                $names[] = $name;
                if (preg_match('/\A(?:geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/', $type) === 1) {
                    $selectColumns[] = 'ST_AsWKB(' . $name . ')';
                    $selectColumns[] = 'ST_SRID(' . $name . ')';
                    $kinds[] = 'spatial';
                } elseif (preg_match('/\Abit\b/', $type) === 1) {
                    $selectColumns[] = 'CAST(' . $name . ' AS UNSIGNED)';
                    $kinds[] = 'bit';
                } else {
                    $selectColumns[] = $name;
                    $kinds[] = preg_match('/\A(?:tinyblob|blob|mediumblob|longblob|binary|varbinary)\b/', $type) === 1 ? 'binary' : 'text';
                }
            }
            backup_write($file, 'DROP TABLE IF EXISTS ' . $table . ";\n" . $create[1] . ";\n");
            if (!$connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false)) {
                throw new RuntimeException('Cannot enable streaming database queries.');
            }
            $select = null;
            try {
                $select = $connection->query('SELECT ' . ($selectColumns === [] ? '1' : implode(', ', $selectColumns)) . ' FROM ' . $table);
                $prefix = 'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES ';
                $batch = [];
                $batchBytes = 0;
                while (($row = $select->fetch(PDO::FETCH_NUM)) !== false) {
                    $parts = [];
                    $offset = 0;
                    foreach ($names as $index => $unused) {
                        $value = $row[$offset++];
                        $srid = $kinds[$index] === 'spatial' ? $row[$offset++] : null;
                        if ($value === null) {
                            $parts[] = 'NULL';
                        } elseif ($kinds[$index] === 'bit') {
                            $value = (string) $value;
                            if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
                                throw new RuntimeException('Cannot encode a bit value.');
                            }
                            $parts[] = $value;
                        } elseif ($kinds[$index] === 'spatial') {
                            if (!is_numeric($srid) || preg_match('/\A[0-9]+\z/', (string) $srid) !== 1) {
                                throw new RuntimeException('Cannot encode a spatial reference.');
                            }
                            $parts[] = "ST_GeomFromWKB(X'" . bin2hex((string) $value) . "', " . $srid . ')';
                        } else {
                            $hex = "X'" . bin2hex((string) $value) . "'";
                            $parts[] = $kinds[$index] === 'binary' ? $hex : 'CONVERT(' . $hex . ' USING utf8mb4)';
                        }
                    }
                    $tuple = '(' . implode(', ', $parts) . ')';
                    if ($batch !== [] && (count($batch) >= 100 || $batchBytes + strlen($tuple) > 262144)) {
                        backup_write($file, $prefix . implode(",\n", $batch) . ";\n");
                        $batch = [];
                        $batchBytes = 0;
                        if (function_exists('set_time_limit')) { set_time_limit(60); }
                    }
                    $batch[] = $tuple;
                    $batchBytes += strlen($tuple);
                }
                if ($batch !== []) { backup_write($file, $prefix . implode(",\n", $batch) . ";\n"); }
            } finally {
                if ($select instanceof PDOStatement) { $select->closeCursor(); }
                if (!$connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true)) {
                    throw new RuntimeException('Cannot reset database query buffering.');
                }
            }
            backup_write($file, "\n");
            if (function_exists('set_time_limit')) { set_time_limit(60); }
        }
        backup_write($file, "SET FOREIGN_KEY_CHECKS = @BACKUP_OLD_FOREIGN_KEY_CHECKS;\nSET SQL_MODE = @BACKUP_OLD_SQL_MODE;\n");
        if (!fflush($file)) { throw new RuntimeException('Could not finish the backup file.'); }
        $connection->commit();
    } finally {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        fclose($file);
    }
}

function create_uploads_backup(string $path): void
{
    if (!class_exists('ZipArchive')) { throw new RuntimeException('ZIP support is unavailable on this server.'); }
    $uploads = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    if (is_link($uploads)) { throw new RuntimeException('The uploads directory must not be a symbolic link.'); }
    $root = realpath($uploads);
    if ($root === false || !is_dir($root) || !is_readable($root)) {
        throw new RuntimeException('The uploads directory is unavailable.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create the uploads archive.');
    }
    try {
        if (function_exists('set_time_limit') && method_exists($zip, 'registerProgressCallback')) {
            if (!$zip->registerProgressCallback(0.01, static function (float $progress): void { set_time_limit(60); })) {
                throw new RuntimeException('Cannot configure ZIP progress tracking.');
            }
        }
        if (!$zip->addEmptyDir('uploads')) { throw new RuntimeException('Cannot add the uploads directory.'); }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) { throw new RuntimeException('The uploads directory contains a symbolic link; remove it before backing up.'); }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
            $name = 'uploads/' . $relative;
            if ($entry->isDir()) {
                if (!$zip->addEmptyDir($name)) { throw new RuntimeException('Cannot add an uploads directory to the archive.'); }
            } elseif (!$entry->isFile() || !$entry->isReadable() || !$zip->addFile($entry->getPathname(), $name)) {
                throw new RuntimeException('Cannot add an uploaded file to the archive.');
            }
            if (function_exists('set_time_limit')) { set_time_limit(60); }
        }
    } catch (RuntimeException $exception) {
        $zip->close();
        throw $exception;
    }
    if (!$zip->close()) { throw new RuntimeException('Cannot finish the uploads archive.'); }
}
