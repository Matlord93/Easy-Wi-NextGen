<?php

declare(strict_types=1);

namespace App\Service\Sinusbot;

use App\Entity\SinusbotNode;
use App\Service\SecretsCrypto;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AgentClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretsCrypto $crypto,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    public function request(SinusbotNode $node, string $method, string $path, ?array $json = null): array
    {
        $url = rtrim($node->getAgentBaseUrl(), '/') . '/' . ltrim($path, '/');
        $token = $this->crypto->decrypt($node->getAgentApiTokenEncrypted());

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $options = [
                    'headers' => [
                        'Authorization' => sprintf('Bearer %s', $token),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => $this->timeoutSeconds,
                ];

                if ($json !== null) {
                    $options['json'] = $json;
                }

                $response = $this->httpClient->request($method, $url, $options);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new AgentBadResponseException(sprintf('Agent responded with status %d.', $status));
                }

                $payload = $response->toArray(false);
                if (!is_array($payload)) {
                    throw new AgentBadResponseException('Agent response was not valid JSON.');
                }

                return $payload;
            } catch (TransportExceptionInterface $exception) {
                if ($attempt === 0) {
                    continue;
                }

                throw new AgentUnavailableException('Agent is unavailable.', 0, $exception);
            } catch (HttpExceptionInterface | DecodingExceptionInterface $exception) {
                throw new AgentBadResponseException('Agent returned an invalid response.', 0, $exception);
            }
        }

        throw new AgentUnavailableException('Agent is unavailable.');
    }
}
