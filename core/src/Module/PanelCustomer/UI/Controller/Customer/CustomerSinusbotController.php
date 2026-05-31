<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Sinusbot\SinusbotInstanceProvisioner;
use App\Module\Core\Domain\Entity\SinusbotInstance;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\SinusbotInstanceRepository;
use App\Repository\SinusbotNodeRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use App\Module\Core\Attribute\RequiresModule;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/customer/infrastructure/sinusbot')]
/**
 * @deprecated since 2026-02. Unified customer voice SoT is /customer/voice.
 *             Keep legacy SinusBot UI reachable during migration horizon.
 */
#[RequiresModule('sinusbot')]
final class CustomerSinusbotController
{
    public function __construct(
        private readonly SinusbotInstanceRepository $instanceRepository,
        private readonly SinusbotNodeRepository $nodeRepository,
        private readonly SinusbotInstanceProvisioner $provisioner,
        private readonly SecretsCrypto $crypto,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'customer_sinusbot_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->loadInstances($customer);
        $soloNodes = $this->nodeRepository->findSoloByCustomer($customer);
        $soloNodeUrls = [];
        foreach ($soloNodes as $node) {
            $soloNodeUrls[$node->getId() ?? 0] = $this->buildNodeManageUrl($node);
        }

        return $this->renderInstances($instances, [], $soloNodes, $soloNodeUrls);
    }

    #[Route(path: '/instances/{id}/start', name: 'customer_sinusbot_instances_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($customer, $id);
        $this->validateCsrf($request, 'sinusbot_start_' . $id);

        $this->provisioner->startInstance($instance);
        $request->getSession()->getFlashBag()->add('success', $this->translator->trans('customer_sinusbot_instance_started'));

        return $this->redirectToIndex();
    }

    #[Route(path: '/instances/{id}/stop', name: 'customer_sinusbot_instances_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($customer, $id);
        $this->validateCsrf($request, 'sinusbot_stop_' . $id);

        $this->provisioner->stopInstance($instance);
        $request->getSession()->getFlashBag()->add('success', $this->translator->trans('customer_sinusbot_instance_stopped'));

        return $this->redirectToIndex();
    }

    #[Route(path: '/instances/{id}/reset-password', name: 'customer_sinusbot_instances_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($customer, $id);
        $this->validateCsrf($request, 'sinusbot_reset_password_' . $id);

        $password = $this->provisioner->resetPassword($instance);
        $request->getSession()->getFlashBag()->add('success', $this->translator->trans('customer_sinusbot_instance_password_reset'));

        return $this->renderWithPassword($instance, $password);
    }

    #[Route(path: '/instances/{id}/reveal-credentials', name: 'customer_sinusbot_instances_reveal_credentials', methods: ['POST'])]
    public function revealCredentials(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstanceForCustomer($customer, $id);
        $this->validateCsrf($request, 'sinusbot_reveal_credentials_' . $id);

        $password = $instance->getSinusbotPassword($this->crypto);

        return $this->renderWithPassword($instance, $password);
    }

    private function renderWithPassword(SinusbotInstance $instance, ?string $password): Response
    {
        $customer = $instance->getCustomer();
        $instances = $customer !== null ? $this->loadInstances($customer) : [$instance];
        $passwords = [];
        if ($instance->getId() !== null) {
            $passwords[$instance->getId()] = $password;
        }
        $soloNodes = $customer !== null ? $this->nodeRepository->findSoloByCustomer($customer) : [];
        $soloNodeUrls = [];
        foreach ($soloNodes as $node) {
            $soloNodeUrls[$node->getId() ?? 0] = $this->buildNodeManageUrl($node);
        }

        return $this->renderInstances($instances, $passwords, $soloNodes, $soloNodeUrls);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', $this->translator->trans('error_unauthorized'));
        }

        return $actor;
    }

    /** @return SinusbotInstance[] */
    private function loadInstances(User $customer): array
    {
        return $this->instanceRepository->findBy(
            ['customer' => $customer, 'archivedAt' => null],
            ['updatedAt' => 'DESC'],
        );
    }

    /**
     * @param SinusbotInstance[] $instances
     * @param array<int, string|null> $passwords
     * @param SinusbotNode[] $soloNodes
     * @param array<int, string|null> $soloNodeUrls
     */
    private function renderInstances(array $instances, array $passwords = [], array $soloNodes = [], array $soloNodeUrls = []): Response
    {
        $botsUsed = [];
        $csrf = [];
        foreach ($instances as $instance) {
            $id = $instance->getId();
            if ($id === null) {
                continue;
            }
            $botsUsed[$id] = $this->instanceRepository->countBotsUsedForInstance($instance);
            $csrf[$id] = $this->csrfTokens($instance);
        }

        return new Response($this->twig->render('customer/infrastructure/sinusbot/index.html.twig', [
            'activeNav' => 'sinusbot',
            'instances' => $instances,
            'bots_used' => $botsUsed,
            'passwords' => $passwords,
            'csrf' => $csrf,
            'solo_nodes' => $soloNodes,
            'solo_node_urls' => $soloNodeUrls,
        ]));
    }

    private function findInstanceForCustomer(User $customer, int $id): SinusbotInstance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null || $instance->getCustomer()?->getId() !== $customer->getId()) {
            throw new NotFoundHttpException($this->translator->trans('sinusbot_instance_not_found'));
        }

        return $instance;
    }

    private function redirectToIndex(): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate('customer_sinusbot_index'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function csrfTokens(SinusbotInstance $instance): array
    {
        $id = (string) $instance->getId();

        return [
            'start' => $this->csrfTokenManager->getToken('sinusbot_start_' . $id)->getValue(),
            'stop' => $this->csrfTokenManager->getToken('sinusbot_stop_' . $id)->getValue(),
            'reset' => $this->csrfTokenManager->getToken('sinusbot_reset_password_' . $id)->getValue(),
            'reveal' => $this->csrfTokenManager->getToken('sinusbot_reveal_credentials_' . $id)->getValue(),
        ];
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', $this->translator->trans('error_invalid_csrf'));
        }
    }

    private function buildNodeManageUrl(SinusbotNode $node): ?string
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
}
