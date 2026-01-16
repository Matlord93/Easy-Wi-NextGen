<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\Instance;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Service\EncryptionService;
use App\Service\InstanceInstallService;
use App\Service\SetupChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/kunden/servers')]
final class CustomerServerSetupController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly SetupChecker $setupChecker,
        private readonly EncryptionService $encryptionService,
        private readonly InstanceInstallService $instanceInstallService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/{id}/setup', name: 'customer_server_setup', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        return $this->renderSetup($instance, []);
    }

    #[Route(path: '/{id}/setup/vars', name: 'customer_server_setup_vars', methods: ['POST'])]
    public function saveVars(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $requirements = $this->setupChecker->getCustomerRequirements($instance->getTemplate());
        $input = $request->request->all('vars');
        if (!is_array($input)) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        $errors = [];
        $setupVars = $instance->getSetupVars();

        foreach ($requirements['vars'] as $entry) {
            $key = $entry['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = is_scalar($input[$key]) ? (string) $input[$key] : '';
            if ($value === '' && !$entry['required']) {
                unset($setupVars[$key]);
                continue;
            }

            $validationError = $this->setupChecker->validateRequirementValue($entry, $value);
            if ($validationError !== null) {
                $errors[$key] = $validationError;
                continue;
            }

            $setupVars[$key] = $entry['type'] === 'number' ? (string) $value : $value;
        }

        if ($errors !== []) {
            return $this->renderSetup($instance, [
                'vars' => [
                    'errors' => $errors,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $instance->setSetupVars($setupVars);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->renderSetup($instance, [
            'vars' => [
                'success' => 'Variablen gespeichert.',
            ],
        ]);
    }

    #[Route(path: '/{id}/setup/secrets', name: 'customer_server_setup_secrets', methods: ['POST'])]
    public function saveSecrets(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $requirements = $this->setupChecker->getCustomerRequirements($instance->getTemplate());
        $input = $request->request->all('secrets');
        if (!is_array($input)) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        $errors = [];
        foreach ($requirements['secrets'] as $entry) {
            $key = $entry['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = is_scalar($input[$key]) ? (string) $input[$key] : '';
            if ($value === '') {
                if ($entry['required'] && !$instance->hasSetupSecret($key)) {
                    $errors[$key] = 'Value is required.';
                }
                continue;
            }

            $validationError = $this->setupChecker->validateRequirementValue($entry, $value);
            if ($validationError !== null) {
                $errors[$key] = $validationError;
                continue;
            }

            $payload = $this->encryptionService->encrypt($value);
            $instance->setSetupSecret($key, $payload);
        }

        if ($errors !== []) {
            return $this->renderSetup($instance, [
                'secrets' => [
                    'errors' => $errors,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->renderSetup($instance, [
            'secrets' => [
                'success' => 'Secrets gespeichert.',
            ],
        ]);
    }

    /**
     * @param array{vars?: array{errors?: array<string, string>, success?: string}, secrets?: array{errors?: array<string, string>, success?: string}} $messages
     */
    private function renderSetup(Instance $instance, array $messages, int $statusCode = Response::HTTP_OK): Response
    {
        $status = $this->setupChecker->getSetupStatus($instance);
        $installStatus = $this->instanceInstallService->getInstallStatus($instance);
        $requirements = $this->setupChecker->getCustomerRequirements($instance->getTemplate());
        $setupVars = $instance->getSetupVars();
        $setupSecrets = $instance->getSetupSecrets();

        $varEntries = array_map(function (array $entry) use ($setupVars, $messages): array {
            $key = $entry['key'];

            return [
                'key' => $key,
                'label' => $entry['label'],
                'type' => $entry['type'],
                'required' => $entry['required'],
                'helptext' => $entry['helptext'],
                'value' => $setupVars[$key] ?? '',
                'error' => $messages['vars']['errors'][$key] ?? null,
            ];
        }, $requirements['vars']);

        $secretEntries = array_map(function (array $entry) use ($setupSecrets, $messages): array {
            $key = $entry['key'];

            return [
                'key' => $key,
                'label' => $entry['label'],
                'type' => $entry['type'],
                'required' => $entry['required'],
                'helptext' => $entry['helptext'],
                'is_set' => array_key_exists($key, $setupSecrets),
                'error' => $messages['secrets']['errors'][$key] ?? null,
            ];
        }, $requirements['secrets']);

        $customerKeys = [];
        foreach (array_merge($requirements['vars'], $requirements['secrets']) as $entry) {
            $customerKeys[$entry['key']] = true;
        }
        $missingLabels = [];
        foreach ($status['missing'] as $entry) {
            if (isset($customerKeys[$entry['key']])) {
                $missingLabels[] = $entry['label'];
            }
        }

        return new Response($this->twig->render('customer/servers/server_setup.html.twig', [
            'instance' => $instance,
            'setupStatus' => $status,
            'installStatus' => $installStatus,
            'vars' => $varEntries,
            'secrets' => $secretEntries,
            'missingLabels' => $missingLabels,
            'messages' => [
                'vars' => $messages['vars']['success'] ?? null,
                'secrets' => $messages['secrets']['success'] ?? null,
            ],
            'activeNav' => 'servers',
            'tabs' => $this->buildTabs($instance->getId() ?? 0),
        ]), $statusCode);
    }

    private function buildTabs(int $instanceId): array
    {
        return [
            [
                'key' => 'overview',
                'label' => 'Overview',
                'href' => sprintf('/kunden/servers/%d?tab=overview', $instanceId),
            ],
            [
                'key' => 'setup',
                'label' => 'Setup',
                'href' => sprintf('/kunden/servers/%d/setup', $instanceId),
            ],
            [
                'key' => 'files',
                'label' => 'Files',
                'href' => sprintf('/kunden/servers/%d?tab=files', $instanceId),
            ],
            [
                'key' => 'config',
                'label' => 'Config',
                'href' => sprintf('/kunden/servers/%d?tab=config', $instanceId),
            ],
            [
                'key' => 'logs',
                'label' => 'Logs',
                'href' => sprintf('/kunden/servers/%d?tab=logs', $instanceId),
            ],
            [
                'key' => 'activity',
                'label' => 'Activity',
                'href' => sprintf('/kunden/servers/%d?tab=activity', $instanceId),
            ],
        ];
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findCustomerInstance(User $customer, int $id): Instance
    {
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
