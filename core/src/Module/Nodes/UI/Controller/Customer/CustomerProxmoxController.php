<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/customer/proxmox')]
final class CustomerProxmoxController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ProxmoxManager $manager, private readonly \App\Module\Nodes\Application\Console\VmConsoleBroker $console)
    {
    }

    #[Route('', name: 'customer_proxmox', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $guests = $this->manager->customerGuests($actor);

        return $this->render('customer/proxmox/index.html.twig', ['guests' => $guests, 'activeNav' => 'proxmox']);
    }

    #[Route('/{id<\d+>}', name: 'customer_proxmox_guest', methods: ['GET'])]
    public function guest(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->owned($actor, $id);
        $detail = null;
        $error = null;
        try {
            $this->manager->assignedGuest($assignment, $actor);
            $detail = $this->manager->detail($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), $assignment->getBackupStorage(), true, $assignment->getIdentityTag());
        } catch (\Throwable) {
            $error = 'pve.error.guest_unavailable';
        }

        return $this->render('customer/proxmox/guest.html.twig', ['assignment' => $assignment, 'kind' => $assignment->getKind(), 'vmId' => $assignment->getVmId(), 'detail' => $detail, 'error' => $error, 'tasks' => $this->tasks($assignment), 'requestKey' => bin2hex(random_bytes(16)), 'actionUrl' => $this->generateUrl('customer_proxmox_action', ['id' => $id]), 'csrfId' => 'proxmox-customer-'.$id, 'admin' => false, 'consoleConfigured' => $this->console->configured(), 'activeNav' => 'proxmox']);
    }

    #[Route('/{id<\d+>}/guest-agent', name: 'customer_proxmox_guest_agent', methods: ['POST'])]
    public function guestAgent(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $assignment = $this->owned($actor, $id);
        if (!$this->isCsrfTokenValid('proxmox-customer-'.$id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
        try {
            $report = $this->manager->guestAgentStatus($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), $actor);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'pve.error.forbidden'], 403, ['Cache-Control' => 'private, no-store']);
        } catch (\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException) {
            return new JsonResponse(['error' => 'pve.error.unavailable'], 503, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse(['guest_agent' => $report], 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/{id<\d+>}/usage', name: 'customer_proxmox_usage', methods: ['GET'])]
    public function usage(Request $request, int $id, InfrastructureUsage $usage): JsonResponse
    {
        $assignment = $this->owned($this->actor($request), $id);
        try {
            $rows = $this->manager->usage($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), $assignment->getIdentityTag());

            return new JsonResponse(['html' => $this->renderView('infrastructure/_usage_pair.html.twig', ['serverUsage' => $usage->proxmox($rows['server']), 'nodeUsage' => $usage->proxmox($rows['node'])])], 200, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'pve.error.unavailable'], 503, ['Cache-Control' => 'private, no-store']);
        }
    }

    #[Route('/{id<\d+>}/action', name: 'customer_proxmox_action', methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->owned($actor, $id);
        if (!$this->isCsrfTokenValid('proxmox-customer-'.$id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
        try {
            $this->manager->perform($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), (string) $request->request->get('action'), $request->request->all(), (string) $request->request->get('request_key'), $actor);
        } catch (\Throwable $exception) {
            $key = $exception instanceof \InvalidArgumentException && (str_starts_with($exception->getMessage(), 'pve.error.') || str_starts_with($exception->getMessage(), 'vm_config.')) ? $exception->getMessage() : 'pve.error.unavailable';
            $this->addFlash('proxmox_error', $key);
        }

        return $this->redirectToRoute('customer_proxmox_guest', ['id' => $id], 303);
    }

    #[Route('/{id<\d+>}/tasks/{taskId<\d+>}', name: 'customer_proxmox_task', methods: ['GET'])]
    public function task(Request $request, int $id, int $taskId): JsonResponse
    {
        $actor = $this->actor($request);
        $assignment = $this->owned($actor, $id);
        $task = $this->em->find(ProxmoxTask::class, $taskId);
        if (!$task instanceof ProxmoxTask || $task->getCluster()->getId() !== $assignment->getCluster()->getId() || $task->getVmId() !== $assignment->getVmId() || $task->getKind() !== $assignment->getKind()) {
            throw $this->createNotFoundException();
        }
        try {
            $this->manager->assignedGuest($assignment, $actor);
            $this->manager->refreshTask($task);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'pve.error.unavailable'], 503);
        }

        return new JsonResponse(['state' => $task->getState(), 'result' => $task->getResult(), 'active' => $task->isActive(), 'poll' => 'uncertain' !== $task->getState()], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || UserType::Customer !== $actor->getType()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    private function owned(User $actor, int $id): ProxmoxGuestAssignment
    {
        $assignment = $this->em->find(ProxmoxGuestAssignment::class, $id);
        if (!$assignment instanceof ProxmoxGuestAssignment || $assignment->getCustomer()->getId() !== $actor->getId()) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    private function tasks(ProxmoxGuestAssignment $assignment): array
    {
        return $this->em->getRepository(ProxmoxTask::class)->findBy(['cluster' => $assignment->getCluster(), 'vmId' => $assignment->getVmId(), 'kind' => $assignment->getKind()], ['id' => 'DESC'], 20);
    }
}
