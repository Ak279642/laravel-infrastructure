<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Http\Requests;

use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationService;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class RepositoryFormRequest extends FormRequest
{
    private bool $repositoryValidationAttached = false;

    /**
     * Define repository-backed rules that run only after normal Laravel rules
     * have passed.
     *
     * @return list<RepositoryValidationRule>
     */
    protected function repositoryValidationRules(): array
    {
        return [];
    }

    /**
     * Override when repository validation should use transformed/custom data.
     *
     * @return array<string, mixed>
     */
    protected function repositoryValidationData(Validator $validator): array
    {
        return $validator->getData();
    }

    /**
     * Attach repository validation after the standard Laravel validator.
     *
     * No repository queries run if normal FormRequest validation already has
     * errors.
     */
    protected function getValidatorInstance()
    {
        $validator = parent::getValidatorInstance();

        if ($this->repositoryValidationAttached) {
            return $validator;
        }

        $this->repositoryValidationAttached = true;
        $rules = $this->repositoryValidationRules();

        if ($rules === []) {
            return $validator;
        }

        $validator->after(function (Validator $validator) use ($rules): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $errors = app(RepositoryValidationService::class)->errors(
                $rules,
                $this->repositoryValidationData($validator),
                resetContext: true,
            );

            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        });

        return $validator;
    }

    public function resolved(string $key): Model|Collection|null
    {
        return app(ValidationContext::class)->get($key);
    }

    public function resolvedModel(
        string $key,
        ?string $expectedClass = null,
    ): Model {
        return app(ValidationContext::class)->requireModel(
            $key,
            $expectedClass,
        );
    }

    public function resolvedCollection(
        string $key,
        ?string $expectedClass = null,
    ): Collection {
        return app(ValidationContext::class)->requireCollection(
            $key,
            $expectedClass,
        );
    }
}
