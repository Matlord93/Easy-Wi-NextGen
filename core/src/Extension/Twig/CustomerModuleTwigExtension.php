<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Module\Core\Domain\Entity\User;
use App\Repository\DatabaseRepository;
use App\Repository\DomainRepository;
use App\Repository\InstanceRepository;
use App\Repository\MailboxRepository;
use App\Repository\ShopRentalRepository;
use App\Repository\SinusbotInstanceRepository;
use App\Repository\SinusbotNodeRepository;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\VoiceInstanceRepository;
use App\Repository\WebspaceRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CustomerModuleTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly InstanceRepository $instanceRepository,
        private readonly Ts3VirtualServerRepository $ts3VirtualServerRepository,
        private readonly Ts6VirtualServerRepository $ts6VirtualServerRepository,
        private readonly VoiceInstanceRepository $voiceInstanceRepository,
        private readonly SinusbotInstanceRepository $sinusbotInstanceRepository,
        private readonly SinusbotNodeRepository $sinusbotNodeRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DatabaseRepository $databaseRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly ShopRentalRepository $shopRentalRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('customer_has_module', [$this, 'customerHasModule']),
        ];
    }

    public function customerHasModule(string $moduleKey): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $actor = $request?->attributes->get('current_user');

        if (!$actor instanceof User || !$actor->isCustomer()) {
            return false;
        }

        $customerId = $actor->getId();
        if ($customerId === null) {
            return false;
        }

        return match (strtolower($moduleKey)) {
            'game' => $this->instanceRepository->count(['customer' => $actor]) > 0,
            'voice' => $this->voiceInstanceRepository->count(['customer' => $actor]) > 0
                || $this->ts3VirtualServerRepository->count(['customerId' => $customerId, 'archivedAt' => null]) > 0
                || $this->ts6VirtualServerRepository->count(['customerId' => $customerId, 'archivedAt' => null]) > 0,
            'sinusbot' => $this->sinusbotInstanceRepository->count(['customer' => $actor, 'archivedAt' => null]) > 0
                || $this->sinusbotNodeRepository->count(['customer' => $actor, 'instanceMode' => 'solo']) > 0,
            'web' => $this->webspaceRepository->count(['customer' => $actor]) > 0,
            'database' => $this->databaseRepository->count(['customer' => $actor]) > 0,
            'domain' => $this->domainRepository->count(['customer' => $actor]) > 0,
            'mail' => $this->mailboxRepository->count(['customer' => $actor]) > 0,
            'shop' => $this->shopRentalRepository->count(['customer' => $actor]) > 0,
            default => false,
        };
    }
}
