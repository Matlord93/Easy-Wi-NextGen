<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\Security\ContactFormProtection;
use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\MailService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicContactController
{
    private const FORM_KEY = 'public_contact';

    public function __construct(
        private readonly Environment $twig,
        private readonly MailService $mailService,
        private readonly ContactFormProtection $protection,
        private readonly AppSettingsServiceInterface $settings,
        private readonly RateLimiterFactory $contactLimiter,
    ) {
    }

    #[Route(path: '/contact', name: 'public_contact', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $success = false;
        $errors = [];
        $form = [];

        if ($request->isMethod('POST')) {
            $error = $this->protection->validate($request, self::FORM_KEY, $this->contactLimiter);
            if ($error === ContactFormProtection::HONEYPOT) {
                $success = true;
            } else {
                $form = [
                    'name' => trim($request->request->getString('name')),
                    'email' => trim($request->request->getString('email')),
                    'subject' => mb_substr(trim(str_replace(["\r", "\n"], ' ', $request->request->getString('subject'))), 0, 140),
                    'message' => mb_substr(trim($request->request->getString('message')), 0, 5000),
                ];

                if ($error !== null) {
                    $errors[] = $error;
                } elseif ($form['name'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL) || $form['subject'] === '' || $form['message'] === '') {
                    $errors[] = 'contact_validation_error';
                } else {
                    $success = $this->mailService->sendTemplate($this->settings->getSupportEmail() ?? $this->settings->getMailFromAddress(), 'contact_message', [
                        'sender_name' => $form['name'],
                        'sender_email' => $form['email'],
                        'subject_line' => $form['subject'],
                        'message_body' => $form['message'],
                    ], $request->getLocale(), true);

                    if (!$success) {
                        $errors[] = 'contact_delivery_failed';
                    }
                }
            }
        }

        return new Response($this->twig->render('public/contact.html.twig', [
            'success' => $success,
            'errors' => $errors,
            'form' => $success ? [] : $form,
            'protection' => $this->protection->issue($request, self::FORM_KEY),
        ]), $errors === [] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
