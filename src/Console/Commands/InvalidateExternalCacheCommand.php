<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Console\Commands;

use Ak279642\LaravelInfrastructure\Cache\ExternalWriteInvalidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class InvalidateExternalCacheCommand extends Command
{
    protected $signature = 'infrastructure:cache:invalidate-external
        {tables* : Names of physical tables changed by a committed external database transaction}
        {--connection= : Laravel database connection name (defaults to the default connection)}';

    protected $description = 'Invalidate dependent Redis cache entries after externally committed database writes';

    public function handle(ExternalWriteInvalidator $invalidator): int
    {
        $name = $this->option('connection');
        $connection = DB::connection(is_string($name) && $name !== '' ? $name : null);

        try {
            $invalidated = $invalidator->invalidateTables(
                $this->argument('tables'),
                $connection,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $invalidated) {
            $this->error('No tag-capable cache invalidation was performed. Check your cache store.');

            return self::FAILURE;
        }

        $this->info('External table cache dependencies invalidated.');

        return self::SUCCESS;
    }
}
