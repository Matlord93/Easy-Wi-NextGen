<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Application\TraceContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestIdSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TraceContext $traceContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
            KernelEvents::RESPONSE => ['onKernelResponse', -20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = $this->traceContext->requestId($request);
        $correlationId = $this->traceContext->correlationId($request);

        $request->headers->set(TraceContext::REQUEST_HEADER, $requestId);
        $request->headers->set(TraceContext::CORRELATION_HEADER, $correlationId);
        $request->attributes->set(TraceContext::REQUEST_ATTRIBUTE, $requestId);
        $request->attributes->set(TraceContext::CORRELATION_ATTRIBUTE, $correlationId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $event->getResponse()->headers->set(TraceContext::REQUEST_HEADER, $this->traceContext->requestId($request));
        $event->getResponse()->headers->set(TraceContext::CORRELATION_HEADER, $this->traceContext->correlationId($request));
    }
}
