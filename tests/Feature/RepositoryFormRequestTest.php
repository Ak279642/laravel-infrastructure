<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\CacheManager;
use Ak279642\LaravelInfrastructure\Database\Repositories\BaseRepository;
use Ak279642\LaravelInfrastructure\Http\Requests\RepositoryFormRequest;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Ak279642\LaravelInfrastructure\Validation\RepositoryValidationRule;
use Ak279642\LaravelInfrastructure\Validation\ValidationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class RepositoryFormRequestTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('repository_validation_countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $this->app['router']->post(
            '/repository-validation',
            function (
                TestRepositoryRequest $request,
                ValidationContext $context,
                TestCountryRepository $repository,
            ) {
                $fromRequest = $request->resolvedModel(
                    'country',
                    TestCountry::class,
                );

                $fromService = $context->requireModel(
                    'country',
                    TestCountry::class,
                );

                // BaseRepository reuses the validation-context instance instead
                // of issuing the same lookup again.
                $fromRepository = $repository->findOrFail(
                    $request->integer('country_id'),
                );

                return response()->json([
                    'same_request_service' => $fromRequest === $fromService,
                    'same_service_repository' => $fromService === $fromRepository,
                    'country' => $fromRepository->name,
                ]);
            },
        );
    }

    public function test_form_request_resolves_repository_data_for_services_and_repositories(): void
    {
        $country = TestCountry::query()->create([
            'name' => 'India',
        ]);

        $response = $this->postJson('/repository-validation', [
            'country_id' => $country->getKey(),
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'same_request_service' => true,
                'same_service_repository' => true,
                'country' => 'India',
            ]);
    }

    public function test_repository_validation_errors_are_returned_as_form_request_errors(): void
    {
        $response = $this->postJson('/repository-validation', [
            'country_id' => 999,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country_id']);
    }
}

final class TestRepositoryRequest extends RepositoryFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer'],
        ];
    }

    protected function repositoryValidationRules(): array
    {
        return [
            new RepositoryValidationRule(
                repository: TestCountryRepository::class,
                exists: [
                    'country_id',
                ],
                resolve: [
                    [
                        'field' => 'country_id',
                        'as' => 'country',
                    ],
                ],
            ),
        ];
    }
}

final class TestCountry extends Model
{
    protected $table = 'repository_validation_countries';

    public $timestamps = false;

    protected $fillable = ['name'];
}

final class TestCountryRepository extends BaseRepository
{
    public function __construct(
        TestCountry $model,
        CacheManager $cache,
        ?ValidationContext $validationContext = null,
    ) {
        parent::__construct($model, $cache, $validationContext);
    }
}
