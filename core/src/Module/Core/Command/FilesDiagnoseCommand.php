<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\SftpFileService;
use App\Module\PanelCustomer\Application\SftpFilesystemService;
use App\Repository\InstanceRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Repository\WebspaceRepository;
use App\Repository\WebspaceSftpCredentialRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:files:diagnose',
    description: 'Diagnose file manager storage configuration and SFTP connectivity.',
)]
final class FilesDiagnoseCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly WebspaceSftpCredentialRepository $webspaceSftpCredentialRepository,
        private readonly FileServiceClient $fileServiceClient,
        private readonly SftpFileService $sftpFileService,
        private readonly SftpFilesystemService $sftpFilesystemService,
        private readonly AppSettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('instance-id', null, InputOption::VALUE_REQUIRED, 'Instance ID to diagnose')
            ->addOption('webspace-id', null, InputOption::VALUE_REQUIRED, 'Webspace ID to diagnose')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Relative path to probe', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = trim((string) $input->getOption('path'));
        $instanceId = $input->getOption('instance-id');
        $webspaceId = $input->getOption('webspace-id');
        $settings = $this->settingsService->getSettings();

        $io->title('File manager diagnostics');

        $host = $this->settingsService->getSftpHost();
        $port = $this->settingsService->getSftpPort();
        $username = $this->settingsService->getSftpUsername();
        $passwordSet = $this->settingsService->getSftpPassword() !== null;
        $keySet = $this->settingsService->getSftpPrivateKey() !== null;
        $keyPathSet = $this->settingsService->getSftpPrivateKeyPath() !== null;

        $io->section('SFTP settings');
        $io->definitionList(
            ['host' => $host ?? '<not set>'],
            ['host source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_HOST, $settings)],
            ['port' => (string) $port],
            ['port source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_PORT, $settings)],
            ['username' => $username ?? '<not set>'],
            ['username source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_USERNAME, $settings)],
            ['password set' => $passwordSet ? 'yes' : 'no'],
            ['private key set' => $keySet ? 'yes' : 'no'],
            ['private key path set' => $keyPathSet ? 'yes' : 'no'],
        );

        $overallOk = true;
        $hasTarget = false;

        if ($instanceId !== null && $instanceId !== '') {
            $hasTarget = true;
            $instance = $this->instanceRepository->find($instanceId);
            if ($instance === null) {
                $io->error(sprintf('Instance %s not found.', (string) $instanceId));
                $overallOk = false;
            } else {
                $io->section('Instance file manager');
                $io->text('Primary: FileServiceClient (filesvc). Fallback: SftpFileService (phpseclib).');
                $io->text(sprintf('Instance ID: %d | Node ID: %d', $instance->getId(), $instance->getNode()->getId()));

                $metadata = $instance->getNode()->getMetadata();
                $metadata = is_array($metadata) ? $metadata : [];
                $io->definitionList(
                    ['filesvc_url' => $metadata['filesvc_url'] ?? '<unset>'],
                    ['filesvc_host' => $metadata['filesvc_host'] ?? '<unset>'],
                    ['filesvc_port' => $metadata['filesvc_port'] ?? '<unset>'],
                    ['filesvc_scheme' => $metadata['filesvc_scheme'] ?? '<unset>'],
                    ['sftp_host_override' => $metadata['sftp_host'] ?? '<unset>'],
                    ['sftp_port_override' => $metadata['sftp_port'] ?? '<unset>'],
                    ['sftp_username_override' => $metadata['sftp_username'] ?? '<unset>'],
                );

                $instanceCredential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
                $io->text(sprintf(
                    'Instance SFTP credential: %s',
                    $instanceCredential === null ? 'missing' : sprintf('present (%s)', $instanceCredential->getUsername()),
                ));

                $filesvcProbe = $this->runInstanceProbe('filesvc', $instance, $path);
                $sftpProbe = $this->runInstanceProbe('sftp', $instance, $path);

                $io->table(
                    ['Source', 'Status', 'Message', 'Entry count'],
                    [
                        [$filesvcProbe['source'], $filesvcProbe['status'], $filesvcProbe['message'], $filesvcProbe['entry_count']],
                        [$sftpProbe['source'], $sftpProbe['status'], $sftpProbe['message'], $sftpProbe['entry_count']],
                    ],
                );

                if ($filesvcProbe['status'] !== 'ok' || $sftpProbe['status'] !== 'ok') {
                    $overallOk = false;
                }
            }
        }

        if ($webspaceId !== null && $webspaceId !== '') {
            $hasTarget = true;
            $webspace = $this->webspaceRepository->find($webspaceId);
            if ($webspace === null) {
                $io->error(sprintf('Webspace %s not found.', (string) $webspaceId));
                $overallOk = false;
            } else {
                $io->section('Webspace file manager');
                $io->text('Uses SftpFilesystemService (Flysystem SFTP adapter).');
                $io->text(sprintf('Webspace ID: %d | Path: %s', $webspace->getId(), $webspace->getPath()));

                $webspaceCredential = $this->webspaceSftpCredentialRepository->findOneByWebspace($webspace);
                $io->text(sprintf(
                    'Webspace SFTP credential: %s',
                    $webspaceCredential === null ? 'missing' : sprintf('present (%s)', $webspaceCredential->getUsername()),
                ));

                $probe = $this->runWebspaceProbe($webspace, $path);
                $io->table(
                    ['Source', 'Status', 'Message', 'Entry count'],
                    [
                        [$probe['source'], $probe['status'], $probe['message'], $probe['entry_count']],
                    ],
                );

                if ($probe['status'] !== 'ok') {
                    $overallOk = false;
                }
            }
        }

        if (!$hasTarget) {
            $io->warning('No instance/webspace provided. Use --instance-id or --webspace-id for active checks.');
            $overallOk = false;
        }

        return $overallOk ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveSettingSource(string $key, array $settings): string
    {
        $envKey = match ($key) {
            AppSettingsService::KEY_SFTP_HOST => 'EASYWI_SFTP_HOST',
            AppSettingsService::KEY_SFTP_PORT => 'EASYWI_SFTP_PORT',
            AppSettingsService::KEY_SFTP_USERNAME => 'EASYWI_SFTP_USERNAME',
            AppSettingsService::KEY_SFTP_PASSWORD => 'EASYWI_SFTP_PASSWORD',
            AppSettingsService::KEY_SFTP_PRIVATE_KEY => 'EASYWI_SFTP_PRIVATE_KEY',
            AppSettingsService::KEY_SFTP_PRIVATE_KEY_PATH => 'EASYWI_SFTP_PRIVATE_KEY_PATH',
            AppSettingsService::KEY_SFTP_PRIVATE_KEY_PASSPHRASE => 'EASYWI_SFTP_PRIVATE_KEY_PASSPHRASE',
            default => strtoupper($key),
        };
        $env = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? null;
        if (is_string($env) && $env !== '') {
            return 'env';
        }

        $value = $settings[$key] ?? null;
        if ($value !== null && $value !== '') {
            return 'settings';
        }

        return 'default';
    }

    /**
     * @return array{source: string, status: string, message: string, entry_count: int}
     */
    private function runInstanceProbe(string $source, \App\Module\Core\Domain\Entity\Instance $instance, string $path): array
    {
        try {
            $listing = $source === 'filesvc'
                ? $this->fileServiceClient->list($instance, $path)
                : $this->sftpFileService->list($instance, $path);

            return [
                'source' => $source,
                'status' => 'ok',
                'message' => 'OK',
                'entry_count' => count($listing['entries'] ?? []),
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('files.diagnose_failed', [
                'source' => $source,
                'instance_id' => $instance->getId(),
                'path' => $path,
                'exception' => $exception,
            ]);

            return [
                'source' => $source,
                'status' => 'error',
                'message' => $exception->getMessage(),
                'entry_count' => 0,
            ];
        }
    }

    /**
     * @return array{source: string, status: string, message: string, entry_count: int}
     */
    private function runWebspaceProbe(\App\Module\Core\Domain\Entity\Webspace $webspace, string $path): array
    {
        try {
            $listing = $this->sftpFilesystemService->list($webspace, $path);

            return [
                'source' => 'sftp',
                'status' => 'ok',
                'message' => 'OK',
                'entry_count' => count($listing),
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('files.diagnose_failed', [
                'source' => 'sftp',
                'webspace_id' => $webspace->getId(),
                'path' => $path,
                'exception' => $exception,
            ]);

            return [
                'source' => 'sftp',
                'status' => 'error',
                'message' => $exception->getMessage(),
                'entry_count' => 0,
            ];
        }
    }
}
