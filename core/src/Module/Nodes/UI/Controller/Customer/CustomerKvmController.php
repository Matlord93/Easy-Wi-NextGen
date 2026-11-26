<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\Console\VmConsoleBroker;
use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Kvm\CustomerKvmService;
use App\Module\Nodes\Application\VmPasswordDelivery;
use App\Module\Nodes\Application\VmRootPassword;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[\App\Module\Core\Attribute\RequiresModule('kvm')]
#[Route('/customer/kvm')]
final class CustomerKvmController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VmConsoleBroker $console, private readonly CustomerKvmService $kvm, private readonly VmPasswordDelivery $passwords)
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
        $assignment = $this->assignment($id, $actor);
        $guest = $this->kvm->guest($assignment, $actor);
        $consoleReason = $this->console->unavailableReason($guest, $assignment->getNode());

        $passwordState = $this->passwords->state($assignment, fn (string $operation): string => $this->kvm->passwordState($assignment, $actor, $operation));

        return $this->render('customer/kvm/guest.html.twig', ['assignment' => $assignment, 'guest' => $guest, 'jobs' => $this->kvm->recent($assignment, $actor), 'passwordState' => $passwordState, 'operationId' => Uuid::v4()->toRfc4122(), 'consoleConfigured' => null === $consoleReason, 'consoleUnavailableReason' => $consoleReason, 'activeNav' => 'kvm'], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/{id<\d+>}/action', name: 'customer_kvm_action', methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->assignment($id, $actor);
        if (!$this->isCsrfTokenValid('kvm-customer-'.$id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
        $action = (string) $request->request->get('action', '');
        if ('vm.root_password' === $action) {
            throw new AccessDeniedHttpException();
        }
        $submitted = false;
        try {
            $input = $request->request->all();
            if ('vm.reinstall' === $action && '1' === ($input['prepare_root_password'] ?? null)) {
                $operation = $this->passwords->queueChange($assignment, VmRootPassword::prepare(),
                    fn (string $hash): string => $this->kvm->submit($assignment, $actor, $action, $input, $hash)->getId());
                $job = $this->em->find(AgentJob::class, $operation) ?? throw new \LogicException('The queued KVM installation job was not found.');
            } else {
                $job = $this->kvm->submit($assignment, $actor, $action, $input);
            }
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['job_id' => $job->getId(), 'status' => $job->getStatus()->value], 202, ['Cache-Control' => 'private, no-store']);
            }
            $submitted = true;
        } catch (\InvalidArgumentException $exception) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'kvm.customer_unavailable'], 422, ['Cache-Control' => 'private, no-store']);
            }
            $this->addFlash('kvm_customer_error', str_starts_with($exception->getMessage(), 'kvm.') || str_starts_with($exception->getMessage(), 'vm_config.') ? $exception->getMessage() : 'kvm.customer_unavailable');
        }
        $pane = match (true) {
            str_starts_with($action, 'snapshot.') => 'snapshots',
            'backup.restore' === $action && $submitted => 'activity',
            str_starts_with($action, 'backup.') => 'backups',
            in_array($action, ['vm.cloud_init', 'vm.boot_order'], true) => 'settings',
            in_array($action, ['vm.reinstall', 'vm.address_switch'], true) => 'activity',
            default => 'overview',
        };

        return $this->redirectToRoute('customer_kvm_guest', ['id' => $id, '_fragment' => 'kvm-'.$pane], 303);
    }

    #[Route('/{id<\d+>}/root-password', name: 'customer_kvm_root_password', methods: ['POST'])]
    public function rootPassword(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->assignment($id, $actor);
        if (!$this->isCsrfTokenValid('kvm-customer-'.$id, $request->request->getString('_token')) || '1' !== $request->request->getString('confirm')) {
            throw new AccessDeniedHttpException();
        }
        try {
            $credentials = VmRootPassword::prepare($request->request->getString('password'), $request->request->getString('password_confirm'));
            $this->passwords->queueChange($assignment, $credentials, fn (string $hash): string => $this->kvm->submit($assignment, $actor, 'vm.root_password', [
                'operation_id' => Uuid::v4()->toRfc4122(), 'confirm' => '1', 'password_hash' => $hash,
            ])->getId());
        } catch (\InvalidArgumentException $exception) {
            $key = $exception->getMessage();
            $this->addFlash('kvm_customer_error', str_starts_with($key, 'vm_config.') || str_starts_with($key, 'kvm.') ? $key : 'kvm.customer_unavailable');
        } catch (\RuntimeException) {
            $this->addFlash('kvm_customer_error', 'kvm.customer_unavailable');
        }

        return $this->redirectToRoute('customer_kvm_guest', ['id' => $id, '_fragment' => 'kvm-access'], 303);
    }

    #[Route('/{id<\d+>}/root-password/reveal', name: 'customer_kvm_root_password_reveal', methods: ['POST'])]
    public function revealRootPassword(Request $request, int $id): Response
    {
        $actor = $this->actor($request);
        $assignment = $this->assignment($id, $actor);
        if (!$this->isCsrfTokenValid('kvm-customer-'.$id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        try {
            $password = $this->passwords->reveal($assignment, fn (string $operation): string => $this->kvm->passwordState($assignment, $actor, $operation));
        } catch (\InvalidArgumentException|\RuntimeException) {
            $this->addFlash('kvm_customer_error', 'vm_config.root_password_not_revealable');

            return $this->redirectToRoute('customer_kvm_guest', ['id' => $id, '_fragment' => 'kvm-access'], 303);
        }
        return $this->render('customer/vm/root-password.html.twig', [
            'password' => $password, 'back' => $this->generateUrl('customer_kvm_guest', ['id' => $id, '_fragment' => 'kvm-access']), 'activeNav' => 'kvm',
        ], new Response(headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
    }

    #[Route('/{id<\d+>}/jobs/{jobId}', name: 'customer_kvm_job', methods: ['GET'])]
    public function job(Request $request, int $id, string $jobId): JsonResponse
    {
        $assignment = $this->assignment($id, $this->actor($request));
        $job = $this->em->find(AgentJob::class, $jobId);
        if (!$job || !$this->kvm->ownsJob($assignment, $job)) {
            throw $this->createNotFoundException();
        }

        return new JsonResponse($this->kvm->jobView($job), 200, ['Cache-Control' => 'private, no-store']);
    }

    private function assignment(int $id, User $actor): KvmGuestAssignment
    {
        $assignment = $this->console->assignment('kvm', $id, $actor);
        if (!$assignment instanceof KvmGuestAssignment) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    private function actor(Request $request): User
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isCustomer()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    #[Route('/{id<\d+>}/usage', name: 'customer_kvm_usage', methods: ['GET'])]
    public function usage(Request $request, int $id, InfrastructureUsage $usage): JsonResponse
    {
        $assignment = $this->assignment($id, $this->actor($request));
        $guest = $this->console->kvmGuest($assignment);
        if ($guest === null || $guest === []) {
            return new JsonResponse(['error' => 'console.unavailable'], 503, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse(['html' => $this->renderView('infrastructure/_server_usage.html.twig', ['serverUsage' => $usage->kvm($assignment->getNode(), $assignment->getVmUuid())])], 200, ['Cache-Control' => 'private, no-store']);
    }
}
