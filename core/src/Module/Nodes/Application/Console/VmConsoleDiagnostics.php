<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Console;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class VmConsoleDiagnostics
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(default::VM_CONSOLE_GATEWAY_SECRET)%')] private ?string $secret = null,
    ) {
    }

    /** Never return a secret, upstream address, response body or exception text. */
    public function status(): array
    {
        if (strlen($this->secret ?? '') < 32) {
            return ['state' => 'missing', 'origin' => null];
        }
        $response = null;
        try {
            $response = $this->http->request('GET', 'http://127.0.0.1:7457/vm-console/health', [
                'headers' => ['X-EasyWI-Console-Gateway' => $this->secret],
                'no_proxy' => '127.0.0.1', 'max_redirects' => 0,
                'timeout' => 1, 'max_duration' => 1.5, 'buffer' => false,
                'on_progress' => static function (int $downloaded, int $size, array $info): void {
                    if ($downloaded > 4096 && empty($info['canceled'])) { throw new \RuntimeException('Console health response exceeds limit.'); }
                },
            ]);
            $code = $response->getStatusCode();
            if (403 === $code) {
                return ['state' => 'secret_mismatch', 'origin' => null];
            }
            if (404 === $code) {
                return ['state' => 'update_gateway', 'origin' => null];
            }
            if (200 !== $code) {
                return ['state' => 'unreachable', 'origin' => null];
            }
            $body = $response->getContent(false);
            if (strlen($body) > 4096) {
                return ['state' => 'unreachable', 'origin' => null];
            }
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            $origin = $data['public_origin'] ?? null;
            $parts = is_string($origin) ? parse_url($origin) : false;
            if (!is_array($data) || 'ok' !== ($data['status'] ?? null) || 1 !== ($data['protocol_version'] ?? null) || !is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
                return ['state' => 'update_gateway', 'origin' => null];
            }

            return ['state' => 'ready', 'origin' => $origin];
        } catch (\Throwable) {
            return ['state' => 'unreachable', 'origin' => null];
        } finally {
            $response?->cancel();
        }
    }
}
