<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiDeprecationSubscriber implements EventSubscriberInterface
{
    private const LEGACY_SUNSET = 'Wed, 31 Dec 2025 23:59:59 GMT';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $legacyApi = str_starts_with($path, '/api/') && !str_starts_with($path, '/api/v1/');
        $legacyAgent = str_starts_with($path, '/agent/');
        $legacyMailAliases = str_starts_with($path, '/mail-aliases');

        if (!$legacyApi && !$legacyAgent && !$legacyMailAliases) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', self::LEGACY_SUNSET);

        $this->logger->warning('Legacy API route used.', [
            'path' => $path,
            'method' => $request->getMethod(),
            'route' => $request->attributes->get('_route'),
        ]);
    }
}
