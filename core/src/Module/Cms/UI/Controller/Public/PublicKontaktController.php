<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\Security\ContactFormProtection;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Core\Domain\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/kontakt', name: 'public_kontakt', methods: ['GET', 'POST'])]
final class PublicKontaktController
{
    private const FORM_KEY = 'public_kontakt';

    public function __construct(
        private readonly Environment $twig,
        private readonly CmsSiteResolver $siteResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly ThemeResolver $themeResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContactFormProtection $protection,
        private readonly RateLimiterFactory $kontaktLimiter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $success = false;
        $error = null;
        $form = [];

        if ($request->isMethod('POST')) {
            $error = $this->protection->validate($request, self::FORM_KEY, $this->kontaktLimiter);
            if ($error === ContactFormProtection::HONEYPOT) {
                $success = true;
                $error = null;
            } else {
                $message = trim($request->request->getString('message'));
                $form = [
                    'name' => trim($request->request->getString('name')),
                    'email' => trim($request->request->getString('email')),
                    'subject' => mb_substr(trim(str_replace(["\r", "\n"], ' ', $request->request->getString('subject'))), 0, 255),
                    'message' => mb_substr($message, 0, 5000),
                ];

                if ($error === null) {
                    $error = match (true) {
                        mb_strlen($message) > 5000 => 'flash_kontakt_message_too_long',
                        $form['name'] === '', mb_strlen($form['name']) > 140, mb_strlen($form['email']) > 180,
                        !filter_var($form['email'], FILTER_VALIDATE_EMAIL), $form['subject'] === '', $form['message'] === '' => 'contact_validation_error',
                        default => null,
                    };
                    if ($error === null) {
                        $contactMsg = new ContactMessage($site, $form['name'], $form['email'], $form['subject'], $form['message'], $request->getClientIp() ?? '');
                        $this->entityManager->persist($contactMsg);
                        $this->entityManager->flush();
                        $success = true;
                    }
                }
            }
        }

        $theme = $this->themeResolver->resolveThemeKey($site);

        return new Response($this->twig->render('themes/nexus-gaming/pages/kontakt.html.twig', [
            'success' => $success,
            'error' => $error,
            'form' => $form,
            'protection' => $success ? null : $this->protection->issue($request, self::FORM_KEY),
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
            'page' => ['title' => $this->translator->trans('theme_contact_title'), 'slug' => 'kontakt'],
            'blocks' => [],
            'template_key' => $theme,
            'active_theme' => $theme,
        ]), $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
