<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Exceptions\ConflictException;
use Ak279642\LaravelInfrastructure\Http\Middleware\RequestCorrelationId;
use Ak279642\LaravelInfrastructure\Http\Responses\MessageResponse;
use Ak279642\LaravelInfrastructure\Http\Responses\ResourceResponse;
use Ak279642\LaravelInfrastructure\Logging\CustomLog;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ApiResponseExceptionLoggingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set(
            'laravel-infrastructure.responses.exception_renderer_enabled',
            true,
        );
        $app['config']->set(
            'laravel-infrastructure.logging.log_client_exceptions',
            false,
        );
        $app['config']->set(
            'laravel-infrastructure.logging.log_server_exceptions',
            true,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];

        $router->get('/batch4/message', fn () => MessageResponse::make(
            'Created.',
            201,
        ))->middleware(RequestCorrelationId::class);

        $router->get('/batch4/paginated', function () {
            $paginator = new LengthAwarePaginator(
                [
                    ['id' => 1, 'name' => 'One'],
                    ['id' => 2, 'name' => 'Two'],
                ],
                5,
                2,
                1,
            );

            return ResourceResponse::make(
                Batch4Resource::collection($paginator),
                'Loaded.',
            );
        });

        $router->get('/batch4/package-conflict', static function () {
            throw new ConflictException('Duplicate record.');
        });

        $router->get('/batch4/framework-validation', static function () {
            throw LaravelValidationException::withMessages([
                'email' => ['The email field is required.'],
            ]);
        });

        $router->get('/batch4/authentication', static function () {
            throw new AuthenticationException('Unauthenticated.');
        });

        $router->get('/batch4/authorization', static function () {
            throw new AuthorizationException('Private detail must not leak.');
        });

        $router->get('/batch4/not-found', static function () {
            throw (new ModelNotFoundException())->setModel(
                Batch4MissingModel::class,
                [99],
            );
        });

        $router->post('/batch4/method', fn () => MessageResponse::make());

        $router->get('/batch4/rate-limit', static function () {
            throw new TooManyRequestsHttpException(
                30,
                'Internal limiter detail.',
            );
        });

        $router->get('/batch4/error', static function () {
            throw new RuntimeException(
                'Database failed password=supersecret token=abcd1234',
            );
        });
    }

    public function test_success_message_envelope_preserves_status_and_correlation_id(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'req-123')
            ->getJson('/batch4/message');

        $response
            ->assertStatus(201)
            ->assertHeader('X-Request-ID', 'req-123')
            ->assertExactJson([
                'success' => true,
                'message' => 'Created.',
            ]);
    }

    public function test_invalid_incoming_correlation_id_is_replaced(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'not valid because spaces')
            ->getJson('/batch4/message');

        $requestId = $response->headers->get('X-Request-ID');

        self::assertNotNull($requestId);
        self::assertNotSame('not valid because spaces', $requestId);
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9._:-]+$/',
            $requestId,
        );
    }

    public function test_paginated_resource_has_stable_envelope_and_pagination_metadata(): void
    {
        $this->getJson('/batch4/paginated')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Loaded.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 2);
    }

    public function test_package_exception_uses_uniform_error_envelope(): void
    {
        $this->getJson('/batch4/package-conflict')
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Duplicate record.',
                'error_code' => 'CONFLICT',
            ]);
    }

    public function test_framework_validation_exception_maps_to_422_envelope(): void
    {
        $this->getJson('/batch4/framework-validation')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['email']);
    }

    public function test_framework_http_failures_map_to_correct_statuses(): void
    {
        $this->getJson('/batch4/authentication')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHORIZED');

        $this->getJson('/batch4/authorization')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Forbidden.',
                'error_code' => 'FORBIDDEN',
            ]);

        $this->getJson('/batch4/not-found')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/batch4/method')
            ->assertStatus(405)
            ->assertJsonPath('error_code', 'METHOD_NOT_ALLOWED');

        $this->getJson('/batch4/rate-limit')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('error_code', 'TOO_MANY_REQUESTS');
    }

    public function test_production_500_response_and_log_do_not_leak_exception_details(): void
    {
        $logger = new Batch4RecordingLogger();
        $this->app->instance('log', $logger);

        $response = $this->getJson('/batch4/error');

        $response
            ->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Internal server error.',
                'error_code' => 'INTERNAL_ERROR',
            ]);

        self::assertArrayNotHasKey('debug', $response->json());

        $json = $response->getContent();

        self::assertIsString($json);
        self::assertStringNotContainsString('supersecret', $json);
        self::assertStringNotContainsString('abcd1234', $json);
        self::assertNotNull($response->headers->get('X-Request-ID'));

        self::assertCount(1, $logger->entries);
        self::assertSame(
            'Unhandled API exception.',
            $logger->entries[0]['message'],
        );

        $logged = json_encode(
            $logger->entries[0]['context'],
            JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString('supersecret', $logged);
        self::assertStringNotContainsString('abcd1234', $logged);
        self::assertStringContainsString('********', $logged);
    }

    public function test_non_json_requests_keep_laravel_web_exception_rendering(): void
    {
        $response = $this->get('/batch4/not-found');

        $response->assertStatus(404);

        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString('"error_code":"NOT_FOUND"', $content);
    }

    public function test_custom_log_uses_same_request_id_and_redacts_sensitive_context(): void
    {
        $request = Request::create(
            '/batch4/log',
            'GET',
            server: [
                'HTTP_X_REQUEST_ID' => 'trace-456',
            ],
        );

        $this->app->instance('request', $request);

        $logger = new Batch4RecordingLogger();
        $this->app->instance('log', $logger);

        CustomLog::info(
            'Calling upstream token=plaintext',
            [
                'password' => 'secret-password',
                'nested' => [
                    'api_key' => 'secret-key',
                    'note' => 'Bearer abc.def.ghi',
                ],
            ],
        );

        self::assertCount(1, $logger->entries);

        $entry = $logger->entries[0];

        self::assertSame('trace-456', $entry['context']['request']['request_id']);
        self::assertSame('********', $entry['context']['password']);
        self::assertSame('********', $entry['context']['nested']['api_key']);
        self::assertStringNotContainsString('plaintext', $entry['message']);
        self::assertStringNotContainsString('abc.def.ghi', $entry['context']['nested']['note']);
    }
}

final class Batch4Resource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
        ];
    }
}

final class Batch4MissingModel
{
}

final class Batch4RecordingLogger
{
    public array $entries = [];

    public function channel(string $channel): static
    {
        return $this;
    }

    public function log(
        string $level,
        string $message,
        array $context = [],
    ): void {
        $this->entries[] = compact('level', 'message', 'context');
    }
}
