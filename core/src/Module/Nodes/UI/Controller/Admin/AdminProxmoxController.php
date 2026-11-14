<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/proxmox')]
final class AdminProxmoxController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SecretsCrypto $crypto, private readonly ProxmoxManager $manager, private readonly AuditLogger $audit)
    {
    }

    #[Route('', name: 'admin_proxmox', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'proxmox-config');
            try {
                $cluster = new ProxmoxCluster((string) $request->request->get('name'), new ProxmoxConnection(rtrim(trim((string) $request->request->get('base_url')), '/'), trim((string) $request->request->get('token_id')), (string) $request->request->get('token_secret'), $request->request->getBoolean('verify_tls', true)), $this->crypto);
                $this->em->persist($cluster);
                $this->em->flush();
                $this->audit->log($actor, 'proxmox.cluster_created', ['cluster_id' => $cluster->getId(), 'verify_tls' => $cluster->isTlsVerificationEnabled()]);
                $this->em->flush();

                return $this->redirectToRoute('admin_proxmox_cluster', ['id' => $cluster->getId()], 303);
            } catch (\InvalidArgumentException) {
                $error = 'pve.error.connection';
            }
        }

        return $this->render('admin/proxmox/index.html.twig', ['clusters' => $this->em->getRepository(ProxmoxCluster::class)->findBy([], ['name' => 'ASC']), 'error' => $error, 'activeNav' => 'proxmox'], new Response(status: $error ? 422 : 200));
    }

    #[Route('/{id<\d+>}', name: 'admin_proxmox_cluster', methods: ['GET'])]
    public function cluster(Request $request, int $id): Response
    {
        $this->actor($request);
        $cluster = $this->findCluster($id);
        $overview = ['nodes' => [], 'guests' => []];
        $error = null;
        try {
            $overview = $this->manager->overview($cluster);
        } catch (\Throwable $exception) {
            $error = $this->error($exception);
        }

        return $this->render('admin/proxmox/cluster.html.twig', ['cluster' => $cluster, 'overview' => $overview, 'error' => $error, 'tasks' => $this->tasks($cluster), 'activeNav' => 'proxmox']);
    }

    #[Route('/{id<\d+>}/settings', name: 'admin_proxmox_settings', methods: ['POST'])]
    public function settings(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $cluster = $this->findCluster($id);
        $this->csrf($request, 'proxmox-settings-'.$id);
        try {
            $cluster->configure((string) $request->request->get('name'), (string) $request->request->get('base_url'), (string) $request->request->get('token_id'), (string) $request->request->get('token_secret', ''), $this->crypto, $request->request->getBoolean('verify_tls', $cluster->isTlsVerificationEnabled()));
            $cluster->setEnabled($request->request->getBoolean('enabled'));
            $this->audit->log($actor, 'proxmox.cluster_updated', ['cluster_id' => $id, 'enabled' => $cluster->isEnabled(), 'verify_tls' => $cluster->isTlsVerificationEnabled()]);
            $this->em->flush();
        } catch (\InvalidArgumentException) {
            return $this->render('admin/proxmox/cluster.html.twig', ['cluster' => $cluster, 'overview' => ['nodes' => [], 'guests' => []], 'tasks' => $this->tasks($cluster), 'error' => 'pve.error.connection', 'activeNav' => 'proxmox'], new Response(status: 422));
        }

        return $this->redirectToRoute('admin_proxmox_cluster', ['id' => $id], 303);
    }

    #[Route('/{id<\d+>}/resources', name: 'admin_proxmox_resources', methods: ['GET'])]
    public function resources(Request $request, int $id): JsonResponse
    {
        $this->actor($request);
        $cluster = $this->findCluster($id);
        try {
            return new JsonResponse(['html' => $this->renderView('admin/proxmox/_resources.html.twig', ['cluster' => $cluster, 'overview' => $this->manager->overview($cluster)])], 200, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $this->error($exception)], 503);
        }
    }

    #[Route('/{id<\d+>}/guests/{kind<qemu|lxc>}/{vmId<\d+>}', name: 'admin_proxmox_guest', methods: ['GET'])]
    public function guest(Request $request, int $id, string $kind, int $vmId): Response
    {
        $this->actor($request);
        $cluster = $this->findCluster($id);
        $detail = null;
        $error = null;
        try {
            $detail = $this->manager->detail($cluster, $kind, $vmId);
        } catch (\Throwable $exception) {
            $error = $this->error($exception);
        }

        return $this->render('admin/proxmox/guest.html.twig', ['cluster' => $cluster, 'kind' => $kind, 'vmId' => $vmId, 'detail' => $detail, 'error' => $error, 'tasks' => $this->tasks($cluster, $vmId), 'requestKey' => bin2hex(random_bytes(16)), 'actionUrl' => $this->generateUrl('admin_proxmox_action', ['id' => $id, 'kind' => $kind, 'vmId' => $vmId]), 'csrfId' => 'proxmox-action-'.$id.'-'.$kind.'-'.$vmId, 'admin' => true, 'customers' => $this->em->getRepository(User::class)->findBy(['type' => UserType::Customer], ['email' => 'ASC']), 'assignment' => $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $cluster, 'vmId' => $vmId]), 'activeNav' => 'proxmox']);
    }

    #[Route('/{id<\d+>}/guests/{kind<qemu|lxc>}/{vmId<\d+>}/guest-agent', name: 'admin_proxmox_guest_agent', methods: ['POST'])]
    public function guestAgent(Request $request, int $id, string $kind, int $vmId): JsonResponse
    {
        $actor = $this->actor($request);
        $cluster = $this->findCluster($id);
        $this->csrf($request, 'proxmox-action-'.$id.'-'.$kind.'-'.$vmId);
        try {
            $report = $this->manager->guestAgentStatus($cluster, $kind, $vmId, $actor);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'pve.error.forbidden'], 403, ['Cache-Control' => 'private, no-store']);
        } catch (ProxmoxApiException) {
            return new JsonResponse(['error' => 'pve.error.unavailable'], 503, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse(['guest_agent' => $report], 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/{id<\d+>}/guests/{kind<qemu|lxc>}/{vmId<\d+>}/usage', name: 'admin_proxmox_usage', methods: ['GET'])]
    public function usage(Request $request, int $id, string $kind, int $vmId, InfrastructureUsage $usage): JsonResponse
    {
        $this->actor($request);
        $cluster = $this->findCluster($id);
        try {
            $rows = $this->manager->usage($cluster, $kind, $vmId);

            return new JsonResponse(['html' => $this->renderView('infrastructure/_usage_pair.html.twig', ['serverUsage' => $usage->proxmox($rows['server']), 'nodeUsage' => $usage->proxmox($rows['node'])])], 200, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'pve.error.unavailable'], 503, ['Cache-Control' => 'private, no-store']);
        }
    }

    #[Route('/{id<\d+>}/guests/{kind<qemu|lxc>}/{vmId<\d+>}/action', name: 'admin_proxmox_action', methods: ['POST'])]
    public function action(Request $request, int $id, string $kind, int $vmId): Response
    {
        $actor = $this->actor($request);
        $cluster = $this->findCluster($id);
        $this->csrf($request, 'proxmox-action-'.$id.'-'.$kind.'-'.$vmId);
        try {
            $this->manager->perform($cluster, $kind, $vmId, (string) $request->request->get('action'), $request->request->all(), (string) $request->request->get('request_key'), $actor);
        } catch (\Throwable $exception) {
            $this->addFlash('proxmox_error', $this->error($exception));
        }

        return $this->redirectToRoute('admin_proxmox_guest', ['id' => $id, 'kind' => $kind, 'vmId' => $vmId], 303);
    }

    #[Route('/{id<\d+>}/guests/{kind<qemu|lxc>}/{vmId<\d+>}/assign', name: 'admin_proxmox_assign', methods: ['POST'])]
    public function assign(Request $request, int $id, string $kind, int $vmId): Response
    {
        $actor = $this->actor($request);
        $cluster = $this->findCluster($id);
        $this->csrf($request, 'proxmox-assign-'.$id.'-'.$kind.'-'.$vmId);
        try {
            $detail = $this->manager->detail($cluster, $kind, $vmId);
            if (!empty($detail['guest']['template'])) {
                throw new \InvalidArgumentException('pve.error.template_action');
            }
            $customerId = '' === $request->request->get('customer_id') ? 0 : $request->request->getInt('customer_id');
            $customer = $customerId > 0 ? $this->em->find(User::class, $customerId) : null;
            if ($customerId > 0 && (!$customer instanceof User || UserType::Customer !== $customer->getType())) {
                throw new \InvalidArgumentException('pve.error.customer');
            }
            $storage = trim((string) $request->request->get('backup_storage', ''));
            if ('' !== $storage && !in_array($storage, array_column(array_filter($detail['storages'], static fn (array $s): bool => !empty($s['active']) && in_array('backup', explode(',', $s['content'] ?? ''), true)), 'storage'), true)) {
                throw new \InvalidArgumentException('pve.error.storage');
            }
            $this->em->wrapInTransaction(function () use ($cluster, $vmId, $kind, $customer, $storage, $actor): void {
                $existing = $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $cluster, 'vmId' => $vmId]);
                $tag = $this->manager->ownershipTag($cluster, $kind, $vmId, $existing?->getIdentityTag(), null !== $customer);
                if ($existing) {
                    $this->em->remove($existing);
                    $this->em->flush();
                }
                if ($customer) {
                    $customer->setCustomerAccessEnabled(true);
                    $this->em->persist(new ProxmoxGuestAssignment($cluster, $vmId, $kind, $customer, $tag, '' !== $storage ? $storage : null));
                }
                $this->audit->log($actor, 'proxmox.guest_assigned', ['cluster_id' => $cluster->getId(), 'vm_id' => $vmId, 'customer_id' => $customer?->getId()]);
            });
        } catch (\Throwable $exception) {
            $this->addFlash('proxmox_error', $this->error($exception));
        }

        return $this->redirectToRoute('admin_proxmox_guest', ['id' => $id, 'kind' => $kind, 'vmId' => $vmId], 303);
    }

    #[Route('/{id<\d+>}/nodes/{node}/new', name: 'admin_proxmox_new_vm', methods: ['GET', 'POST'])]
    public function newVm(Request $request, int $id, string $node): Response
    {
        $actor = $this->actor($request);
        $cluster = $this->findCluster($id);
        $error = null;
        $options = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'proxmox-new-'.$id.'-'.$node);
            try {
                $this->manager->perform($cluster, 'qemu', 0, 'create-vm', array_replace($request->request->all(), ['node' => $node]), (string) $request->request->get('request_key'), $actor);

                return $this->redirectToRoute('admin_proxmox_cluster', ['id' => $id], 303);
            } catch (\Throwable $exception) {
                $error = $this->error($exception);
            }
        }
        try {
            $options = $this->manager->creationOptions($cluster, $node);
        } catch (\Throwable $exception) {
            $error = $this->error($exception);
        }

        return $this->render('admin/proxmox/new.html.twig', ['cluster' => $cluster, 'node' => $node, 'options' => $options, 'error' => $error, 'requestKey' => bin2hex(random_bytes(16)), 'activeNav' => 'proxmox']);
    }

    #[Route('/{id<\d+>}/tasks/{taskId<\d+>}', name: 'admin_proxmox_task', methods: ['GET'])]
    public function task(Request $request, int $id, int $taskId): JsonResponse
    {
        $this->actor($request);
        $task = $this->em->find(ProxmoxTask::class, $taskId);
        if (!$task instanceof ProxmoxTask || $task->getCluster()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        try {
            $this->manager->refreshTask($task);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $this->error($exception)], 503);
        }

        return new JsonResponse(['state' => $task->getState(), 'result' => $task->getResult(), 'active' => $task->isActive(), 'poll' => 'uncertain' !== $task->getState()], 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/{id<\d+>}/tasks/{taskId<\d+>}/review', name: 'admin_proxmox_task_review', methods: ['GET', 'POST'])]
    public function reviewTask(Request $request, int $id, int $taskId): Response
    {
        $actor = $this->actor($request);
        $task = $this->em->find(ProxmoxTask::class, $taskId);
        if (!$task instanceof ProxmoxTask || $task->getCluster()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'proxmox-task-review-'.$taskId);
            try {
                $outcome = $request->request->get('outcome');
                if ('1' !== $request->request->get('confirm') || !in_array($outcome, ['success', 'failure'], true)) {
                    throw new \InvalidArgumentException('pve.error.review');
                }
                $this->manager->reviewTask($task, $actor, 'success' === $outcome, (string) $request->request->get('note'));

                return $this->redirectToRoute('admin_proxmox_task_review', ['id' => $id, 'taskId' => $taskId], 303);
            } catch (\Throwable $exception) {
                $error = $this->error($exception);
            }
        }

        return $this->render('admin/proxmox/task-review.html.twig', ['task' => $task, 'error' => $error, 'activeNav' => 'proxmox'], new Response(status: $error ? 422 : 200, headers: ['Cache-Control' => 'private, no-store']));
    }

    private function actor(Request $request): User
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function findCluster(int $id): ProxmoxCluster
    {
        return $this->em->find(ProxmoxCluster::class, $id) ?? throw $this->createNotFoundException();
    }

    /** @return list<\App\Module\Nodes\Domain\Entity\ProxmoxTask> */
    private function tasks(ProxmoxCluster $cluster, ?int $vmId = null): array
    {
        $criteria = ['cluster' => $cluster] + (null === $vmId ? [] : ['vmId' => $vmId]);
        $repository = $this->em->getRepository(ProxmoxTask::class);
        $rows = [...$repository->findBy($criteria + ['state' => ['submitting', 'running', 'uncertain']], ['id' => 'DESC']), ...$repository->findBy($criteria, ['id' => 'DESC'], 20)];
        $unique = [];
        foreach ($rows as $row) {
            $unique[$row->getId()] = $row;
        }

        return array_values($unique);
    }

    private function error(\Throwable $exception): string
    {
        if ($exception instanceof \InvalidArgumentException && (str_starts_with($exception->getMessage(), 'pve.error.') || str_starts_with($exception->getMessage(), 'vm_config.'))) {
            return $exception->getMessage();
        }
        if ($exception instanceof ProxmoxApiException && in_array($exception->statusCode, [401, 403], true)) {
            return 'pve.error.permissions';
        }

        return 'pve.error.unavailable';
    }
}
