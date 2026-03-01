<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class MailDkimKeyGenerator
{
    public function generateSelector(?\DateTimeImmutable $now = null): string
    {
        $timestamp = $now ?? new \DateTimeImmutable();

        return sprintf('mail%s', $timestamp->format('Ym'));
    }

    /**
     * @return array{selector: string, dns_name: string, dns_value: string, fingerprint_sha256: string}
     */
    public function buildDnsMaterial(string $domain, string $publicKeyPem, ?string $selector = null): array
    {
        $normalizedDomain = strtolower(trim($domain));
        if ($normalizedDomain === '') {
            throw new \InvalidArgumentException('Domain must not be empty.');
        }

        $selectorValue = $selector !== null && trim($selector) !== ''
            ? strtolower(trim($selector))
            : $this->generateSelector();

        $publicKeyDns = $this->convertPublicPemToDnsValue($publicKeyPem);

        return [
            'selector' => $selectorValue,
            'dns_name' => sprintf('%s._domainkey.%s', $selectorValue, $normalizedDomain),
            'dns_value' => sprintf('v=DKIM1; k=rsa; p=%s', $publicKeyDns),
            'fingerprint_sha256' => hash('sha256', $publicKeyDns),
        ];
    }

    private function convertPublicPemToDnsValue(string $publicKeyPem): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($publicKeyPem));
        if (!is_array($lines)) {
            throw new \RuntimeException('Failed to normalize DKIM public key.');
        }

        $body = array_filter(
            $lines,
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '-----')
        );

        $concatenated = trim(implode('', $body));
        if ($concatenated === '' || preg_match('/[^A-Za-z0-9\/+\=]/', $concatenated) === 1) {
            throw new \InvalidArgumentException('DKIM public key content is invalid.');
        }

        return $concatenated;
    }
}
