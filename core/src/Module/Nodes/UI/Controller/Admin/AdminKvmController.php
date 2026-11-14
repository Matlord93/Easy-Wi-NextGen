<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Kvm\KvmOperationLock;
use App\Module\Nodes\Application\Kvm\KvmPanelService;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use App\Repository\AgentJobRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/kvm')]
final class AdminKvmController extends AbstractController
{
    public function __construct(
        private readonly AgentRepository $agents,
        private readonly AgentJobRepository $jobs,
        private readonly KvmPanelService $kvm,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_kvm', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->actor($request);

        return $this->render('admin/kvm/index.html.twig', ['nodes' => $this->agents->findBy([], ['name' => 'ASC']), 'activeNav' => 'kvm'], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/nodes/{id}', name: 'admin_kvm_node', methods: ['GET'])]
    public function node(Request $request, string $id): Response
    {
        $this->actor($request);
        $node = $this->nodeById($id);

        return $this->render('admin/kvm/node.html.twig', ['node' => $node, 'inventory' => $this->kvm->inventory($node), 'jobs' => $this->kvm->recent($node), 'availability' => $this->kvm->availability($node), 'activeNav' => 'kvm', 'operationId' => Uuid::v4()->toRfc4122(), 'customers' => $this->em->getRepository(User::class)->findBy(['type' => UserType::Customer], ['email' => 'ASC']), 'assignments' => $this->assignmentMap($node)], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/nodes/{id}/actions', name: 'admin_kvm_action', methods: ['POST'])]
    public function action(Request $request, string $id): Response
    {
        $actor = $this->actor($request);
        $node = $this->nodeById($id);
        if (!$this->isCsrfTokenValid('kvm-'.$id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
        try {
            $action = (string) $request->request->get('action', '');
            $job = $this->kvm->submit($node, $action, $request->request->all());
            // Deliberately exclude template URLs, SSH keys and raw job payloads.
            $this->audit->log($actor, 'kvm.job_requested', ['node_id' => $id, 'job_id' => $job->getId(), 'action' => $action]);
            $this->em->flush();
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['job_id' => $job->getId(), 'status' => $job->getStatus()->value], 202);
            }
        } catch (\InvalidArgumentException $exception) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => $exception->getMessage()], 422);
            }
            $this->addFlash('kvm_error', $exception->getMessage());
        }

        $vmId = (string) $request->request->get('vm_id', '');
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $vmId)) {
            foreach ($this->kvm->inventory($node)['guests'] ?? [] as $vm) {
                if (($vm['id'] ?? '') === $vmId) {
                    return $this->redirectToRoute('admin_kvm_guest', ['id' => $id, 'uuid' => $vmId], 303);
                }
            }
        }

        return $this->redirectToRoute('admin_kvm_node', ['id' => $id], 303);
    }

    #[Route('/nodes/{id}/guests/{uuid}', name: 'admin_kvm_guest', methods: ['GET'])]
    public function guest(Request $request, string $id, string $uuid): Response
    {
        $this->actor($request);
        $node = $this->nodeById($id);
        $vm = $this->findGuest($node, $uuid);

        return $this->render('admin/kvm/guest.html.twig', ['node' => $node, 'vm' => $vm, 'inventory' => $this->kvm->inventory($node), 'jobs' => array_values(array_filter($this->kvm->recent($node), static fn (AgentJob $job): bool => ($job->getPayload()['vm_id'] ?? '') === $uuid)), 'operationId' => Uuid::v4()->toRfc4122(), 'customers' => $this->em->getRepository(User::class)->findBy(['type' => UserType::Customer], ['email' => 'ASC']), 'assignments' => $this->assignmentMap($node), 'activeNav' => 'kvm'], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/nodes/{id}/usage', name: 'admin_kvm_node_usage', methods: ['GET'])]
    public function nodeUsage(Request $request, string $id, InfrastructureUsage $usage): JsonResponse
    {
        $this->actor($request);

        $node = $this->nodeById($id);
        $inventory = $this->kvm->inventory($node);
        $data = ['html' => $this->renderView('infrastructure/_metrics.html.twig', ['usage' => $usage->node($node)])];
        if ($inventory) {
            $data['guests_html'] = $this->renderView('admin/kvm/_guests.html.twig', ['node' => $node, 'inventory' => $inventory]);
        }

        return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/nodes/{id}/guests/{uuid}/usage', name: 'admin_kvm_guest_usage', methods: ['GET'])]
    public function guestUsage(Request $request, string $id, string $uuid, InfrastructureUsage $usage): JsonResponse
    {
        $this->actor($request);
        $node = $this->nodeById($id);
        $this->findGuest($node, $uuid);

        return new JsonResponse(['html' => $this->renderView('infrastructure/_usage_pair.html.twig', ['serverUsage' => $usage->kvm($node, $uuid), 'nodeUsage' => $usage->node($node)])], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function findGuest(Agent $node, string $uuid): array
    {
        foreach ($this->kvm->inventory($node)['guests'] ?? [] as $vm) {
            if (($vm['id'] ?? '') === $uuid) {
                return $vm;
            }
        }
        throw $this->createNotFoundException();
    }

    #[Route('/nodes/{id}/jobs/{jobId}', name: 'admin_kvm_job', methods: ['GET'])]
    public function job(Request $request, string $id, string $jobId): JsonResponse
    {
        $this->actor($request);
        $this->nodeById($id);
        $job = $this->jobs->find($jobId);
        if (!$job instanceof AgentJob || $job->getNode()->getId() !== $id || 'kvm.managed' !== $job->getType()) {
            throw $this->createNotFoundException();
        }

        $view = ['id' => $job->getId(), 'status' => $job->getStatus()->value];
        if (($job->getPayload()['action'] ?? '') === 'vm.agent_status' && \App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Success === $job->getStatus()) {
            $view['guest_agent'] = \App\Module\Nodes\Application\VmGuestAgentReport::sanitize($job->getResultPayload()['guest_agent'] ?? null);
        }

        return new JsonResponse($view, 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/nodes/{id}/guests/{uuid}/assign', name: 'admin_kvm_assign', methods: ['POST'])]
    public function assign(Request $request, string $id, string $uuid, KvmOperationLock $lock): Response
    {
        $actor = $this->actor($request);
        $node = $this->nodeById($id);
        if (!$this->isCsrfTokenValid('kvm-assign-'.$id.'-'.$uuid, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
        $found = false;
        foreach ($this->kvm->inventory($node)['guests'] ?? [] as $guest) {
            if (($guest['id'] ?? '') === $uuid && ($guest['managed'] ?? false) && empty($guest['error'])) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw $this->createNotFoundException();
        }
        $customerId = $request->request->getInt('customer_id');
        $customer = $customerId ? $this->em->find(User::class, $customerId) : null;
        if ($customerId && (!$customer instanceof User || !$customer->isCustomer())) {
            throw new AccessDeniedHttpException();
        }
        $changed = $lock->run($node, function () use ($node, $uuid, $customer, $actor): bool {
            foreach ($this->jobs->findBy(['node' => $node, 'type' => 'kvm.managed', 'status' => [\App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Queued, \App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Running]]) as $pending) {
                if (($pending->getPayload()['vm_id'] ?? '') === $uuid) {
                    return false;
                }
            }
            if ($old = $this->em->getRepository(KvmGuestAssignment::class)->findOneBy(['node' => $node, 'vmUuid' => $uuid])) {
                $this->em->remove($old);
                $this->em->flush();
            }
            if ($customer) {
                $customer->setCustomerAccessEnabled(true);
                $this->em->persist(new KvmGuestAssignment($node, $uuid, $customer));
            }
            $this->audit->log($actor, 'kvm.guest_assigned', ['node_id' => $node->getId(), 'vm_uuid' => $uuid, 'customer_id' => $customer?->getId()]);
            $this->em->flush();

            return true;
        });
        if (!$changed) {
            $this->addFlash('kvm_error', 'kvm.assignment_busy');
        }

        return $this->redirectToRoute('admin_kvm_node', ['id' => $id], 303);
    }

    private function assignmentMap(Agent $node): array
    {
        $result = [];
        foreach ($this->em->getRepository(KvmGuestAssignment::class)->findBy(['node' => $node]) as $assignment) {
            $result[$assignment->getVmUuid()] = $assignment;
        }

        return $result;
    }

    private function actor(Request $request): User
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    private function nodeById(string $id): Agent
    {
        return $this->agents->find($id) ?? throw $this->createNotFoundException();
    }
}
