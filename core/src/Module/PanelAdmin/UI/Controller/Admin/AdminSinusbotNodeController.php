<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Dto\Sinusbot\SinusbotNodeDto;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Form\SinusbotNodeType;
use App\Repository\AgentRepository;
use App\Repository\AgentJobRepository;
use App\Repository\SinusbotInstanceRepository;
use App\Repository\SinusbotNodeRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\AgentConfigurationException;
use App\Module\Core\Application\Sinusbot\AgentBadResponseException;
use App\Module\Core\Application\Sinusbot\AgentUnavailableException;
use App\Module\Core\Application\Sinusbot\SinusbotQuotaValidator;
use App\Module\Core\Application\Sinusbot\SinusbotNodeService;
use App\Module\Core\Application\Sinusbot\SinusbotInstanceProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/admin/sinusbot/nodes')]
final class AdminSinusbotNodeController
{
    public function __construct(
        private readonly SinusbotNodeRepository $nodeRepository,
        private readonly SinusbotInstanceRepository $instanceRepository,
        private readonly AgentRepository $agentRepository,
        private readonly AgentJobRepository $agentJobRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
        private readonly SinusbotNodeService $nodeService,
        private readonly SinusbotQuotaValidator $quotaValidator,
        private readonly SinusbotInstanceProvisioner $instanceProvisioner,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_sinusbot_nodes_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);

