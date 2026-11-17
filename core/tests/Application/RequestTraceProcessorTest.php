<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\Logging\RequestTraceProcessor;
use App\Module\Core\Application\TraceContext;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestTraceProcessorTest extends TestCase
{
    public function testAddsFallbackTraceIdsWhenNoRequestIsAvailable(): void
    {
        $processor = new RequestTraceProcessor(new RequestStack(), new TraceContext());

        $record = $processor($this->record());

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
        $record = $processor($this->record());

        self::assertSame('d42f4308-3a8b-46a4-9c20-b093e11d3696', $record['extra']['request_id']);
        self::assertSame('220f8d4d-6bc1-4a0d-8b7a-30700fd3334b', $record['extra']['correlation_id']);
    }

    public function testPreservesRecordDataAndUsesRequestAttributes(): void
    {
        $request = new Request();
        $request->attributes->set(TraceContext::REQUEST_ATTRIBUTE, 'd42f4308-3a8b-46a4-9c20-b093e11d3696');
        $request->attributes->set(TraceContext::CORRELATION_ATTRIBUTE, '220f8d4d-6bc1-4a0d-8b7a-30700fd3334b');
        $request->headers->set(TraceContext::REQUEST_HEADER, 'invalid');
        $request->headers->set(TraceContext::CORRELATION_HEADER, 'invalid');
        $stack = new RequestStack();
        $stack->push($request);
        $original = $this->record();

        $record = (new RequestTraceProcessor($stack, new TraceContext()))($original);

        self::assertSame('d42f4308-3a8b-46a4-9c20-b093e11d3696', $record->extra['request_id']);
        self::assertSame('220f8d4d-6bc1-4a0d-8b7a-30700fd3334b', $record->extra['correlation_id']);
        self::assertSame('support', $record->extra['existing']);
        self::assertSame($original->context, $record->context);
        self::assertSame($original->message, $record->message);
        self::assertSame($original->datetime, $record->datetime);
        self::assertSame(['existing' => 'support'], $original->extra);
        self::assertNotSame($original, $record);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'Trace test', ['job_id' => 42], ['existing' => 'support']);
    }
}
