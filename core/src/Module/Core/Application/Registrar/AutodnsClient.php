<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Registrar;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** AutoDNS JSON API; fixed vendor origins and bounded requests, never arbitrary configured URLs. */
final readonly class AutodnsClient
{
    public function __construct(private HttpClientInterface $http, private RegistrarSettings $settings)
    {
    }

    /** @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    public function call(string $method, string $path, ?array $body = null): array
    {
        $settings = $this->settings->publicSettings();
        if (true !== $settings['enabled']) {
            throw new \DomainException('AUTODNS_DISABLED');
        }
        if (!preg_match('~^/(?:domainstudio|domain(?:/[a-z0-9._/-]+)?|contact(?:/[a-z0-9._/-]+)?|job(?:/history)?/[0-9]+)$~D', $path)) {
            throw new \InvalidArgumentException('Invalid AutoDNS resource.');
        }
        $base = true === $settings['sandbox'] ? 'https://api.demo.autodns.com/v1' : 'https://api.autodns.com/v1';
        $options = ['auth_basic' => [$this->settings->secret('user'), $this->settings->secret('password')], 'headers' => ['Accept' => 'application/json', 'X-Domainrobot-Context' => (string) $settings['context']], 'timeout' => 10, 'max_duration' => 15, 'max_redirects' => 0];
        if (null !== $body) {
            $options['json'] = $body;
        }
        $response = $this->http->request($method, $base.$path, $options);
        $raw = $response->getContent(false);
        if (strlen($raw) > 2_000_000) {
            throw new \DomainException('AUTODNS_INVALID_RESPONSE');
        }
        $data = JsonMap::decode($raw);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || null === $data || (JsonMap::fromValue($data['status'] ?? null)['type'] ?? '') !== 'SUCCESS') {
            throw new \DomainException('AUTODNS_REQUEST_REJECTED');
        }

        return $data;
    }

    /** @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public static function first(array $response): array
    {
        $rows = JsonArray::orEmpty($response['data'] ?? null);

        return JsonMap::fromValue(reset($rows)) ?? [];
    }

    public function available(string $domain): bool
    {
        $response = $this->call('POST', '/domainstudio', ['searchToken' => $domain, 'ignorePremium' => true, 'ignoreMarket' => true, 'sources' => ['initial' => ['domains' => [$domain], 'services' => ['WHOIS']]]]);
        $rows = JsonArray::orEmpty($response['data'] ?? null);
        foreach (array_keys($rows) as $key) {
            $row = JsonMap::fromValue($rows[$key]) ?? [];
            if (($row['domain'] ?? '') !== $domain) {
                continue;
            }
            $whois = JsonMap::fromValue(JsonMap::fromValue($row['services'] ?? null)['whois'] ?? null) ?? [];
            $data = JsonMap::fromValue($whois['data'] ?? null) ?? [];

            return ($whois['status'] ?? '') === 'SUCCESS' && ($data['status'] ?? '') === 'FREE' && '' === ScalarValue::text($data['priceClass'] ?? null);
        }

        return false;
    }
}
