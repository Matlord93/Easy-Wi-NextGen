<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Payment credentials are encrypted at rest and are never returned to a form. */
final class PaymentSettings implements \Symfony\Contracts\Service\ResetInterface
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;
    private bool $configured = false;

    #[\Override]
    public function reset(): void
    {
        $this->cached = null;
        $this->configured = false;
    }
    private const KEY = 'shop_payment_options';
    private const SECRETS = ['stripe_key', 'stripe_webhook', 'paypal_client', 'paypal_secret', 'paypal_webhook'];

    public function __construct(private readonly AppSettingRepository $repository, private readonly SecretsCryptoInterface $crypto, private readonly EntityManagerInterface $em, #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:app.stripe_secret_key_default:STRIPE_SECRET_KEY)%')] private readonly string $legacyStripeKey = '')
    {
    }

    public function isConfigured(): bool
    {
        $this->stored();

        return $this->configured;
    }

    /** @return array{bank_transfer:bool,stripe:bool,card:bool,klarna:bool,sepa_debit:bool,paypal:bool,paypal_sandbox:bool,currency:string,base_url:string,...<string,bool|string>} */
    public function publicSettings(): array
    {
        $values = $this->stored();
        $result = [
            'bank_transfer' => ($values['bank_transfer'] ?? true) === true,
            'stripe' => ($values['stripe'] ?? false) === true,
            'card' => ($values['card'] ?? true) === true,
            'klarna' => ($values['klarna'] ?? false) === true,
            'sepa_debit' => ($values['sepa_debit'] ?? false) === true,
            'paypal' => ($values['paypal'] ?? false) === true,
            'paypal_sandbox' => ($values['paypal_sandbox'] ?? true) === true,
            'currency' => ScalarValue::text($values['currency'] ?? 'EUR'),
            'base_url' => ScalarValue::text($values['base_url'] ?? ''),
        ];
        foreach (self::SECRETS as $key) {
            $result[$key.'_configured'] = isset($values[$key]) && '' !== $values[$key];
        }

        return $result;
    }

    public function secret(string $key, string $fallback = ''): string
    {
        if (!in_array($key, self::SECRETS, true)) {
            throw new \InvalidArgumentException('Unknown payment credential.');
        }
        $value = ScalarValue::text($this->stored()[$key] ?? '');

        return '' !== $value ? $this->crypto->decrypt($value) : $fallback;
    }

    /** @return list<string> */
    public function methods(): array
    {
        $settings = $this->publicSettings();
        $methods = $settings['bank_transfer'] ? ['bank_transfer'] : [];
        if (!$this->isConfigured() && '' !== $this->legacyStripeKey) {
            $methods[] = 'card';
        }
        if ($settings['stripe'] && '' !== $this->secret('stripe_key')) {
            foreach (['card', 'klarna', 'sepa_debit'] as $method) {
                if (($settings[$method] ?? false) === true) {
                    $methods[] = $method;
                }
            }
        }
        if ($settings['paypal'] && '' !== $this->secret('paypal_client') && '' !== $this->secret('paypal_secret')) {
            $methods[] = 'paypal';
        }

        return $methods;
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $values = $this->stored();
        foreach (['bank_transfer', 'stripe', 'card', 'klarna', 'sepa_debit', 'paypal', 'paypal_sandbox'] as $key) {
            $values[$key] = filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOL);
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'EUR')));
        if (!in_array($currency, ['EUR', 'USD', 'GBP', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN'], true)) {
            throw new \InvalidArgumentException('Unsupported shop currency.');
        }
        $url = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        $parts = parse_url($url);
        if (false === filter_var($url, FILTER_VALIDATE_URL) || false === $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '') {
            throw new \InvalidArgumentException('Enter the HTTPS origin of the panel, without a path.');
        }
        if ($values['sepa_debit'] && 'EUR' !== $currency) {
            throw new \InvalidArgumentException('SEPA direct debit requires EUR.');
        }
        $values['currency'] = $currency;
        $values['base_url'] = $url;
        foreach (self::SECRETS as $key) {
            $raw = trim((string) ($input[$key] ?? ''));
            if (strlen($raw) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
                throw new \InvalidArgumentException('Invalid payment credential.');
            }
            if ('' !== $raw) {
                $values[$key] = $this->crypto->encrypt($raw);
            }
            if (filter_var($input['clear_'.$key] ?? false, FILTER_VALIDATE_BOOL)) {
                unset($values[$key]);
            }
        }
        if ($values['stripe'] && (!($values['card'] || $values['klarna'] || $values['sepa_debit']) || empty($values['stripe_key']) || empty($values['stripe_webhook']))) {
            throw new \InvalidArgumentException('Stripe requires API and webhook credentials and at least one payment method.');
        }
        if ($values['paypal'] && (empty($values['paypal_client']) || empty($values['paypal_secret']) || empty($values['paypal_webhook']))) {
            throw new \InvalidArgumentException('PayPal requires client, secret and webhook ID.');
        }
        $setting = $this->repository->find(self::KEY) ?? new AppSetting(self::KEY, []);
        $setting->setValue($values);
        $this->em->persist($setting);
        $this->em->flush();
        $this->reset();
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        if (null === $this->cached) {
            $setting = $this->repository->find(self::KEY);
            $this->configured = null !== $setting;
            $this->cached = JsonMap::fromValue($setting?->getValue()) ?? [];
        }

        return $this->cached;
    }
}
