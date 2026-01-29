<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Sinusbot\SinusbotNodeService;
use App\Repository\SinusbotInstanceRepository;
use App\Repository\SinusbotNodeRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/customer/infrastructure/sinusbot')]
final class CustomerSinusbotController
{
    public function __construct(
        private readonly SinusbotInstanceRepository $instanceRepository,
        private readonly SinusbotNodeRepository $nodeRepository,
        private readonly SecretsCrypto $crypto,
        private readonly SinusbotNodeService $nodeService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_sinusbot_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->loadInstances($customer);
        $nodes = $this->loadNodes($customer);

        return new Response($this->twig->render('customer/infrastructure/sinusbot/index.html.twig', [
            'activeNav' => 'sinusbot',
            'instances' => $instances,
            'nodes' => $nodes,
            'management_urls' => $this->buildManagementUrls($nodes),
            'admin_passwords' => [],
            'csrf' => $this->csrfTokens($nodes),
        ]));
    }

    #[Route(path: '/nodes/{id}/start', name: 'customer_sinusbot_nodes_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $node = $this->findNodeForCustomer($customer, $id);
        $this->validateCsrf($request, 'sinusbot_start_' . $id);

        $this->nodeService->start($node);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Service gestartet.');

        return $this->redirectToIndex();
    }

    #[Route(path: '/nodes/{id}/stop', name: 'customer_sinusbot_nodes_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $node = $this->findNodeForCustomer($customer, $id);
        $this->validateCsrf($request, 'sinusbot_stop_' . $id);

        $this->nodeService->stop($node);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Service gestoppt.');

        return $this->redirectToIndex();
    }

    #[Route(path: '/nodes/{id}/reveal-credentials', name: 'customer_sinusbot_nodes_reveal_credentials', methods: ['POST'])]
    public function revealCredentials(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $node = $this->findNodeForCustomer($customer, $id);
        $this->validateCsrf($request, 'sinusbot_reveal_credentials_' . $id);

        $adminPassword = $node->getAdminPassword($this->crypto);
        $nodes = $this->loadNodes($customer);

        return new Response($this->twig->render('customer/infrastructure/sinusbot/index.html.twig', [
            'activeNav' => 'sinusbot',
            'instances' => $this->loadInstances($customer),
            'nodes' => $nodes,
            'management_urls' => $this->buildManagementUrls($nodes),
            'admin_passwords' => [$id => $adminPassword],
            'csrf' => $this->csrfTokens($nodes),
        ]));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @return array<int, SinusbotNode>
     */
    private function loadNodes(User $customer): array
    {
        return $this->nodeRepository->findBy(
            ['customer' => $customer],
            ['updatedAt' => 'DESC'],
        );
    }

    /**
     * @return array<int, \App\Module\Core\Domain\Entity\SinusbotInstance>
     */
    private function loadInstances(User $customer): array
    {
        return $this->instanceRepository->findBy(
            ['customerId' => $customer->getId(), 'archivedAt' => null],
            ['updatedAt' => 'DESC'],
        );
    }

    private function findNodeForCustomer(User $customer, int $id): SinusbotNode
    {
        $node = $this->nodeRepository->find($id);
        if ($node === null || $node->getCustomer()?->getId() !== $customer->getId()) {
            throw new NotFoundHttpException('SinusBot node not found.');
        }

        return $node;
    }

    private function redirectToIndex(): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => '/customer/infrastructure/sinusbot',
        ]);
    }

    /**
     * @param array<int, SinusbotNode> $nodes
     * @return array<string, ?string>
     */
    private function buildManagementUrls(array $nodes): array
    {
        $urls = [];

        foreach ($nodes as $node) {
            $id = (string) $node->getId();
            $urls[$id] = $this->buildManagementUrl($node);
        }

        return $urls;
    }

    /**
     * @param array<int, SinusbotNode> $nodes
     * @return array<string, array<string, string>>
     */
    private function csrfTokens(array $nodes): array
    {
        $tokens = [];

        foreach ($nodes as $node) {
            $id = (string) $node->getId();
            $tokens[$id] = [
                'start' => $this->csrfTokenManager->getToken('sinusbot_start_' . $id)->getValue(),
                'stop' => $this->csrfTokenManager->getToken('sinusbot_stop_' . $id)->getValue(),
                'reveal' => $this->csrfTokenManager->getToken('sinusbot_reveal_credentials_' . $id)->getValue(),
            ];
        }

        return $tokens;
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }
    }

    private function buildManagementUrl(SinusbotNode $node): ?string
    {
        $host = trim($node->getWebBindIp());
        $scheme = 'http';

        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            $host = trim((string) $node->getAgent()->getLastHeartbeatIp());
        }

        $agentBaseUrl = $node->getAgentBaseUrl();
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
}
