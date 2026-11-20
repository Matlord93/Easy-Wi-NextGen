<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Application\PortalLocale;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Recover newly shipped CMS messages when an older production catalogue is cached. */
final readonly class CmsTranslations
{
    public function __construct(
        private TranslatorInterface $translator,
        private RequestStack $requests,
        #[Autowire('%kernel.project_dir%/translations')] private string $directory,
    ) {
    }

    /** @param array<string, scalar|\Stringable|null> $parameters */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        $locale = PortalLocale::normalize($locale ?? $this->requests->getCurrentRequest()?->getLocale() ?? $this->translator->getLocale()) ?? PortalLocale::DEFAULT;
        $missing = $this->translator instanceof TranslatorBagInterface && !$this->translator->getCatalogue($locale)->has($key, 'cms_themes');
        $translated = $missing ? $key : $this->translator->trans($key, $parameters, 'cms_themes', $locale);
        if ($translated !== $key) {
            return $translated;
        }
        $message = $this->catalogue($locale)[$key] ?? ('de' !== $locale ? ($this->catalogue('de')[$key] ?? null) : null);
        if (null === $message) {
            return $missing ? $this->translator->trans($key, $parameters, 'cms_themes', $locale) : $translated;
        }
        $values = [];
        foreach ($parameters as $name => $value) {
            $values[$name] = (string) $value;
        }

        return strtr($message, $values);
    }

    /** @return array<string,string> */
    private function catalogue(string $locale): array
    {
        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_message_fallback_'.$locale;
        if (null !== $request && $request->attributes->has($cacheKey)) {
            return JsonMap::strings($request->attributes->get($cacheKey));
        }
        $file = $this->directory.('en' === $locale ? '/cms_themes.en.yaml' : '/cms_themes.de.yaml');
        $catalogue = is_file($file) ? JsonMap::strings(Yaml::parseFile($file)) : [];
        $request?->attributes->set($cacheKey, $catalogue);

        return $catalogue;
    }
}
