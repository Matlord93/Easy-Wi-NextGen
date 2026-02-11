<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\AbuseLog;
use App\Repository\AbuseLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class LocalAntiAbuseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AbuseLogRepository $abuseLogRepository,
        private readonly AppSettingsService $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registerFormSession(SessionInterface $session, string $formKey): array
    {
        $current = $session->get($this->sessionKey($formKey));
        if (is_array($current) && isset($current['nonce'], $current['issued_at'])) {
            return [
                'nonce' => (string) $current['nonce'],
                'issued_at' => (int) $current['issued_at'],
            ];
        }

        $nonce = bin2hex(random_bytes(16));
        $issuedAt = time();
        $session->set($this->sessionKey($formKey), ['nonce' => $nonce, 'issued_at' => $issuedAt]);

        return ['nonce' => $nonce, 'issued_at' => $issuedAt];
    }

    public function verifyMinTime(SessionInterface $session, string $formKey): bool
    {
        $data = $session->get($this->sessionKey($formKey));
        if (!is_array($data)) {
            return false;
        }

        return (time() - (int) ($data['issued_at'] ?? 0)) >= $this->settings->getAntiAbuseMinSubmitSeconds();
    }

    public function verifyPow(SessionInterface $session, string $formKey, string $solution): bool
    {
        $difficulty = $this->settings->getAntiAbusePowDifficulty();
        if ($difficulty < 1) {
            return true;
        }

        $data = $session->get($this->sessionKey($formKey));
        if (!is_array($data) || !isset($data['nonce'])) {
            return false;
        }

        $nonce = (string) $data['nonce'];
        $hash = hash('sha256', $nonce . $solution);

        return str_starts_with($hash, str_repeat('0', $difficulty));
    }

    public function isIpLocked(Request $request, string $type): bool
    {
        $ipHash = $this->hashIp($request);
        if ($ipHash === null) {
            return false;
        }

        $count = $this->abuseLogRepository->countByTypeAndIpSince(
            $type,
            $ipHash,
            new \DateTimeImmutable('-1 day')
        );

        return $count >= $this->settings->getAntiAbuseDailyIpLimit();
    }

    public function isHoneypotTriggered(Request $request): bool
    {
        return trim((string) $request->request->get('website', '')) !== '';
    }

    public function log(string $type, Request $request, ?string $email = null): void
    {
        $log = new AbuseLog($type);
        $log->setIpHash($this->hashIp($request));
        $ua = (string) $request->headers->get('User-Agent', '');
        $log->setUaHash($ua !== '' ? hash('sha256', $ua) : null);
        $log->setEmailHash($email !== null ? hash('sha256', strtolower(trim($email))) : null);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->logger->warning('Anti abuse event logged.', ['type' => $type, 'ip' => $request->getClientIp()]);
    }

    private function sessionKey(string $formKey): string
    {
        return 'anti_abuse_' . $formKey;
    }

    private function hashIp(Request $request): ?string
    {
        $ip = $request->getClientIp();
        return $ip !== null ? hash('sha256', $ip) : null;
    }
}
