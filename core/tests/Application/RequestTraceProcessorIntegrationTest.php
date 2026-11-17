<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\Logging\RequestTraceProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestTraceProcessorIntegrationTest extends KernelTestCase
{
    public function testConfiguredLoggerAddsRequestTraceIds(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $logger = $container->get('logger');
        $stack = $container->get('request_stack');
        self::assertInstanceOf(Logger::class, $logger);
        self::assertInstanceOf(RequestStack::class, $stack);
        self::assertCount(1, array_filter($logger->getProcessors(), static fn ($processor): bool => $processor instanceof RequestTraceProcessor));

        $request = new Request();
        $request->headers->set('X-Request-ID', 'd42f4308-3a8b-46a4-9c20-b093e11d3696');
        $request->headers->set('X-Correlation-ID', '220f8d4d-6bc1-4a0d-8b7a-30700fd3334b');
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        $stack->push($request);

        try {
            $logger->info('Registered trace processor', ['job_id' => 42]);

            $records = $handler->getRecords();
            self::assertCount(1, $records);
            self::assertSame('d42f4308-3a8b-46a4-9c20-b093e11d3696', $records[0]->extra['request_id']);
            self::assertSame('220f8d4d-6bc1-4a0d-8b7a-30700fd3334b', $records[0]->extra['correlation_id']);
            self::assertSame(['job_id' => 42], $records[0]->context);
        } finally {
            $stack->pop();
            $logger->popHandler();
        }
    }
}
