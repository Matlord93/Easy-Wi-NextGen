<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AgentConfigurationException;
use App\Module\Core\Application\Sinusbot\AgentBadResponseException;
use App\Module\Core\Application\Sinusbot\AgentUnavailableException;
use App\Module\Core\Application\Sinusbot\SinusbotInstanceProvisioner;
use App\Module\Core\Domain\Entity\SinusbotInstance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\SinusbotInstanceRepository;
use App\Repository\SinusbotNodeRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route(path: '/admin/sinusbot/instances')]
final class AdminSinusbotInstanceController
{
    public function __construct(
        private readonly SinusbotInstanceRepository $instanceRepository,
        private readonly SinusbotNodeRepository $nodeRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SinusbotInstanceProvisioner $provisioner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route(path: '/create', name: 'admin_sinusbot_instances_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin($request);
        $this->validateCsrf($request, 'sinusbot_instance_create');

        $nodeId = (int) $request->request->get('node_id');
        $customerId = (int) $request->request->get('customer_id');
        $quota = (int) $request->request->get('quota');
        $username = trim((string) $request->request->get('username', ''));

        $node = $this->nodeRepository->find($nodeId);
        if ($node === null) {
            throw new NotFoundHttpException('SinusBot node not found.');
        }

        $customer = $this->userRepository->find($customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            throw new NotFoundHttpException('Customer not found.');
        }

        $existing = $this->instanceRepository->findOneBy([
            'customer' => $customer,
            'archivedAt' => null,
        ]);
        if ($existing !== null) {
            $request->getSession()->getFlashBag()->add('error', 'Der Kunde besitzt bereits eine SinusBot-Instanz.');
            return $this->redirectToNode($nodeId);
        }

        try {
            $this->provisioner->createInstanceForCustomer(
                $customer,
                $node,
                $quota,
                $username !== '' ? $username : null,
            );
        } catch (\InvalidArgumentException $exception) {
            $request->getSession()->getFlashBag()->add('error', $exception->getMessage());
            return $this->redirectToNode($nodeId);
        } catch (AgentConfigurationException | AgentBadResponseException | AgentUnavailableException $exception) {
            $request->getSession()->getFlashBag()->add('error', $exception->getMessage());
            return $this->redirectToNode($nodeId);
        } catch (UniqueConstraintViolationException) {
            $request->getSession()->getFlashBag()->add('error', 'Die Instanz existiert bereits.');
            return $this->redirectToNode($nodeId);
        }

        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Instanz erstellt.');

        return $this->redirectToNode($nodeId);
    }

    #[Route(path: '/{id}/start', name: 'admin_sinusbot_instances_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $this->validateCsrf($request, 'sinusbot_instance_start_' . $id);

        $this->provisioner->startInstance($instance);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Instanz gestartet.');

        return $this->redirectToNode($instance->getNode()->getId());
    }

    #[Route(path: '/{id}/stop', name: 'admin_sinusbot_instances_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $this->validateCsrf($request, 'sinusbot_instance_stop_' . $id);

        $this->provisioner->stopInstance($instance);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Instanz gestoppt.');

        return $this->redirectToNode($instance->getNode()->getId());
    }

    #[Route(path: '/{id}/restart', name: 'admin_sinusbot_instances_restart', methods: ['POST'])]
    public function restart(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $this->validateCsrf($request, 'sinusbot_instance_restart_' . $id);

        $this->provisioner->restartInstance($instance);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Instanz neu gestartet.');

        return $this->redirectToNode($instance->getNode()->getId());
    }

    #[Route(path: '/{id}/reset-password', name: 'admin_sinusbot_instances_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $this->validateCsrf($request, 'sinusbot_instance_reset_password_' . $id);

        $this->provisioner->resetPassword($instance);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Passwort neu gesetzt.');

        return $this->redirectToNode($instance->getNode()->getId());
    }

    #[Route(path: '/{id}/quota', name: 'admin_sinusbot_instances_quota', methods: ['POST'])]
    public function updateQuota(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $this->validateCsrf($request, 'sinusbot_instance_quota_' . $id);

        $quota = (int) $request->request->get('quota');
        try {
            $this->provisioner->updateQuota($instance, $quota);
        } catch (\InvalidArgumentException $exception) {
            $request->getSession()->getFlashBag()->add('error', $exception->getMessage());
            return $this->redirectToNode($instance->getNode()->getId());
        }

        $request->getSession()->getFlashBag()->add('success', 'Bot-Quota aktualisiert.');

        return $this->redirectToNode($instance->getNode()->getId());
    }

    #[Route(path: '/{id}/delete', name: 'admin_sinusbot_instances_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $instance = $this->findInstance($id);
        $this->validateCsrf($request, 'sinusbot_instance_delete_' . $id);

        $mode = (string) $request->request->get('mode', 'delete');
        $nodeId = $instance->getNode()->getId();

        if ($mode === 'detach') {
            $instance->setArchivedAt(new \DateTimeImmutable());
            $instance->setCustomer(null);
            $instance->setStatus('stopped');
            $this->entityManager->flush();
            $request->getSession()->getFlashBag()->add('success', 'SinusBot-Instanz deaktiviert.');

            return $this->redirectToNode($nodeId);
        }

        $this->provisioner->deleteInstance($instance);
        $request->getSession()->getFlashBag()->add('success', 'SinusBot-Instanz gelöscht.');

        return $this->redirectToNode($nodeId);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findInstance(int $id): SinusbotInstance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('SinusBot instance not found.');
        }

        return $instance;
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }
    }

    private function redirectToNode(int $nodeId): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => sprintf('/admin/sinusbot/nodes/%d', $nodeId),
        ]);
    }
}
