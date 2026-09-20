<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Application\ApiErrorResponseFactory;
use App\Module\Core\Application\TraceContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private ApiErrorResponseFactory $responseFactory, private TraceContext $traceContext, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array { return [KernelEvents::EXCEPTION => ['onException', 0]]; }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/') && $request->getPreferredFormat() !== 'json') { return; }
        $exception = $event->getThrowable();
        $requestId = $this->traceContext->requestId($request);
        $this->logger->error('api.request_failed', ['request_id' => $requestId, 'exception_class' => $exception::class, 'status' => $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 500, 'exception' => $exception]);
        $event->setResponse($this->responseFactory->create($request, $exception));
    }
}
