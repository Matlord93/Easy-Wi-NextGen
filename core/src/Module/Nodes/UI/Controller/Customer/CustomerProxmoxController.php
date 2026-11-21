<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Customer;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Application\SiteResolver;
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

#[\App\Module\Core\Attribute\RequiresModule('proxmox')]
#[Route('/customer/proxmox')]
final class CustomerProxmoxController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ProxmoxManager $manager, private readonly \App\Module\Nodes\Application\Console\VmConsoleBroker $console, private readonly SiteResolver $sites)
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
        $consoleGuest = null;
        $error = null;
        try {
            $consoleGuest = $this->manager->assignedGuest($assignment, $actor);
            $detail = $this->manager->detail($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), $assignment->getBackupStorage(), true, $assignment->getIdentityTag());
        } catch (\Throwable) {
            $error = 'pve.error.guest_unavailable';
        }

        $consoleReason = $this->console->unavailableReason($consoleGuest);
        $reinstallation = null;
        $tasks = $this->tasks($assignment);
        if (null === $detail) {
            // PVE may temporarily omit the guest while its data is being replaced.
            $tasks = array_values(array_filter($tasks, static fn (ProxmoxTask $task): bool => self::isOwnRestore($task, $actor)));
        }
        if ('qemu' === $assignment->getKind() && 'stopped' === ($detail['status']['status'] ?? null)
            && !array_any($tasks, static fn (ProxmoxTask $task): bool => $task->isActive())
            && !array_any(JsonArray::orEmpty($detail['snapshots'] ?? null), static fn (mixed $snapshot): bool => !is_array($snapshot) || 'current' !== ($snapshot['name'] ?? null))
            && null !== ($siteId = $this->sites->resolve($request)?->getId())) {
            try {
                $reinstallation = $this->manager->reinstallationChoices($assignment, $actor, $siteId);
            } catch (\InvalidArgumentException|\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException) {
                // Keep the guest page usable if reinstallation cannot be offered.
            }
        }

        return $this->render('customer/proxmox/guest.html.twig', ['assignment' => $assignment, 'kind' => $assignment->getKind(), 'vmId' => $assignment->getVmId(), 'detail' => $detail, 'error' => $error, 'tasks' => $tasks, 'requestKey' => bin2hex(random_bytes(16)), 'reinstallation' => $reinstallation, 'reinstallationRequestKey' => bin2hex(random_bytes(16)), 'addressSwitchRequestKey' => bin2hex(random_bytes(16)), 'actionUrl' => $this->generateUrl('customer_proxmox_action', ['id' => $id]), 'csrfId' => 'proxmox-customer-'.$id, 'admin' => false, 'consoleConfigured' => null === $consoleReason, 'consoleUnavailableReason' => $consoleReason, 'activeNav' => 'proxmox']);
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
        $summary = 'summary' === $request->query->getString('view');
        try {
            $rows = $this->manager->usage($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), $assignment->getIdentityTag(), !$summary);
            $serverUsage = $usage->proxmox($rows['server']);

            return new JsonResponse(['html' => $this->renderView(
                $summary ? 'infrastructure/_metrics.html.twig' : 'infrastructure/_server_usage.html.twig',
                $summary ? ['usage' => $serverUsage, 'seriesRole' => 'server', 'withIo' => false] : ['serverUsage' => $serverUsage],
            )], 200, ['Cache-Control' => 'private, no-store']);
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
        $action = $request->request->getString('action');
        if ('root-password' === $action) {
            throw new AccessDeniedHttpException();
        }
        $submitted = false;
        try {
            $task = $this->manager->perform($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), $action, $request->request->all(), $request->request->getString('request_key'), $actor);
            if (in_array($task->getState(), ['failed', 'uncertain'], true)) {
                throw new \InvalidArgumentException('permission_denied' === $task->getResult() ? 'pve.error.permissions' : 'pve.error.unavailable');
            }
            $submitted = true;
        } catch (\Throwable $exception) {
            $key = $exception instanceof \InvalidArgumentException && (str_starts_with($exception->getMessage(), 'pve.error.') || str_starts_with($exception->getMessage(), 'vm_config.')) ? $exception->getMessage() : 'pve.error.unavailable';
            $this->addFlash('proxmox_error', $key);
        }

        $params = ['id' => $id];
        if ('address-switch' === $action) {
            $params['_fragment'] = 'pve-access';
        } elseif ('backup-restore' === $action) {
            $params['_fragment'] = $submitted ? 'pve-activity' : 'pve-backups';
        } elseif ('backup' === $action) {
            $params['_fragment'] = 'pve-backups';
        }

        return $this->redirectToRoute('customer_proxmox_guest', $params, 303);
    }

    #[Route('/{id<\d+>}/root-password', name: 'customer_proxmox_root_password', methods: ['POST'])]
    public function rootPassword(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->owned($actor, $id);
        if (!$this->isCsrfTokenValid('proxmox-customer-'.$id, $request->request->getString('_token')) || '1' !== $request->request->getString('confirm')) {
            throw new AccessDeniedHttpException();
        }
        try {
            $credentials = \App\Module\Nodes\Application\VmRootPassword::generate();
            $task = $this->manager->perform($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId(), 'root-password', [
                'confirm' => '1', 'password_hash' => $credentials->hash,
            ], bin2hex(random_bytes(16)), $actor);
            if ('OK' !== $task->getResult()) {
                throw new \RuntimeException('Password reset not confirmed.');
            }
        } catch (\InvalidArgumentException|\RuntimeException) {
            $this->addFlash('proxmox_error', 'pve.error.unavailable');

            return $this->redirectToRoute('customer_proxmox_guest', ['id' => $id], 303);
        }

        return $this->render('customer/vm/root-password.html.twig', [
            'password' => $credentials->password, 'pending' => false, 'back' => $this->generateUrl('customer_proxmox_guest', ['id' => $id]), 'activeNav' => 'proxmox',
        ], new Response(headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
    }

    #[Route('/{id<\d+>}/reinstall', name: 'customer_proxmox_reinstall', methods: ['POST'])]
    public function reinstall(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->owned($actor, $id);
        $form = $request->request;
        if (!$this->isCsrfTokenValid('proxmox-customer-'.$id, $form->getString('_token')) || '1' !== $form->getString('confirm')) {
            throw new AccessDeniedHttpException();
        }
        try {
            $siteId = $this->sites->resolve($request)?->getId() ?? throw new \InvalidArgumentException('pve.error.reinstall_unavailable');
            $this->manager->queueReinstallation($assignment, $actor, $siteId, $form->getInt('product_id'), $form->getString('expected_digest'), $form->getString('request_key'));
        } catch (\InvalidArgumentException|\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException $exception) {
            $this->addFlash('proxmox_error', $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'pve.error.unavailable');

            return $this->redirectToRoute('customer_proxmox_guest', ['id' => $id, '_fragment' => 'pve-access'], 303);
        }

        return $this->redirectToRoute('customer_proxmox_guest', ['id' => $id, '_fragment' => 'pve-activity'], 303);
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
            if (!self::isOwnRestore($task, $actor)) {
                $this->manager->assignedGuest($assignment, $actor);
            }
            $this->manager->refreshTask($task);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'pve.error.unavailable'], 503);
        }

        return new JsonResponse(['state' => $task->getState(), 'result' => $task->getResult(), 'active' => $task->isActive(), 'poll' => 'uncertain' !== $task->getState()], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function actor(Request $request): User
    {
        $actor = RequestActor::user($request);
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

    private static function isOwnRestore(ProxmoxTask $task, User $actor): bool
    {
        return 'backup-restore' === $task->getAction() && $task->getActorId() === $actor->getId();
    }

    /** @return list<\App\Module\Nodes\Domain\Entity\ProxmoxTask> */
    private function tasks(ProxmoxGuestAssignment $assignment): array
    {
        return $this->em->getRepository(ProxmoxTask::class)->findBy(['cluster' => $assignment->getCluster(), 'vmId' => $assignment->getVmId(), 'kind' => $assignment->getKind()], ['id' => 'DESC'], 20);
    }
}
