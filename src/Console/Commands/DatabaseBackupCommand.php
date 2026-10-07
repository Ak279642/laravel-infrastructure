<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Console\Commands;

use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DatabaseBackupCommand extends Command
{
    protected $signature = 'infrastructure:database-backup
        {--connection= : Database connection to back up}';

    protected $description = 'Create a database backup and retain only the newest configured backups.';

    public function handle(): int
    {
        $connectionName = (string) (
            $this->option('connection')
            ?: config('database.default')
        );

        $connection = config(
            "database.connections.{$connectionName}",
        );

        if (! is_array($connection)) {
            $this->error(
                "Database connection [{$connectionName}] is not configured.",
            );

            return self::FAILURE;
        }

        $database = (string) ($connection['database'] ?? 'database');
        $driver = (string) ($connection['driver'] ?? '');
        $diskName = (string) config(
            'laravel-infrastructure.database_backup.disk',
            'local',
        );
        $backupPath = $this->normalizeBackupPath(
            (string) config(
                'laravel-infrastructure.database_backup.path',
                'backups/database',
            ),
        );
        $keep = max(
            1,
            (int) config(
                'laravel-infrastructure.database_backup.keep',
                3,
            ),
        );
        $compress = (bool) config(
            'laravel-infrastructure.database_backup.compress',
            true,
        );
        $excludeData = $this->normalizeExcludedTables(
            (array) config(
                'laravel-infrastructure.database_backup.exclude_data',
                [],
            ),
        );

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->error(
                "Database backup driver [{$driver}] is not supported. ".
                'Supported drivers: mysql, mariadb, pgsql.',
            );

            return self::FAILURE;
        }

        $safeDatabase = preg_replace(
            '/[^A-Za-z0-9_.-]+/',
            '-',
            $database,
        ) ?: 'database';
        $safeConnection = preg_replace(
            '/[^A-Za-z0-9_.-]+/',
            '-',
            $connectionName,
        ) ?: 'default';

        $baseName = sprintf(
            '%s-%s-%s.sql',
            $safeConnection,
            $safeDatabase,
            now()->format('Y-m-d_H-i-s'),
        );

        $tempDirectory = storage_path(
            'framework/cache/laravel-infrastructure-backups',
        );
        $sqlPath = $tempDirectory.DIRECTORY_SEPARATOR.$baseName;
        $finalLocalPath = $compress ? $sqlPath.'.gz' : $sqlPath;
        $remotePath = $backupPath.'/'.basename($finalLocalPath);

        if (
            ! is_dir($tempDirectory)
            && ! mkdir($tempDirectory, 0750, true)
            && ! is_dir($tempDirectory)
        ) {
            $this->error(
                'Unable to create the temporary backup directory.',
            );

            return self::FAILURE;
        }

        try {
            $this->writeHeader(
                $sqlPath,
                $connectionName,
                $database,
                $excludeData,
            );

            match ($driver) {
                'mysql', 'mariadb' => $this->dumpMySql(
                    $connection,
                    $database,
                    $excludeData,
                    $sqlPath,
                ),
                'pgsql' => $this->dumpPostgres(
                    $connection,
                    $database,
                    $excludeData,
                    $sqlPath,
                ),
            };

            if ($compress) {
                $this->gzip($sqlPath, $finalLocalPath);
                @unlink($sqlPath);
            }

            $stream = fopen($finalLocalPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException(
                    'Unable to open the generated backup file for storage.',
                );
            }

            try {
                $stored = Storage::disk($diskName)->put(
                    $remotePath,
                    $stream,
                );
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw new RuntimeException(
                    "Unable to store backup on disk [{$diskName}].",
                );
            }

            $deleted = $this->pruneOldBackups(
                $diskName,
                $backupPath,
                $keep,
            );
            $size = filesize($finalLocalPath) ?: 0;

            CustomLog::info(
                'Database backup completed.',
                [
                    'connection' => $connectionName,
                    'database' => $database,
                    'driver' => $driver,
                    'disk' => $diskName,
                    'path' => $remotePath,
                    'size_bytes' => $size,
                    'excluded_data_tables' => $excludeData,
                    'retention_count' => $keep,
                    'old_backups_deleted' => $deleted,
                ],
                LogDomain::JOBS,
            );

            $this->info(
                "Database backup created: {$diskName}:{$remotePath}",
            );

            if ($excludeData !== []) {
                $this->line(
                    'Schema-only tables: '.implode(', ', $excludeData),
                );
            }

            $this->line(
                "Retention applied: newest {$keep} backup(s) kept.",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            CustomLog::exception(
                $exception,
                [
                    'operation' => 'database_backup',
                    'connection' => $connectionName,
                    'database' => $database,
                    'driver' => $driver,
                    'disk' => $diskName,
                    'path' => $remotePath,
                ],
                'Database backup failed.',
                'error',
                LogDomain::JOBS,
            );

            $this->error(
                'Database backup failed: '.$exception->getMessage(),
            );

            return self::FAILURE;
        } finally {
            if (is_file($sqlPath)) {
                @unlink($sqlPath);
            }

            if (
                $finalLocalPath !== $sqlPath
                && is_file($finalLocalPath)
            ) {
                @unlink($finalLocalPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  list<string>  $excludeData
     */
    private function dumpMySql(
        array $connection,
        string $database,
        array $excludeData,
        string $targetPath,
    ): void {
        $common = [
            $this->resolveDumpBinary('mysql'),
        ];

        if (! empty($connection['unix_socket'])) {
            $common[] = '--socket='.(string) $connection['unix_socket'];
        } else {
            $common[] = '--host='.(string) (
                $connection['host'] ?? '127.0.0.1'
            );
            $common[] = '--port='.(string) (
                $connection['port'] ?? 3306
            );
            $common[] = '--protocol=tcp';
        }

        $common[] = '--user='.(string) (
            $connection['username'] ?? ''
        );
        $common[] = '--default-character-set='.(string) (
            $connection['charset'] ?? 'utf8mb4'
        );
        $common[] = '--single-transaction';
        $common[] = '--skip-lock-tables';
        $common[] = '--hex-blob';

        $environment = [];
        $password = (string) ($connection['password'] ?? '');

        if ($password !== '') {
            $environment['MYSQL_PWD'] = $password;
        }

        $this->runDump(
            [...$common, '--no-data', '--triggers', $database],
            $targetPath,
            true,
            $environment,
        );

        $dataCommand = [
            ...$common,
            '--no-create-info',
            '--skip-triggers',
        ];

        foreach ($excludeData as $table) {
            $dataCommand[] = "--ignore-table={$database}.{$table}";
        }

        $dataCommand[] = $database;

        $this->runDump(
            $dataCommand,
            $targetPath,
            true,
            $environment,
        );
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  list<string>  $excludeData
     */
    private function dumpPostgres(
        array $connection,
        string $database,
        array $excludeData,
        string $targetPath,
    ): void {
        $common = [
            $this->resolveDumpBinary('pgsql'),
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? 5432),
            '--username='.(string) ($connection['username'] ?? ''),
            '--dbname='.$database,
            '--no-owner',
            '--no-privileges',
        ];

        $environment = [];
        $password = (string) ($connection['password'] ?? '');

        if ($password !== '') {
            $environment['PGPASSWORD'] = $password;
        }

        $this->runDump(
            [...$common, '--schema-only'],
            $targetPath,
            true,
            $environment,
        );

        $dataCommand = [...$common, '--data-only'];

        foreach ($excludeData as $table) {
            $dataCommand[] = '--exclude-table-data='.$table;
        }

        $this->runDump(
            $dataCommand,
            $targetPath,
            true,
            $environment,
        );
    }

    private function resolveDumpBinary(string $driver): string
    {
        $key = $driver === 'pgsql'
            ? 'pgsql_dump_binary'
            : 'mysql_dump_binary';

        $configured = trim((string) config(
            "laravel-infrastructure.database_backup.{$key}",
            '',
        ));

        if ($configured !== '') {
            if ($this->looksLikePath($configured) && ! is_file($configured)) {
                throw new RuntimeException(
                    "Configured database dump binary was not found: {$configured}.",
                );
            }

            return $configured;
        }

        if ($driver === 'pgsql') {
            return PHP_OS_FAMILY === 'Windows'
                ? 'pg_dump.exe'
                : 'pg_dump';
        }

        return PHP_OS_FAMILY === 'Windows'
            ? 'mysqldump.exe'
            : 'mysqldump';
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $extraEnvironment
     */
    private function runDump(
        array $command,
        string $targetPath,
        bool $append,
        array $extraEnvironment = [],
    ): void {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $targetPath, $append ? 'a' : 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = array_merge(
            is_array(getenv()) ? getenv() : [],
            $extraEnvironment,
        );

        $process = @proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException(
                'Unable to start the database dump process.',
            );
        }

        fclose($pipes[0]);
        $errorOutput = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Database dump process failed with exit code '.
                $exitCode.': '.trim($errorOutput),
            );
        }
    }

    /**
     * @param  list<string>  $excludeData
     */
    private function writeHeader(
        string $targetPath,
        string $connection,
        string $database,
        array $excludeData,
    ): void {
        $header = implode(PHP_EOL, [
            '-- Laravel Infrastructure database backup',
            '-- Generated at: '.now()->toIso8601String(),
            '-- Connection: '.$connection,
            '-- Database: '.$database,
            '-- Schema-only tables: '.implode(', ', $excludeData),
            '',
        ]);

        if (file_put_contents($targetPath, $header) === false) {
            throw new RuntimeException(
                'Unable to initialize the database backup file.',
            );
        }
    }

    private function gzip(
        string $sourcePath,
        string $destinationPath,
    ): void {
        if (! function_exists('gzopen')) {
            throw new RuntimeException(
                'Gzip compression requires the PHP zlib extension.',
            );
        }

        $source = fopen($sourcePath, 'rb');
        $destination = gzopen($destinationPath, 'wb9');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($destination)) {
                gzclose($destination);
            }

            throw new RuntimeException(
                'Unable to open backup file for gzip compression.',
            );
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException(
                        'Unable to read backup file during compression.',
                    );
                }

                if (
                    $chunk !== ''
                    && gzwrite($destination, $chunk) === false
                ) {
                    throw new RuntimeException(
                        'Unable to write compressed backup file.',
                    );
                }
            }
        } finally {
            fclose($source);
            gzclose($destination);
        }
    }

    private function pruneOldBackups(
        string $diskName,
        string $path,
        int $keep,
    ): int {
        $disk = Storage::disk($diskName);
        $files = array_values(array_filter(
            $disk->files($path),
            static fn (string $file): bool => preg_match(
                '/\.sql(?:\.gz)?$/i',
                $file,
            ) === 1,
        ));

        usort(
            $files,
            static fn (string $left, string $right): int =>
                $disk->lastModified($right)
                <=> $disk->lastModified($left),
        );

        $deleted = 0;

        foreach (array_slice($files, $keep) as $file) {
            if ($disk->delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function normalizeBackupPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/(^|\/)\.\.(\/|$)/', $path) === 1
        ) {
            throw new RuntimeException(
                'Database backup path must be a safe relative storage path.',
            );
        }

        return $path;
    }

    /**
     * @param  array<int, mixed>  $tables
     * @return list<string>
     */
    private function normalizeExcludedTables(array $tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            if (! is_string($table) || trim($table) === '') {
                continue;
            }

            $table = trim($table);

            if (
                preg_match(
                    '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/D',
                    $table,
                ) !== 1
            ) {
                throw new RuntimeException(
                    "Unsafe database backup table name [{$table}].",
                );
            }

            $result[] = $table;
        }

        return array_values(array_unique($result));
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/')
            || str_contains($value, '\\')
            || preg_match('/^[A-Za-z]:/', $value) === 1;
    }
}
