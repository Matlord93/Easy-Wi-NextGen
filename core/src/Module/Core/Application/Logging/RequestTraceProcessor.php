<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Logging;

use App\Module\Core\Application\TraceContext;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestTraceProcessor
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TraceContext $traceContext,
    ) {
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function __invoke(array $record): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            $fallbackId = $this->traceContext->newId();
            $record['extra']['request_id'] = $fallbackId;
            $record['extra']['correlation_id'] = $fallbackId;

            return $record;
        }

        $record['extra']['request_id'] = $this->traceContext->requestId($request);
        $record['extra']['correlation_id'] = $this->traceContext->correlationId($request);

        return $record;
    }
}
