<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Module\Core\Application\AgentReleaseChecker;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\CookieConsentService;
use App\Module\Core\Application\PortalLocale;
use App\Module\Core\Domain\Entity\User;
use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Repository\AgentRepository;
use App\Repository\NotificationRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PortalContextTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly NotificationRepository $notificationRepository,
        private readonly WebinterfaceUpdateService $webinterfaceUpdateService,
        private readonly AgentRepository $agentRepository,
        private readonly AgentReleaseChecker $agentReleaseChecker,
        private readonly AppSettingsService $appSettingsService,
        private readonly TranslatorInterface $translator,
        private readonly CookieConsentService $cookieConsentService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_user', [$this, 'currentUser']),
            new TwigFunction('admin_update_status', [$this, 'adminUpdateStatus']),
            new TwigFunction('page_locale', [$this, 'pageLocale']),
            new TwigFunction('unread_notifications', [$this, 'unreadNotifications']),
            new TwigFunction('t', [$this, 'translate']),
            new TwigFunction('app_setting', [$this, 'appSetting']),
            new TwigFunction('app_settings', [$this, 'appSettings']),
            new TwigFunction('cookie_consent', [$this, 'cookieConsent']),
            new TwigFunction('cookie_has_consent', [$this, 'cookieHasConsent']),
            new TwigFunction('cookie_consent_cookie_name', [$this, 'cookieConsentCookieName']),
            new TwigFunction('cookie_consent_version', [$this, 'cookieConsentVersion']),
        ];
    }

    public function currentUser(): ?User
    {
        $request = $this->requestStack->getCurrentRequest();
        $actor = $request?->attributes->get('current_user');

        return $actor instanceof User ? $actor : null;
    }

    public function pageLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $locale = $request?->getLocale();

        return PortalLocale::normalize($locale) ?? PortalLocale::DEFAULT;
    }

    public function unreadNotifications(): int
    {
        $request = $this->requestStack->getCurrentRequest();
        $actor = $request?->attributes->get('current_user');

        if (!$actor instanceof User) {
            return 0;
        }

        return $this->notificationRepository->findUnreadCount($actor);
    }

    /**
     * @return array{coreUpdateAvailable: bool, agentUpdates: int, hasUpdates: bool}|null
     */
    public function adminUpdateStatus(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        $actor = $request?->attributes->get('current_user');

        if (!$actor instanceof User || !$actor->isAdmin()) {
            return null;
        }

        $coreStatus = $this->webinterfaceUpdateService->checkForUpdate();
        $coreUpdateAvailable = $coreStatus->updateAvailable === true;

        $latestVersion = $this->agentReleaseChecker->getLatestVersion();
        $agentUpdates = 0;
        if ($latestVersion !== null && $latestVersion !== '') {
            $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
            foreach ($agents as $agent) {
                if ($this->agentReleaseChecker->isUpdateAvailable($agent->getLastHeartbeatVersion(), $latestVersion)) {
                    $agentUpdates++;
                }
            }
        }

        return [
            'coreUpdateAvailable' => $coreUpdateAvailable,
            'agentUpdates' => $agentUpdates,
            'hasUpdates' => $coreUpdateAvailable || $agentUpdates > 0,
        ];
    }

    public function translate(string $key, ?string $locale = null): string
    {
        $resolvedLocale = $locale ?? $this->pageLocale();

        return $this->translator->trans($key, [], 'portal', $resolvedLocale);
    }


    /** @return array{version:int, necessary:bool, statistics:bool, marketing:bool}|null */
    public function cookieConsent(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        return $this->cookieConsentService->readFromRequest($request);
    }

    public function cookieHasConsent(string $category): bool
    {
        if ($category === 'necessary') {
            return true;
        }

        $consent = $this->cookieConsent();
        if ($consent === null) {
            return false;
        }

        return match ($category) {
            'statistics' => $consent['statistics'],
            'marketing' => $consent['marketing'],
            default => false,
        };
    }

    public function cookieConsentCookieName(): string
    {
        return CookieConsentService::COOKIE_NAME;
    }

    public function cookieConsentVersion(): int
    {
        return CookieConsentService::VERSION;
    }

    public function appSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->appSettingsService->getSettings();

        return $settings[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function appSettings(): array
    {
        return $this->appSettingsService->getSettings();
    }
}
