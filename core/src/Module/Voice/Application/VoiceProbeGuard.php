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
        $state = $this->stateRepository->findOneByNodeAndProvider($node, $node->getProviderType());
        if (!$state instanceof VoiceRateLimitState) {
            $state = new VoiceRateLimitState($node, $node->getProviderType());
            $this->entityManager->persist($state);
        }

        if ($state->getCircuitOpenUntil() !== null && $state->getCircuitOpenUntil() > $now) {
            return [
                'allowed' => false,
                'retry_after' => max(1, $state->getCircuitOpenUntil()->getTimestamp() - $now->getTimestamp()),
                'reason' => 'Circuit open.',
                'error_code' => 'voice_circuit_open',
            ];
        }

        if ($state->getLockedUntil() !== null && $state->getLockedUntil() > $now) {
            return [
                'allowed' => false,
                'retry_after' => max(1, $state->getLockedUntil()->getTimestamp() - $now->getTimestamp()),
                'reason' => 'Rate limited.',
                'error_code' => 'voice_rate_limited',
            ];
        }

        $elapsed = max(0, $now->getTimestamp() - $state->getUpdatedAt()->getTimestamp());
        $tokens = min(self::MAX_TOKENS, $state->getTokens() + ($elapsed * self::REFILL_PER_SECOND));

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

    public function registerFailure(VoiceInstance $instance): void
    {
        $node = $instance->getNode();
        $state = $this->stateRepository->findOneByNodeAndProvider($node, $node->getProviderType());
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
        $node = $instance->getNode();
        $state = $this->stateRepository->findOneByNodeAndProvider($node, $node->getProviderType());
        if (!$state instanceof VoiceRateLimitState) {
            return;
        }
        $state->setConsecutiveFailures(0);
        $state->setCircuitOpenUntil(null);
    }
}
