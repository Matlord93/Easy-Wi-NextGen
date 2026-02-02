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
        $ssh = new SSH2($sshConfig->host(), $sshConfig->port(), $sshConfig->timeoutSeconds());
        $authenticated = false;

        if ($sshConfig->privateKey() !== null) {
            $key = PublicKeyLoader::load($sshConfig->privateKey());
            $authenticated = $ssh->login($sshConfig->username(), $key);
        } elseif ($sshConfig->password() !== null) {
            $authenticated = $ssh->login($sshConfig->username(), $sshConfig->password());
        }

        if (!$authenticated) {
            throw new QueryTransportException('SSH authentication failed.');
        }

        $payload = $this->buildPayload($commands, $context);
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadPath = $this->writePayloadFile($sshConfig, $payloadJson);
        $command = sprintf(
            '%s --payload-file %s',
            escapeshellcmd($context->runnerPath()),
            escapeshellarg($payloadPath)
        );

        $output = $ssh->exec($command);
        $this->cleanupPayloadFile($sshConfig, $payloadPath);
        $result = $this->parseResult($output);

        return new QueryResponse(
            $result['success'],
            $output,
            $result['payload'],
            $result['error'],
            $result['actionId']
        );
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

    private function writePayloadFile(SshConnectionConfig $sshConfig, string $payloadJson): string
    {
        $path = sprintf('/tmp/ts-query-%s.json', bin2hex(random_bytes(8)));
        $sftp = new SFTP($sshConfig->host(), $sshConfig->port(), $sshConfig->timeoutSeconds());
        if ($sshConfig->privateKey() !== null) {
            $key = PublicKeyLoader::load($sshConfig->privateKey());
            if (!$sftp->login($sshConfig->username(), $key)) {
                throw new QueryTransportException('SFTP authentication failed.');
            }
        } elseif ($sshConfig->password() !== null) {
            if (!$sftp->login($sshConfig->username(), $sshConfig->password())) {
                throw new QueryTransportException('SFTP authentication failed.');
            }
        } else {
            throw new QueryTransportException('Missing SSH credentials for payload upload.');
        }

        if (!$sftp->put($path, $payloadJson)) {
            throw new QueryTransportException('Failed to upload payload file.');
        }

        return $path;
    }

    private function cleanupPayloadFile(SshConnectionConfig $sshConfig, string $path): void
    {
        $sftp = new SFTP($sshConfig->host(), $sshConfig->port(), $sshConfig->timeoutSeconds());
        if ($sshConfig->privateKey() !== null) {
            $key = PublicKeyLoader::load($sshConfig->privateKey());
            if (!$sftp->login($sshConfig->username(), $key)) {
                return;
            }
        } elseif ($sshConfig->password() !== null) {
            if (!$sftp->login($sshConfig->username(), $sshConfig->password())) {
                return;
            }
        } else {
            return;
        }

        $sftp->delete($path);
    }
}
