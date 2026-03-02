<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\Logging\RequestTraceProcessor;
use App\Module\Core\Application\TraceContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestTraceProcessorTest extends TestCase
{
    public function testAddsFallbackTraceIdsWhenNoRequestIsAvailable(): void
    {
        $processor = new RequestTraceProcessor(new RequestStack(), new TraceContext());

        $record = $processor(['extra' => []]);

        self::assertArrayHasKey('request_id', $record['extra']);
        self::assertArrayHasKey('correlation_id', $record['extra']);
        self::assertNotSame('', $record['extra']['request_id']);
        self::assertSame($record['extra']['request_id'], $record['extra']['correlation_id']);
    }

    public function testUsesRequestHeadersForTraceIds(): void
    {
        $request = new Request();
        $request->headers->set('X-Request-ID', 'd42f4308-3a8b-46a4-9c20-b093e11d3696');
        $request->headers->set('X-Correlation-ID', '220f8d4d-6bc1-4a0d-8b7a-30700fd3334b');

        $stack = new RequestStack();
        $stack->push($request);

        $processor = new RequestTraceProcessor($stack, new TraceContext());
        $record = $processor(['extra' => []]);

        self::assertSame('d42f4308-3a8b-46a4-9c20-b093e11d3696', $record['extra']['request_id']);
        self::assertSame('220f8d4d-6bc1-4a0d-8b7a-30700fd3334b', $record['extra']['correlation_id']);
    }
}
