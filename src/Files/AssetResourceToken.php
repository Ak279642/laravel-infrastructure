<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Files;

use Illuminate\Support\Facades\Crypt;
use Throwable;

final class AssetResourceToken
{
    public static function encrypt(string $resource): string
    {
        return rtrim(
            strtr(
                base64_encode(Crypt::encryptString($resource)),
                '+/',
                '-_',
            ),
            '=',
        );
    }

    public static function decrypt(string $token): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            return null;
        }

        $encoded = strtr($token, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $encrypted = base64_decode($encoded, true);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $resource = Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }

        return $resource !== '' ? $resource : null;
    }
}
