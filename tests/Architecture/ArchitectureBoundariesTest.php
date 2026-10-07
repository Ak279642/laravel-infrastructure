<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ArchitectureBoundariesTest extends TestCase
{
    public function test_repositories_do_not_own_transaction_boundaries_or_http_controllers(): void
    {
        $root = dirname(__DIR__, 2)
            .'/src/Database/Repositories';

        $violations = [];

        foreach ($this->phpFiles($root) as $file) {
            $contents = file_get_contents($file);

            if (! is_string($contents)) {
                continue;
            }

            if (
                preg_match(
                    '/DB::transaction|->transaction\s*\(/',
                    $contents,
                )
                || str_contains(
                    $contents,
                    'Illuminate\\Http\\',
                )
                || str_contains(
                    $contents,
                    'Controller',
                )
            ) {
                $violations[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $violations,
            'Repository architecture violations: '
                .implode(', ', $violations),
        );
    }

    public function test_infrastructure_source_never_depends_on_host_application_namespace(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $violations = [];

        foreach ($this->phpFiles($root) as $file) {
            $contents = file_get_contents($file);

            if (
                is_string($contents)
                && preg_match(
                    '/(?:use|new|extends|implements)\s+App\\\\/',
                    $contents,
                )
            ) {
                $violations[] = str_replace(
                    $root.'/',
                    '',
                    $file,
                );
            }
        }

        self::assertSame(
            [],
            $violations,
            'Host application dependencies found: '
                .implode(', ', $violations),
        );
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root),
        );

        foreach ($iterator as $file) {
            if (
                $file->isFile()
                && $file->getExtension() === 'php'
            ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
