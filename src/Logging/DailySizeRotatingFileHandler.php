<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use RuntimeException;

final class DailySizeRotatingFileHandler extends AbstractProcessingHandler
{
    private mixed $stream = null;

    private ?string $currentDate = null;

    private ?string $currentFile = null;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes = 104857600,
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
        $date = date('Y-m-d');

        if (
            $this->stream === null
            || $this->currentDate !== $date
            || $this->wouldExceedLimit(strlen($formatted))
        ) {
            $this->rotate($date);
        }

        if (! is_resource($this->stream)) {
            throw new RuntimeException('Unable to open domain log stream.');
        }

        if (! flock($this->stream, LOCK_EX)) {
            throw new RuntimeException('Unable to lock domain log stream.');
        }

        try {
            if (fwrite($this->stream, $formatted) === false) {
                throw new RuntimeException('Unable to write domain log entry.');
            }
        } finally {
            flock($this->stream, LOCK_UN);
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->currentFile = null;
        $this->currentDate = null;

        parent::close();
    }

    private function wouldExceedLimit(int $incomingBytes): bool
    {
        if ($this->currentFile === null || $this->maxBytes <= 0) {
            return false;
        }

        clearstatcache(true, $this->currentFile);
        $currentBytes = is_file($this->currentFile)
            ? (int) filesize($this->currentFile)
            : 0;

        return $currentBytes > 0
            && ($currentBytes + $incomingBytes) > $this->maxBytes;
    }

    private function rotate(string $date): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create log directory [{$directory}].");
        }

        $this->currentDate = $date;
        $this->currentFile = $this->resolveWritableFile($date);
        $this->stream = fopen($this->currentFile, 'ab');

        if (! is_resource($this->stream)) {
            throw new RuntimeException("Unable to open log file [{$this->currentFile}].");
        }

        @chmod($this->currentFile, 0640);

        $this->deleteExpiredFiles();
    }

    private function resolveWritableFile(string $date): string
    {
        $directory = dirname($this->path);
        $baseName = pathinfo($this->path, PATHINFO_FILENAME);
        $extension = pathinfo($this->path, PATHINFO_EXTENSION) ?: 'log';
        $pattern = $directory.DIRECTORY_SEPARATOR.$baseName.'-'.$date.'-*.'.$extension;
        $files = glob($pattern) ?: [];
        sort($files, SORT_NATURAL);

        if ($files !== []) {
            $latest = (string) end($files);
            clearstatcache(true, $latest);

            if ($this->maxBytes <= 0 || (int) filesize($latest) < $this->maxBytes) {
                return $latest;
            }
        }

        $segment = 1;

        if ($files !== []) {
            $latest = (string) end($files);
            $fileName = pathinfo($latest, PATHINFO_FILENAME);

            if (preg_match('/-(\d{3})$/', $fileName, $matches) === 1) {
                $segment = ((int) $matches[1]) + 1;
            }
        }

        return sprintf(
            '%s%s%s-%s-%03d.%s',
            $directory,
            DIRECTORY_SEPARATOR,
            $baseName,
            $date,
            $segment,
            $extension,
        );
    }

    private function deleteExpiredFiles(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $directory = dirname($this->path);
        $baseName = pathinfo($this->path, PATHINFO_FILENAME);
        $extension = pathinfo($this->path, PATHINFO_EXTENSION) ?: 'log';
        $cutoff = strtotime('-'.$this->retentionDays.' days');

        foreach (glob($directory.DIRECTORY_SEPARATOR.$baseName.'-????-??-??-*.'.$extension) ?: [] as $file) {
            $modifiedAt = filemtime($file);

            if ($modifiedAt !== false && $modifiedAt < $cutoff) {
                @unlink($file);
            }
        }
    }
}
