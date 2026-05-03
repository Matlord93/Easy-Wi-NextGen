<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Command;

use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandRequest;
use App\Module\Gameserver\Application\Console\ConsoleUnavailableException;
use App\Module\Gameserver\Application\Console\NodeEndpointMissingException;
use App\Module\Gameserver\Infrastructure\Grpc\GrpcConsoleAgentGrpcClient;
use App\Repository\InstanceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Smoke-tests the console pipeline for a given game server instance.
 *
 * Checks (in order):
 *   1. Agent is reachable and returns a valid health response.
 *   2. Console source: log file (universal) or journalctl (systemd).
 *   3. Command socket exists (required for sending commands).
 *   4. Recent console output can be fetched (ring-buffer not empty).
 *   5. Optionally sends a test command and reports the result.
 *
 * Usage:
 *   php bin/console gameserver:console:smoke 42
 *   php bin/console gameserver:console:smoke 42 --send="status"
 */
#[AsCommand(
    name: 'gameserver:console:smoke',
    description: 'Smoke-test the live console pipeline for a game server instance.',
)]
final class ConsoleSmokeCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly ConsoleAgentGrpcClientInterface $grpcClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('instance-id', InputArgument::REQUIRED, 'Numeric instance ID to test')
            ->addOption('send', null, InputOption::VALUE_REQUIRED, 'Send this command to the game server as part of the test (e.g. "status")')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Number of recent console lines to display (default 10)', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $instanceId = (int) $input->getArgument('instance-id');
        $sendCommand = trim((string) ($input->getOption('send') ?? ''));
        $maxLines = max(1, (int) ($input->getOption('lines') ?? 10));

        $io->title(sprintf('Console smoke-test – Instance #%d', $instanceId));

        // ── 0. Instance exists ────────────────────────────────────────────────
        $instance = $this->instanceRepository->find($instanceId);
        if ($instance === null) {
            $io->error(sprintf('Instance #%d not found in the database.', $instanceId));
            return Command::FAILURE;
        }
        $io->writeln(sprintf('  Instance : <info>%s</info> (ID %d)', $instance->getServerName() ?? 'unnamed', $instanceId));
        $io->writeln(sprintf('  Node     : <info>%s</info>', $instance->getNode()->getName() ?? 'unknown'));
        $legacyWrapper = sprintf('/etc/systemd/system/gs-%d.service', $instanceId);
        if (is_file($legacyWrapper)) {
            $unitContent = (string) @file_get_contents($legacyWrapper);
            if ($unitContent !== '' && str_contains($unitContent, '/usr/local/bin/easywi-wrapper')) {
                $io->warning('Legacy systemd unit detected (/usr/local/bin/easywi-wrapper). Migrate to easywi-agent --wrapper with --command-socket.');
            }
        }

        // ── 1. Agent health ───────────────────────────────────────────────────
        $io->section('1 · Agent console health');

        if (!$this->grpcClient instanceof GrpcConsoleAgentGrpcClient) {
            $io->warning('Console backend is not the gRPC client (NullClient or alternative). Health check skipped.');
        } else {
            try {
                $health = $this->grpcClient->getConsoleHealth($instanceId);
                $this->printHealth($io, $health);
            } catch (NodeEndpointMissingException $e) {
                $io->error('Agent endpoint not configured for this node. Check node metadata (grpc_endpoint).');
                return Command::FAILURE;
            } catch (\Throwable $e) {
                $io->error(sprintf('Could not reach agent: %s', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        // ── 2. Recent console output ──────────────────────────────────────────
        $io->section('2 · Recent console output');

        if (!$this->grpcClient instanceof GrpcConsoleAgentGrpcClient) {
            $io->warning('Skipped (not a real gRPC client).');
        } else {
            try {
                $logsPayload = $this->grpcClient->getConsoleLogs($instanceId);
                $data = $logsPayload['data'] ?? [];
                $lines = (array) ($data['lines'] ?? []);
                $meta = (array) ($data['meta'] ?? []);

                $state = (string) ($meta['state'] ?? 'unknown');
                $source = (bool) ($meta['log_file_available'] ?? false) ? 'log-file' : 'journalctl';
                $io->writeln(sprintf('  State  : <info>%s</info>', $state));
                $io->writeln(sprintf('  Source : <info>%s</info>', $source));

                if ($lines === []) {
                    $io->comment('Ring buffer is empty – game server may not have produced any output yet.');
                } else {
                    $recent = array_slice($lines, -$maxLines);
                    $io->writeln(sprintf('  Last %d line(s):', count($recent)));
                    foreach ($recent as $line) {
                        $io->writeln(sprintf('    <fg=gray>%s</> %s', $line['ts'] ?? '', $line['text'] ?? ''));
                    }
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Could not fetch console logs: %s', $e->getMessage()));
            }
        }

        // ── 3. Optional: send a command ───────────────────────────────────────
        if ($sendCommand !== '') {
            $io->section(sprintf('3 · Send command: "%s"', $sendCommand));

            try {
                $result = $this->grpcClient->sendCommand(new ConsoleCommandRequest(
                    $instanceId,
                    $sendCommand,
                    bin2hex(random_bytes(8)),
                    (int) floor(microtime(true) * 1000),
                    'smoke-test',
                ));
                $io->success(sprintf(
                    'Command accepted. applied=%s duplicate=%s seq=%s',
                    $result->applied ? 'yes' : 'no',
                    $result->duplicate ? 'yes' : 'no',
                    $result->seq !== null ? (string) $result->seq : 'n/a',
                ));
            } catch (ConsoleUnavailableException $e) {
                $io->error(sprintf('[%s] %s', $e->agentErrorCode, $e->getMessage()));
                $io->note('Hint: start the game server using  agent --wrapper --instance-id ' . $instanceId
                    . ' --command-socket /run/easywi/instances/' . $instanceId . '/console.sock -- <start-script>');
                return Command::FAILURE;
            } catch (\Throwable $e) {
                $io->error(sprintf('Command dispatch failed: %s', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        $io->success('Smoke-test completed.');
        return Command::SUCCESS;
    }

    /** @param array<string,mixed> $health */
    private function printHealth(SymfonyStyle $io, array $health): void
    {
        $data = $health['data'] ?? $health;

        $logAvail  = (bool) ($data['log_file_available'] ?? false);
        $sockExist = (bool) ($data['socket_exists'] ?? false);
        $journalOk = (bool) ($data['journal_available'] ?? false);
        $connected = (bool) (($data['journal_session'] ?? [])['connected'] ?? false);
        $logPath   = (string) ($data['log_file_path'] ?? '/run/easywi/instances/…/console.log');
        $sockPath  = (string) ($data['socket_path'] ?? '/run/easywi/instances/…/console.sock');
        $restarts  = (int) (($data['journal_session'] ?? [])['restarts'] ?? 0);

        $io->definitionList(
            ['Log file available' => $logAvail  ? '<info>YES</info> (' . $logPath . ')' : '<comment>NO</comment> (wrapper not running or game server not started)'],
            ['journalctl available' => $journalOk ? '<info>YES</info>' : '<comment>NO</comment>'],
            ['Command socket exists' => $sockExist ? '<info>YES</info> (' . $sockPath . ')' : '<comment>NO</comment> (commands cannot be sent)'],
            ['Console connected' => $connected  ? '<info>YES</info>' : '<comment>NO</comment>'],
            ['Stream restarts' => $restarts],
        );

        if (!$logAvail && !$journalOk) {
            $io->caution([
                'No console source is available.',
                'Start the game server with the agent wrapper:',
                '  agent --wrapper --instance-id <ID> --command-socket ' . $sockPath . ' -- <start-script>',
            ]);
        } elseif (!$sockExist) {
            $io->warning([
                'Console output is readable but the command socket is missing.',
                'Commands cannot be sent until the game server is started via:',
                '  agent --wrapper --instance-id <ID> --command-socket ' . $sockPath . ' -- <start-script>',
            ]);
        }
    }
}
