<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Models\BaseModel;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AssetModelAuthorizationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('laravel-infrastructure.assets.disk_aliases', ['media' => 'public']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');
        Schema::create('private_media_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('document')->nullable();
        });
    }

    public function test_private_model_link_requires_current_owner_and_signed_url(): void
    {
        Storage::disk('private')->put('contracts/agreement.pdf', 'private content');
        $document = PrivateMediaDocument::query()->create([
            'user_id' => 42, 'document' => 'contracts/agreement.pdf',
        ]);
        $url = $document->fileAssetUrl('document');
        self::assertNotNull($url);
        self::assertStringContainsString('/_infrastructure/files/', $url);
        self::assertStringNotContainsString('/'.$document->id.'/', $url);
        self::assertStringNotContainsString('contracts/agreement.pdf', $url);

        $this->get($url)->assertHeader('X-Asset-Error-Status', '403');

        $other = new PrivateMediaUser;
        $other->id = 99;
        $this->actingAs($other, 'web');
        $this->get($url)->assertHeader('X-Asset-Error-Status', '403');

        $owner = new PrivateMediaUser;
        $owner->id = 42;
        $this->actingAs($owner, 'web');
        $this->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store, max-age=0');

        $tampered = preg_replace('/signature=[^&]+/', 'signature=invalid', $url);
        $this->get($tampered)->assertHeader('X-Asset-Error-Status', '403');
        $this->get('/media/contracts/agreement.pdf')->assertHeader('X-Asset-Error-Status', '404');
    }
}

final class PrivateMediaDocument extends BaseModel
{
    public $timestamps = false;
    protected $table = 'private_media_documents';
    protected $guarded = [];

    protected function fileAttributes(): array
    {
        return [
            'document' => [
                'disk' => 'private',
                'directory' => 'contracts',
                'access' => ['guard' => 'web', 'signed' => true],
            ],
        ];
    }

    public function authorizesAssetField(string $field): bool
    {
        return $field === 'document' && (int) auth('web')->id() === (int) $this->user_id;
    }
}

final class PrivateMediaUser extends Authenticatable {}
