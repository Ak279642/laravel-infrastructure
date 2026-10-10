<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

final readonly class FileReference implements JsonSerializable
{
    public function __construct(
        private Model $model,
        private string $column,
        public string $path,
    ) {}

    public function getFileUrl(?string $seoName = null): string
    {
        return $this->model->getFileUrl($this->column, $seoName);
    }

    public function __toString(): string
    {
        return $this->path;
    }

    public function jsonSerialize(): string
    {
        return $this->path;
    }
}
