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

        $activeTab = $this->resolveTab((string) $request->query->get('tab', 'general'));

        return new Response($this->twig->render('admin/settings/index.html.twig', [
            'activeNav' => 'settings',
            'settings' => $this->settingsService->getSettings(),
            'activeTab' => $activeTab,
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

        $activeTab = $this->resolveTab((string) $request->request->get('tab', 'general'));
        $sftpPortRaw = trim((string) $request->request->get('sftp_port', ''));
        $supportEmail = trim((string) $request->request->get('support_email', ''));
        $mailFromAddress = trim((string) $request->request->get('mail_from_address', ''));
        $mailReplyTo = trim((string) $request->request->get('mail_reply_to', ''));
        $brandingLogoUrl = trim((string) $request->request->get('branding_logo_url', ''));
        $defaultLocale = trim((string) $request->request->get('mail_default_locale', 'en'));
        $defaultSlotsRaw = trim((string) $request->request->get('gameserver_default_slots', ''));
        $minSlotsRaw = trim((string) $request->request->get('gameserver_min_slots', ''));
        $maxSlotsRaw = trim((string) $request->request->get('gameserver_max_slots', ''));
        $errors = [];
        if ($sftpPortRaw !== '' && !is_numeric($sftpPortRaw)) {
            $errors[] = 'SFTP port must be numeric.';
        }

        if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Support email must be valid.';
        }
        if ($mailFromAddress !== '' && !filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Mail from address must be valid.';
        }
        if ($mailReplyTo !== '' && !filter_var($mailReplyTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Reply-to address must be valid.';
        }
        if ($brandingLogoUrl !== '' && !$this->isValidLogoPath($brandingLogoUrl)) {
            $errors[] = 'Logo URL must be a valid URL or relative path.';
        }
        if ($defaultLocale !== '' && !in_array($defaultLocale, ['de', 'en'], true)) {
            $errors[] = 'Default mail locale must be de or en.';
        }

        $defaultSlots = $this->parseOptionalInt($defaultSlotsRaw, 'Default slots must be numeric.', $errors);
        $minSlots = $this->parseOptionalInt($minSlotsRaw, 'Min slots must be numeric.', $errors);
        $maxSlots = $this->parseOptionalInt($maxSlotsRaw, 'Max slots must be numeric.', $errors);

        if ($defaultSlots !== null && $defaultSlots < 1) {
            $errors[] = 'Default slots must be at least 1.';
        }
        if ($minSlots !== null && $minSlots < 1) {
            $errors[] = 'Min slots must be at least 1.';
        }
        if ($maxSlots !== null && $maxSlots < 1) {
            $errors[] = 'Max slots must be at least 1.';
        }
        if ($minSlots !== null && $maxSlots !== null && $minSlots > $maxSlots) {
            $errors[] = 'Min slots cannot exceed max slots.';
        }
        if ($defaultSlots !== null && $minSlots !== null && $defaultSlots < $minSlots) {
            $errors[] = 'Default slots cannot be lower than min slots.';
        }
        if ($defaultSlots !== null && $maxSlots !== null && $defaultSlots > $maxSlots) {
            $errors[] = 'Default slots cannot exceed max slots.';
        }

        if ($errors !== []) {
            return new Response($this->twig->render('admin/settings/index.html.twig', [
                'activeNav' => 'settings',
                'settings' => $this->settingsService->getSettings(),
                'activeTab' => $activeTab,
                'saved' => false,
                'errors' => $errors,
            ]), Response::HTTP_BAD_REQUEST);
        }

        $this->settingsService->updateSettings([
            AppSettingsService::KEY_SITE_TITLE => trim((string) $request->request->get('site_title', '')),
            AppSettingsService::KEY_BRANDING_NAME => trim((string) $request->request->get('branding_name', '')),
            AppSettingsService::KEY_BRANDING_LOGO_URL => $brandingLogoUrl,
            AppSettingsService::KEY_SUPPORT_EMAIL => $supportEmail,
            AppSettingsService::KEY_MAIL_FROM_NAME => trim((string) $request->request->get('mail_from_name', '')),
            AppSettingsService::KEY_MAIL_FROM_ADDRESS => $mailFromAddress,
            AppSettingsService::KEY_MAIL_REPLY_TO => $mailReplyTo,
            AppSettingsService::KEY_MAIL_DEFAULT_LOCALE => $defaultLocale,
            AppSettingsService::KEY_GAMESERVER_DEFAULT_SLOTS => $defaultSlotsRaw,
            AppSettingsService::KEY_GAMESERVER_MIN_SLOTS => $minSlotsRaw,
            AppSettingsService::KEY_GAMESERVER_MAX_SLOTS => $maxSlotsRaw,
            AppSettingsService::KEY_GAMESERVER_SHOW_PORT_RANGE => $request->request->get('gameserver_show_port_range') === '1',
            AppSettingsService::KEY_GAMESERVER_ALLOW_START_STOP => $request->request->get('gameserver_allow_start_stop') === '1',
            AppSettingsService::KEY_CUSTOMER_DATA_MANAGER_ENABLED => $request->request->get('customer_data_manager_enabled') === '1',
            AppSettingsService::KEY_CUSTOMER_FILE_PUSH_ENABLED => $request->request->get('customer_file_push_enabled') === '1',
            AppSettingsService::KEY_CUSTOMER_CONSOLE_LABEL => trim((string) $request->request->get('customer_console_label', '')),
            AppSettingsService::KEY_CUSTOMER_LOGS_LABEL => trim((string) $request->request->get('customer_logs_label', '')),
            AppSettingsService::KEY_INSTANCE_BASE_DIR => trim((string) $request->request->get('instance_base_dir', '')),
            AppSettingsService::KEY_SFTP_HOST => trim((string) $request->request->get('sftp_host', '')),
            AppSettingsService::KEY_SFTP_PORT => $sftpPortRaw,
            AppSettingsService::KEY_SFTP_USERNAME => trim((string) $request->request->get('sftp_username', '')),
            AppSettingsService::KEY_SFTP_PASSWORD => (string) $request->request->get('sftp_password', ''),
            AppSettingsService::KEY_SFTP_PRIVATE_KEY => (string) $request->request->get('sftp_private_key', ''),
            AppSettingsService::KEY_SFTP_PRIVATE_KEY_PATH => trim((string) $request->request->get('sftp_private_key_path', '')),
            AppSettingsService::KEY_SFTP_PRIVATE_KEY_PASSPHRASE => (string) $request->request->get('sftp_private_key_passphrase', ''),
        ]);

        return new RedirectResponse(sprintf('/admin/settings?saved=1&tab=%s', $activeTab));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    private function resolveTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        $allowed = ['general', 'email', 'gameserver', 'customer'];

        return in_array($tab, $allowed, true) ? $tab : 'general';
    }

    private function isValidLogoPath(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param array<int, string> $errors
     */
    private function parseOptionalInt(string $value, string $errorMessage, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            $errors[] = $errorMessage;
            return null;
        }

        return (int) $value;
    }
}
