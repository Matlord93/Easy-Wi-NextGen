<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Webhook;

class WebhookUrlValidator
{
    public function validate(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || 'https' !== ($parts['scheme'] ?? null) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URLs must use HTTPS and must not contain credentials.');
        }
        $host = strtolower((string) $parts['host']);
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }
        if ($addresses === []) {
            throw new \InvalidArgumentException('Webhook host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Webhook URLs must not target private or reserved networks.');
            }
        }

        return $addresses[0];
    }
}
