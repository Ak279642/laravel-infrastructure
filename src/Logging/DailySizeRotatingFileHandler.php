<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use RuntimeException;

final class DailySizeRotatingFileHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = 10485760,
        private readonly int $retentionDays = 14,
        int|string $level = 'debug',
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(mixed $record): void
    {
        $formatted = is_array($record)
            ? (string) ($record['formatted'] ?? '')
            : (string) $record->formatted;

        if ($formatted === '') {
            return;
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create log directory [{$directory}].");
        }

        // A stable lock protects both the segment choice and the append across workers.
        $lockPath = $directory.DIRECTORY_SEPARATOR.'.'.pathinfo($this->path, PATHINFO_FILENAME).'.rotation.lock';
        $lock = fopen($lockPath, 'c+');

        if (! is_resource($lock)) {
            throw new RuntimeException('Unable to open domain log rotation lock.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire domain log rotation lock.');
            }

            try {
                $date = date('Y-m-d');
                [$target, $isNew] = $this->resolveWritableFile($date, strlen($formatted));
                $stream = fopen($target, 'ab');

                if (! is_resource($stream)) {
                    throw new RuntimeException("Unable to open log file [{$target}].");
                }

                try {
                    @chmod($target, 0640);
                    $remaining = $formatted;

                    while ($remaining !== '') {
                        $written = fwrite($stream, $remaining);

                        if ($written === false || $written === 0) {
                            throw new RuntimeException('Unable to write complete domain log entry.');
                        }

                        $remaining = substr($remaining, $written);
                    }

                    if (! fflush($stream)) {
                        throw new RuntimeException('Unable to flush domain log entry.');
                    }
                } finally {
                    fclose($stream);
                }

                if ($isNew) {
                    $this->deleteExpiredFiles($date);
                }
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /** @return array{string, bool} Path and whether this is a new segment */
    private function resolveWritableFile(string $date, int $incomingBytes): array
    {
        $directory = dirname($this->path);
        $baseName = pathinfo($this->path, PATHINFO_FILENAME);
        $extension = pathinfo($this->path, PATHINFO_EXTENSION) ?: 'log';
        $pattern = $directory.DIRECTORY_SEPARATOR.$baseName.'_'.$date.'_*.'.$extension;
        $files = glob($pattern) ?: [];
        $latestSegment = 0;
        $latest = null;

        foreach ($files as $file) {
            $filename = basename($file);
            $prefix = $baseName.'_'.$date.'_';
            $suffix = '.'.$extension;

            if (! str_starts_with($filename, $prefix) || ! str_ends_with($filename, $suffix)) {
                continue;
            }

            $segment = substr($filename, strlen($prefix), -strlen($suffix));

            if (! ctype_digit($segment)) {
                continue;
            }

            $number = (int) $segment;

            if ($number > $latestSegment) {
                $latestSegment = $number;
                $latest = $file;
            }
        }

        if ($latest !== null) {
            clearstatcache(true, $latest);
            $currentSize = (int) filesize($latest);

            // Keep a single oversized entry whole when the segment is empty.
            if ($this->maxBytes <= 0 || $currentSize === 0
                || $currentSize + $incomingBytes <= $this->maxBytes) {
                return [$latest, false];
            }
        }

        $target = sprintf(
            '%s%s%s_%s_%03d.%s',
            $directory,
            DIRECTORY_SEPARATOR,
            $baseName,
            $date,
            $latestSegment + 1,
            $extension,
        );

        return [$target, true];
    }

    private function deleteExpiredFiles(string $today): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $directory = dirname($this->path);
        $baseName = pathinfo($this->path, PATHINFO_FILENAME);
        $extension = pathinfo($this->path, PATHINFO_EXTENSION) ?: 'log';
        $cutoff = date('Y-m-d', strtotime($today.' -'.$this->retentionDays.' days'));

        foreach (glob($directory.DIRECTORY_SEPARATOR.$baseName.'_????-??-??_*.' . $extension) ?: [] as $file) {
            $pattern = '/^'.preg_quote($baseName, '/').'_([0-9]{4}-[0-9]{2}-[0-9]{2})_[0-9]+\\.'
                .preg_quote($extension, '/').'$/D';

            if (preg_match($pattern, basename($file), $matches) === 1 && $matches[1] < $cutoff) {
                @unlink($file);
            }
        }
    }
}
