<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\CustomerProfile;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\CustomerProfileRepository;
use App\Repository\InvoicePreferencesRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/profile')]
final class CustomerProfileController
{
    private const COUNTRY_OPTIONS = [
        'AT' => 'Austria',
        'AU' => 'Australia',
        'BE' => 'Belgium',
        'CA' => 'Canada',
        'CH' => 'Switzerland',
        'CZ' => 'Czech Republic',
        'DE' => 'Germany',
        'DK' => 'Denmark',
        'ES' => 'Spain',
        'FR' => 'France',
        'GB' => 'United Kingdom',
        'IT' => 'Italy',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'SE' => 'Sweden',
        'US' => 'United States',
    ];

    private const LOCALE_OPTIONS = [
        'de_DE' => 'Deutsch (DE)',
        'en_GB' => 'English (UK)',
        'en_US' => 'English (US)',
    ];

    private const PORTAL_LANGUAGE_OPTIONS = [
        'de' => 'Deutsch',
        'en' => 'English',
    ];

    private const PAYMENT_METHOD_LABELS = [
        'manual' => 'Manual transfer',
        'dummy' => 'Test payment (TEST ONLY)',
    ];

    public function __construct(
        private readonly CustomerProfileRepository $profileRepository,
        private readonly InvoicePreferencesRepository $invoicePreferencesRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%app.payment_dummy_enabled%')]
        private readonly bool $allowDummyPayment,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_profile', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        return $this->renderPage($customer);
    }

    #[Route(path: '/customer', name: 'customer_profile_update', methods: ['POST'])]
    public function updateProfile(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $profile = $this->profileRepository->findOneByCustomer($customer);
        $preferences = $this->invoicePreferencesRepository->findOneByCustomer($customer);

        $formData = $this->parseProfilePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderPage($customer, $profile, $preferences, $formData, [], Response::HTTP_BAD_REQUEST);
        }

        if ($profile === null) {
            $profile = new CustomerProfile(
                $customer,
                $formData['firstname'],
                $formData['lastname'],
                $formData['address'],
                $formData['postal'],
                $formData['city'],
                $formData['country'],
            );
            $this->entityManager->persist($profile);
        } else {
            $profile->setFirstName($formData['firstname']);
            $profile->setLastName($formData['lastname']);
            $profile->setAddress($formData['address']);
            $profile->setPostal($formData['postal']);
            $profile->setCity($formData['city']);
            $profile->setCountry($formData['country']);
        }

        $profile->setPhone($formData['phone']);
        $profile->setCompany($formData['company']);
        $profile->setVatId($formData['vat_id']);

        $this->auditLogger->log($customer, 'customer.profile.updated', [
            'customer_id' => $customer->getId(),
            'country' => $profile->getCountry(),
            'company' => $profile->getCompany(),
            'vat_id_present' => $profile->getVatId() !== null,
        ]);
        $this->entityManager->flush();

        $formData['success'] = true;