        $nodes = $this->nodeRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/sinusbot/nodes/index.html.twig', [
            'activeNav' => 'sinusbot',
            'nodes' => $nodes,
        ]));
    }

    #[Route(path: '/new', name: 'admin_sinusbot_nodes_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin($request);

        $dto = new SinusbotNodeDto();
        $form = $this->formFactory->create(SinusbotNodeType::class, $dto, [
            'agent_choices' => $this->buildAgentChoices(),
            'customer_choices' => $this->buildCustomerChoices(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyAgentDefaults($dto, $form);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $agent = $this->agentRepository->find($dto->agentNodeId);
            if ($agent === null) {
                $form->addError(new FormError('Selected agent was not found.'));
                return new Response($this->twig->render('admin/sinusbot/nodes/new.html.twig', [
                    'activeNav' => 'sinusbot',
                    'form' => $form->createView(),
                ]), Response::HTTP_BAD_REQUEST);
            }

            $customer = null;
            if ($dto->customerId !== null) {
                $customer = $this->userRepository->find($dto->customerId);
                if ($customer === null || $customer->getType() !== UserType::Customer) {
                    $form->addError(new FormError('Selected customer was not found.'));
                    return new Response($this->twig->render('admin/sinusbot/nodes/new.html.twig', [
                        'activeNav' => 'sinusbot',
                        'form' => $form->createView(),
                    ]), Response::HTTP_BAD_REQUEST);
                }
            }

            $node = new SinusbotNode(
                $dto->name,
                $agent,
                rtrim($dto->agentBaseUrl, '/'),
                $this->crypto->encrypt($dto->agentApiToken),
                $dto->downloadUrl,
                $dto->installPath,
                $dto->instanceRoot,
            );
            $node->setWebBindIp($dto->webBindIp);
            $node->setWebPortBase($dto->webPortBase);
            $node->setCustomer($customer);

            $this->entityManager->persist($node);
            $this->entityManager->flush();

            $this->nodeService->install($node);
            $request->getSession()->getFlashBag()->add('success', 'SinusBot-Node erstellt. Installationsauftrag eingereiht. (SinusBot node created. Install job queued.)');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => sprintf('/admin/sinusbot/nodes/%d', $node->getId()),
            ]);
        }

        return new Response($this->twig->render('admin/sinusbot/nodes/new.html.twig', [
            'activeNav' => 'sinusbot',
            'form' => $form->createView(),
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_sinusbot_nodes_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->requireAdmin($request);

        $node = $this->findNode($id);
        $instances = $this->instanceRepository->findBy(
            ['node' => $node, 'archivedAt' => null],
            ['updatedAt' => 'DESC'],
        );
        $syncError = null;
        foreach ($instances as $instance) {
            try {
                $this->instanceProvisioner->syncStatus($instance);
            } catch (AgentConfigurationException | AgentBadResponseException | AgentUnavailableException $exception) {
                $syncError = $exception->getMessage();
                break;
            }
        }
        if ($syncError !== null) {
            $request->getSession()->getFlashBag()->add('error', $syncError);
        }

        $instancePasswords = [];
        foreach ($instances as $instance) {
            $password = $instance->getSinusbotPassword($this->crypto);
            if ($password !== null) {
                $instancePasswords[$instance->getId() ?? 0] = $password;
            }
        }

        return new Response($this->twig->render('admin/sinusbot/nodes/show.html.twig', [
            'activeNav' => 'sinusbot',
            'node' => $node,
            'agent_base_url' => $node->getAgent()->getServiceBaseUrl(),
            'agent_id' => $node->getAgent()->getId(),
            'admin_password' => null,
            'management_url' => $this->buildManagementUrl($node),
            'agent_jobs' => $this->loadAgentJobs($node),
            'csrf' => $this->csrfTokens($node),
            'instances' => $instances,
            'instance_csrf' => $this->instanceCsrfTokens($instances),
            'instance_passwords' => $instancePasswords,
            'customers' => $this->userRepository->findCustomers(),
            'quota_min' => $this->quotaValidator->getMinQuota(),
            'quota_max' => $this->quotaValidator->getMaxQuota(),
        ]));
    }

    #[Route(path: '/{id}/install', name: 'admin_sinusbot_nodes_install', methods: ['POST'])]
    public function install(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'sinusbot_install_' . $id);

        $this->nodeService->install($node);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Installation eingereiht. (SinusBot install queued.)');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/refresh', name: 'admin_sinusbot_nodes_refresh', methods: ['POST'])]
    public function refresh(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'sinusbot_refresh_' . $id);

        $this->nodeService->refreshStatus($node);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Status aktualisiert. (SinusBot status refreshed.)');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/start', name: 'admin_sinusbot_nodes_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'sinusbot_start_' . $id);

        $this->nodeService->start($node);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Service gestartet. (SinusBot service started.)');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/stop', name: 'admin_sinusbot_nodes_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'sinusbot_stop_' . $id);

        $this->nodeService->stop($node);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Service gestoppt. (SinusBot service stopped.)');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/restart', name: 'admin_sinusbot_nodes_restart', methods: ['POST'])]
    public function restart(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'sinusbot_restart_' . $id);

        $this->nodeService->restart($node);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Service neu gestartet. (SinusBot service restarted.)');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/delete', name: 'admin_sinusbot_nodes_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'sinusbot_delete_' . $id);

        $this->entityManager->remove($node);
        $this->entityManager->flush();

        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Node gelöscht. (SinusBot node deleted.)');

        return new Response('', Response::HTTP_FOUND, [
            'Location' => '/admin/sinusbot/nodes',
        ]);
    }

    #[Route(path: '/{id}/reveal-credentials', name: 'admin_sinusbot_nodes_reveal_credentials', methods: ['POST'])]
    public function revealCredentials(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'sinusbot_reveal_credentials_' . $id);

        $adminPassword = $node->getAdminPassword($this->crypto);
        $instances = $this->instanceRepository->findBy(
            ['node' => $node, 'archivedAt' => null],
            ['updatedAt' => 'DESC'],
        );
        $syncError = null;
        foreach ($instances as $instance) {
            try {
                $this->instanceProvisioner->syncStatus($instance);
            } catch (AgentConfigurationException | AgentBadResponseException | AgentUnavailableException $exception) {
                $syncError = $exception->getMessage();
                break;
            }
        }
        if ($syncError !== null) {
            $request->getSession()->getFlashBag()->add('error', $syncError);
        }

        $instancePasswords = [];
        foreach ($instances as $instance) {
            $password = $instance->getSinusbotPassword($this->crypto);
            if ($password !== null) {
                $instancePasswords[$instance->getId() ?? 0] = $password;
            }
        }

        return new Response($this->twig->render('admin/sinusbot/nodes/show.html.twig', [
            'activeNav' => 'sinusbot',
            'node' => $node,
            'agent_base_url' => $node->getAgent()->getServiceBaseUrl(),
            'agent_id' => $node->getAgent()->getId(),
            'admin_password' => $adminPassword,
            'management_url' => $this->buildManagementUrl($node),
            'agent_jobs' => $this->loadAgentJobs($node),
            'csrf' => $this->csrfTokens($node),
            'instances' => $instances,
            'instance_csrf' => $this->instanceCsrfTokens($instances),
            'instance_passwords' => $instancePasswords,
            'customers' => $this->userRepository->findCustomers(),
            'quota_min' => $this->quotaValidator->getMinQuota(),
            'quota_max' => $this->quotaValidator->getMaxQuota(),
        ]));
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findNode(int $id): SinusbotNode
    {
        $node = $this->nodeRepository->find($id);
        if ($node === null) {
            throw new NotFoundHttpException('SinusBot node not found.');
        }

        return $node;
    }

    /**
     * @return array<string, string>
     */
    private function csrfTokens(SinusbotNode $node): array
    {
        $id = (string) $node->getId();

        return [
            'install' => $this->csrfTokenManager->getToken('sinusbot_install_' . $id)->getValue(),
            'refresh' => $this->csrfTokenManager->getToken('sinusbot_refresh_' . $id)->getValue(),
            'start' => $this->csrfTokenManager->getToken('sinusbot_start_' . $id)->getValue(),
            'stop' => $this->csrfTokenManager->getToken('sinusbot_stop_' . $id)->getValue(),
            'restart' => $this->csrfTokenManager->getToken('sinusbot_restart_' . $id)->getValue(),
            'delete' => $this->csrfTokenManager->getToken('sinusbot_delete_' . $id)->getValue(),
            'reveal' => $this->csrfTokenManager->getToken('sinusbot_reveal_credentials_' . $id)->getValue(),
            'instance_create' => $this->csrfTokenManager->getToken('sinusbot_instance_create')->getValue(),
        ];
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }
    }

    private function redirectToNode(SinusbotNode $node): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => sprintf('/admin/sinusbot/nodes/%d', $node->getId()),
        ]);
    }

    /**
     * @return array<\App\Module\AgentOrchestrator\Domain\Entity\AgentJob>
     */
    private function loadAgentJobs(SinusbotNode $node): array
    {
        return $this->agentJobRepository->findLatestForNodeAndTypes($node->getAgent()->getId(), [
            'sinusbot.install',
            'sinusbot.status',
        ], 5);
    }

    /**
     * @return array<string, string>
     */
    private function buildAgentChoices(): array
    {
        $choices = [];
        $agents = $this->agentRepository->findBy([], ['name' => 'ASC']);

        foreach ($agents as $agent) {
            $label = $agent->getName() !== null && $agent->getName() !== ''
                ? sprintf('%s (%s)', $agent->getName(), $agent->getId())
                : $agent->getId();
            $choices[$label] = $agent->getId();
        }

        return $choices;
    }

    private function applyAgentDefaults(SinusbotNodeDto $dto, \Symfony\Component\Form\FormInterface $form): void
    {
        $agentId = trim($dto->agentNodeId);
        if ($agentId === '') {
            $form->addError(new FormError('Agent Node is required.'));
            return;
        }

        $agent = $this->agentRepository->find($agentId);
        if ($agent === null) {
            $form->addError(new FormError('Selected agent was not found.'));

            return;
        }

        $dto->agentBaseUrl = '';
        $dto->agentApiToken = '';

        if (trim($dto->installPath) === '') {
            $dto->installPath = '/opt/sinusbot';
        }
        if (trim($dto->instanceRoot) === '') {
            $dto->instanceRoot = '/opt/sinusbot/instances';
        }

        if (trim($dto->installPath) === '') {
            $form->addError(new FormError('Install path is required.'));
        }
        if (!$this->isValidInstallPath($dto->installPath)) {
            $form->addError(new FormError('Installationspfad muss absolut sein und darf kein "..", "~" oder Nullbytes enthalten.'));
        }
    }

    private function buildManagementUrl(SinusbotNode $node): ?string
    {
        $host = trim($node->getWebBindIp());
        $scheme = 'http';

        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            $host = trim((string) $node->getAgent()->getLastHeartbeatIp());
        }

        $agentBaseUrl = $node->getAgent()->getServiceBaseUrl();
        if (($host === '' || $host === '0.0.0.0' || $host === '::') && $agentBaseUrl !== '') {
            $parts = parse_url($agentBaseUrl);
            if (is_array($parts)) {
                $host = $parts['host'] ?? $host;
                $scheme = $parts['scheme'] ?? $scheme;
            }
        }

        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            return null;
        }

        return sprintf('%s://%s:%d', $scheme, $host, $node->getWebPortBase());
    }

    private function isValidInstallPath(string $installPath): bool
    {
        $trimmed = trim($installPath);
        if ($trimmed === '') {
            return false;
        }
        if (!str_starts_with($trimmed, '/')) {
            return false;
        }
        if (str_contains($trimmed, '..')) {
            return false;
        }
        if (str_contains($trimmed, "\0")) {
            return false;
        }
        if (str_contains($trimmed, '~')) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, \App\Module\Core\Domain\Entity\SinusbotInstance> $instances
     * @return array<int, array<string, string>>
     */
    private function instanceCsrfTokens(array $instances): array
    {
        $tokens = [];
        foreach ($instances as $instance) {
            $id = (string) $instance->getId();
            $tokens[(int) $instance->getId()] = [
                'start' => $this->csrfTokenManager->getToken('sinusbot_instance_start_' . $id)->getValue(),
                'stop' => $this->csrfTokenManager->getToken('sinusbot_instance_stop_' . $id)->getValue(),
                'restart' => $this->csrfTokenManager->getToken('sinusbot_instance_restart_' . $id)->getValue(),
                'delete' => $this->csrfTokenManager->getToken('sinusbot_instance_delete_' . $id)->getValue(),
                'reset' => $this->csrfTokenManager->getToken('sinusbot_instance_reset_password_' . $id)->getValue(),
                'quota' => $this->csrfTokenManager->getToken('sinusbot_instance_quota_' . $id)->getValue(),
            ];
        }

        return $tokens;
    }

    /**
     * @return array<string, int>
     */
    private function buildCustomerChoices(): array
    {
        $choices = [];
        $customers = $this->userRepository->findCustomers();

        foreach ($customers as $customer) {
            $label = sprintf('%s (%d)', $customer->getEmail(), $customer->getId());
            $choices[$label] = $customer->getId();
        }

        return $choices;
    }
}
