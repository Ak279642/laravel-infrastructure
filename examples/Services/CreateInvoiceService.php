<?php

declare(strict_types=1);

namespace App\Services;

use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use App\Models\Customer;
use App\Models\Product;
use App\Repositories\CustomerRepository;

final class CreateInvoiceService
{
    public function __construct(
        private readonly ValidationContext $validationContext,
        private readonly CustomerRepository $customers,
    ) {}

    public function create(array $data): void
    {
        // Directly reuse the model loaded by StoreInvoiceRequest.
        $customer = $this->validationContext->requireModel(
            'customer',
            Customer::class,
        );

        $products = $this->validationContext->requireCollection(
            'products',
            Product::class,
        );

        // This also reuses the same resolved Customer instance because
        // BaseRepository searches ValidationContext by model class + ID.
        $sameCustomer = $this->customers->findOrFail(
            $data['customer_id'],
        );

        // $customer === $sameCustomer is true.
        // Continue complete business logic using $customer and $products.
    }
}
