<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use Symfony\Component\Process\Process;

final class MusicbotProcessRunner
{
    /**
     * A command vector bypasses the shell on both Linux and Windows. Symfony
     * drains both pipes and checks timeouts even when the child writes nothing.
     *
     * @param list<string> $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function run(array $args, float $timeout, int $maxBytes = 8388608): array
    {
        if ([] === $args || $timeout <= 0 || $maxBytes < 1) {
            throw new \InvalidArgumentException('Invalid process limits.');
        }
        $process = new Process($args, timeout: $timeout);
        $stdout = '';
        $stderr = '';
        try {
            $exit = $process->run(static function (string $type, string $chunk) use (&$stdout, &$stderr, $process, $maxBytes): void {
                if (Process::OUT === $type) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
                if (strlen($stdout) + strlen($stderr) > $maxBytes) {
                    throw new \RuntimeException('yt-dlp output exceeds the configured limit.');
                }
            });
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        return ['exit_code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
