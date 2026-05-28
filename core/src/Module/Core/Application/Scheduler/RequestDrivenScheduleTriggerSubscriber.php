<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Infrastructure\Config\DbConfigProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Process\Process;

final class RequestDrivenScheduleTriggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $kernelEnvironment,
        private readonly DbConfigProvider $configProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (\PHP_SAPI === 'cli' || !$this->configProvider->exists()) {
            return;
        }

        $cacheDir = rtrim($this->projectDir, '/') . '/var/cache';
        if (!is_dir($cacheDir)) {
            return;
        }

        $lockPath = $cacheDir . '/.run_schedules_trigger.lock';
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            return;
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return;
        }

        $marker = $cacheDir . '/.run_schedules_last_trigger';
        $last = is_file($marker) ? (int) @file_get_contents($marker) : 0;
        if ($last > 0 && (time() - $last) < 300) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return;
        }

        $schedulerLockPath = sprintf('%s/easywi-run-schedules.lock', sys_get_temp_dir());
        if (is_file($schedulerLockPath) && time() - (int) @filemtime($schedulerLockPath) < 300) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return;
        }

        @file_put_contents($marker, (string) time());

        try {
            (new Process([PHP_BINARY, 'bin/console', 'app:run-schedules', '--once', '--no-interaction', '--env=' . $this->kernelEnvironment], $this->projectDir, ['EASYWI_PHP_BIN' => PHP_BINARY], null, 20))->start();
        } catch (\Throwable $exception) {
            $this->logger->warning('scheduler.request_trigger_failed', ['exception' => $exception]);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
