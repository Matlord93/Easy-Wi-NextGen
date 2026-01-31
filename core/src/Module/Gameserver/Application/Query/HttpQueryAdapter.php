<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class HttpQueryAdapter implements QueryAdapterInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'http';
    }

    public function query(Instance $instance, QueryContext $context): QueryResult
    {
        $config = $context->getConfig();
        $url = isset($config['url']) ? trim((string) $config['url']) : '';
        if ($url === '') {
            return QueryResult::unavailable('Query URL missing.');
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $exception) {
            return QueryResult::unavailable('Query endpoint unavailable.');
        }

        $payload = $response->toArray(false);
        if (!is_array($payload)) {
            return QueryResult::unavailable('Invalid query response.');
        }

        $players = isset($payload['players']) && is_numeric($payload['players']) ? (int) $payload['players'] : null;
        $maxPlayers = isset($payload['max_players']) && is_numeric($payload['max_players']) ? (int) $payload['max_players'] : null;
        if ($maxPlayers === null && isset($payload['maxPlayers']) && is_numeric($payload['maxPlayers'])) {
            $maxPlayers = (int) $payload['maxPlayers'];
        }

        $status = isset($payload['status']) ? strtolower((string) $payload['status']) : null;
        if ($status === null || $status === '') {
            $status = 'online';
        }

        $capabilities = [
            'players' => array_key_exists('players', $payload),
            'slots' => array_key_exists('max_players', $payload) || array_key_exists('maxPlayers', $payload),
            'map' => array_key_exists('map', $payload),
            'version' => array_key_exists('version', $payload),
            'motd' => array_key_exists('motd', $payload),
        ];

        $normalized = QueryResultNormalizer::build(
            in_array($status, ['online', 'running', 'up'], true),
            'http',
            isset($payload['latency_ms']) && is_numeric($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
            is_string($payload['error'] ?? null) ? (string) $payload['error'] : null,
            [
                'players' => $players,
                'max_players' => $maxPlayers,
                'map' => is_string($payload['map'] ?? null) ? (string) $payload['map'] : null,
                'version' => is_string($payload['version'] ?? null) ? (string) $payload['version'] : null,
                'motd' => is_string($payload['motd'] ?? null) ? (string) $payload['motd'] : null,
            ],
            $capabilities,
        );

        return new QueryResult($status, $players, $maxPlayers, null, $normalized);
    }
}
