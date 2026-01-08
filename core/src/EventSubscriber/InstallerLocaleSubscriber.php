<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Installer\InstallerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class InstallerLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly InstallerService $installerService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/install')) {
            return;
        }

        $this->installerService->resolveInstallerLocale($request);
    }
}
