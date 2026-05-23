<?php

declare(strict_types=1);

namespace App\Module\Voice\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\VoiceInstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route('/customer/voice')]
final class CustomerVoiceController
{
    public function __construct(
        private readonly VoiceInstanceRepository $repository,
        private readonly Ts3VirtualServerRepository $ts3Servers,
        private readonly Ts6VirtualServerRepository $ts6Servers,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'customer_voice', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return new Response($this->translator->trans('error_security_unauthorized'), 401);
        }

        $instances = $this->repository->findByCustomer($actor, 200);

        return new Response($this->twig->render('customer/voice/index.html.twig', [
            'activeNav' => 'voiceservers',
            'instances' => $instances,
        ]));
    }

    #[Route('/legacy/{type}/{id}', name: 'customer_voice_legacy_show', methods: ['GET'],
        requirements: ['type' => 'ts3|ts6', 'id' => '\d+'])]
    public function legacyShow(Request $request, string $type, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return new Response($this->translator->trans('error_security_unauthorized'), 401);
        }

        $customerId = $actor->getId();
        if (!is_int($customerId)) {
            return new Response($this->translator->trans('error_security_unauthorized'), 401);
        }

        if ($type === 'ts3') {
            $server = $this->ts3Servers->find($id);
        } else {
            $server = $this->ts6Servers->find($id);
        }

        if ($server === null || $server->getCustomerId() !== $customerId) {
            return new Response($this->translator->trans('error_not_found'), 404);
        }

        $apiBase = '/api/v1/customer/voice/legacy/' . $type . '/' . $id;

        return new Response($this->twig->render('customer/voice/show.html.twig', [
            'activeNav' => 'voiceservers',
            'instance' => ['name' => $server->getName(), 'node' => ['providerType' => $type]],
            'urlDetail' => $apiBase . '/detail',
            'urlProbe' => $apiBase . '/probe',
            'urlActions' => $apiBase . '/actions',
            'urlTokens' => $apiBase . '/tokens',
            'urlTokensRotate' => $apiBase . '/tokens/rotate',
            'urlSummary' => $apiBase . '/summary',
            'urlGroups' => $apiBase . '/groups',
            'urlQueryBase' => $apiBase . '/query',
            'urlSettings' => $apiBase . '/settings',
            'urlViewer' => $apiBase . '/viewer',
            'urlRecreate' => $apiBase . '/recreate',
            'urlSnapshot' => $apiBase . '/snapshot',
            'urlSnapshotPoll' => $apiBase . '/snapshot/poll',
            'urlSnapshotRestore' => $apiBase . '/snapshot/restore',
            'urlClientsBase' => $apiBase . '/clients',
            'urlBans' => $apiBase . '/bans',
            'urlList' => $this->urlGenerator->generate('customer_voice'),
        ]));
    }

    #[Route('/{id}', name: 'customer_voice_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return new Response($this->translator->trans('error_security_unauthorized'), 401);
        }

        $instance = $this->repository->find($id);
        if ($instance === null || $instance->getCustomer()->getId() !== $actor->getId()) {
            return new Response($this->translator->trans('error_not_found'), 404);
        }

        $apiBase = '/api/v1/customer/voice/' . $id;

        return new Response($this->twig->render('customer/voice/show.html.twig', [
            'activeNav' => 'voiceservers',
            'instance' => $instance,
            'urlDetail' => $apiBase . '/detail',
            'urlProbe' => $apiBase . '/probe',
            'urlActions' => $apiBase . '/actions',
            'urlTokens' => $apiBase . '/tokens',
            'urlTokensRotate' => $apiBase . '/tokens/rotate',
            'urlSummary' => $apiBase . '/summary',
            'urlGroups' => $apiBase . '/groups',
            'urlQueryBase' => $apiBase . '/query',
            'urlSettings' => $apiBase . '/settings',
            'urlViewer' => $apiBase . '/viewer',
            'urlRecreate' => $apiBase . '/recreate',
            'urlSnapshot' => $apiBase . '/snapshot',
            'urlSnapshotPoll' => $apiBase . '/snapshot/poll',
            'urlSnapshotRestore' => $apiBase . '/snapshot/restore',
            'urlClientsBase' => $apiBase . '/clients',
            'urlBans' => $apiBase . '/bans',
            'urlList' => $this->urlGenerator->generate('customer_voice'),
        ]));
    }
}
