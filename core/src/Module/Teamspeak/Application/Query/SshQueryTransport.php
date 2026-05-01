<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

final class SshQueryTransport implements QueryTransportInterface
{
    /**
     * @param QueryCommand[] $commands
     */
    public function execute(array $commands, QueryContext $context): QueryResponse
    {
        if ($commands === []) {
            throw new QueryTransportException('No query commands provided.');
        }

        $sshConfig = $context->sshConfig();

        // Use one SFTP session for payload upload, exec, and cleanup to avoid
        // opening 3 separate SSH connections per query (previous behaviour).
        $sftp = new SFTP($sshConfig->host(), $sshConfig->port(), $sshConfig->timeoutSeconds());

        if (!$this->authenticate($sftp, $sshConfig)) {
            // Single retry after a brief delay for transient SSH failures.
            usleep(200_000);
            $sftp = new SFTP($sshConfig->host(), $sshConfig->port(), $sshConfig->timeoutSeconds());
            if (!$this->authenticate($sftp, $sshConfig)) {
                throw new QueryTransportException('SSH authentication failed.');
            }
        }

        $payload = $this->buildPayload($commands, $context);
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadPath = sprintf('/tmp/ts-query-%s.json', bin2hex(random_bytes(8)));

        if (!$sftp->put($payloadPath, $payloadJson)) {
            throw new QueryTransportException('Failed to upload payload file.');
        }

        $command = sprintf(
            '%s --payload-file %s',
            escapeshellcmd($context->runnerPath()),
            escapeshellarg($payloadPath)
        );

        $output = $sftp->exec($command);

        // Best-effort cleanup — do not throw if this fails.
        try {
            $sftp->delete($payloadPath);
        } catch (\Throwable) {
        }

        $result = $this->parseResult((string) $output);

        return new QueryResponse(
            $result['success'],
            (string) $output,
            $result['payload'],
            $result['error'],
            $result['actionId']
        );
    }

    private function authenticate(SFTP $sftp, SshConnectionConfig $sshConfig): bool
    {
        if ($sshConfig->privateKey() !== null) {
            $key = PublicKeyLoader::load($sshConfig->privateKey());
            return $sftp->login($sshConfig->username(), $key);
        }

        if ($sshConfig->password() !== null) {
            return $sftp->login($sshConfig->username(), $sshConfig->password());
        }

        throw new QueryTransportException('Missing SSH credentials.');
    }

    /**
     * @param QueryCommand[] $commands
     * @return array<string, mixed>
     */
    private function buildPayload(array $commands, QueryContext $context): array
    {
        $normalizedCommands = [];
        foreach ($commands as $command) {
            if (!$command instanceof QueryCommand) {
                throw new QueryTransportException('Invalid command payload.');
            }
            $normalizedCommands[] = [
                'command' => $command->command(),
                'args' => $command->args(),
            ];
        }

        return [
            'ts_version' => $context->tsVersion(),
            'sid' => $context->sid(),
            'query_host' => $context->queryHost(),
            'query_port' => $context->queryPort(),
            'query_user' => $context->queryUser(),
            'query_password' => $context->queryPassword(),
            'commands' => $normalizedCommands,
        ];
    }

    /**
     * @return array{success: bool, payload: array<string, mixed>, error: ?QueryError, actionId: ?string}
     */
    private function parseResult(string $output): array
    {
        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'payload' => [],
                'error' => new QueryError('invalid_response', 'Invalid response from query runner.'),
                'actionId' => null,
            ];
        }

        $error = null;
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $code = is_string($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : 'runner_error';
            $message = is_string($decoded['error']['message'] ?? null) ? $decoded['error']['message'] : 'Query runner error.';
            $details = is_array($decoded['error']['details'] ?? null) ? $decoded['error']['details'] : [];
            $error = new QueryError($code, $message, $details);
        }

        return [
            'success' => (bool) ($decoded['ok'] ?? $decoded['success'] ?? false),
            'payload' => is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
            'error' => $error,
            'actionId' => is_string($decoded['actionId'] ?? null) ? $decoded['actionId'] : null,
        ];
    }
}
