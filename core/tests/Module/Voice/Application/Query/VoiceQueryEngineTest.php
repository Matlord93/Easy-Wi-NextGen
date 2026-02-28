<?php

declare(strict_types=1);

namespace App\Tests\Module\Voice\Application\Query;

use App\Module\Voice\Application\Model\VoiceServer;
use App\Module\Voice\Application\Query\VoiceQueryEngine;
use App\Module\Voice\Application\Query\VoiceQueryException;
use PHPUnit\Framework\TestCase;

final class VoiceQueryEngineTest extends TestCase
{
    public function testItAppliesBackoffDuringRetriesAndThenSucceeds(): void
    {
        $sleepCalls = [];
        $engine = new VoiceQueryEngine(
            maxRetries: 2,
            baseBackoffMs: 10,
            sleep: static function (int $ms) use (&$sleepCalls): void {
                $sleepCalls[] = $ms;
            }
        );

        $server = new VoiceServer('srv-1', 'ts3', '127.0.0.1', 10011, 9987);
        $attempt = 0;
        $result = $engine->execute($server, static function () use (&$attempt): string {
            ++$attempt;
            if ($attempt < 3) {
                throw new \RuntimeException('rate-limited');
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame([10, 20], $sleepCalls);
    }

    public function testItDoesNotRetryNonRetryableOperation(): void
    {
        $engine = new VoiceQueryEngine(maxRetries: 3, sleep: static fn () => null);
        $server = new VoiceServer('srv-create', 'ts3', '127.0.0.1', 10011, 9987);
        $attempt = 0;

        $this->expectException(VoiceQueryException::class);
        $engine->execute($server, static function () use (&$attempt): void {
            ++$attempt;
            throw new \RuntimeException('temporary unavailable');
        }, retryable: false);

        self::assertSame(1, $attempt);
    }

    public function testItDoesNotRetryNonTransientFailuresAndDoesNotTripCircuit(): void
    {
        $now = 1_000;
        $engine = new VoiceQueryEngine(
            maxRetries: 3,
            circuitBreakerFailures: 1,
            circuitBreakerTtlMs: 500,
            clockMs: function () use (&$now): int {
                return $now;
            },
            sleep: static fn () => null,
        );
        $server = new VoiceServer('srv-non-transient', 'ts3', '127.0.0.1', 10011, 9987);

        try {
            $engine->execute($server, static function (): void {
                throw new \InvalidArgumentException('invalid payload');
            });
            self::fail('Expected exception was not thrown.');
        } catch (VoiceQueryException $exception) {
            self::assertStringContainsString('non-transient', $exception->getMessage());
        }

        $result = $engine->execute($server, static fn (): string => 'ok');
        self::assertSame('ok', $result);
    }

    public function testItPreventsReentrantDeadlockWithSingleConcurrencySlot(): void
    {
        $engine = new VoiceQueryEngine();
        $server = new VoiceServer('srv-2', 'ts6', '127.0.0.1', 10022, 9987, 1);

        $result = $engine->execute($server, function () use ($engine, $server): string {
            return $engine->execute($server, static fn (): string => 'nested-ok');
        });

        self::assertSame('nested-ok', $result);
    }

    public function testCircuitBreakerHalfOpenProbeReopensOnFailureThenClosesOnSuccess(): void
    {
        $now = 1_000;
        $engine = new VoiceQueryEngine(
            maxRetries: 0,
            circuitBreakerFailures: 2,
            circuitBreakerTtlMs: 500,
            clockMs: function () use (&$now): int {
                return $now;
            },
            sleep: static fn () => null,
        );
        $server = new VoiceServer('srv-3', 'ts3', '127.0.0.1', 10011, 9987);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $engine->execute($server, static function (): void {
                    throw new \RuntimeException('temporarily unavailable');
                });
            } catch (VoiceQueryException) {
            }
        }

        try {
            $engine->execute($server, static fn (): string => 'never');
            self::fail('Expected open-circuit rejection.');
        } catch (VoiceQueryException $exception) {
            self::assertStringContainsString('Circuit breaker is open', $exception->getMessage());
        }

        $now += 600;
        try {
            $engine->execute($server, static function (): void {
                throw new \RuntimeException('temporarily unavailable');
            });
            self::fail('Expected half-open probe failure.');
        } catch (VoiceQueryException $exception) {
            self::assertStringContainsString('half-open probe', $exception->getMessage());
        }

        try {
            $engine->execute($server, static fn (): string => 'still-open');
            self::fail('Expected open-circuit rejection after failed probe.');
        } catch (VoiceQueryException $exception) {
            self::assertStringContainsString('Circuit breaker is open', $exception->getMessage());
        }
    }

    public function testHalfOpenProbeCanCloseCircuitAgainAfterSuccess(): void
    {
        $now = 1_000;
        $engine = new VoiceQueryEngine(
            maxRetries: 0,
            circuitBreakerFailures: 1,
            circuitBreakerTtlMs: 500,
            clockMs: function () use (&$now): int {
                return $now;
            },
            sleep: static fn () => null,
        );
        $server = new VoiceServer('srv-4', 'ts6', '127.0.0.1', 10022, 9987);

        try {
            $engine->execute($server, static function (): void {
                throw new \RuntimeException('temporarily unavailable');
            });
        } catch (VoiceQueryException) {
        }

        $now += 600;
        self::assertSame('recovered', $engine->execute($server, static fn (): string => 'recovered'));
    }
}
