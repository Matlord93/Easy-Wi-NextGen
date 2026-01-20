<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(path: '/kunden/servers')]
final class CustomerServerPanelController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(path: '', name: 'customer_server_panel_list', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireCustomer($request);

        return $this->redirectToInstances();
    }

    #[Route(path: '/{id}', name: 'customer_server_panel_detail', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToInstanceDetail($instance->getId() ?? 0);
    }

    #[Route(path: '/{id}/activity', name: 'customer_server_panel_activity', methods: ['GET'])]
    public function activity(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToInstanceDetail($instance->getId() ?? 0, ['tab' => 'tasks']);
    }

    #[Route(path: '/{id}/logs/download', name: 'customer_server_panel_logs_download', methods: ['GET'])]
    public function downloadLogs(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToInstanceDetail($instance->getId() ?? 0, ['tab' => 'console']);
    }

    #[Route(path: '/{id}/logs/stream', name: 'customer_server_panel_logs_stream', methods: ['GET'])]
    public function streamLogs(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToInstanceDetail($instance->getId() ?? 0, ['tab' => 'console']);
    }

    private function redirectToInstances(): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate('customer_instances'),
        ]);
    }

    private function redirectToInstanceDetail(int $id, array $params = []): Response
    {
        $params = array_merge(['id' => $id], $params);

        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urlGenerator->generate('customer_instance_detail', $params),
        ]);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function resolveLegacyInstance(Request $request, int $id): Instance
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }
}
