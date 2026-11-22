<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\Security;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\LocalAntiAbuseService;
use App\Module\Core\Application\MathCaptchaService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class ContactFormProtection
{
    public const HONEYPOT = 'contact_honeypot';

    public function __construct(
        private LocalAntiAbuseService $antiAbuse,
        private MathCaptchaService $captcha,
        private AppSettingsServiceInterface $settings,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    /** @return array{nonce: string, pow_difficulty: int, captcha_question: ?string} */
    public function issue(Request $request, string $formKey): array
    {
        $session = $request->getSession();
        $this->antiAbuse->resetFormSession($session, $formKey);

        return [
            'nonce' => $this->antiAbuse->registerFormSession($session, $formKey)['nonce'],
            'pow_difficulty' => $this->settings->isAntiAbusePowEnabledForContact() ? $this->settings->getAntiAbusePowDifficulty() : 0,
            'captcha_question' => $this->settings->isAntiAbuseCaptchaEnabledForContact()
                ? $this->captcha->issueChallenge($session, $formKey)['question']
                : null,
        ];
    }

    public function validate(Request $request, string $formKey, RateLimiterFactory $limiter): ?string
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($formKey, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $limit = $limiter->create($request->getClientIp() ?? 'contact')->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        $session = $request->getSession();
        $powRequired = $this->settings->isAntiAbusePowEnabledForContact() && $this->settings->getAntiAbusePowDifficulty() > 0;
        $solution = $powRequired ? $request->request->getString('pow_solution') : '';
        $error = match (true) {
            $this->antiAbuse->isHoneypotTriggered($request) => self::HONEYPOT,
            !$this->antiAbuse->verifyFormNonce($session, $formKey, $request->request->getString('contact_form_nonce')) => 'contact_form_expired',
            !$this->antiAbuse->verifyMinTime($session, $formKey) => 'contact_form_too_fast',
            $this->settings->isAntiAbuseCaptchaEnabledForContact() && !$this->captcha->verifyAnswer($session, $formKey, $request->request->getString('captcha_answer')) => 'contact_captcha_invalid',
            $powRequired && (preg_match('/\A[0-9]{1,9}\z/', $solution) !== 1 || !$this->antiAbuse->verifyPow($session, $formKey, $solution)) => 'contact_pow_invalid',
            default => null,
        };
        $this->antiAbuse->resetFormSession($session, $formKey);

        if ($error !== null) {
            $this->antiAbuse->log($error, $request, $request->request->getString('email'));
        }

        return $error;
    }
}
