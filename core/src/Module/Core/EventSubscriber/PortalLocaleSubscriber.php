<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Application\PortalLocale;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\User;
use App\Repository\InvoicePreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PortalLocaleSubscriber implements EventSubscriberInterface
{
    private const LOCALE_COOKIE = 'portal_language';
    private const LOCALE_SESSION_KEY = 'portal_language';

    public function __construct(
        private readonly InvoicePreferencesRepository $invoicePreferencesRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
            KernelEvents::RESPONSE => ['onKernelResponse', -5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale = $this->resolveLocale($request);

        $request->setLocale($locale);

        if ($request->hasSession()) {
            $request->getSession()->set(self::LOCALE_SESSION_KEY, $locale);
        }

        $this->persistLocalePreference($request, $locale);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale = PortalLocale::normalize($request->getLocale()) ?? PortalLocale::DEFAULT;
        $cookie = Cookie::create(self::LOCALE_COOKIE)
            ->withValue($locale)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX);

        $domain = $this->resolveCookieDomain($request->getHost());
        if ($domain !== null) {
            $cookie = $cookie->withDomain($domain);
        }

        $event->getResponse()->headers->setCookie($cookie);
    }

    private function resolveLocale(Request $request): string
    {
        $queryLocale = $request->query->get('lang');
        $normalizedQuery = PortalLocale::normalize($queryLocale);
        if ($normalizedQuery !== null) {
            return $normalizedQuery;
        }

        $actor = $request->attributes->get('current_user');
        if ($actor instanceof User) {
            $preferences = $this->invoicePreferencesRepository->findOneByCustomer($actor);
            $userLocale = PortalLocale::normalize($preferences?->getPortalLanguage());
            if ($userLocale !== null) {
                return $userLocale;
            }
        }

        $sessionLocale = $request->hasSession() ? $request->getSession()->get(self::LOCALE_SESSION_KEY) : null;
        $normalizedSession = PortalLocale::normalize($sessionLocale);
        if ($normalizedSession !== null) {
            return $normalizedSession;
        }

        $cookieLocale = PortalLocale::normalize($request->cookies->get(self::LOCALE_COOKIE));
        if ($cookieLocale !== null) {
            return $cookieLocale;
        }

        return PortalLocale::DEFAULT;
    }

    private function persistLocalePreference(Request $request, string $locale): void
    {
        $rawLocale = $request->query->get('lang');
        if (PortalLocale::normalize($rawLocale) === null) {
            return;
        }

        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return;
        }

        $preferences = $this->invoicePreferencesRepository->findOneByCustomer($actor);
        $hasChanges = false;

        if ($preferences === null) {
            $preferences = new InvoicePreferences(
                $actor,
                $locale === 'en' ? 'en_GB' : 'de_DE',
                true,
                true,
                'manual',
                $locale,
            );
            $this->entityManager->persist($preferences);
            $hasChanges = true;
        } elseif ($preferences->getPortalLanguage() !== $locale) {
            $preferences->setPortalLanguage($locale);
            $hasChanges = true;
        }

        if ($hasChanges) {
            $this->entityManager->flush();
        }
    }

    private function resolveCookieDomain(string $host): ?string
    {
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $host;
    }
}
