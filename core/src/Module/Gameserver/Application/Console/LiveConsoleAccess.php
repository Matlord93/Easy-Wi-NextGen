<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Console;

use App\Module\Core\Application\AgentCredentialsResolver;
use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;

final readonly class LiveConsoleAccess
{
    public function __construct(private AgentEndpointResolver $endpoints, private AgentCredentialsResolver $credentials)
    {
    }

    /** Only the private Nginx auth subrequest receives these values. */
    public function issue(Instance $instance, User $actor, string $origin, int $until): array
    {
        $base = rtrim($this->endpoints->resolveForAgent($instance->getNode())->getBaseUrl(), '/');
        $url = parse_url($base);
        if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true) || preg_match('/[\s\x00-\x1f\x7f]/', $base)) {
            throw new \RuntimeException('Invalid agent console endpoint.');
        }
        $credentials = $this->credentials->resolveForAgent($instance->getNode());
        $now = time();
        if ($until <= $now) {
            throw new \RuntimeException('Console session expired.');
        }
        $claims = [
            'v' => 1, 'scope' => 'gameserver.console.read',
            'agent' => $credentials->getAgentId(), 'instance' => (string) $instance->getId(),
            'actor' => (string) $actor->getId(), 'origin' => $origin,
            'iat' => $now, 'exp' => $now + 30, 'until' => min($until, $now + 300),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = $this->encode(hash_hmac('sha256', 'easywi-console-live-v1.'.$payload, $credentials->getSecret(), true));
        return ['target' => $base.'/v1/console/live/'.$instance->getId(), 'authorization' => 'Bearer '.$payload.'.'.$signature];
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
