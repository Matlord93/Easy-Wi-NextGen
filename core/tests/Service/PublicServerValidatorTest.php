<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\PublicServerValidator;
use PHPUnit\Framework\TestCase;

final class PublicServerValidatorTest extends TestCase
{
    public function testRejectsPrivateIpWhenDisabled(): void
    {
        $validator = new PublicServerValidator();

        $errors = $validator->validate('10.0.0.1', 27015, null, 60, false);

        self::assertContains('Private or reserved IP ranges are not allowed.', $errors);
    }

    public function testAllowsPublicIpWhenDisabled(): void
    {
        $validator = new PublicServerValidator();

        $errors = $validator->validate('8.8.8.8', 27015, 27016, 60, false);

        self::assertSame([], $errors);
    }
}
