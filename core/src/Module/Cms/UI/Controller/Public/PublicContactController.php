<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\LocalAntiAbuseService;
use App\Module\Core\Application\MailService;
use App\Module\Core\Application\MathCaptchaService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class PublicContactController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly MailService $mailService,
        private readonly LocalAntiAbuseService $antiAbuse,
        private readonly MathCaptchaService $captcha,
        private readonly AppSettingsService $settings,
        private readonly RateLimiterFactory $contactLimiter,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route(path: '/contact', name: 'public_contact', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $success = false;
        $errors = [];
        $session = $request->getSession();
        $formData = $this->antiAbuse->registerFormSession($session, 'contact');
        $captchaQuestion = null;
        if ($this->settings->isAntiAbuseCaptchaEnabledForContact()) {
            $captchaQuestion = $this->captcha->issueChallenge($session, 'contact')['question'];
        }

        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            $email = trim((string) $request->request->get('email', ''));
            $subject = trim((string) $request->request->get('subject', ''));
            $message = trim((string) $request->request->get('message', ''));

            $csrf = new CsrfToken('public_contact', (string) $request->request->get('_token', ''));
            if (!$this->csrfTokenManager->isTokenValid($csrf)) {
                return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
            }

            if ($this->antiAbuse->isHoneypotTriggered($request)) {
                $this->antiAbuse->log('contact_honeypot', $request, $email);
                return $this->okSpamResponse($captchaQuestion, $formData['nonce']);
            }

            $limit = $this->contactLimiter->create($request->getClientIp() ?? 'contact')->consume(1);
            if (!$limit->isAccepted()) {
                return new Response('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
            }

            if (!$this->antiAbuse->verifyMinTime($session, 'contact')) {
                $this->antiAbuse->log('contact_too_fast', $request, $email);
                return $this->okSpamResponse($captchaQuestion, $formData['nonce']);
            }

            if ($this->settings->isAntiAbusePowEnabledForContact() && !$this->antiAbuse->verifyPow($session, 'contact', (string) $request->request->get('pow_solution', ''))) {
                $this->antiAbuse->log('contact_pow_failed', $request, $email);
                return $this->okSpamResponse($captchaQuestion, $formData['nonce']);
            }

            if ($this->settings->isAntiAbuseCaptchaEnabledForContact() && !$this->captcha->verifyAnswer($session, 'contact', (string) $request->request->get('captcha_answer', ''))) {
                $this->antiAbuse->log('contact_captcha_failed', $request, $email);
                return $this->okSpamResponse($captchaQuestion, $formData['nonce']);
            }

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
                $errors[] = 'Please fill out all fields correctly.';
            }
            $subject = mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 140);
            $message = mb_substr($message, 0, 5000);

            if ($errors === []) {
                $this->mailService->sendTemplate($this->settings->getSupportEmail() ?? $this->settings->getMailFromAddress(), 'contact_message', [
                    'sender_name' => $name,
                    'sender_email' => $email,
                    'subject_line' => $subject,
                    'message_body' => $message,
                ], 'en', true);
                $success = true;
            }
        }

        return new Response($this->twig->render('public/contact.html.twig', [
            'success' => $success,
            'errors' => $errors,
            'anti_abuse_nonce' => $formData['nonce'],
            'pow_difficulty' => 4,
            'captcha_question' => $captchaQuestion,
        ]));
    }

    private function okSpamResponse(?string $captchaQuestion, string $nonce): Response
    {
        return new Response($this->twig->render('public/contact.html.twig', [
            'success' => true,
            'errors' => [],
            'anti_abuse_nonce' => $nonce,
            'pow_difficulty' => 4,
            'captcha_question' => $captchaQuestion,
        ]));
    }
}
