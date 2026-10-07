<?php

declare(strict_types=1);

namespace App\Services;

use Ak279642\LaravelInfrastructure\Files\FileStorage;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use Illuminate\Http\UploadedFile;

final class UpdateCustomerAvatarService
{
    public function __construct(
        private readonly FileStorage $files,
        private readonly CustomerRepository $customers,
    ) {}

    public function update(Customer $customer, UploadedFile $avatar): Customer
    {
        $path = $this->files->store(
            file: $avatar,
            directory: 'customers/avatars',
            disk: 'public',
        );

        // Repository persists the new path.
        // Customer::InteractsWithFiles deletes the previous avatar only after
        // this update succeeds.
        return $this->customers->update(
            id: $customer,
            data: [
                'avatar' => $path,
            ],
            refresh: true,
        );
    }
}
