<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\MailPasswordHasher;
use PHPUnit\Framework\TestCase;

final class MailPasswordHasherTest extends TestCase
{
    public function testArgon2idIsPrefixedForDovecot(): void
    {
        $hasher = new MailPasswordHasher('argon2id');
        $hash = $hasher->hash('S3cretPa$$');

        if (defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('{ARGON2ID}$argon2id$', $hash);

            return;
        }

        self::assertStringStartsWith('{ARGON2ID}$2', $hash);
    }

    public function testBcryptIsPrefixedForDovecot(): void
    {
        $hasher = new MailPasswordHasher('bcrypt');
        $hash = $hasher->hash('S3cretPa$$');

        self::assertStringStartsWith('{BLF-CRYPT}$2', $hash);
    }
}