        return $this->renderPage($customer, $profile, $preferences, $formData);
    }

    #[Route(path: '/invoice', name: 'customer_invoice_preferences_update', methods: ['POST'])]
    public function updateInvoicePreferences(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $profile = $this->profileRepository->findOneByCustomer($customer);
        $preferences = $this->invoicePreferencesRepository->findOneByCustomer($customer);

        $formData = $this->parseInvoicePreferencesPayload($request);
        $profileErrors = $this->validateProfileForInvoices($profile);
        if ($profileErrors !== []) {
            $formData['errors'] = array_merge($formData['errors'], $profileErrors);
        }
        if ($formData['errors'] !== []) {
            return $this->renderPage($customer, $profile, $preferences, [], $formData, Response::HTTP_BAD_REQUEST);
        }

        if ($preferences === null) {
            $preferences = new InvoicePreferences(
                $customer,
                $formData['locale'],
                $formData['email_delivery'],
                $formData['pdf_download_history'],
                $formData['payment_method'],
                $formData['portal_language'],
            );
            $this->entityManager->persist($preferences);
        } else {
            $preferences->setLocale($formData['locale']);
            $preferences->setEmailDelivery($formData['email_delivery']);
            $preferences->setPdfDownloadHistory($formData['pdf_download_history']);
            $preferences->setDefaultPaymentMethod($formData['payment_method']);
            $preferences->setPortalLanguage($formData['portal_language']);
        }

        $this->auditLogger->log($customer, 'customer.invoice_preferences.updated', [
            'customer_id' => $customer->getId(),
            'locale' => $preferences->getLocale(),
            'portal_language' => $preferences->getPortalLanguage(),
            'email_delivery' => $preferences->isEmailDelivery(),
            'pdf_download_history' => $preferences->isPdfDownloadHistory(),
            'payment_method' => $preferences->getDefaultPaymentMethod(),
        ]);
        $this->entityManager->flush();

        $formData['success'] = true;

        return $this->renderPage($customer, $profile, $preferences, [], $formData);
    }

    private function renderPage(
        User $customer,
        ?CustomerProfile $profile = null,
        ?InvoicePreferences $preferences = null,
        array $profileOverrides = [],
        array $preferencesOverrides = [],
        int $status = Response::HTTP_OK,
    ): Response {
        $profile = $profile ?? $this->profileRepository->findOneByCustomer($customer);
        $preferences = $preferences ?? $this->invoicePreferencesRepository->findOneByCustomer($customer);

        return new Response($this->twig->render('customer/profile/index.html.twig', [
            'profile' => $this->buildProfileFormContext($profile, $profileOverrides),
            'preferences' => $this->buildPreferencesFormContext($preferences, $preferencesOverrides),
            'countries' => self::COUNTRY_OPTIONS,
            'locales' => self::LOCALE_OPTIONS,
            'portalLanguages' => self::PORTAL_LANGUAGE_OPTIONS,
            'paymentMethods' => $this->getPaymentMethods(),
            'activeNav' => 'profile',
            'pageLocale' => $preferences?->getPortalLanguage() ?? 'de',
        ]), $status);
    }

    private function buildProfileFormContext(?CustomerProfile $profile, ?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'success' => false,
            'firstname' => $profile?->getFirstName() ?? '',
            'lastname' => $profile?->getLastName() ?? '',
            'address' => $profile?->getAddress() ?? '',
            'postal' => $profile?->getPostal() ?? '',
            'city' => $profile?->getCity() ?? '',
            'country' => $profile?->getCountry() ?? 'DE',
            'phone' => $profile?->getPhone() ?? '',
            'company' => $profile?->getCompany() ?? '',
            'vat_id' => $profile?->getVatId() ?? '',
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function buildPreferencesFormContext(?InvoicePreferences $preferences, ?array $overrides = null): array
    {
        $paymentMethod = $preferences?->getDefaultPaymentMethod() ?? 'manual';
        if ($paymentMethod === 'dummy' && !$this->isDummyPaymentAllowed()) {
            $paymentMethod = 'manual';
        }

        $defaults = [
            'errors' => [],
            'success' => false,
            'locale' => $preferences?->getLocale() ?? 'de_DE',
            'portal_language' => $preferences?->getPortalLanguage() ?? 'de',
            'email_delivery' => $preferences?->isEmailDelivery() ?? true,
            'pdf_download_history' => $preferences?->isPdfDownloadHistory() ?? true,
            'payment_method' => $paymentMethod,
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function parseProfilePayload(Request $request): array
    {
        $errors = [];
        $firstname = trim((string) $request->request->get('firstname', ''));
        $lastname = trim((string) $request->request->get('lastname', ''));
        $address = trim((string) $request->request->get('address', ''));
        $postal = trim((string) $request->request->get('postal', ''));
        $city = trim((string) $request->request->get('city', ''));
        $country = strtoupper(trim((string) $request->request->get('country', '')));
        $phone = trim((string) $request->request->get('phone', ''));
        $company = trim((string) $request->request->get('company', ''));
        $vatId = strtoupper(str_replace(' ', '', trim((string) $request->request->get('vat_id', ''))));

        if ($firstname === '') {
            $errors[] = 'First name is required.';
        }
        if ($lastname === '') {
            $errors[] = 'Last name is required.';
        }
        if ($address === '') {
            $errors[] = 'Address is required.';
        }
        if ($postal === '') {
            $errors[] = 'Postal code is required.';
        }
        if ($city === '') {
            $errors[] = 'City is required.';
        }
        if ($country === '' || !array_key_exists($country, self::COUNTRY_OPTIONS)) {
            $errors[] = 'Country selection is required.';
        }

        if ($vatId !== '') {
            if (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,16}$/', $vatId)) {
                $errors[] = 'VAT ID format is invalid.';
            } elseif ($country !== '' && !str_starts_with($vatId, $country)) {
                $errors[] = 'VAT ID should start with the country code.';
            }

            if ($company === '') {
                $errors[] = 'Company is required when VAT ID is provided.';
            }
        }

        return [
            'errors' => $errors,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'address' => $address,
            'postal' => $postal,
            'city' => $city,
            'country' => $country !== '' ? $country : 'DE',
            'phone' => $phone !== '' ? $phone : null,
            'company' => $company !== '' ? $company : null,
            'vat_id' => $vatId !== '' ? $vatId : null,
        ];
    }

    private function parseInvoicePreferencesPayload(Request $request): array
    {
        $errors = [];
        $locale = (string) $request->request->get('locale', '');
        $portalLanguage = (string) $request->request->get('portal_language', '');
        $paymentMethod = (string) $request->request->get('payment_method', '');
        $emailDelivery = $request->request->has('email_delivery');
        $pdfDownloadHistory = $request->request->has('pdf_download_history');

        if ($locale === '' || !array_key_exists($locale, self::LOCALE_OPTIONS)) {
            $errors[] = 'Locale is required.';
        }

        if ($portalLanguage === '' || !array_key_exists($portalLanguage, self::PORTAL_LANGUAGE_OPTIONS)) {
            $errors[] = 'Portal language is required.';
        }

        if ($paymentMethod === '' || !array_key_exists($paymentMethod, $this->getPaymentMethods())) {
            $errors[] = 'Payment method is required.';
            if ($paymentMethod === 'dummy' && !$this->isDummyPaymentAllowed()) {
                $errors[] = 'Test payment is disabled for production environments.';
            }
        }

        return [
            'errors' => $errors,
            'locale' => $locale !== '' ? $locale : 'de_DE',
            'portal_language' => $portalLanguage !== '' ? $portalLanguage : 'de',
            'email_delivery' => $emailDelivery,
            'pdf_download_history' => $pdfDownloadHistory,
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : 'manual',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getPaymentMethods(): array
    {
        $methods = self::PAYMENT_METHOD_LABELS;
        if (!$this->isDummyPaymentAllowed()) {
            unset($methods['dummy']);
        }

        return $methods;
    }

    private function isDummyPaymentAllowed(): bool
    {
        return $this->environment !== 'prod' || $this->allowDummyPayment;
    }

    private function validateProfileForInvoices(?CustomerProfile $profile): array
    {
        if ($profile === null) {
            return ['Complete the customer profile before configuring invoice preferences.'];
        }

        $errors = [];
        if (trim($profile->getFirstName()) === '') {
            $errors[] = 'First name is required for invoices.';
        }
        if (trim($profile->getLastName()) === '') {
            $errors[] = 'Last name is required for invoices.';
        }
        if (trim($profile->getAddress()) === '') {
            $errors[] = 'Address is required for invoices.';
        }
        if (trim($profile->getPostal()) === '') {
            $errors[] = 'Postal code is required for invoices.';
        }
        if (trim($profile->getCity()) === '') {
            $errors[] = 'City is required for invoices.';
        }
        if (trim($profile->getCountry()) === '') {
            $errors[] = 'Country is required for invoices.';
        }

        if ($profile->getVatId() !== null && $profile->getCompany() === null) {
            $errors[] = 'Company is required when VAT ID is provided.';
        }

        return $errors;
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }
}
