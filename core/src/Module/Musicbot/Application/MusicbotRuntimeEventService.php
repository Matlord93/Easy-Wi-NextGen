<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRuntimeEvent;
use App\Repository\MusicbotRuntimeEventRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotRuntimeEventService
{
    private const SENSITIVE_KEYS = [
        'password', 'token', 'secret',
        'server_password', 'channel_password', 'bot_token',
        'api_secret', 'api_key', 'stream_token',
        'runtime_control_token', 'webhook_secret',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotRuntimeEventRepository $eventRepository,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function record(MusicbotInstance $instance, string $type, string $level = 'info', string $message = '', array $context = []): MusicbotRuntimeEvent
    {
        $event = new MusicbotRuntimeEvent($instance, $type, $level, $message, $this->sanitize($context));
        $this->entityManager->persist($event);

        return $event;
    }

    /** @return MusicbotRuntimeEvent[] */
    public function latestForInstance(MusicbotInstance $instance, int $limit = 50): array
    {
        return $this->eventRepository->findLatestForInstance($instance, $limit);
    }

    /** @return MusicbotRuntimeEvent[] */
    public function latestForCustomer(User $customer, int $limit = 100): array
    {
        return $this->eventRepository->findByCustomer($customer, $limit);
    }

    /** @return MusicbotRuntimeEvent[] */
    public function errorsForInstance(MusicbotInstance $instance, int $limit = 50): array
    {
        return $this->eventRepository->findErrorsForInstance($instance, $limit);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function sanitize(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            if ($this->isSensitiveKey($lower)) {
                $sanitized[$key] = '********';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
