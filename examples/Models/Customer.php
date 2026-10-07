<?php

declare(strict_types=1);

namespace App\Models;

final class Customer extends BaseModel
{
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'avatar',
        'document_path',
    ];

    protected function slugOptions(): array
    {
        return array_replace(parent::slugOptions(), [
            // Slugs are unique inside each organization.
            'scope' => ['organization_id'],
            'regenerate_on_update' => true,
        ]);
    }

    protected function fileAttributes(): array
    {
        return [
            // Uses BaseModel fileOptions().
            'avatar',

            // Override options for one attribute.
            'document_path' => [
                'disk' => 'private',
                'delete_on_delete' => true,
            ],
        ];
    }
}
