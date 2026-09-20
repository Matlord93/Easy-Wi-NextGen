<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

final class RequestIdSubscriber implements EventSubscriberInterface
{
    public const HEADER_NAME = 'X-Request-ID';
    private const ATTRIBUTE_NAME = 'request_id';

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
        $requestId = $request->headers->get(self::HEADER_NAME);
        if (!is_string($requestId) || !Uuid::isValid(trim($requestId))) {
            $requestId = Uuid::v4()->toRfc4122();
            $request->headers->set(self::HEADER_NAME, $requestId);
        }

        $request->attributes->set(self::ATTRIBUTE_NAME, $requestId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = $request->headers->get(self::HEADER_NAME);
        if (is_string($requestId) && $requestId !== '') {
            $event->getResponse()->headers->set(self::HEADER_NAME, $requestId);
        }
    }
}
