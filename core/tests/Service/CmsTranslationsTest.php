<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Cms\Application\CmsTranslations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\LoggingTranslator;

final class CmsTranslationsTest extends TestCase
{
    public function testRecoveredMessagesDoNotProduceMissingTranslationWarnings(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $translator = new LoggingTranslator(new Translator('de'), $logger);
        $messages = new CmsTranslations($translator, new RequestStack(), dirname(__DIR__, 2).'/translations');
        self::assertSame('Startseite', $messages->trans('media_home'));
    }

    public function testCurrentMessagesRecoverAnOlderEmptyCatalogueInBothLanguages(): void
    {
        $requests = new RequestStack();
        $translator = new Translator('de');
        $messages = new CmsTranslations($translator, $requests, dirname(__DIR__, 2).'/translations');
        foreach (['de' => ['Startseite', 'Medien', '12 Monate feste Laufzeit'], 'en' => ['Home', 'Media', '12 month fixed term']] as $locale => $expected) {
            $request = Request::create('/shop');
            $request->setLocale($locale);
            $requests->push($request);
            self::assertFalse($translator->getCatalogue($locale)->has('media_home', 'cms_themes'));
            self::assertSame($expected, [$messages->trans('media_home'), $messages->trans('nav_media'), $messages->trans('shop_domain_term', ['%months%' => 12])]);
            self::assertIsArray($request->attributes->get('_cms_message_fallback_'.$locale));
            $requests->pop();
        }
    }

    public function testNormalTranslatorOverridesArePreservedWithoutReadingFallbackFiles(): void
    {
        $translator = new Translator('de');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['media_home' => 'Eigene Startseite'], 'de', 'cms_themes');
        $requests = new RequestStack();
        $request = Request::create('/');
        $request->setLocale('de');
        $requests->push($request);
        $messages = new CmsTranslations($translator, $requests, '/missing-cms-translation-directory');
        self::assertSame('Eigene Startseite', $messages->trans('media_home'));
        self::assertFalse($request->attributes->has('_cms_message_fallback_de'));
    }

    public function testFallbackIsSharedOnlyWithinTheCurrentRequestAndReloadedOnTheNextOne(): void
    {
        $directory = sys_get_temp_dir().'/cms-message-test-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $file = $directory.'/cms_themes.de.yaml';
        $requests = new RequestStack();
        $messages = new CmsTranslations(new Translator('de'), $requests, $directory);
        try {
            file_put_contents($file, 'example: Vorher');
            $request = Request::create('/');
            $request->setLocale('de');
            $requests->push($request);
            self::assertSame('Vorher', $messages->trans('example'));
            file_put_contents($file, 'example: Nachher');
            self::assertSame('Vorher', $messages->trans('example'));
            $requests->pop();
            $next = Request::create('/');
            $next->setLocale('de');
            $requests->push($next);
            self::assertSame('Nachher', $messages->trans('example'));
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function testUnsupportedLocalesCannotSelectArbitraryFilesAndUnknownKeysStayUnchanged(): void
    {
        $messages = new CmsTranslations(new Translator('de'), new RequestStack(), dirname(__DIR__, 2).'/translations');
        self::assertSame('Startseite', $messages->trans('media_home', [], '../../en'));
        self::assertSame('unknown_message_key', $messages->trans('unknown_message_key'));
        $missing = new CmsTranslations(new Translator('de'), new RequestStack(), '/missing-cms-translation-directory');
        self::assertSame('unknown_message_key', $missing->trans('unknown_message_key'));
    }
}
