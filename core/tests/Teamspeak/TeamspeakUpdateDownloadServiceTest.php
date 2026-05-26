<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\TeamspeakUpdateDownloadService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class TeamspeakUpdateDownloadServiceTest extends TestCase
{
    public function testChecksumCorrect(): void
    {
        $svc = new TeamspeakUpdateDownloadService($this->dummyClient());
        $f = tempnam(sys_get_temp_dir(), 'ts6');
        file_put_contents($f, 'abc');
        $r = $svc->verifySha256($f, hash('sha256', 'abc'), false);
        self::assertTrue($r->verified);
    }

    public function testChecksumWrong(): void
    {
        $svc = new TeamspeakUpdateDownloadService($this->dummyClient());
        $f = tempnam(sys_get_temp_dir(), 'ts6');
        file_put_contents($f, 'abc');
        $r = $svc->verifySha256($f, str_repeat('0', 64), false);
        self::assertFalse($r->verified);
        self::assertFalse($r->missing);
    }

    public function testChecksumMissingStrictOff(): void
    {
        $svc = new TeamspeakUpdateDownloadService($this->dummyClient());
        $f = tempnam(sys_get_temp_dir(), 'ts6');
        file_put_contents($f, 'abc');
        $r = $svc->verifySha256($f, null, false);
        self::assertTrue($r->missing);
    }

    public function testChecksumMissingStrictOn(): void
    {
        $svc = new TeamspeakUpdateDownloadService($this->dummyClient());
        $f = tempnam(sys_get_temp_dir(), 'ts6');
        file_put_contents($f, 'abc');
        $r = $svc->verifySha256($f, null, true);
        self::assertTrue($r->missing);
        self::assertStringContainsString('erforderlich', (string) $r->message);
    }

    private function dummyClient(): HttpClientInterface
    {
        return new class implements HttpClientInterface { public function request(string $method, string $url, array $options = []): ResponseInterface { throw new \RuntimeException('not used'); } public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface { return new class implements ResponseStreamInterface { public function key(): ResponseInterface { throw new \LogicException('empty'); } public function current(): ChunkInterface { throw new \LogicException('empty'); } public function valid(): bool { return false; } public function next(): void {} public function rewind(): void {} }; } public function withOptions(array $options): static { return $this; } };
    }
}
