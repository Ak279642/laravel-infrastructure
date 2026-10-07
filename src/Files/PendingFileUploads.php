<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Logging\LogDomain;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

final class PendingFileUploads
{
    /**
     * @var array<string, array<string, array{level:int,path:string,disk:string}>>
     */
    private array $uploads = [];

    public function __construct(
        private FilesystemFactory $filesystems,
    ) {}

    public function track(
        string $connection,
        int $transactionLevel,
        string $path,
        string $disk,
    ): void {
        if ($transactionLevel < 1 || $path === '') {
            return;
        }

        $this->uploads[$connection][$this->key($disk, $path)] = [
            'level' => $transactionLevel,
            'path' => $path,
            'disk' => $disk,
        ];
    }

    /**
     * @param  list<array{path:string,disk:string}>  $files
     */
    public function discard(
        array $files,
        ?string $connection = null,
    ): void {
        foreach ($files as $file) {
            if ($connection !== null) {
                unset(
                    $this->uploads[$connection][
                        $this->key($file['disk'], $file['path'])
                    ],
                );
            }

            $this->delete($file['disk'], $file['path']);
        }

        if (
            $connection !== null
            && ($this->uploads[$connection] ?? []) === []
        ) {
            unset($this->uploads[$connection]);
        }
    }

    public function committed(string $connection): void
    {
        unset($this->uploads[$connection]);
    }

    public function rolledBack(
        string $connection,
        int $remainingTransactionLevel,
    ): void {
        $uploads = $this->uploads[$connection] ?? [];

        foreach ($uploads as $key => $upload) {
            if ($upload['level'] <= $remainingTransactionLevel) {
                continue;
            }

            $this->delete($upload['disk'], $upload['path']);
            unset($this->uploads[$connection][$key]);
        }

        if (($this->uploads[$connection] ?? []) === []) {
            unset($this->uploads[$connection]);
        }
    }

    private function key(string $disk, string $path): string
    {
        return hash('sha256', $disk."\0".$path);
    }

    private function delete(string $disk, string $path): void
    {
        try {
            $this->filesystems->disk($disk)->delete($path);
        } catch (Throwable $exception) {
            CustomLog::warning(
                'Unable to clean up a pending uploaded file.',
                [
                    'disk' => $disk,
                    'path' => $path,
                    'exception_class' => $exception::class,
                ],
                LogDomain::APPLICATION,
            );
        }
    }
}
