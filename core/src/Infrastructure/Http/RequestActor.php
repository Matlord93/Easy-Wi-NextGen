<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Module\Core\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final class RequestActor
{
    public static function user(?Request $request): ?User
    {
        return $request === null ? null : self::asUser($request->attributes->get('current_user'));
    }

    private static function asUser(mixed $value): ?User
    {
        return $value instanceof User ? $value : null;
    }
}
