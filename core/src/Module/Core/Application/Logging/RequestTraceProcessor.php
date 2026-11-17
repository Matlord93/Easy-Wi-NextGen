<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Logging;

use App\Module\Core\Application\TraceContext;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsMonologProcessor]
final class RequestTraceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TraceContext $traceContext,
    ) {
    }

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            $fallbackId = $this->traceContext->newId();
            $extra['request_id'] = $fallbackId;
            $extra['correlation_id'] = $fallbackId;

            return $record->with(extra: $extra);
        }

        $extra['request_id'] = $this->traceContext->requestId($request);
        $extra['correlation_id'] = $this->traceContext->correlationId($request);

        return $record->with(extra: $extra);
    }
}
