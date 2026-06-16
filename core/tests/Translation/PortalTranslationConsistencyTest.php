<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortalTranslationConsistencyTest extends TestCase
{
    #[DataProvider('domainProvider')]
    public function testDomainCatalogContainsSameKeysInGermanAndEnglish(string $domain): void
    {
        $deKeys = $this->catalogKeys(__DIR__ . '/../../translations/' . $domain . '.de.yaml');
        $enKeys = $this->catalogKeys(__DIR__ . '/../../translations/' . $domain . '.en.yaml');

        sort($deKeys);
        sort($enKeys);

        $missingInGerman = array_values(array_diff($enKeys, $deKeys));
        $missingInEnglish = array_values(array_diff($deKeys, $enKeys));

        self::assertSame([], $missingInGerman, sprintf('%s.de.yaml is missing keys that exist in %s.en.yaml.', $domain, $domain));
        self::assertSame([], $missingInEnglish, sprintf('%s.en.yaml is missing keys that exist in %s.de.yaml.', $domain, $domain));
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

    /** @return list<string> */
    private function catalogKeys(string $file): array
    {
        $keys = [];
        $pathByIndent = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        self::assertNotFalse($lines, sprintf('Unable to read translation catalog "%s".', $file));

        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (!preg_match('/^(\s*)([\'\"]?[^\'\"#:\n]+[\'\"]?)\s*:/', $line, $matches)) {
                continue;
            }

            $indent = strlen($matches[1]);
            $key = trim($matches[2], ' \t\'\"');

            foreach (array_keys($pathByIndent) as $knownIndent) {
                if ($knownIndent >= $indent) {
                    unset($pathByIndent[$knownIndent]);
                }
            }

            $parentPath = $pathByIndent === [] ? [] : $pathByIndent[max(array_keys($pathByIndent))];
            $fullPath = [...$parentPath, $key];
            $pathByIndent[$indent] = $fullPath;

            if (!str_ends_with(rtrim($line), ':')) {
                $keys[] = implode('.', $fullPath);
            }
        }

        return $keys;
    }
}
