<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\DdosProviderCredentialRepository;
use App\Repository\FirewallStateRepository;
use App\Service\Ddos\DdosCredentialManager;
use App\Service\FirewallStateManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/security')]
final class AdminSecurityController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly FirewallStateRepository $firewallStateRepository,
        private readonly FirewallStateManager $firewallStateManager,
        private readonly DdosProviderCredentialRepository $credentialRepository,
        private readonly DdosCredentialManager $credentialManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_security', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        return $this->renderPage($admin, $request);
    }

    #[Route(path: '/ddos', name: 'admin_security_ddos', methods: ['POST'])]
    public function updateDdos(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $provider = trim((string) $request->request->get('provider', ''));
        $apiKey = trim((string) $request->request->get('api_key', ''));

        $errors = [];
        if ($provider === '') {
            $errors[] = 'Provider is required.';
        }

        if ($apiKey === '') {
            $errors[] = 'API key is required.';
        }

        if ($errors !== []) {
            return $this->renderPage($admin, $request, $errors, Response::HTTP_BAD_REQUEST);
        }

        $this->credentialManager->storeCredential($admin, $provider, $apiKey, $admin);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/security?ddos=updated');
    }

    #[Route(path: '/firewall/{id}', name: 'admin_security_firewall', methods: ['POST'])]
    public function updateFirewall(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);

        $agent = $this->agentRepository->find($id);
        if ($agent === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $desiredPorts = $this->parsePorts((string) $request->request->get('ports', ''));
        $currentState = $this->firewallStateRepository->findOneBy(['node' => $agent]);
        $currentPorts = $currentState?->getPorts() ?? [];

        $toOpen = array_values(array_diff($desiredPorts, $currentPorts));
        $toClose = array_values(array_diff($currentPorts, $desiredPorts));

        if ($toOpen !== []) {
            $this->firewallStateManager->applyOpenPorts($agent, $toOpen);
        }

        if ($toClose !== []) {
            $this->firewallStateManager->applyClosePorts($agent, $toClose);
        }

        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/security?firewall=%s', $agent->getId()));
    }

    private function renderPage(User $admin, Request $request, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $agents = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        $firewallNodes = array_map(function ($agent): array {
            $state = $this->firewallStateRepository->findOneBy(['node' => $agent]);
            $ports = $state?->getPorts() ?? [];
            sort($ports);

            return [
                'id' => $agent->getId(),
                'name' => $agent->getName() ?? 'Unnamed node',
                'updatedAt' => $agent->getUpdatedAt(),
                'lastHeartbeatAt' => $agent->getLastHeartbeatAt(),
                'ports' => $ports,
                'status' => $this->resolveAgentStatus($agent->getLastHeartbeatAt()),
            ];
        }, $agents);

        $credentials = $this->credentialRepository->findBy(['customer' => $admin], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/security/index.html.twig', [
            'activeNav' => 'security',
            'errors' => $errors,
            'firewallNodes' => $firewallNodes,
            'credentials' => $credentials,
            'ddosUpdated' => $request->query->get('ddos') === 'updated',
            'firewallUpdated' => $request->query->get('firewall'),
        ]), $status);
    }

    private function parsePorts(string $raw): array
    {
        $ports = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !ctype_digit($entry)) {
                continue;
            }

            $port = (int) $entry;
            if ($port <= 0 || $port > 65535) {
                continue;
            }

            $ports[] = $port;
        }

        $ports = array_values(array_unique($ports));
        sort($ports);
        return $ports;
    }

    private function resolveAgentStatus(?\DateTimeImmutable $lastHeartbeatAt): string
    {
        if ($lastHeartbeatAt === null) {
            return 'offline';
        }

        $now = new \DateTimeImmutable();
        if ($lastHeartbeatAt >= $now->sub(new \DateInterval('PT5M'))) {
            return 'online';
        }

        if ($lastHeartbeatAt >= $now->sub(new \DateInterval('PT30M'))) {
            return 'degraded';
        }

        return 'offline';
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }
}
