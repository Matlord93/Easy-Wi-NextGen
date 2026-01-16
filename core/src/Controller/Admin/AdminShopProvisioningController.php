<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Instance;
use App\Entity\InvoicePreferences;
use App\Entity\User;
use App\Enum\InstanceStatus;
use App\Enum\InstanceUpdatePolicy;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\TemplateRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\DiskEnforcementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AdminShopProvisioningController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly AgentRepository $agentRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLogger $auditLogger,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/admin/shop/provision', name: 'admin_shop_provision', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/shop/provision', name: 'admin_shop_provision_v1', methods: ['POST'])]
    public function provision(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $request->toArray();
        $email = trim((string) ($payload['email'] ?? ''));
        $templateId = $payload['template_id'] ?? null;
        $nodeId = (string) ($payload['node_id'] ?? '');
        $cpuLimitValue = $payload['cpu_limit'] ?? null;
        $ramLimitValue = $payload['ram_limit'] ?? null;
        $diskLimitValue = $payload['disk_limit'] ?? null;
        $portBlockId = $payload['port_block_id'] ?? null;
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $templateId === null || $nodeId === '' || $cpuLimitValue === null || $ramLimitValue === null || $diskLimitValue === null) {
            return new JsonResponse(['error' => 'Missing required fields.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_numeric($cpuLimitValue) || !is_numeric($ramLimitValue) || !is_numeric($diskLimitValue)) {
            return new JsonResponse(['error' => 'Limits must be numeric.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $cpuLimit = (int) $cpuLimitValue;
        $ramLimit = (int) $ramLimitValue;
        $diskLimit = (int) $diskLimitValue;

        if ($cpuLimit <= 0 || $ramLimit <= 0 || $diskLimit <= 0) {
            return new JsonResponse(['error' => 'Limits must be positive.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $customer = $this->userRepository->findOneByEmail($email);
        $isNewCustomer = false;
        $generatedPassword = null;

        if ($customer === null) {
            $customer = new User($email, UserType::Customer);
            if ($password === '') {
                $generatedPassword = bin2hex(random_bytes(12));
                $password = $generatedPassword;
            }
            $customer->setPasswordHash($this->passwordHasher->hashPassword($customer, $password));

            $this->entityManager->persist($customer);
            $preferences = new InvoicePreferences($customer, 'de_DE', true, true, 'manual', 'de');
            $this->entityManager->persist($preferences);
            $this->entityManager->flush();

            $this->auditLogger->log($actor, 'user.created', [
                'user_id' => $customer->getId(),
                'email' => $customer->getEmail(),
                'type' => $customer->getType()->value,
                'source' => 'shop.checkout',
            ]);

            $isNewCustomer = true;
        } elseif ($customer->getType() !== UserType::Customer) {
            return new JsonResponse(['error' => 'User is not a customer.'], JsonResponse::HTTP_CONFLICT);
        }

        $template = $this->templateRepository->find($templateId);
        if ($template === null) {
            return new JsonResponse(['error' => 'Template not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return new JsonResponse(['error' => 'Node not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $blockMessage = $this->diskEnforcementService->guardNodeProvisioning($node, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return new JsonResponse(['error' => $blockMessage], JsonResponse::HTTP_BAD_REQUEST);
        }

        $portBlock = null;
        if ($portBlockId !== null && $portBlockId !== '') {
            $portBlock = $this->portBlockRepository->find((string) $portBlockId);
            if ($portBlock === null) {
                return new JsonResponse(['error' => 'Port block not found.'], JsonResponse::HTTP_NOT_FOUND);
            }
            if ($portBlock->getCustomer()->getId() !== $customer->getId()) {
                return new JsonResponse(['error' => 'Port block does not belong to customer.'], JsonResponse::HTTP_FORBIDDEN);
            }
            if ($portBlock->getInstance() !== null) {
                return new JsonResponse(['error' => 'Port block is already assigned.'], JsonResponse::HTTP_CONFLICT);
            }
            if ($portBlock->getPool()->getNode()->getId() !== $node->getId()) {
                return new JsonResponse(['error' => 'Port block does not belong to selected node.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $instance = new Instance(
            $customer,
            $template,
            $node,
            $cpuLimit,
            $ramLimit,
            $diskLimit,
            $portBlock?->getId(),
            InstanceStatus::PendingSetup,
            InstanceUpdatePolicy::Manual,
        );

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        if ($portBlock !== null) {
            $portBlock->assignInstance($instance);
            $this->entityManager->persist($portBlock);
            $this->auditLogger->log($actor, 'port_block.assigned', [
                'port_block_id' => $portBlock->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $customer->getId(),
            ]);
        }

        $this->auditLogger->log($actor, 'instance.created', [
            'instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'template_id' => $template->getId(),
            'node_id' => $node->getId(),
            'cpu_limit' => $cpuLimit,
            'ram_limit' => $ramLimit,
            'disk_limit' => $diskLimit,
            'port_block_id' => $instance->getPortBlockId(),
            'source' => 'shop.checkout',
        ]);

        $this->auditLogger->log($actor, 'shop.checkout.provisioned', [
            'customer_id' => $customer->getId(),
            'instance_id' => $instance->getId(),
            'template_id' => $template->getId(),
            'node_id' => $node->getId(),
            'email' => $customer->getEmail(),
        ]);

        $this->entityManager->flush();

        $response = [
            'customer_id' => $customer->getId(),
            'instance_id' => $instance->getId(),
            'customer_created' => $isNewCustomer,
        ];

        if ($generatedPassword !== null) {
            $response['generated_password'] = $generatedPassword;
        }

        return new JsonResponse($response, JsonResponse::HTTP_CREATED);
    }

}
