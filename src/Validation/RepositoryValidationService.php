<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Validation;

use Ak279642\LaravelInfrastructure\Database\Repositories\Contracts\RepositoryValidationRepository;
use Ak279642\LaravelInfrastructure\Exceptions\RepositoryValidationConfigurationException;
use Ak279642\LaravelInfrastructure\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Throwable;

final class RepositoryValidationService
{
    public function __construct(
        private readonly ValidationContext $context,
    ) {}

    /**
     * @param  list<RepositoryValidationRule>  $rules
     */
    public function validate(
        array $rules,
        array $input,
        bool $resetContext = true,
    ): void {
        $errors = $this->errors($rules, $input, $resetContext);

        if ($errors !== []) {
            throw new ValidationException(errors: $errors);
        }
    }

    /**
     * Validate repository-backed rules without throwing validation failures.
     *
     * @param  list<RepositoryValidationRule>  $rules
     * @return array<string, list<string>>
     */
    public function errors(
        array $rules,
        array $input,
        bool $resetContext = true,
    ): array {
        if ($resetContext) {
            $this->context->clear();
        }

        try {
            $errors = $this->collectErrors($rules, $input);
        } catch (Throwable $exception) {
            // Never leave partially resolved models available after a failed
            // validation/configuration/database operation.
            $this->context->clear();

            throw $exception;
        }

        if ($errors !== []) {
            $this->context->clear();
        }

        return $errors;
    }

    /**
     * @param  list<RepositoryValidationRule>  $rules
     * @return array<string, list<string>>
     */
    private function collectErrors(array $rules, array $input): array
    {
        $errors = [];

        foreach ($rules as $rule) {
            if (! $rule instanceof RepositoryValidationRule) {
                throw RepositoryValidationConfigurationException::invalidRule($rule);
            }

            $repository = $this->resolveRepository($rule->repository);

            if ($rule->unique !== []) {
                $fields = collect($rule->unique)
                    ->mapWithKeys(fn (string $field) => [
                        $field => data_get($input, $field),
                    ])
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

                            if (
                                is_scalar($value)
                                && is_scalar($duplicateValue)
                                && (string) $duplicateValue === (string) $value
                            ) {
                                $errors[$field][] = str($field)
                                    ->replace('_', ' ')
                                    ->title()
                                    ->append(' already exists.')
                                    ->toString();
                            }
                        }
                    }
                }
            }

            foreach ($rule->exists as $key => $configuration) {
                $field = is_int($key) ? $configuration : $key;
                $config = is_int($key) || ! is_array($configuration)
                    ? []
                    : $configuration;

                if (! is_string($field) || $field === '') {
                    continue;
                }

                $value = data_get(
                    $input,
                    (string) ($config['input'] ?? $field),
                );

                if ($value === null || $value === '') {
                    continue;
                }

                $model = $repository->findWhere(
                    $value,
                    $this->resolveWhere(
                        (array) ($config['where'] ?? []),
                        $input,
                    ),
                );

                if (! $model) {
                    $errors[$field][] = str($field)
                        ->replace('_', ' ')
                        ->title()
                        ->append(' does not exist.')
                        ->toString();

                    continue;
                }

                $this->resolveModel(
                    $repository,
                    $rule,
                    $field,
                    $model,
                );
            }

            if ($rule->existsIn !== []) {
                $inputField = (string) ($rule->existsIn['field'] ?? '');
                $column = (string) ($rule->existsIn['column'] ?? 'id');

                if ($inputField === '') {
                    continue;
                }

                $configuredValues = $rule->existsIn['values'] ?? $inputField;

                $values = is_string($configuredValues)
                    ? data_get($input, $configuredValues, [])
                    : $configuredValues;

                $values = array_values(array_unique((array) $values));

                if ($values === []) {
                    continue;
                }

                $models = $repository->findWhereIn(
                    $column,
                    $values,
                    $this->resolveWhere(
                        (array) ($rule->existsIn['where'] ?? []),
                        $input,
                    ),
                );

                $existing = $models
                    ->pluck($column)
                    ->map(fn ($value) => (string) $value)
                    ->all();

                $missing = array_diff(
                    array_map('strval', $values),
                    $existing,
                );

                if ($missing !== []) {
                    $errors[$inputField][] = 'Invalid values: '.implode(', ', $missing);
                }

                if ($missing === []) {
                    $this->resolveCollection(
                        $repository,
                        $rule,
                        $inputField,
                        $models,
                    );
                }
            }
        }

        return $errors;
    }

    private function resolveRepository(
        string $repository,
    ): RepositoryValidationRepository {
        $resolved = app($repository);

        if (! $resolved instanceof RepositoryValidationRepository) {
            throw RepositoryValidationConfigurationException::invalidRepository(
                $repository,
            );
        }

        return $resolved;
    }

    private function resolveModel(
        RepositoryValidationRepository $repository,
        RepositoryValidationRule $rule,
        string $field,
        Model $model,
    ): void {
        foreach ($this->matchingResolveConfigurations($rule, $field) as $resolve) {
            $with = (array) ($resolve['with'] ?? []);

            if ($with !== []) {
                $repository->loadMissing($model, $with);
            }

            $this->context->put(
                (string) ($resolve['as'] ?? $repository->getModel()::class),
                $model,
            );
        }
    }

    private function resolveCollection(
        RepositoryValidationRepository $repository,
        RepositoryValidationRule $rule,
        string $field,
        Collection $models,
    ): void {
        foreach ($this->matchingResolveConfigurations($rule, $field) as $resolve) {
            $with = (array) ($resolve['with'] ?? []);

            if ($with !== []) {
                foreach ($models as $model) {
                    $repository->loadMissing($model, $with);
                }
            }

            $this->context->put(
                (string) ($resolve['as'] ?? $repository->getModel()::class),
                $models,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchingResolveConfigurations(
        RepositoryValidationRule $rule,
        string $field,
    ): array {
        return array_values(array_filter(
            $rule->resolve,
            static fn ($resolve): bool => is_array($resolve)
                && ($resolve['field'] ?? null) === $field,
        ));
    }

    private function resolveWhere(array $where, array $input): array
    {
        return collect($where)
            ->mapWithKeys(function ($value, $field) use ($input): array {
                if (is_string($value) && Arr::has($input, $value)) {
                    return [$field => data_get($input, $value)];
                }

                return [$field => $value];
            })
            ->all();
    }
}
