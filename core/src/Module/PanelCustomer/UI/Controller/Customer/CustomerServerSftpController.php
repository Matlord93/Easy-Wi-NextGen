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
final class CustomerServerSftpController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(path: '/{id}/sftp/enable', name: 'customer_server_sftp_enable', methods: ['POST'])]
    public function enable(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToFiles($instance->getId() ?? 0);
    }

    #[Route(path: '/{id}/sftp/reset-password', name: 'customer_server_sftp_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToFiles($instance->getId() ?? 0);
    }

    #[Route(path: '/{id}/sftp/keys', name: 'customer_server_sftp_keys', methods: ['POST'])]
    public function updateKeys(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToFiles($instance->getId() ?? 0);
    }

    #[Route(path: '/{id}/sftp/disable', name: 'customer_server_sftp_disable', methods: ['POST'])]
    public function disable(Request $request, int $id): Response
    {
        $instance = $this->resolveLegacyInstance($request, $id);

        return $this->redirectToFiles($instance->getId() ?? 0);
    }

    private function redirectToFiles(int $id): Response
    {
        return new Response('', Response::HTTP_SEE_OTHER, [
            'Location' => $this->urlGenerator->generate('customer_instance_files', ['id' => $id]),
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
