<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\Console\VmConsoleBroker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/customer/vm-console')]
final class CustomerVmConsoleController extends AbstractController
{
    public function __construct(private readonly VmConsoleBroker $broker)
    {
    }

    #[Route('/{provider<proxmox|kvm>}/{id<\d+>}/ticket', name: 'customer_vm_console_ticket', methods: ['POST'])]
    public function ticket(Request $request, string $provider, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $this->broker->assignment($provider, $id, $actor);
        if (!$this->isCsrfTokenValid('vm-console-'.$provider.'-'.$id, (string) $request->request->get('_token', ''))) {
            throw new AccessDeniedHttpException();
        }
        try {
            return $this->response($this->broker->mint($provider, $id, $actor, $request));
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            return $this->response(['error' => 'console.unavailable'], 503);
        }
    }

    #[Route('/connect', name: 'customer_vm_console_connect', methods: ['POST'])]
    public function connect(Request $request): JsonResponse
    {
        $this->broker->assertGateway($request);
        try {
            return $this->response($this->broker->connect((string) ($request->toArray()['ticket'] ?? ''), $this->actor($request), $request));
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            return $this->response(['error' => 'console.unavailable'], 503);
        }
    }

    #[Route('/validate', name: 'customer_vm_console_validate', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        $this->broker->assertGateway($request);
        try {
            $this->broker->validate((string) ($request->toArray()['session_id'] ?? ''), $this->actor($request), $request);

            return $this->response(['valid' => true]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            return $this->response(['error' => 'console.unavailable'], 503);
        }
    }

    private function actor(Request $request): User
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isCustomer()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }
}
