<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Entity\User;
use App\Enum\UserType;
use App\Repository\InvoicePreferencesRepository;
use App\Repository\NotificationRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PortalContextTwigExtension extends AbstractExtension
{
    private const TRANSLATIONS = [
        'en' => [
            'global_search_placeholder' => 'Search customers, instances, domains, tickets',
            'notifications' => 'Notifications',
            'activity' => 'Activity',
            'toggle_theme' => 'Toggle theme',
            'search_help' => 'Press / to search',
            'no_results' => 'No results yet.',
            'search_title' => 'Global search',
            'mark_read' => 'Mark as read',
            'unread' => 'Unread',
            'read' => 'Read',
            'activity_title' => 'Activity feed',
        ],
        'de' => [
            'global_search_placeholder' => 'Kunden, Instanzen, Domains, Tickets suchen',
            'notifications' => 'Benachrichtigungen',
            'activity' => 'Aktivität',
            'toggle_theme' => 'Design umschalten',
            'search_help' => 'Mit / suchen',
            'no_results' => 'Noch keine Ergebnisse.',
            'search_title' => 'Globale Suche',
            'mark_read' => 'Als gelesen markieren',
            'unread' => 'Ungelesen',
            'read' => 'Gelesen',
            'activity_title' => 'Aktivitätsfeed',
        ],
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly NotificationRepository $notificationRepository,
        private readonly InvoicePreferencesRepository $invoicePreferencesRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('page_locale', [$this, 'pageLocale']),
            new TwigFunction('unread_notifications', [$this, 'unreadNotifications']),
            new TwigFunction('t', [$this, 'translate']),
        ];
    }

    public function pageLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $actor = $request?->attributes->get('current_user');

        if (!$actor instanceof User) {
            return 'en';
        }

        if ($actor->getType() !== UserType::Customer) {
            return 'en';
        }

        $preferences = $this->invoicePreferencesRepository->findOneByCustomer($actor);
        return $preferences?->getPortalLanguage() ?? 'de';
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

    public function translate(string $key, ?string $locale = null): string
    {
        $resolvedLocale = $locale ?? $this->pageLocale();
        $dictionary = self::TRANSLATIONS[$resolvedLocale] ?? self::TRANSLATIONS['en'];

        return $dictionary[$key] ?? $key;
    }
}
