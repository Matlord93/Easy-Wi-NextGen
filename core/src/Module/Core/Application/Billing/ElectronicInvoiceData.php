<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

/** Data is frozen with the invoice, never inferred from subsequently edited profiles. */
final class ElectronicInvoiceData
{
    public static function fromInput(array $input): array
    {
        $result = [];
        foreach (['buyer_reference', 'service_date'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > 200 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('billing.einvoice_invalid_data');
            }
            $result[$key] = trim($value);
        }
        if ('' !== $result['service_date']) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $result['service_date']);
            if (false === $date || $date->format('Y-m-d') !== $result['service_date']) {
                throw new \InvalidArgumentException('billing.invalid_date');
            }
        }

        return $result;
    }

    /** @return list<string> Translation keys identifying missing or incompatible source data. */
    public static function issues(array $details, string $format = 'xrechnung'): array
    {
        $errors = [];
        $seller = ['name', 'street', 'postal', 'city', 'country', 'email', 'iban'];
        if ('xrechnung' === $format) {
            array_push($seller, 'contact', 'phone');
        }
        foreach (['company' => $seller, 'customer' => ['name', 'address', 'postal', 'city', 'country', 'email']] as $party => $fields) {
            foreach ($fields as $field) {
                if (!is_string($details[$party][$field] ?? null) || '' === trim($details[$party][$field])) {
                    $errors[] = 'billing.einvoice_missing.'.$party.'.'.$field;
                }
            }
        }
        foreach (['company', 'customer'] as $party) {
            if (!preg_match('/^[A-Z]{2}$/D', (string) ($details[$party]['country'] ?? ''))) {
                $errors[] = 'billing.einvoice_invalid_country.'.$party;
            }
            if (!filter_var($details[$party]['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'billing.einvoice_invalid_email.'.$party;
            }
        }
        if (empty($details['company']['vat_id']) && empty($details['company']['tax_number'])) {
            $errors[] = 'billing.einvoice_missing.company.tax_number';
        }
        if ('xrechnung' === $format && empty($details['electronic_invoice']['buyer_reference'])) {
            $errors[] = 'billing.einvoice_missing.buyer_reference';
        }
        if (empty($details['electronic_invoice']['service_date'])) {
            $errors[] = 'billing.einvoice_missing.service_date';
        }
        if ('reverse_charge' === ($details['tax_mode'] ?? '') && (empty($details['customer']['vat_id']) || empty($details['company']['vat_id']))) {
            $errors[] = 'billing.einvoice_reverse_charge_ids';
        }
        if (!isset($details['items'], $details['tax_breakdown'], $details['net_cents'], $details['tax_cents'], $details['gross_cents'], $details['document']['number'], $details['document']['currency'], $details['document']['issue_date'], $details['document']['due_date'], $details['document']['total_cents'])) {
            $errors[] = 'billing.einvoice_missing_snapshot';
        }

        return array_values(array_unique($errors));
    }
}
