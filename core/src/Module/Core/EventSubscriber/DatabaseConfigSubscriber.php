<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Infrastructure\Config\DbConfigProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class DatabaseConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DbConfigProvider $configProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->configProvider->exists()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/install')
            || str_starts_with($path, '/system/health')
            || str_starts_with($path, '/system/recovery')
        ) {
            return;
        }

        $event->setResponse(new RedirectResponse('/install'));
    }
}
