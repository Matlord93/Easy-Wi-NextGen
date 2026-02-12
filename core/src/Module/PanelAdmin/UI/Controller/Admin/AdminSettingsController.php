<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[Route(path: '/admin/settings')]
final class AdminSettingsController
{
    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
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
            'twoFactorOverview' => $this->resolveTwoFactorOverview($activeTab),
            'agentRegistrationTokenMasked' => $this->settingsService->getAgentRegistrationTokenMasked(),
            'agentRegistrationTokenSet' => $this->settingsService->hasAgentRegistrationTokenInDb(),
            'tokenRotated' => $request->query->getBoolean('tokenRotated'),
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
        $sessionIdleMinutesRaw = trim((string) $request->request->get('security_session_idle_minutes', ''));
        $minSubmitSecondsRaw = trim((string) $request->request->get('anti_abuse_min_submit_seconds', '2'));
        $powDifficultyRaw = trim((string) $request->request->get('anti_abuse_pow_difficulty', '4'));
        $dailyIpLimitRaw = trim((string) $request->request->get('anti_abuse_daily_ip_limit', '20'));
        $maintenanceStartsRaw = trim((string) $request->request->get('cms_maintenance_starts_at', ''));
        $maintenanceEndsRaw = trim((string) $request->request->get('cms_maintenance_ends_at', ''));
        $maintenanceAllowlistRaw = trim((string) $request->request->get('cms_maintenance_allowlist', ''));
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

        if ($activeTab === 'security') {
            if ($sessionIdleMinutesRaw === '' || !is_numeric($sessionIdleMinutesRaw)) {
                $errors[] = 'Session idle timeout must be numeric.';
            } elseif ((int) $sessionIdleMinutesRaw < 5) {
                $errors[] = 'Session idle timeout must be at least 5 minutes.';
            }
            if (!is_numeric($minSubmitSecondsRaw) || (int) $minSubmitSecondsRaw < 1) {
                $errors[] = 'Minimum submit seconds must be numeric and >= 1.';
            }
            if (!is_numeric($powDifficultyRaw) || (int) $powDifficultyRaw < 0 || (int) $powDifficultyRaw > 6) {
                $errors[] = 'PoW difficulty must be between 0 and 6.';
            }
            if (!is_numeric($dailyIpLimitRaw) || (int) $dailyIpLimitRaw < 1) {
                $errors[] = 'Daily IP limit must be numeric and >= 1.';
            }
        }

        $maintenanceStartsAt = null;
        $maintenanceEndsAt = null;
        if ($activeTab === 'maintenance') {
            $maintenanceStartsAt = $this->parseDateTimeInput($maintenanceStartsRaw, 'Maintenance start time is invalid.', $errors);
            $maintenanceEndsAt = $this->parseDateTimeInput($maintenanceEndsRaw, 'Maintenance end time is invalid.', $errors);

            if ($maintenanceStartsAt !== null && $maintenanceEndsAt !== null && $maintenanceEndsAt < $maintenanceStartsAt) {
                $errors[] = 'Maintenance end time must be after start time.';
            }

            $allowlistEntries = $this->normalizeAllowlist($maintenanceAllowlistRaw);
            foreach ($allowlistEntries as $entry) {
                if (!$this->isValidIpOrCidr($entry)) {
                    $errors[] = sprintf('Allowlist entry "%s" is invalid.', $entry);
                }
            }
        }

        if ($errors !== []) {
            return new Response($this->twig->render('admin/settings/index.html.twig', [
                'activeNav' => 'settings',
                'settings' => $this->settingsService->getSettings(),
                'activeTab' => $activeTab,
                'saved' => false,
                'errors' => $errors,
                'twoFactorOverview' => $this->resolveTwoFactorOverview($activeTab),
            'agentRegistrationTokenMasked' => $this->settingsService->getAgentRegistrationTokenMasked(),
            'agentRegistrationTokenSet' => $this->settingsService->hasAgentRegistrationTokenInDb(),
            'tokenRotated' => $request->query->getBoolean('tokenRotated'),
            ]), Response::HTTP_BAD_REQUEST);
        }

