<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Exception\FileServiceException;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Domain\Entity\Agent;
use App\Repository\InstanceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:diagnose:files',
    description: 'End-to-end diagnostics for the file manager (agent file API).',
)]
final class FilesAgentDiagnoseCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly FileServiceClient $fileServiceClient,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server/Instance ID to diagnose')
            ->addOption('server-id', null, InputOption::VALUE_REQUIRED, 'Server/Instance ID to diagnose')
            ->addOption('instance-id', null, InputOption::VALUE_REQUIRED, 'Instance ID to diagnose')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Relative path to probe', '.')
            ->addOption('read-path', null, InputOption::VALUE_OPTIONAL, 'Optional file path to read', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serverId = (string) $input->getOption('server');
        if ($serverId === '') {
            $serverId = (string) $input->getOption('server-id');
        }
        $instanceId = (string) $input->getOption('instance-id');
        $resolvedId = $serverId !== '' ? $serverId : $instanceId;
        $path = (string) $input->getOption('path');
        $readPath = (string) $input->getOption('read-path');

        if ($resolvedId === '') {
            $io->error('Missing --server/--server-id or --instance-id.');
            return Command::INVALID;
        }

        $instance = $this->instanceRepository->find($resolvedId);
        if ($instance === null) {
            $io->error(sprintf('Instance %s not found.', $resolvedId));
            return Command::FAILURE;
        }

        $io->title('File manager diagnostics');

        $agent = $instance->getNode();
        $io->section('Agent health');
        $agentHealth = $this->probeAgentHealth($agent);
        if ($agentHealth['status'] === 'ok') {
            $io->success(sprintf(
                'Agent health OK (%s, %d ms, HTTP %s).',
                $agentHealth['url'] ?? '',
                $agentHealth['latency_ms'] ?? 0,
                $agentHealth['status_code'] ?? 'n/a',
            ));
            if (is_array($agentHealth['body'] ?? null)) {
                $io->text(sprintf('Agent version: %s', (string) ($agentHealth['body']['version'] ?? 'unknown')));
                $io->text(sprintf('File API enabled: %s', ($agentHealth['body']['file_api'] ?? false) ? 'yes' : 'no'));
            }
        } else {
            $io->warning(sprintf(
                'Agent health %s (%s, %d ms, HTTP %s).',
                $agentHealth['status'],
                $agentHealth['url'] ?? '',
                $agentHealth['latency_ms'] ?? 0,
                $agentHealth['status_code'] ?? 'n/a',
            ));
        }

        $io->section('File API health');
        $fileApiStart = microtime(true);
        $fileApiHealth = $this->fileServiceClient->ping($instance);
        $fileApiLatency = (int) round((microtime(true) - $fileApiStart) * 1000);
        if ($fileApiHealth['ok']) {
            $io->success(sprintf(
                'File API health OK (%s, %d ms, HTTP %s).',
                $fileApiHealth['url'],
                $fileApiLatency,
                $fileApiHealth['status_code'] ?? 'n/a',
            ));
            if (is_array($fileApiHealth['body'] ?? null)) {
                $io->text(sprintf('File API version: %s', (string) ($fileApiHealth['body']['version'] ?? 'unknown')));
                $io->text(sprintf('File API root: %s', (string) ($fileApiHealth['body']['root'] ?? 'unknown')));
            }
        } else {
            $io->warning(sprintf(
                'File API health failed (%s, %d ms, HTTP %s).',
                $fileApiHealth['url'],
                $fileApiLatency,
                $fileApiHealth['status_code'] ?? 'n/a',
            ));
        }

        $io->section('Signed request headers (masked)');
        $headers = $this->fileServiceClient->getMaskedAuthHeaders(
            $instance,
            'GET',
            sprintf('/v1/servers/%s/files', $instance->getId()),
            ['path' => $path],
        );
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }
        $io->listing($headerLines);

        $io->section('List path');
        try {
            $listing = $this->fileServiceClient->list($instance, $path);
            $entryCount = count($listing['entries'] ?? []);
            $io->success(sprintf('Listed %d entries at "%s".', $entryCount, $listing['path'] ?? ''));
            $io->text('Auth/signature check: OK (list returned 200).');
        } catch (\Throwable $exception) {
            $this->logFailure('files.diagnose.list_failed', $exception, [
                'instance_id' => $resolvedId,
                'path' => $path,
            ]);
            $io->error(sprintf('List failed: %s', $this->describeException($exception)));
            if ($exception instanceof FileServiceException) {
                $io->text(sprintf('Auth/signature check: FAILED (%s).', $exception->getErrorCode()));
            }
            return Command::FAILURE;
        }

        $io->section('Read test file');
        $readTarget = $readPath !== '' ? $readPath : $this->pickReadablePath($listing['entries'] ?? []);
        if ($readTarget === '') {
            $io->warning('No readable file found in listing. Provide --read-path to force a specific file.');
        } else {
            try {
                $content = $this->fileServiceClient->readFileForEditor($instance, dirname($readTarget), basename($readTarget));
                if ($this->isLikelyBinary($content)) {
                    $io->warning(sprintf('Read %d bytes from %s but content looks binary.', strlen($content), $readTarget));
                } else {
                    $io->success(sprintf('Read %d bytes from %s.', strlen($content), $readTarget));
                }
            } catch (\Throwable $exception) {
                $this->logFailure('files.diagnose.read_failed', $exception, [
                    'instance_id' => $resolvedId,
                    'path' => $readTarget,
                ]);
                $io->error(sprintf('Read failed: %s', $this->describeException($exception)));
            }
        }

        $io->section('Write/read-back/delete test file');
        $testName = sprintf('.easywi_diagnose_%s.txt', bin2hex(random_bytes(4)));
        $testContent = sprintf("diagnose ok %s\n", $testName);
        try {
            $this->fileServiceClient->writeFile($instance, $path, $testName, $testContent);
            $readBack = $this->fileServiceClient->readFileForEditor($instance, $path, $testName);
            if ($readBack !== $testContent) {
                throw new \RuntimeException('Read-back verification failed.');
            }
            $this->fileServiceClient->delete($instance, $path, $testName);
            $io->success(sprintf('Wrote, verified, and deleted %s.', $testName));
        } catch (\Throwable $exception) {
            $this->logFailure('files.diagnose.write_failed', $exception, [
                'instance_id' => $resolvedId,
                'path' => $path,
                'name' => $testName,
            ]);
            $io->error(sprintf('Write/read-back/delete failed: %s', $this->describeException($exception)));
            return Command::FAILURE;
        }

        $io->section('Upload/download test file');
        $uploadName = sprintf('.easywi_upload_%s.txt', bin2hex(random_bytes(4)));
        $uploadContent = sprintf("upload ok %s\n", $uploadName);
        $tempPath = tempnam(sys_get_temp_dir(), 'easywi-upload-');
        if ($tempPath === false) {
            $io->error('Failed to create temp file for upload.');
            return Command::FAILURE;
        }
        try {
            file_put_contents($tempPath, $uploadContent);
            $uploaded = new UploadedFile($tempPath, $uploadName, 'text/plain', null, true);
            $this->fileServiceClient->uploadFile($instance, $path, $uploaded);
            $downloaded = $this->fileServiceClient->downloadFile($instance, $path, $uploadName);
            if ($downloaded !== $uploadContent) {
                throw new \RuntimeException('Downloaded content mismatch.');
            }
            $this->fileServiceClient->delete($instance, $path, $uploadName);
            $io->success(sprintf('Uploaded, downloaded, and deleted %s.', $uploadName));
        } catch (\Throwable $exception) {
            $this->logFailure('files.diagnose.upload_failed', $exception, [
                'instance_id' => $resolvedId,
                'path' => $path,
                'name' => $uploadName,
            ]);
            $io->error(sprintf('Upload/download failed: %s', $this->describeException($exception)));
            $this->printUploadHints($io, $exception);
            return Command::FAILURE;
        } finally {
            @unlink($tempPath);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{status: string, url: string|null}
     */
    private function probeAgentHealth(Agent $agent): array
    {
        $baseUrl = $agent->getAgentBaseUrl();
        if ($baseUrl === '') {
            return [
                'status' => 'missing',
                'url' => null,
                'latency_ms' => null,
                'status_code' => null,
                'body' => null,
            ];
        }

        $healthUrl = rtrim($baseUrl, '/') . '/health';
        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->request('GET', $healthUrl, [
                'timeout' => 3,
                'max_duration' => 3,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $decoded = json_decode($body, true);
            if ($status >= 200 && $status < 300) {
                return [
                    'status' => 'ok',
                    'url' => $healthUrl,
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'status_code' => $status,
                    'body' => is_array($decoded) ? $decoded : null,
                ];
            }

            return [
                'status' => 'bad_status',
                'url' => $healthUrl,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'status_code' => $status,
                'body' => is_array($decoded) ? $decoded : null,
            ];
        } catch (TimeoutExceptionInterface $exception) {
            return [
                'status' => 'timeout',
                'url' => $healthUrl,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'status_code' => null,
                'body' => null,
            ];
        } catch (TransportExceptionInterface $exception) {
            return [
                'status' => 'unreachable',
                'url' => $healthUrl,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'status_code' => null,
                'body' => null,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function pickReadablePath(array $entries): string
    {
        foreach ($entries as $entry) {
            if (!is_array($entry) || !empty($entry['is_dir'])) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            return $name;
        }
        return '';
    }

    private function isLikelyBinary(string $content): bool
    {
        if ($content === '') {
            return false;
        }
        if (str_contains($content, "\0")) {
            return true;
        }
        return !preg_match('//u', $content);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFailure(string $message, \Throwable $exception, array $context): void
    {
        if ($exception instanceof FileServiceException) {
            $context = array_merge($context, [
                'error_code' => $exception->getErrorCode(),
                'status_code' => $exception->getStatusCode(),
                'details' => $exception->getDetails(),
            ]);
        }
        $this->logger->error($message, array_merge($context, [
            'exception' => $exception,
        ]));
    }

    private function printUploadHints(SymfonyStyle $io, \Throwable $exception): void
    {
        if ($exception instanceof FileServiceException) {
            $details = $exception->getDetails();
            $statusCode = $details['status_code'] ?? null;
            if ($statusCode === 413) {
                $io->text('Hint: HTTP 413 - check nginx client_max_body_size and PHP post_max_size/upload_max_filesize.');
                return;
            }
            if ($exception->getErrorCode() === 'agent_timeout' || $statusCode === 504) {
                $io->text('Hint: timeout - check fastcgi_read_timeout and PHP max_execution_time.');
                return;
            }
        }
    }

    private function describeException(\Throwable $exception): string
    {
        if ($exception instanceof FileServiceException) {
            $details = $exception->getDetails();
            $statusCode = $details['status_code'] ?? null;
            if ($statusCode !== null) {
                return sprintf('%s (HTTP %s)', $exception->getMessage(), $statusCode);
            }
        }
        return $exception->getMessage();
    }
}
