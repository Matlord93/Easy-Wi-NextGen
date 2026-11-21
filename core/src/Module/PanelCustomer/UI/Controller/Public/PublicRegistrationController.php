<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\ThemeResolver;
use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\Contracts\CheckoutSelection;
use App\Module\Core\Application\CustomerBillingDetails;
use App\Module\Core\Application\ModuleRegistry;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\LocalAntiAbuseService;
use App\Module\Core\Application\MailService;
use App\Module\Core\Application\MathCaptchaService;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\CustomerProfileRepository;
use App\Repository\UserRepositoryInterface;
use App\Security\SessionTokenGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublicRegistrationController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly CmsSiteResolver $siteResolver,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly ThemeResolver $themeResolver,
        private readonly MailService $mailService,
        private readonly LocalAntiAbuseService $antiAbuse,
        private readonly MathCaptchaService $captcha,
        private readonly AppSettingsServiceInterface $settings,
        private readonly UriSigner $uriSigner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.public_registration')]
        private readonly RateLimiterFactory $registrationLimiter,
        #[Autowire(service: 'limiter.public_registration_email')]
        private readonly RateLimiterFactory $registrationEmailLimiter,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly ModuleRegistry $modules,
        private readonly CheckoutSelection $checkout,
        private readonly CustomerProfileRepository $profiles,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/register', name: 'public_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $session = $request->getSession();
        $maintenance = $this->maintenanceService->resolve($request, $site);
        $registrationAllowed = !$maintenance['active'] && $this->settings->isRegistrationEnabled();
        $isPost = $request->isMethod('POST');
        $input = JsonMap::strings($isPost ? $request->request->all() : $request->query->all());
        $target = $this->checkout->registrationTarget($request, $input['target'] ?? null);
        $customerRegistration = $this->modules->isEnabled('shop') && $this->modules->isEnabled('billing');
        $customerAccount = $customerRegistration && (null !== $target || 'member' !== ($input['account'] ?? 'customer'));
        $fields = CustomerBillingDetails::fields($isPost ? $input : []);
        $email = $isPost ? strtolower(trim($input['email'] ?? '')) : '';
        $registered = false;
        $errors = [];
        $retryAfter = null;
        $status = $registrationAllowed ? Response::HTTP_OK : ($maintenance['active'] ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_NOT_FOUND);

        if ($registrationAllowed && $isPost) {
            if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('public_register', $input['_token'] ?? ''))) {
                $status = Response::HTTP_FORBIDDEN;
                $errors[] = 'portal_problem_form_expired_message';
            } elseif ($this->antiAbuse->isIpLocked($request, 'registration_reject')) {
                $status = Response::HTTP_TOO_MANY_REQUESTS;
                $errors[] = 'error_too_many_registration';
            } elseif ($this->antiAbuse->isHoneypotTriggered($request)) {
                $this->antiAbuse->log('registration_honeypot', $request, $email);
                $status = Response::HTTP_BAD_REQUEST;
                $errors[] = 'registration_security_check';
            } elseif (!$this->antiAbuse->verifyMinTime($session, 'registration')) {
                $status = Response::HTTP_BAD_REQUEST;
                $errors[] = 'registration_too_fast';
            } elseif (($this->settings->isAntiAbusePowEnabledForRegistration() && !$this->antiAbuse->verifyPow($session, 'registration', $input['pow_solution'] ?? ''))
                || ($this->settings->isAntiAbuseCaptchaEnabledForRegistration() && !$this->captcha->verifyAnswer($session, 'registration', $input['captcha_answer'] ?? ''))) {
                $this->antiAbuse->log('registration_challenge_failed', $request, $email);
                $status = Response::HTTP_BAD_REQUEST;
                $errors[] = 'registration_security_check';
            } else {
                $limit = $this->registrationLimiter->create($request->getClientIp() ?? 'public')->consume(1);
                if ($limit->isAccepted() && strlen($email) <= 180 && false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $limit = $this->registrationEmailLimiter->create(hash('sha256', $email))->consume(1);
                }
                if (!$limit->isAccepted()) {
                    $status = Response::HTTP_TOO_MANY_REQUESTS;
                    $errors[] = 'error_too_many_registration';
                    $retryAfter = $limit->getRetryAfter();
                    $this->antiAbuse->log('registration_rate_limited', $request, $email);
                } else {
                    $errors = $customerAccount ? CustomerBillingDetails::errors($fields) : [];
                    $password = $input['password'] ?? '';
                    if (strlen($email) > 180 || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'error_invalid_email';
                    }
                    if (mb_strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
                        $errors[] = 'registration_password_length';
                    }
                    if ($password !== ($input['password_confirm'] ?? '')) {
                        $errors[] = 'error_passwords_dont_match';
                    }
                    if ('1' !== ($input['consents'] ?? '')) {
                        $errors[] = 'registration_consents_required';
                    }
                    if ([] === $errors) {
                        $language = 'en' === $request->getLocale() ? 'en' : 'de';
                        $user = $this->users->findOneByEmail($email);
                        if (null !== $user && (!$user->isEmailVerificationPending() || !$this->passwordHasher->isPasswordValid($user, $password))) {
                            $errors[] = 'error_email_exists';
                        } else {
                            $new = null === $user;
                            if (null === $user) {
                                $user = new User($email, UserType::Customer);
                                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
                                $user->setName($email);
                                $user->recordConsents($request->getClientIp() ?? 'unknown', new \DateTimeImmutable());
                                $this->entityManager->persist($user);
                                $this->entityManager->persist(new InvoicePreferences($user, 'en' === $language ? 'en_GB' : 'de_DE', true, true, 'manual', $language));
                                if ($customerAccount) {
                                    $this->entityManager->persist(CustomerBillingDetails::apply($user, null, $fields));
                                }
                            }
                            try {
                                $registered = $this->sendVerification($request, $user, $language, $target);
                                if ($new) {
                                    $this->auditLogger->log($user, 'customer.registered', ['user_id' => $user->getId(), 'site_id' => $site->getId()]);
                                }
                                if (!$registered) {
                                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                                    $errors[] = 'registration_mail_retry';
                                }
                            } catch (UniqueConstraintViolationException) {
                                $errors[] = 'error_email_exists';
                            }
                        }
                    }
                    if ([] !== $errors && Response::HTTP_OK === $status) {
                        $status = Response::HTTP_BAD_REQUEST;
                    }
                }
            }
        }

        $templateKey = $this->themeResolver->resolveThemeKey($site);
        $captchaQuestion = null;
        $nonce = '';
        if ($registrationAllowed && !$registered) {
            if ($this->settings->isAntiAbuseCaptchaEnabledForRegistration()) {
                $captchaQuestion = $this->captcha->issueChallenge($session, 'registration')['question'];
            }
            $nonce = $this->antiAbuse->registerFormSession($session, 'registration')['nonce'];
        }
        $response = new Response($this->twig->render($this->resolveRegisterTemplate($templateKey), [
            'form' => ['email' => $email, 'consents' => '1' === ($input['consents'] ?? '')],
            'billing' => $fields,
            'billing_countries' => CustomerBillingDetails::COUNTRIES,
            'billing_lengths' => CustomerBillingDetails::LENGTHS,
            'customer_account' => $customerAccount,
            'customer_registration' => $customerRegistration,
            'checkout_target' => $target,
            'errors' => $errors,
            'registered' => $registered,
            'registrationAllowed' => $registrationAllowed,
            'registration_disabled_reason' => $maintenance['active'] ? 'registration_maintenance' : 'registration_disabled',
            'siteName' => $site->getName(),
            'template_key' => $templateKey,
            'active_theme' => $templateKey,
            'anti_abuse_nonce' => $nonce,
            'pow_difficulty' => $this->settings->isAntiAbusePowEnabledForRegistration() ? $this->settings->getAntiAbusePowDifficulty() : 0,
            'captcha_question' => $captchaQuestion,
        ]), $status, ['Cache-Control' => 'private, no-store']);

        if (null !== $retryAfter) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter->getTimestamp() - time()));
        }

        return $response;
    }

    private function sendVerification(Request $request, User $user, string $language, ?string $target): bool
    {
        $token = $this->tokenGenerator->generateToken();
        $tokenHash = $this->tokenGenerator->hashToken($token);
        $expires = new \DateTimeImmutable('+1 day');
        $user->setEmailVerificationTokenHash($tokenHash);
        $user->setEmailVerificationExpiresAt($expires);
        $this->entityManager->flush();

        $query = ['token' => $token, 'expires' => $expires->getTimestamp()];
        if (null !== $target) {
            $query['target'] = $target;
        }
        $url = $this->uriSigner->sign($request->getSchemeAndHttpHost().$request->getBaseUrl().'/register/verify?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        try {
            if (null !== $target) {
                $this->checkout->holdForVerification($request, $tokenHash, $target);
            }
            return $this->mailService->sendTemplate($user->getEmail(), 'account_created', [
                'verification_url' => $url, 'user_email' => $user->getEmail(),
            ], $language, true);
        } catch (\Exception $exception) {
            $this->logger->warning('Registration verification mail could not be queued.', ['exception_class' => $exception::class]);

            return false;
        }
    }

    #[Route(path: '/register/verify', name: 'public_register_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $token = $request->query->all()['token'] ?? null;
        $expires = filter_var($request->query->all()['expires'] ?? null, FILTER_VALIDATE_INT);
        if (!$this->uriSigner->checkRequest($request) || !is_string($token) || '' === $token || false === $expires || $expires <= time()) {
            return $this->invalidVerification($request);
        }
        $tokenHash = $this->tokenGenerator->hashToken($token);
        $user = $this->users->findOneBy(['emailVerificationTokenHash' => $tokenHash]);
        if (!$user instanceof User || !$user->isEmailVerificationPending() || ($user->getEmailVerificationExpiresAt()?->getTimestamp() ?? 0) <= time()) {
            return $this->invalidVerification($request);
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationTokenHash(null);
        $user->setEmailVerificationExpiresAt(null);
        $user->setMemberAccessEnabled(true);
        if (CustomerBillingDetails::complete($this->profiles->findOneByCustomer($user))) {
            $user->setCustomerAccessEnabled(true);
        }
        $this->entityManager->flush();
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('registration_feedback', 'registration_confirmed');
        }
        $target = JsonMap::strings($request->query->all())['target'] ?? '';
        if (preg_match('~^/shop/products/[1-9][0-9]*\?intent=[a-f0-9]{32}$~D', $target)) {
            $this->checkout->restoreAfterVerification($request, $tokenHash, $target);

            return new RedirectResponse('/login?target='.rawurlencode($target));
        }

        return new RedirectResponse('/login');
    }

    private function invalidVerification(Request $request): Response
    {
        return new Response($this->twig->render('public/auth/access_problem.html.twig', [
            'locale' => $request->getLocale(),
            'title' => $this->translator->trans('registration_email_pending', [], 'portal'),
            'message' => $this->translator->trans('registration_verification_invalid', [], 'portal'),
            'destination' => '/register',
            'action' => $this->translator->trans('create_account', [], 'portal'),
        ]), Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'private, no-store']);
    }

    private function resolveRegisterTemplate(string $templateKey): string
    {
        $themeTemplate = sprintf('themes/%s/auth/register.html.twig', $templateKey);
        if ($this->templateExists($themeTemplate)) {
            return $themeTemplate;
        }

        return 'themes/minimal/auth/register.html.twig';
    }

    private function templateExists(string $template): bool
    {
        $loader = $this->twig->getLoader();

        return $loader->exists($template);
    }
}