        $payload = match ($activeTab) {
            'general' => [
                AppSettingsService::KEY_SITE_TITLE => trim((string) $request->request->get('site_title', '')),
                AppSettingsService::KEY_BRANDING_NAME => trim((string) $request->request->get('branding_name', '')),
                AppSettingsService::KEY_BRANDING_LOGO_URL => $brandingLogoUrl,
                AppSettingsService::KEY_SUPPORT_EMAIL => $supportEmail,
            ],
            'email' => [
                AppSettingsService::KEY_MAIL_FROM_NAME => trim((string) $request->request->get('mail_from_name', '')),
                AppSettingsService::KEY_MAIL_FROM_ADDRESS => $mailFromAddress,
                AppSettingsService::KEY_MAIL_REPLY_TO => $mailReplyTo,
                AppSettingsService::KEY_MAIL_DEFAULT_LOCALE => $defaultLocale,
            ],
            'gameserver' => [
                AppSettingsService::KEY_GAMESERVER_DEFAULT_SLOTS => $defaultSlotsRaw,
                AppSettingsService::KEY_GAMESERVER_MIN_SLOTS => $minSlotsRaw,
                AppSettingsService::KEY_GAMESERVER_MAX_SLOTS => $maxSlotsRaw,
                AppSettingsService::KEY_GAMESERVER_SHOW_PORT_RANGE => $request->request->get('gameserver_show_port_range') === '1',
                AppSettingsService::KEY_GAMESERVER_ALLOW_START_STOP => $request->request->get('gameserver_allow_start_stop') === '1',
                AppSettingsService::KEY_INSTANCE_BASE_DIR => trim((string) $request->request->get('instance_base_dir', '')),
                AppSettingsService::KEY_SFTP_HOST => trim((string) $request->request->get('sftp_host', '')),
                AppSettingsService::KEY_SFTP_PORT => $sftpPortRaw,
                AppSettingsService::KEY_SFTP_USERNAME => trim((string) $request->request->get('sftp_username', '')),
                AppSettingsService::KEY_SFTP_PASSWORD => (string) $request->request->get('sftp_password', ''),
                AppSettingsService::KEY_SFTP_PRIVATE_KEY => (string) $request->request->get('sftp_private_key', ''),
                AppSettingsService::KEY_SFTP_PRIVATE_KEY_PATH => trim((string) $request->request->get('sftp_private_key_path', '')),
                AppSettingsService::KEY_SFTP_PRIVATE_KEY_PASSPHRASE => (string) $request->request->get('sftp_private_key_passphrase', ''),
            ],
            'customer' => [
                AppSettingsService::KEY_CUSTOMER_DATA_MANAGER_ENABLED => $request->request->get('customer_data_manager_enabled') === '1',
                AppSettingsService::KEY_CUSTOMER_FILE_PUSH_ENABLED => $request->request->get('customer_file_push_enabled') === '1',
                AppSettingsService::KEY_CUSTOMER_CONSOLE_LABEL => trim((string) $request->request->get('customer_console_label', '')),
                AppSettingsService::KEY_CUSTOMER_LOGS_LABEL => trim((string) $request->request->get('customer_logs_label', '')),
            ],
            'security' => [
                AppSettingsService::KEY_SECURITY_SESSION_IDLE_MINUTES => $sessionIdleMinutesRaw,
                AppSettingsService::KEY_SECURITY_2FA_GLOBAL_REQUIRED => $request->request->get('security_2fa_required_global') === '1',
                AppSettingsService::KEY_SECURITY_2FA_ADMIN_REQUIRED => $request->request->get('security_2fa_required_admin') === '1',
                AppSettingsService::KEY_SECURITY_2FA_RESELLER_REQUIRED => $request->request->get('security_2fa_required_reseller') === '1',
                AppSettingsService::KEY_SECURITY_2FA_CUSTOMER_REQUIRED => $request->request->get('security_2fa_required_customer') === '1',
                AppSettingsService::KEY_REGISTRATION_ENABLED => $request->request->get('registration_enabled') === '1',
                AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_CONTACT => $request->request->get('anti_abuse_enable_pow_contact') === '1',
                AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_REGISTRATION => $request->request->get('anti_abuse_enable_pow_registration') === '1',
                AppSettingsService::KEY_ANTI_ABUSE_ENABLE_CAPTCHA_REGISTRATION => $request->request->get('anti_abuse_enable_captcha_registration') === '1',
                AppSettingsService::KEY_ANTI_ABUSE_ENABLE_CAPTCHA_CONTACT => $request->request->get('anti_abuse_enable_captcha_contact') === '1',
                AppSettingsService::KEY_ANTI_ABUSE_MIN_SUBMIT_SECONDS => $minSubmitSecondsRaw,
                AppSettingsService::KEY_ANTI_ABUSE_POW_DIFFICULTY => $powDifficultyRaw,
                AppSettingsService::KEY_ANTI_ABUSE_DAILY_IP_LIMIT => $dailyIpLimitRaw,
            ],
            'maintenance' => [
                AppSettingsService::KEY_CMS_MAINTENANCE_ENABLED => $request->request->get('cms_maintenance_enabled') === '1',
                AppSettingsService::KEY_CMS_MAINTENANCE_MESSAGE => trim((string) $request->request->get('cms_maintenance_message', '')),
                AppSettingsService::KEY_CMS_MAINTENANCE_GRAPHIC => trim((string) $request->request->get('cms_maintenance_graphic', '')),
                AppSettingsService::KEY_CMS_MAINTENANCE_ALLOWLIST => implode("\n", $this->normalizeAllowlist($maintenanceAllowlistRaw)),
                AppSettingsService::KEY_CMS_MAINTENANCE_STARTS_AT => $maintenanceStartsAt?->format('Y-m-d\TH:i'),
                AppSettingsService::KEY_CMS_MAINTENANCE_ENDS_AT => $maintenanceEndsAt?->format('Y-m-d\TH:i'),
            ],
            default => [],
        };

