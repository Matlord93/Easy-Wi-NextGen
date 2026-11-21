<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Domain\Entity\User;
use App\Repository\DatabaseRepositoryInterface;
use App\Repository\DomainRepositoryInterface;
use App\Repository\InstanceRepositoryInterface;
use App\Repository\MailboxRepositoryInterface;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\ShopRentalRepository;
use App\Repository\SinusbotInstanceRepositoryInterface;
use App\Repository\SinusbotNodeRepositoryInterface;
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
        private readonly \App\Module\Core\Application\ModuleRegistry $modules,
        private readonly InstanceRepositoryInterface $instanceRepository,
        private readonly Ts3VirtualServerRepository $ts3VirtualServerRepository,
        private readonly Ts6VirtualServerRepository $ts6VirtualServerRepository,
        private readonly VoiceInstanceRepository $voiceInstanceRepository,
        private readonly SinusbotInstanceRepositoryInterface $sinusbotInstanceRepository,
        private readonly SinusbotNodeRepositoryInterface $sinusbotNodeRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DatabaseRepositoryInterface $databaseRepository,
        private readonly DomainRepositoryInterface $domainRepository,
        private readonly MailboxRepositoryInterface $mailboxRepository,
        private readonly MusicbotInstanceRepository $musicbotInstanceRepository,
        private readonly ShopRentalRepository $shopRentalRepository,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('customer_has_module', [$this, 'customerHasModule']),
        ];
    }

    public function customerHasModule(string $moduleKey): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $actor = RequestActor::user($request);

        if (!$actor instanceof User || !$actor->isCustomer()) {
            return false;
        }

        $customerId = $actor->getId();
        if ($customerId === null) {
            return false;
        }

        return match (strtolower($moduleKey)) {
            'kvm' => $this->modules->isEnabled('kvm') && $this->em->getRepository(\App\Module\Nodes\Domain\Entity\KvmGuestAssignment::class)->count(['customer' => $actor]) > 0,
            'proxmox' => $this->modules->isEnabled('proxmox') && $this->em->getRepository(\App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment::class)->count(['customer' => $actor]) > 0,
            'game' => $this->instanceRepository->count(['customer' => $actor]) > 0,
            'voice' => $this->voiceInstanceRepository->count(['customer' => $actor]) > 0
                || $this->ts3VirtualServerRepository->count(['customerId' => $customerId, 'archivedAt' => null]) > 0
                || $this->ts6VirtualServerRepository->count(['customerId' => $customerId, 'archivedAt' => null]) > 0,
            'sinusbot' => $this->sinusbotInstanceRepository->count(['customer' => $actor, 'archivedAt' => null]) > 0
                || $this->sinusbotNodeRepository->count(['customer' => $actor, 'instanceMode' => 'solo']) > 0,
            'musicbot' => $this->musicbotInstanceRepository->count(['customer' => $actor]) > 0,
            'web' => $this->webspaceRepository->count(['customer' => $actor]) > 0,
            'database' => $this->databaseRepository->count(['customer' => $actor]) > 0
                || $this->em->getRepository(\App\Module\Core\Domain\Entity\DatabaseNode::class)->count(['isActive' => true]) > 0,
            'domain' => $this->domainRepository->count(['customer' => $actor]) > 0,
            'mail' => $this->mailboxRepository->count(['customer' => $actor]) > 0
                || $this->domainRepository->count(['customer' => $actor]) > 0,
            'shop' => $this->shopRentalRepository->count(['customer' => $actor]) > 0,
            default => false,
        };
    }
}
