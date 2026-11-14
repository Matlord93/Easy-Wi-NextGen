<?php

declare(strict_types=1);
namespace App\Tests\Unit;
use App\Infrastructure\Network\ConfiguredAgentTarget;
use PHPUnit\Framework\TestCase;
final class ConfiguredAgentTargetTest extends TestCase
{
    public function testAllowsPrivatePublicAndCustomPorts(): void
    {
        foreach (['http://10.0.0.1:7456', 'https://agent.example.org:65535/base', 'http://[::1]:1234', 'http://localhost'] as $url) {
            ConfiguredAgentTarget::validate($url);
            self::assertTrue(true);
        }
    }
    public function testRejectsNonHttpTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfiguredAgentTarget::validate('file:///etc/passwd');
    }
    public function testRejectsCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfiguredAgentTarget::validate('http://user:password@localhost');
    }
}
