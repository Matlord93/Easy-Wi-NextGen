<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
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
use Twig\Environment;

final class CustomerGameserverFilesController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly AppSettingsService $appSettingsService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/instances/{id}/files', name: 'customer_instance_files', methods: ['GET'])]
    public function __invoke(Request $request, int $id): Response
    {
        if (!$this->appSettingsService->isCustomerDataManagerEnabled()) {
            throw new AccessDeniedHttpException('File manager is disabled.');
        }

        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        return new Response($this->twig->render('customer/instances/files/dashboard.html.twig', [
            'instance' => $instance,
            'tabs' => $this->buildTabs((int) ($instance->getId() ?? 0)),
            'activeTab' => 'files',
            'activeNav' => 'instances',
            'pageTitle' => 'File Manager',
            'pageSubtitle' => $instance->getServerName() !== '' ? $instance->getServerName() : $instance->getTemplate()->getDisplayName(),
        ]));
    }


    private function buildTabs(int $instanceId): array
    {
        return [
            [
                'key' => 'overview',
                'label' => 'customer_instance_tab_overview',
                'href' => $this->urlGenerator->generate('customer_instance_overview_page', ['id' => $instanceId]),
            ],
            [
                'key' => 'console',
                'label' => $this->appSettingsService->getCustomerConsoleLabel() ?? 'customer_instance_tab_console',
                'label_is_key' => $this->appSettingsService->getCustomerConsoleLabel() === null,
                'href' => $this->urlGenerator->generate('customer_instance_console_page', ['id' => $instanceId]),
            ],
            [
                'key' => 'setup',
                'label' => 'customer_instance_tab_setup',
                'href' => $this->urlGenerator->generate('customer_instance_detail', ['id' => $instanceId, 'tab' => 'setup']),
            ],
            [
                'key' => 'files',
                'label' => 'customer_instance_tab_files',
                'href' => $this->urlGenerator->generate('customer_instance_files', ['id' => $instanceId]),
            ],
            [
                'key' => 'backups',
                'label' => 'customer_instance_tab_backups',
                'href' => $this->urlGenerator->generate('customer_instance_backups_page', ['id' => $instanceId]),
            ],
            [
                'key' => 'addons',
                'label' => 'customer_instance_tab_addons',
                'href' => $this->urlGenerator->generate('customer_instance_addons_page', ['id' => $instanceId]),
            ],
            [
                'key' => 'tasks',
                'label' => 'customer_instance_tab_tasks',
                'href' => $this->urlGenerator->generate('customer_instance_tasks_page', ['id' => $instanceId]),
            ],
            [
                'key' => 'settings',
                'label' => 'customer_instance_tab_settings',
                'href' => $this->urlGenerator->generate('customer_instance_settings_page', ['id' => $instanceId]),
            ],
            [
                'key' => 'reinstall',
                'label' => 'customer_instance_tab_reinstall',
                'href' => $this->urlGenerator->generate('customer_instance_reinstall_page', ['id' => $instanceId]),
            ],
        ];
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)) {
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

        if (!$customer->isAdmin() && $instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }
}
