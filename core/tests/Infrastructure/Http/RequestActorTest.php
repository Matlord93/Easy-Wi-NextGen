<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestActorTest extends TestCase
{
    public function testApplicationUserIdentityIsPreserved(): void
    {
        $user = new User('admin@example.test', UserType::Admin);
        $request = new Request();
        $request->attributes->set('current_user', $user);

        self::assertSame($user, RequestActor::user($request));
    }

    #[DataProvider('invalidActors')]
    public function testUntrustedAttributeValuesAreRejected(mixed $value): void
    {
        $request = new Request();
        $request->attributes->set('current_user', $value);

        self::assertNull(RequestActor::user($request));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidActors(): iterable
    {
        yield 'missing user' => [null];
        yield 'email string' => ['admin@example.test'];
        yield 'numeric id' => [1];
        yield 'forged admin fields' => [['id' => 1, 'isAdmin' => true]];
        yield 'unrelated object' => [new \stdClass()];
    }

    public function testMissingRequestIsAnonymous(): void
    {
        self::assertNull(RequestActor::user(null));
    }
}
