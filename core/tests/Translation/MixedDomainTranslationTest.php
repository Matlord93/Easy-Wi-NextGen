<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MixedDomainTranslationTest extends KernelTestCase
{
    public function testTranslationsResolveAcrossPortalSecurityAndValidatorsDomains(): void
    {
        self::bootKernel();

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $checks = [
            ['domain' => 'portal', 'key' => 'language'],
            ['domain' => 'security', 'key' => 'Invalid credentials.'],
            ['domain' => 'validators', 'key' => 'This value should not be blank.'],
        ];

        foreach ($checks as $check) {
            $deTranslated = $translator->trans($check['key'], [], $check['domain'], 'de');
            self::assertNotSame($check['key'], $deTranslated, sprintf('Missing %s translation for "%s" in locale de.', $check['domain'], $check['key']));

            $enTranslated = $translator->trans($check['key'], [], $check['domain'], 'en');
            self::assertNotSame('', trim($enTranslated), sprintf('Empty %s translation for "%s" in locale en.', $check['domain'], $check['key']));
        }
    }
}
