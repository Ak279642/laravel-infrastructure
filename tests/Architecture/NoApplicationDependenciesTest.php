<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class NoApplicationDependenciesTest extends TestCase
{
    public function test_package_does_not_depend_on_host_app_namespace(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach (['src', 'config'] as $directory) {
            $path = $root.'/'.$directory;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path),
            );

            foreach ($iterator as $file) {
                if (
                    ! $file->isFile()
                    || $file->getExtension() !== 'php'
                ) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (
                    is_string($contents)
                    && preg_match(
                        '/(?<![A-Za-z0-9_])\\\\?App\\\\[A-Za-z_]/',
                        $contents,
                    )
                ) {
                    $violations[] = str_replace(
                        $root.'/',
                        '',
                        $file->getPathname(),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Host application dependencies found: '.
            implode(', ', $violations),
        );
    }
}
