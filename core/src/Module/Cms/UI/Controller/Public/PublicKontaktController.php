<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ContactMessage;
use App\Repository\ContactMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/kontakt', name: 'public_kontakt', methods: ['GET', 'POST'])]
final class PublicKontaktController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SiteResolver $siteResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly ContactMessageRepository $contactMessageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactory $kontaktLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $success = false;
        $error = null;

        if ($request->isMethod('POST')) {
            $honeypot = (string) $request->request->get('website_url', '');
            if ($honeypot !== '') {
                return $this->renderSuccess($request, $site);
            }

            $limit = $this->kontaktLimiter->create($request->getClientIp() ?? 'anon')->consume(1);
            if (!$limit->isAccepted()) {
                $error = 'Zu viele Nachrichten. Bitte warte eine Stunde und versuche es erneut.';
            } else {
                $name = trim((string) $request->request->get('name', ''));
                $email = trim((string) $request->request->get('email', ''));
                $subject = trim((string) $request->request->get('subject', ''));
                $message = trim((string) $request->request->get('message', ''));

                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
                    $error = 'Bitte fülle alle Felder korrekt aus.';
                } elseif (mb_strlen($message) > 5000) {
                    $error = 'Deine Nachricht ist zu lang (max. 5000 Zeichen).';
                } else {
                    $subject = mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 255);
                    $message = mb_substr($message, 0, 5000);
                    $ip = $request->getClientIp() ?? '';

                    $contactMsg = new ContactMessage($site, $name, $email, $subject, $message, $ip);
                    $this->entityManager->persist($contactMsg);
                    $this->entityManager->flush();
                    $success = true;
                }
            }
        }

        return new Response($this->twig->render('themes/nexus-gaming/pages/kontakt.html.twig', [
            'success' => $success,
            'error' => $error,
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
            'page' => ['title' => 'Kontakt', 'slug' => 'kontakt'],
            'blocks' => [],
            'template_key' => 'nexus-gaming',
            'active_theme' => 'nexus-gaming',
        ]));
    }

    private function renderSuccess(Request $request, \App\Module\Core\Domain\Entity\Site $site): Response
    {
        return new Response($this->twig->render('themes/nexus-gaming/pages/kontakt.html.twig', [
            'success' => true,
            'error' => null,
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
            'page' => ['title' => 'Kontakt', 'slug' => 'kontakt'],
            'blocks' => [],
            'template_key' => 'nexus-gaming',
            'active_theme' => 'nexus-gaming',
        ]));
    }
}
