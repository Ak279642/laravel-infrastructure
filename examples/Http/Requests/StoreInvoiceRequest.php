<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;
use App\Repositories\CustomerRepository;
use App\Repositories\ProductRepository;

final class StoreInvoiceRequest extends RepositoryFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'organization_id' => ['required', 'integer'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: CustomerRepository::class,
                exists: [
                    'customer_id' => [
                        'where' => [
                            'organization_id' => 'organization_id',
                        ],
                    ],
                ],
                resolve: [
                    [
                        'field' => 'customer_id',
                        'as' => 'customer',
                        'with' => ['organization'],
                    ],
                ],
            ),

            new RepositoryValidationRule(
                repository: ProductRepository::class,
                existsIn: [
                    // Request field containing IDs.
                    'field' => 'product_ids',

                    // Repository/model column checked against those values.
                    'column' => 'id',

                    // Optional; defaults to the field above.
                    'values' => 'product_ids',

                    'where' => [
                        'organization_id' => 'organization_id',
                    ],
                ],
                resolve: [
                    [
                        'field' => 'product_ids',
                        'as' => 'products',
                        'with' => ['tax'],
                    ],
                ],
            ),
        ];
    }
}
