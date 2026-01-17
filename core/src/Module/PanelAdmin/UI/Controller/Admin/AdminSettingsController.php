<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\AppSettingsService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/settings')]
final class AdminSettingsController
{
    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/settings/index.html.twig', [
            'activeNav' => 'settings',
            'settings' => $this->settingsService->getSettings(),
            'saved' => $request->query->getBoolean('saved'),
            'errors' => [],
        ]));
    }

    #[Route(path: '', name: 'admin_settings_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $sftpPortRaw = trim((string) $request->request->get('sftp_port', ''));
        $errors = [];
        if ($sftpPortRaw !== '' && !is_numeric($sftpPortRaw)) {
            $errors[] = 'SFTP port must be numeric.';
        }

        if ($errors !== []) {
            return new Response($this->twig->render('admin/settings/index.html.twig', [
                'activeNav' => 'settings',
                'settings' => $this->settingsService->getSettings(),
                'saved' => false,
                'errors' => $errors,
            ]), Response::HTTP_BAD_REQUEST);
        }

        $this->settingsService->updateSettings([
            AppSettingsService::KEY_INSTANCE_BASE_DIR => trim((string) $request->request->get('instance_base_dir', '')),
            AppSettingsService::KEY_SFTP_HOST => trim((string) $request->request->get('sftp_host', '')),
            AppSettingsService::KEY_SFTP_PORT => $sftpPortRaw,
            AppSettingsService::KEY_SFTP_USERNAME => trim((string) $request->request->get('sftp_username', '')),
            AppSettingsService::KEY_SFTP_PASSWORD => (string) $request->request->get('sftp_password', ''),
            AppSettingsService::KEY_SFTP_PRIVATE_KEY => (string) $request->request->get('sftp_private_key', ''),
            AppSettingsService::KEY_SFTP_PRIVATE_KEY_PATH => trim((string) $request->request->get('sftp_private_key_path', '')),
            AppSettingsService::KEY_SFTP_PRIVATE_KEY_PASSPHRASE => (string) $request->request->get('sftp_private_key_passphrase', ''),
        ]);

        return new RedirectResponse('/admin/settings?saved=1');
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }
}
