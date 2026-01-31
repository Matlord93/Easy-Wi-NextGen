<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\Sinusbot\SinusbotQuotaValidator;
use PHPUnit\Framework\TestCase;

final class SinusbotQuotaValidatorTest extends TestCase
{
    public function testValidQuotaPasses(): void
    {
        $validator = new SinusbotQuotaValidator(1, 100);
        $validator->validate(10);
        $this->assertTrue(true);
    }

    public function testInvalidQuotaThrows(): void
    {
        $validator = new SinusbotQuotaValidator(1, 100);

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate(0);
    }
}
