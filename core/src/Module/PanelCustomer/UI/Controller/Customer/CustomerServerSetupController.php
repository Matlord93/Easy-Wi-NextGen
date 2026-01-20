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
final class CustomerServerSetupController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(path: '/{id}/setup', name: 'customer_server_setup', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToInstanceDetail($instance->getId() ?? 0, ['tab' => 'setup']);
    }

    #[Route(path: '/{id}/setup/vars', name: 'customer_server_setup_vars', methods: ['POST'])]
    public function saveVars(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToInstanceSetupAction('customer_instance_setup_vars', $instance->getId() ?? 0);
    }

    #[Route(path: '/{id}/setup/secrets', name: 'customer_server_setup_secrets', methods: ['POST'])]
    public function saveSecrets(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToInstanceSetupAction('customer_instance_setup_secrets', $instance->getId() ?? 0);
    }

    private function redirectToInstanceSetupAction(string $routeName, int $id): Response
    {
        return new Response('', Response::HTTP_TEMPORARY_REDIRECT, [
            'Location' => $this->urlGenerator->generate($routeName, ['id' => $id]),
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
