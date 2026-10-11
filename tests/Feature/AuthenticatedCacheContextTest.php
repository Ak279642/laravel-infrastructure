<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Tests\Feature;

use Ak279642\LaravelInfrastructure\Cache\AuthenticatedCacheContext;
use Ak279642\LaravelInfrastructure\Tests\TestCase;
use Illuminate\Foundation\Auth\User as EloquentUser;
use Illuminate\Http\Request;

final class AuthenticatedCacheContextTest extends TestCase
{
    public function test_verified_request_actor_is_partitioned_by_identity_and_model_snapshot(): void
    {
        $actor = $this->actor(10, 3);
        $request = $this->request();
        $request->attributes->set('user', $actor);
        $request->attributes->set('user_type', 'admin');
        $request->attributes->set('user_id', 10);

        $first = AuthenticatedCacheContext::resolve();
        self::assertTrue($first['cacheable']);
        self::assertSame('admin', $first['identity']['type']);
        self::assertSame('10', $first['identity']['subject']['id']);

        $actor->setAttribute('admin_role_id', 4);
        $roleChanged = AuthenticatedCacheContext::resolve();
        self::assertNotSame($first['identity'], $roleChanged['identity']);

        $request->attributes->set('user', $this->actor(11, 3));
        $request->attributes->set('user_id', 11);
        $otherAdmin = AuthenticatedCacheContext::resolve();
        self::assertNotSame($first['identity'], $otherAdmin['identity']);
    }

    public function test_partner_and_agent_context_do_not_share_cache_partitions(): void
    {
        $actor = $this->actor(17, 1);
        $request = $this->request();
        $request->attributes->set('user', $actor);
        $request->attributes->set('user_id', 17);
        $request->attributes->set('user_type', 'agent');
        $request->attributes->set('partner_id', 9);
        $request->attributes->set('partner_user', $this->actor(9, 1));

        $agent = AuthenticatedCacheContext::resolve();
        self::assertTrue($agent['cacheable']);
        self::assertSame(9, $agent['identity']['partner_id']);

        $request->attributes->set('partner_id', 12);
        $request->attributes->set('partner_user', $this->actor(12, 1));
        self::assertNotSame($agent['identity'], AuthenticatedCacheContext::resolve()['identity']);

        $request->attributes->set('user_type', 'partner');
        self::assertNotSame('agent', AuthenticatedCacheContext::resolve()['identity']['type']);
    }

    public function test_incomplete_or_mismatched_attribute_identity_is_never_shared(): void
    {
        $request = $this->request();
        $request->attributes->set('user_type', 'admin');
        $request->attributes->set('user_id', 10);
        self::assertFalse(AuthenticatedCacheContext::resolve()['cacheable']);

        $request->attributes->set('user', $this->actor(11, 3));
        self::assertFalse(AuthenticatedCacheContext::resolve()['cacheable']);

        $request->attributes->set('user_id', 11);
        self::assertTrue(AuthenticatedCacheContext::resolve()['cacheable']);
    }

    public function test_default_laravel_guard_and_guest_requests_are_supported(): void
    {
        $request = $this->request();
        self::assertSame(
            ['kind' => 'guest'],
            AuthenticatedCacheContext::resolve()['identity'],
        );

        $actor = $this->actor(41, 2);
        $request->setUserResolver(static fn (): EloquentUser => $actor);

        $identity = AuthenticatedCacheContext::resolve();
        self::assertTrue($identity['cacheable']);
        self::assertSame('41', $identity['identity']['subject']['id']);
        self::assertSame('guard', $identity['identity']['type']);
    }

    private function request(): Request
    {
        $request = Request::create('/test');
        $request->setUserResolver(static fn (): null => null);
        $this->app->instance('request', $request);

        return $request;
    }

    private function actor(int $id, int $roleId): EloquentUser
    {
        $actor = new EloquentUser();
        $actor->forceFill([
            'id' => $id,
            'email' => 'actor'.$id.'@example.test',
            'admin_role_id' => $roleId,
        ]);

        return $actor;
    }
}
