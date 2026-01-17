<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ServerSftpAccess;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Repository\ServerSftpAccessRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\InstanceFilesystemResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/kunden/servers')]
final class CustomerServerSftpController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly ServerSftpAccessRepository $serverSftpAccessRepository,
        private readonly InstanceFilesystemResolver $filesystemResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/{id}/sftp/enable', name: 'customer_server_sftp_enable', methods: ['POST'])]
    public function enable(Request $request, int $id): Response
    {
        $actor = $this->requireUser($request);
        $instance = $this->findInstance($actor, $id);

        $access = $this->serverSftpAccessRepository->findOneByServer($instance);
        if ($access === null) {
            $access = new ServerSftpAccess($instance, $this->buildUsername($instance), true);
        } else {
            $access->setEnabled(true);
        }

        $access->setPasswordSetAt(new \DateTimeImmutable());
        $this->entityManager->persist($access);

        $password = $this->generatePassword();
        $payload = $this->buildJobPayload($instance, [
            'username' => $access->getUsername(),
            'password' => $password,
            'instance_root' => $this->filesystemResolver->resolveInstanceDir($instance),
            'authorized_keys' => $this->formatKeys($access->getKeys()),
        ]);

        $job = new Job('instance.sftp.access.enable', $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'instance.sftp.access.enabled', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return $this->redirectBack($request, $instance);
    }

    #[Route(path: '/{id}/sftp/reset-password', name: 'customer_server_sftp_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $actor = $this->requireUser($request);
        $instance = $this->findInstance($actor, $id);

        $access = $this->serverSftpAccessRepository->findOneByServer($instance);
        if ($access === null || !$access->isEnabled()) {
            throw new BadRequestHttpException('SFTP access is not enabled.');
        }

        $access->setPasswordSetAt(new \DateTimeImmutable());
        $this->entityManager->persist($access);

        $password = $this->generatePassword();
        $payload = $this->buildJobPayload($instance, [
            'username' => $access->getUsername(),
            'password' => $password,
            'instance_root' => $this->filesystemResolver->resolveInstanceDir($instance),
        ]);

        $job = new Job('instance.sftp.access.reset_password', $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'instance.sftp.access.password_reset', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return $this->redirectBack($request, $instance);
    }

    #[Route(path: '/{id}/sftp/keys', name: 'customer_server_sftp_keys', methods: ['POST'])]
    public function updateKeys(Request $request, int $id): Response
    {
        $actor = $this->requireUser($request);
        $instance = $this->findInstance($actor, $id);

        $keys = $this->parseKeys((string) $request->request->get('keys', ''));

        $access = $this->serverSftpAccessRepository->findOneByServer($instance);
        if ($access === null) {
            $access = new ServerSftpAccess($instance, $this->buildUsername($instance), false, $keys);
        } else {
            $access->setKeys($keys);
        }

        $this->entityManager->persist($access);

        if ($access->isEnabled()) {
            $payload = $this->buildJobPayload($instance, [
                'username' => $access->getUsername(),
                'instance_root' => $this->filesystemResolver->resolveInstanceDir($instance),
                'authorized_keys' => $this->formatKeys($keys),
            ]);

            $job = new Job('instance.sftp.access.keys', $payload);
            $this->entityManager->persist($job);

            $this->auditLogger->log($actor, 'instance.sftp.access.keys_updated', [
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'job_id' => $job->getId(),
            ]);
        } else {
            $this->auditLogger->log($actor, 'instance.sftp.access.keys_saved', [
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
            ]);
        }

        $this->entityManager->flush();

        return $this->redirectBack($request, $instance);
    }

    #[Route(path: '/{id}/sftp/disable', name: 'customer_server_sftp_disable', methods: ['POST'])]
    public function disable(Request $request, int $id): Response
    {
        $actor = $this->requireUser($request);
        $instance = $this->findInstance($actor, $id);

        $access = $this->serverSftpAccessRepository->findOneByServer($instance);
        if ($access === null) {
            throw new NotFoundHttpException('SFTP access not found.');
        }

        if ($access->isEnabled()) {
            $access->setEnabled(false);
            $this->entityManager->persist($access);

            $payload = $this->buildJobPayload($instance, [
                'username' => $access->getUsername(),
                'instance_root' => $this->filesystemResolver->resolveInstanceDir($instance),
            ]);

            $job = new Job('instance.sftp.access.disable', $payload);
            $this->entityManager->persist($job);

            $this->auditLogger->log($actor, 'instance.sftp.access.disabled', [
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'job_id' => $job->getId(),
            ]);
        }

        $this->entityManager->flush();

        return $this->redirectBack($request, $instance);
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        if (!$actor->isAdmin() && $actor->getType() !== UserType::Customer) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }

    private function findInstance(User $actor, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if ($actor->isAdmin()) {
            return $instance;
        }

        if ($instance->getCustomer()->getId() !== $actor->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function buildUsername(Instance $instance): string
    {
        return sprintf('gs_%d', $instance->getId());
    }

    /**
     * @return list<string>
     */
    private function parseKeys(string $rawKeys): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $rawKeys) ?: [];
        $keys = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (strlen($trimmed) > 4096) {
                throw new BadRequestHttpException('SSH key entry is too long.');
            }
            $keys[] = $trimmed;
        }

        $keys = array_values(array_unique($keys));
        if (count($keys) > 20) {
            throw new BadRequestHttpException('Too many SSH keys provided.');
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     */
    private function formatKeys(array $keys): string
    {
        if ($keys === []) {
            return '';
        }

        return implode("\n", $keys) . "\n";
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, string>
     */
    private function buildJobPayload(Instance $instance, array $payload): array
    {
        return array_merge([
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
        ], $payload);
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(12));
    }

    private function redirectBack(Request $request, Instance $instance): RedirectResponse
    {
        $fallback = sprintf('/kunden/servers/%d?tab=files', $instance->getId());

        return new RedirectResponse($request->headers->get('referer', $fallback), Response::HTTP_SEE_OTHER);
    }
}
