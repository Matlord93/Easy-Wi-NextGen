<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AppSettingsService;

final readonly class BillingSettings
{
    public function __construct(private AppSettingsService $settings)
    {
    }

    public function get(): array
    {
        $stored = $this->settings->getSettings()[AppSettingsService::KEY_BILLING_OPTIONS] ?? [];

        return array_replace([
            'company_name' => '', 'company_address' => '', 'company_email' => '', 'company_vat_id' => '',
            'company_street' => '', 'company_postal' => '', 'company_city' => '', 'company_country' => 'DE',
            'company_contact' => '', 'company_phone' => '', 'company_tax_number' => '', 'company_iban' => '', 'company_bic' => '',
            'company_footer' => '', 'tax_mode' => 'standard', 'tax_rate' => '19.00',
            'automation_enabled' => false, 'reminders_enabled' => false,
            'reminder_grace_days' => 7, 'reminder_fees' => ['0.00', '0.00', '0.00'],
        ], is_array($stored) ? $stored : []);
    }

    public function save(array $input): void
    {
        $values = $this->get();
        foreach (['company_name', 'company_address', 'company_email', 'company_vat_id', 'company_footer'] as $key) {
            $values[$key] = trim((string) ($input[$key] ?? ''));
            if (mb_strlen($values[$key]) > 2000) {
                throw new \InvalidArgumentException('billing.invalid_settings');
            }
        }
        if ($values['company_email'] !== '' && !filter_var($values['company_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('billing.invalid_settings');
        }
        foreach (['company_street', 'company_postal', 'company_city', 'company_country', 'company_contact', 'company_phone', 'company_tax_number', 'company_iban', 'company_bic'] as $key) {
            $raw = $input[$key] ?? $values[$key];
            if (!is_string($raw) || mb_strlen($raw) > 200 || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
                throw new \InvalidArgumentException('billing.einvoice_invalid_data');
            }
            $values[$key] = trim($raw);
        }
        $values['company_country'] = strtoupper($values['company_country']);
        $values['company_iban'] = strtoupper(str_replace(' ', '', $values['company_iban']));
        $values['company_bic'] = strtoupper($values['company_bic']);
        if (!preg_match('/^[A-Z]{2}$/D', $values['company_country']) || ('' !== $values['company_bic'] && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D', $values['company_bic']))) {
            throw new \InvalidArgumentException('billing.einvoice_invalid_data');
        }
        if ('' !== $values['company_iban'] && count(\Symfony\Component\Validator\Validation::createValidator()->validate($values['company_iban'], new \Symfony\Component\Validator\Constraints\Iban())) > 0) {
            throw new \InvalidArgumentException('billing.einvoice_invalid_iban');
        }
        $values['tax_mode'] = (string) ($input['tax_mode'] ?? 'standard');
        $values['tax_rate'] = (string) ($input['tax_rate'] ?? '19.00');
        BillingAmount::splitGross(100, $values['tax_mode'], BillingAmount::parse($values['tax_rate']));
        $values['automation_enabled'] = filter_var($input['automation_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $values['reminders_enabled'] = filter_var($input['reminders_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $days = filter_var($input['reminder_grace_days'] ?? 7, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
        if ($days === false) {
            throw new \InvalidArgumentException('billing.invalid_settings');
        }
        $values['reminder_grace_days'] = $days;
        $fees = $input['reminder_fees'] ?? ['0.00', '0.00', '0.00'];
        if (!is_array($fees) || count($fees) !== 3) {
            throw new \InvalidArgumentException('billing.invalid_settings');
        }
        foreach ($fees as $fee) {
            BillingAmount::parse((string) $fee);
        }
        $values['reminder_fees'] = array_values($fees);
        $this->settings->updateSettings([AppSettingsService::KEY_BILLING_OPTIONS => $values]);
    }
}
