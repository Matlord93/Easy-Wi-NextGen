<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Customer;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\Console\VmConsoleBroker;
use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Kvm\CustomerKvmService;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/customer/kvm')]
final class CustomerKvmController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VmConsoleBroker $console, private readonly CustomerKvmService $kvm)
    {
    }

    #[Route('', name: 'customer_kvm', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $rows = [];
        foreach ($this->em->getRepository(KvmGuestAssignment::class)->findBy(['customer' => $actor]) as $assignment) {
            $rows[] = ['assignment' => $assignment, 'guest' => $this->console->kvmGuest($assignment)];
        }

        return $this->render('customer/kvm/index.html.twig', ['guests' => $rows, 'activeNav' => 'kvm'], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/{id<\d+>}', name: 'customer_kvm_guest', methods: ['GET'])]
    public function guest(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->console->assignment('kvm', $id, $actor);

        return $this->render('customer/kvm/guest.html.twig', ['assignment' => $assignment, 'guest' => $this->kvm->guest($assignment, $actor), 'jobs' => $this->kvm->recent($assignment, $actor), 'operationId' => Uuid::v4()->toRfc4122(), 'consoleConfigured' => $this->console->configured() && 1 === ($assignment->getNode()->getMetadata()['kvm_managed']['console_version'] ?? null), 'activeNav' => 'kvm'], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/{id<\d+>}/action', name: 'customer_kvm_action', methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->console->assignment('kvm', $id, $actor);
        if (!$this->isCsrfTokenValid('kvm-customer-'.$id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
        $action = (string) $request->request->get('action', '');
        try {
            $job = $this->kvm->submit($assignment, $actor, $action, $request->request->all());
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['job_id' => $job->getId(), 'status' => $job->getStatus()->value], 202, ['Cache-Control' => 'private, no-store']);
            }
        } catch (\InvalidArgumentException $exception) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'kvm.customer_unavailable'], 422, ['Cache-Control' => 'private, no-store']);
            }
            $this->addFlash('kvm_customer_error', str_starts_with($exception->getMessage(), 'kvm.') || str_starts_with($exception->getMessage(), 'vm_config.') ? $exception->getMessage() : 'kvm.customer_unavailable');
        }
        $pane = str_starts_with($action, 'snapshot.') ? 'snapshots' : (str_starts_with($action, 'backup.') ? 'backups' : (in_array($action, ['vm.cloud_init', 'vm.boot_order'], true) ? 'settings' : 'overview'));

        return $this->redirectToRoute('customer_kvm_guest', ['id' => $id, '_fragment' => 'kvm-'.$pane], 303);
    }

    #[Route('/{id<\d+>}/jobs/{jobId}', name: 'customer_kvm_job', methods: ['GET'])]
    public function job(Request $request, int $id, string $jobId): JsonResponse
    {
        $assignment = $this->console->assignment('kvm', $id, $this->actor($request));
        $job = $this->em->find(AgentJob::class, $jobId);
        if (!$job || 'kvm.managed' !== $job->getType() || $job->getNode()->getId() !== $assignment->getNode()->getId() || ($job->getPayload()['vm_id'] ?? '') !== $assignment->getVmUuid() || !in_array($job->getPayload()['action'] ?? '', CustomerKvmService::ACTIONS, true)) {
            throw $this->createNotFoundException();
        }

        return new JsonResponse($this->kvm->jobView($job), 200, ['Cache-Control' => 'private, no-store']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isCustomer()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    #[Route('/{id<\d+>}/usage', name: 'customer_kvm_usage', methods: ['GET'])]
    public function usage(Request $request, int $id, InfrastructureUsage $usage): JsonResponse
    {
        $assignment = $this->console->assignment('kvm', $id, $this->actor($request));
        if (!$this->console->kvmGuest($assignment)) {
            return new JsonResponse(['error' => 'console.unavailable'], 503, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse(['html' => $this->renderView('infrastructure/_usage_pair.html.twig', ['serverUsage' => $usage->kvm($assignment->getNode(), $assignment->getVmUuid()), 'nodeUsage' => $usage->node($assignment->getNode())])], 200, ['Cache-Control' => 'private, no-store']);
    }
}
