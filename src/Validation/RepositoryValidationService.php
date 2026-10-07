<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Validation;

use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;
use Ak279642\LaravelInfrastructure\Exceptions\ValidationException;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;

final class RepositoryValidationService
{
    public function __construct(private readonly ValidationContext $context) {}

    /** @param list<RepositoryValidationRule> $rules */
    public function validate(array $rules, array $input): void
    {
        $errors = [];

        foreach ($rules as $rule) {
            $repository = app($rule->repository);

            if ($rule->unique !== []) {
                $fields = collect($rule->unique)
                    ->mapWithKeys(fn (string $field) => [$field => data_get($input, $field)])
                    ->filter(fn ($value) => $value !== null && $value !== '')
                    ->all();

                if ($fields !== []) {
                    $duplicate = $repository->findDuplicate(
                        $fields,
                        $rule->ignore,
                        $this->resolveWhere($rule->where, $input),
                    );

                    if ($duplicate) {
                        foreach ($fields as $field => $value) {
                            $duplicateValue = data_get($duplicate, $field);
                            if (is_scalar($value) && is_scalar($duplicateValue) && (string) $duplicateValue === (string) $value) {
                                $errors[$field][] = str($field)->replace('_', ' ')->title()->append(' already exists.')->toString();
                            }
                        }
                    }
                }
            }

            foreach ($rule->exists as $key => $configuration) {
                $field = is_int($key) ? $configuration : $key;
                $config = is_int($key) || ! is_array($configuration) ? [] : $configuration;
                $value = data_get($input, $field);

                if ($value === null || $value === '') {
                    continue;
                }

                $model = $repository->findWhere(
                    $value,
                    $this->resolveWhere($config['where'] ?? [], $input),
                );

                if (! $model) {
                    $errors[$field][] = str($field)->replace('_', ' ')->title()->append(' does not exist.')->toString();
                    continue;
                }

                foreach ($rule->resolve as $resolve) {
                    if (($resolve['field'] ?? null) !== $field) {
                        continue;
                    }
                    if (! empty($resolve['with'])) {
                        $repository->loadMissing($model, $resolve['with']);
                    }
                    $this->context->put($repository->getModel()::class, $model);
                }
            }

            if ($rule->existsIn !== []) {
                $field = (string) ($rule->existsIn['field'] ?? '');
                $values = array_values(array_unique($rule->existsIn['values'] ?? []));
                $where = $rule->existsIn['where'] ?? [];

                if ($field !== '' && $values !== []) {
                    $models = $repository->findWhereIn($field, $values, $where);
                    $existing = $models->pluck($field)->map(fn ($value) => (string) $value)->all();
                    $missing = array_diff(array_map('strval', $values), $existing);

                    if ($missing !== []) {
                        $errors[$field][] = 'Invalid values: '.implode(', ', $missing);
                    }

                    if ($rule->resolve !== []) {
                        $this->context->put($repository->getModel()::class, $models);
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException(errors: $errors);
        }
    }

    private function resolveWhere(array $where, array $input): array
    {
        return collect($where)->mapWithKeys(function ($value, $field) use ($input): array {
            if (is_string($value) && data_get($input, $value) !== null) {
                return [$field => data_get($input, $value)];
            }
            return [$field => $value];
        })->all();
    }
}