        $this->settingsService->updateSettings($payload);

        return new RedirectResponse(sprintf('/admin/settings?saved=1&tab=%s', $activeTab));
    }

    #[Route(path: '/agent-registration-token/rotate', name: 'admin_settings_agent_token_rotate', methods: ['POST'])]
    public function rotateAgentRegistrationToken(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $this->settingsService->rotateAgentRegistrationToken();
        $this->auditLogger->log($actor, 'admin.agent_registration_token.rotated', []);

        return new RedirectResponse($this->urlGenerator->generate('admin_settings', ['tab' => 'agent', 'tokenRotated' => 1]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }

    private function resolveTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        $allowed = ['general', 'email', 'gameserver', 'customer', 'security', 'maintenance', 'agent'];

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

    /**
     * @return array<int, array{id: int, email: string, type: string, totpEnabled: bool}>
     */
    private function resolveTwoFactorOverview(string $activeTab): array
    {
        if ($activeTab !== 'security') {
            return [];
        }

        return $this->userRepository->findTwoFactorOverview();
    }

    /**
     * @param array<int, string> $errors
     */
    private function parseDateTimeInput(string $raw, string $errorMessage, array &$errors): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw) ?: null;
        if ($parsed === null) {
            $errors[] = $errorMessage;
        }

        return $parsed;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAllowlist(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $entry): bool => $entry !== ''));
    }

    private function isValidIpOrCidr(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (!str_contains($entry, '/')) {
            return false;
        }

        [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);
        if ($ip === null || $mask === null) {
            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (!is_numeric($mask)) {
            return false;
        }

        $maskInt = (int) $mask;
        $maxMask = str_contains($ip, ':') ? 128 : 32;
        if ($maskInt < 0 || $maskInt > $maxMask) {
            return false;
        }

        return IpUtils::checkIp($ip, $entry);
    }
}
