<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class PublicProfileController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SiteResolver $siteResolver,
        private readonly ThemeResolver $themeResolver,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/profile', name: 'public_profile', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User) {
            return new RedirectResponse('/login');
        }

        $templateKey = $this->resolveTemplateKey($request);

        return new Response($this->twig->render('public/profile/show.html.twig', [
            'user' => $user,
            'template_key' => $templateKey,
            'active_theme' => $templateKey,
        ]));
    }

    #[Route(path: '/profile/edit', name: 'public_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User) {
            return new RedirectResponse('/login');
        }

        $templateKey = $this->resolveTemplateKey($request);

        $errors = [];
        $success = false;

        $form = [
            'name' => $user->getName() ?? '',
            'email' => $user->getEmail(),
        ];

        if ($request->isMethod('POST')) {
            $csrf = new CsrfToken('public_profile_edit', (string) $request->request->get('_token', ''));
            if (!$this->csrfTokenManager->isTokenValid($csrf)) {
                return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
            }

            $name = trim((string) $request->request->get('name', ''));
            $email = trim((string) $request->request->get('email', ''));
            $form['name'] = $name;
            $form['email'] = $email;

            if ($name !== '' && mb_strlen($name) > 120) {
                $errors[] = 'Name must not exceed 120 characters.';
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address.';
            }

            if ($errors === []) {
                $user->setName($name !== '' ? $name : null);
                $user->setEmail($email);
                $this->entityManager->flush();
                $success = true;
            }
        }

        return new Response($this->twig->render('public/profile/edit.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'success' => $success,
            'user' => $user,
            'template_key' => $templateKey,
            'active_theme' => $templateKey,
        ]), $errors !== [] ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }

    private function resolveTemplateKey(Request $request): string
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return 'minimal';
        }

        return $this->themeResolver->resolveThemeKey($site);
    }
}
