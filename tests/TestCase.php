<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests;

use Ak279642\LaravelInfrastructure\LaravelInfrastructureServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelInfrastructureServiceProvider::class];
    }
}
