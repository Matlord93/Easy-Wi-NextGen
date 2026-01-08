<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PortalLocaleSubscriber implements EventSubscriberInterface
{
    private const LOCALE_COOKIE = 'portal_language';
    private const SUPPORTED_LOCALES = ['de', 'en'];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $rawLocale = $request->query->get('lang') ?? $request->query->get('locale');

        if (!is_string($rawLocale)) {
            return;
        }

        $locale = strtolower(trim($rawLocale));

        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            return;
        }

        $cookie = Cookie::create(self::LOCALE_COOKIE)
            ->withValue($locale)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite('lax');

        $event->getResponse()->headers->setCookie($cookie);
    }
}
