<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PortalTranslationConsistencyTest extends TestCase
{
    #[DataProvider('domainProvider')]
    public function testDomainCatalogContainsSameKeysInGermanAndEnglish(string $domain): void
    {
        $de = $this->flatten(Yaml::parseFile(__DIR__ . '/../../translations/' . $domain . '.de.yaml'));
        $en = $this->flatten(Yaml::parseFile(__DIR__ . '/../../translations/' . $domain . '.en.yaml'));

        $deKeys = array_keys($de);
        $enKeys = array_keys($en);
        sort($deKeys);
        sort($enKeys);

        self::assertSame($deKeys, $enKeys, sprintf('%s.de.yaml and %s.en.yaml must have identical key sets.', $domain, $domain));
    }

    /** @return iterable<string, array{string}> */
    public static function domainProvider(): iterable
    {
        yield 'portal' => ['portal'];
        yield 'security' => ['security'];
        yield 'validators' => ['validators'];
        yield 'installer' => ['installer'];
        yield 'mail' => ['mail'];
    }

    /** @return array<string, mixed> */
    private function flatten(array $catalog, string $prefix = ''): array
    {
        $result = [];

        foreach ($catalog as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $result += $this->flatten($value, $fullKey);
                continue;
            }

            $result[$fullKey] = $value;
        }

        return $result;
    }
}
