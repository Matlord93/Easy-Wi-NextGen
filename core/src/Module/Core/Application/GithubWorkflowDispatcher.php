<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GithubWorkflowDispatcher
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $repository,
        private readonly string $workflowFile,
        private readonly string $token,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->repository !== '' && $this->workflowFile !== '' && $this->token !== '';
    }

    /**
     * @param array<string, string> $inputs
     */
    public function dispatch(string $ref, array $inputs = []): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('GitHub workflow dispatch is not configured.');
        }

        if ($ref === '') {
            throw new \RuntimeException('Missing ref for GitHub workflow dispatch.');
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
            $this->repository,
            $this->workflowFile,
        );

        $payload = ['ref' => $ref];
        if ($inputs !== []) {
            $payload['inputs'] = $inputs;
        }

        $response = $this->client->request('POST', $url, [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => sprintf('Bearer %s', $this->token),
                'User-Agent' => 'Easy-Wi-NextGen',
            ],
            'json' => $payload,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('GitHub dispatch failed with status %d.', $status));
        }
    }
}
