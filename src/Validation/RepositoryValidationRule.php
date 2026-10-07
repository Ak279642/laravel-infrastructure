<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Validation;

/**
 * Repository-backed validation definition.
 *
 * resolve entries support:
 * [
 *     'field' => 'customer_id',
 *     'as' => 'customer',
 *     'with' => ['organization'],
 * ]
 */
final readonly class RepositoryValidationRule
{
    public function __construct(
        public string $repository,
        public array $unique = [],
        public array $exists = [],
        public array $where = [],
        public array $existsIn = [],
        public array $resolve = [],
        public ?int $ignore = null,
    ) {}
}
