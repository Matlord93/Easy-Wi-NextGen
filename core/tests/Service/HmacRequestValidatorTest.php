<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Gameserver\Application\Status\HmacRequestValidator;
use PHPUnit\Framework\TestCase;

final class HmacRequestValidatorTest extends TestCase
{
    public function testValidSignature(): void
    {
        $body = json_encode(['items' => [['server_id' => 1]]], JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $validator = new HmacRequestValidator('secret', 300);

        self::assertTrue($validator->isValid($body, $ts, $sig));
    }

    public function testInvalidWhenSkewed(): void
    {
        $body = '{}';
        $ts = (string) (time() - 1000);
        $sig = base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $validator = new HmacRequestValidator('secret', 300);

        self::assertFalse($validator->isValid($body, $ts, $sig));
    }
}
