<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Derive the repository cache partition from the authenticated request.
 *
 * No repository operation list is required. We only trust the actor held by
 * Laravel's request/auth resolver, not bearer tokens or user-supplied inputs.
 *
 * @internal
 */
final class AuthenticatedCacheContext
{
    /**
     * @return array{cacheable: bool, identity: array<string, mixed>}
     */
    public static function resolve(): array
    {
        if (! app()->bound('request')) {
            return ['cacheable' => true, 'identity' => ['kind' => 'system']];
        }

        $request = request();
        $attributes = $request->attributes;
        $actor = $attributes->get('user');
        $actorId = $attributes->get('user_id');
        $actorType = $attributes->get('user_type');

        // Support applications that resolve API/session actors into request
        // attributes instead of using Laravel's default guard.
        if ($actor !== null || $actorId !== null || $actorType !== null) {
            $subject = self::subject($actor);
            if ($subject === null
                || ! is_string($actorType)
                || $actorType === ''
                || (! is_string($actorId) && ! is_int($actorId))
                || (string) $actorId !== $subject['id']) {
                // An incomplete or mismatched actor must never populate a
                // cross-request cache, even if a custom read policy opts in.
                return ['cacheable' => false, 'identity' => []];
            }

            $partnerId = $attributes->get('partner_id');
            if ($partnerId !== null && ! is_string($partnerId) && ! is_int($partnerId)) {
                return ['cacheable' => false, 'identity' => []];
            }

            $identity = [
                'kind' => 'actor',
                'type' => $actorType,
                'subject' => $subject,
                'partner_id' => $partnerId,
            ];

            $partnerUser = $attributes->get('partner_user');
            if ($partnerUser !== null) {
                $partnerSubject = self::subject($partnerUser);
                if ($partnerSubject === null) {
                    return ['cacheable' => false, 'identity' => []];
                }
                $identity['partner_subject'] = $partnerSubject;
            }

            return ['cacheable' => true, 'identity' => $identity];
        }

        // The normal Laravel request guard also works, without requiring an
        // application-level BaseRepository subclass.
        $guardUser = $request->user();
        if ($guardUser === null) {
            return ['cacheable' => true, 'identity' => ['kind' => 'guest']];
        }

        $subject = self::subject($guardUser);
        if ($subject === null) {
            return ['cacheable' => false, 'identity' => []];
        }

        return [
            'cacheable' => true,
            'identity' => ['kind' => 'actor', 'type' => 'guard', 'subject' => $subject],
        ];
    }

    /**
     * @return array{class: class-string, id: string, snapshot: string|null}|null
     */
    private static function subject(mixed $actor): ?array
    {
        if (! $actor instanceof Authenticatable) {
            return null;
        }

        $id = $actor->getAuthIdentifier();
        if ((! is_string($id) && ! is_int($id)) || (string) $id === '') {
            return null;
        }

        // A different role/status/tenant on the freshly authenticated model
        // creates a different key, even when its user ID stays the same.
        // The snapshot is hashed: credentials are never placed in plaintext
        // in the Redis key or request-cache metadata.
        return [
            'class' => $actor::class,
            'id' => (string) $id,
            'snapshot' => $actor instanceof Model
                ? hash('sha256', serialize($actor->getRawOriginal()))
                : null,
        ];
    }
}
