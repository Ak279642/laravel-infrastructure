<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Models\Concerns;

use Ak279642\LaravelInfrastructure\Slugs\SlugGenerator;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithSlug
{
    public static function bootInteractsWithSlug(): void
    {
        static::creating(function (Model $model): void {
            foreach ($model->configuredSlugFields() as $options) {
                $model->generateInfrastructureSlug(
                    options: $options,
                    force: false,
                );
            }
        });

        static::updating(function (Model $model): void {
            foreach ($model->configuredSlugFields() as $options) {
                if (! (bool) ($options['enabled'] ?? false)) {
                    continue;
                }

                $column = (string) ($options['column'] ?? 'slug');

                // A manually supplied slug always wins for this specific field.
                if (
                    $model->isDirty($column)
                    && filled($model->getAttribute($column))
                ) {
                    continue;
                }

                $current = $model->getAttribute($column);

                if (! filled($current)) {
                    $model->generateInfrastructureSlug(
                        options: $options,
                        force: false,
                    );

                    continue;
                }

                if (! (bool) ($options['regenerate_on_update'] ?? false)) {
                    continue;
                }

                $sources = $options['source'] ?? 'name';
                $sources = is_array($sources) ? $sources : [$sources];

                foreach ($sources as $source) {
                    if (
                        is_string($source)
                        && $model->isDirty($source)
                    ) {
                        $model->generateInfrastructureSlug(
                            options: $options,
                            force: true,
                        );

                        break;
                    }
                }
            }
        });
    }

    /**
     * Backward-compatible single-slug configuration and shared defaults for
     * slugFields().
     *
     * @return array<string, mixed>
     */
    protected function slugOptions(): array
    {
        return [];
    }

    /**
     * Configure one or more slug columns on the model.
     *
     * Example:
     * [
     *     'slug' => ['source' => 'name'],
     *     'seo_slug' => ['source' => 'seo_title'],
     * ]
     *
     * Declaring a field enables it by default. Set enabled=false on an
     * individual field when it should be temporarily disabled.
     *
     * @return array<int|string, string|array<string, mixed>>
     */
    protected function slugFields(): array
    {
        return [];
    }

    /**
     * Existing single-slug API retained for compatibility.
     *
     * @return array<string, mixed>
     */
    public function resolvedSlugOptions(): array
    {
        return array_replace(
            (array) config('laravel-infrastructure.slug', []),
            $this->slugOptions(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function configuredSlugFields(): array
    {
        $defaults = $this->resolvedSlugOptions();
        $fields = $this->slugFields();

        if ($fields === []) {
            $column = (string) ($defaults['column'] ?? 'slug');

            return [
                $column => array_replace(
                    $defaults,
                    ['column' => $column],
                ),
            ];
        }

        $configured = [];

        foreach ($fields as $key => $value) {
            if (is_int($key) && is_string($value) && $value !== '') {
                $configured[$value] = array_replace(
                    $defaults,
                    [
                        'enabled' => true,
                        'column' => $value,
                    ],
                );

                continue;
            }

            if (! is_string($key) || $key === '') {
                continue;
            }

            $configured[$key] = array_replace(
                $defaults,
                [
                    'enabled' => true,
                    'column' => $key,
                ],
                is_array($value) ? $value : [],
                ['column' => $key],
            );
        }

        return $configured;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function generateInfrastructureSlug(
        array $options,
        bool $force,
    ): void {
        if (! (bool) ($options['enabled'] ?? false)) {
            return;
        }

        $column = (string) ($options['column'] ?? 'slug');

        if (! $force && filled($this->getAttribute($column))) {
            return;
        }

        $slug = app(SlugGenerator::class)->generateFor(
            $this,
            $options,
        );

        if ($slug !== null) {
            $this->setAttribute($column, $slug);
        }
    }
}
