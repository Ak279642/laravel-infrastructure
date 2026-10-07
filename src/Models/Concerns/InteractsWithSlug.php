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
            $model->generateInfrastructureSlug(force: false);
        });

        static::updating(function (Model $model): void {
            $options = $model->resolvedSlugOptions();

            if (! (bool) ($options['enabled'] ?? false)) {
                return;
            }

            $column = (string) ($options['column'] ?? 'slug');

            // A manually supplied slug always wins.
            if ($model->isDirty($column) && filled($model->getAttribute($column))) {
                return;
            }

            $current = $model->getAttribute($column);

            if (! filled($current)) {
                $model->generateInfrastructureSlug(force: false);

                return;
            }

            if (! (bool) ($options['regenerate_on_update'] ?? false)) {
                return;
            }

            $sources = $options['source'] ?? 'name';
            $sources = is_array($sources) ? $sources : [$sources];

            foreach ($sources as $source) {
                if (is_string($source) && $model->isDirty($source)) {
                    $model->generateInfrastructureSlug(force: true);

                    return;
                }
            }
        });
    }

    /**
     * Override this on an application base model or individual model.
     *
     * @return array<string, mixed>
     */
    protected function slugOptions(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedSlugOptions(): array
    {
        return array_replace(
            (array) config('laravel-infrastructure.slug', []),
            $this->slugOptions(),
        );
    }

    private function generateInfrastructureSlug(bool $force): void
    {
        $options = $this->resolvedSlugOptions();

        if (! (bool) ($options['enabled'] ?? false)) {
            return;
        }

        $column = (string) ($options['column'] ?? 'slug');

        if (! $force && filled($this->getAttribute($column))) {
            return;
        }

        $slug = app(SlugGenerator::class)->generateFor($this, $options);

        if ($slug !== null) {
            $this->setAttribute($column, $slug);
        }
    }
}
