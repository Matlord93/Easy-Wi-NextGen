<?php

declare(strict_types=1);

namespace App\Module\Voice\Application;

use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Core\Domain\Entity\VoiceRateLimitState;
use Doctrine\ORM\EntityManagerInterface;

final class VoiceProbeGuard
{
    private const MAX_TOKENS = 5.0;
    private const REFILL_PER_SECOND = 0.1;

    public function __construct(
        private readonly VoiceRateLimitStateStoreInterface $stateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array{allowed:bool,retry_after:int,reason:?string,error_code:?string} */
    public function allow(VoiceInstance $instance): array
    {
        $now = new \DateTimeImmutable();
        $node = $instance->getNode();
        $state = $this->findState($instance);
        if (!$state instanceof VoiceRateLimitState) {
            $state = new VoiceRateLimitState($node, $node->getProviderType());
            $this->entityManager->persist($state);
        }

        $circuitOpenUntil = $state->getCircuitOpenUntil();
        if ($circuitOpenUntil !== null && $circuitOpenUntil > $now) {
            return [
                'allowed' => false,
                'retry_after' => max(1, $circuitOpenUntil->getTimestamp() - $now->getTimestamp()),
                'reason' => 'Circuit open.',
                'error_code' => 'voice_circuit_open',
            ];
        }

        $lockedUntil = $state->getLockedUntil();
        if ($lockedUntil !== null && $lockedUntil > $now) {
            return [
                'allowed' => false,
                'retry_after' => max(1, $lockedUntil->getTimestamp() - $now->getTimestamp()),
                'reason' => 'Rate limited.',
                'error_code' => 'voice_rate_limited',
            ];
        }

        $elapsed = max(0, $now->getTimestamp() - $state->getUpdatedAt()->getTimestamp());
        $tokens = min(self::MAX_TOKENS, $state->getTokens() + ((float) $elapsed * self::REFILL_PER_SECOND));

        if ($tokens < 1.0) {
            $missing = 1.0 - $tokens;
            $retryAfter = max(1, (int) ceil($missing / self::REFILL_PER_SECOND));
            $state->setTokens($tokens);
            $state->setLockedUntil($now->modify(sprintf('+%d seconds', $retryAfter)));

            return [
                'allowed' => false,
                'retry_after' => $retryAfter,
                'reason' => 'Rate limited.',
                'error_code' => 'voice_rate_limited',
            ];
        }

        $state->setLockedUntil(null);
        $state->setTokens($tokens - 1.0);

        return ['allowed' => true, 'retry_after' => 0, 'reason' => null, 'error_code' => null];
    }

    public function recordProbeResult(VoiceInstance $instance, string $status, ?string $errorCode): void
    {
        if (in_array($errorCode, ['voice_query_timeout', 'voice_query_banned', 'voice_query_auth_failed', 'voice_query_failed'], true)) {
            $this->registerFailure($instance);
        } elseif ($errorCode === null && in_array($status, ['online', 'offline'], true)) {
            // An offline server is a valid probe result, not a failed query.
            $this->resetFailures($instance);
        }
    }

    public function registerFailure(VoiceInstance $instance): void
    {
        $node = $instance->getNode();
        $state = $this->findState($instance);
        if (!$state instanceof VoiceRateLimitState) {
            $state = new VoiceRateLimitState($node, $node->getProviderType());
            $this->entityManager->persist($state);
        }

        $fails = $state->getConsecutiveFailures() + 1;
        $state->setConsecutiveFailures($fails);
        if ($fails >= 3) {
            $state->setCircuitOpenUntil(new \DateTimeImmutable('+120 seconds'));
        }
    }

    public function resetFailures(VoiceInstance $instance): void
    {
        $state = $this->findState($instance);
        if (!$state instanceof VoiceRateLimitState) {
            return;
        }
        $state->setConsecutiveFailures(0);
        $state->setCircuitOpenUntil(null);
    }

    private function findState(VoiceInstance $instance): ?VoiceRateLimitState
    {
        $node = $instance->getNode();
        $providerType = $node->getProviderType();
        $state = $this->stateRepository->findOneByNodeAndProvider($node, $providerType);
        if ($state !== null) {
            return $state;
        }

        // allow() may have persisted the first state without flushing yet.
        // Doctrine queries do not include scheduled inserts; reuse that managed
        // entity so the token bucket and failure history stay in one row.
        $nodeId = $node->getId();
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if (!$pending instanceof VoiceRateLimitState || $pending->getProviderType() !== $providerType) {
                continue;
            }
            if ($pending->getNode() === $node || ($nodeId !== null && $pending->getNode()->getId() === $nodeId)) {
                return $pending;
            }
        }

        return null;
    }
}
