<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Infrastructure\Config\JsonArray;
/**
 * Data is frozen with the invoice, never inferred from subsequently edited profiles.
 *
 * @psalm-type Party = array{name: string, street: string, address: string, postal: string, city: string, country: string, email: string, iban: string, bic: string, contact: string, phone: string, vat_id: string, tax_number: string}
 * @psalm-type TaxGroup = array{tax_rate_bps: int, net_cents: int, tax_cents: int, gross_cents: int}
 * @psalm-type InvoiceLine = array{description: string, quantity_milli: int, unit_price_cents: int, tax_rate_bps: int, net_cents: int, tax_cents: int, gross_cents: int}
 * @psalm-type Snapshot = array{company: Party, customer: Party, document: array{number: string, currency: string, issue_date: string, due_date: string, total_cents: int}, electronic_invoice: array{buyer_reference: string, service_date: string}, items: list<InvoiceLine>, tax_breakdown: list<TaxGroup>, tax_mode: string, net_cents: int, tax_cents: int, gross_cents: int}
 */
final class ElectronicInvoiceData
{
    /** @return array{buyer_reference: string, service_date: string} */
    public static function fromInput(array $input): array
    {
        $result = [
            'buyer_reference' => self::inputText($input['buyer_reference'] ?? ''),
            'service_date' => self::inputText($input['service_date'] ?? ''),
        ];
        if ('' !== $result['service_date']) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $result['service_date']);
            if (false === $date || $date->format('Y-m-d') !== $result['service_date']) {
                throw new \InvalidArgumentException('billing.invalid_date');
            }
        }

        return $result;
    }

    /** @return Snapshot */
    public static function snapshot(array $details, string $format): array
    {
        if (self::issues($details, $format) !== []) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }

        $document = self::record($details['document'] ?? null);
        $electronic = self::record($details['electronic_invoice'] ?? null);
        $items = [];
        $rows = self::record($details['items'] ?? null);
        foreach (array_keys($rows) as $key) {
            $item = self::record($rows[$key]);
            $quantity = self::integer($item, 'quantity_milli');
            if ($quantity <= 0) {
                throw new \InvalidArgumentException('billing.einvoice_incomplete');
            }
            $items[] = [
                'description' => self::text($item, 'description'),
                'quantity_milli' => $quantity,
                'unit_price_cents' => self::integer($item, 'unit_price_cents'),
                ...self::taxGroup($item),
            ];
        }
        $groups = [];
        $taxGroups = self::record($details['tax_breakdown'] ?? null);
        foreach (array_keys($taxGroups) as $key) {
            $groups[] = self::taxGroup(self::record($taxGroups[$key]));
        }
        $mode = self::text($details, 'tax_mode');
        if ($items === [] || $groups === [] || !in_array($mode, ['standard', 'exempt', 'reverse_charge', 'small_business'], true)) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }

        return [
            'company' => self::party(self::record($details['company'] ?? null)),
            'customer' => self::party(self::record($details['customer'] ?? null)),
            'document' => [
                'number' => self::text($document, 'number'),
                'currency' => self::text($document, 'currency'),
                'issue_date' => self::date($document, 'issue_date'),
                'due_date' => self::date($document, 'due_date'),
                'total_cents' => self::integer($document, 'total_cents'),
            ],
            'electronic_invoice' => [
                'buyer_reference' => self::optionalText($electronic, 'buyer_reference'),
                'service_date' => self::date($electronic, 'service_date'),
            ],
            'items' => $items,
            'tax_breakdown' => $groups,
            'tax_mode' => $mode,
            'net_cents' => self::integer($details, 'net_cents'),
            'tax_cents' => self::integer($details, 'tax_cents'),
            'gross_cents' => self::integer($details, 'gross_cents'),
        ];
    }

    private static function inputText(mixed $value): string
    {
        if (!is_string($value) || mb_strlen($value) > 200 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('billing.einvoice_invalid_data');
        }

        return trim($value);
    }

    /** @return array<array-key, mixed> */
    private static function record(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }

        return $value;
    }

    private static function text(array $record, string $key): string
    {
        $strings = array_filter($record, 'is_string');
        if (!isset($strings[$key])) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }

        return $strings[$key];
    }

    private static function optionalText(array $record, string $key): string
    {
        return ($record[$key] ?? null) === null ? '' : self::text($record, $key);
    }

    private static function integer(array $record, string $key): int
    {
        $integers = array_filter($record, 'is_int');
        if (!isset($integers[$key]) || $integers[$key] < 0) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }

        return $integers[$key];
    }

    private static function date(array $record, string $key): string
    {
        $value = self::text($record, $key);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }

        return $value;
    }

    /** @return Party */
    private static function party(array $record): array
    {
        return [
            'name' => self::text($record, 'name'),
            'street' => self::optionalText($record, 'street'),
            'address' => self::optionalText($record, 'address'),
            'postal' => self::text($record, 'postal'),
            'city' => self::text($record, 'city'),
            'country' => self::text($record, 'country'),
            'email' => self::text($record, 'email'),
            'iban' => self::optionalText($record, 'iban'),
            'bic' => self::optionalText($record, 'bic'),
            'contact' => self::optionalText($record, 'contact'),
            'phone' => self::optionalText($record, 'phone'),
            'vat_id' => self::optionalText($record, 'vat_id'),
            'tax_number' => self::optionalText($record, 'tax_number'),
        ];
    }

    /** @return TaxGroup */
    private static function taxGroup(array $record): array
    {
        $rate = self::integer($record, 'tax_rate_bps');
        if ($rate > 10000) {
            throw new \InvalidArgumentException('billing.einvoice_incomplete');
        }

        return [
            'tax_rate_bps' => $rate,
            'net_cents' => self::integer($record, 'net_cents'),
            'tax_cents' => self::integer($record, 'tax_cents'),
            'gross_cents' => self::integer($record, 'gross_cents'),
        ];
    }

    /** @return list<string> Translation keys identifying missing or incompatible source data. */
    public static function issues(array $details, string $format = 'xrechnung'): array
    {
        $errors = [];
        $parties = [
            'company' => self::recordOrEmpty($details['company'] ?? null),
            'customer' => self::recordOrEmpty($details['customer'] ?? null),
        ];
        $electronic = self::recordOrEmpty($details['electronic_invoice'] ?? null);
        $document = self::recordOrEmpty($details['document'] ?? null);
        $seller = ['name', 'street', 'postal', 'city', 'country', 'email', 'iban'];
        if ('xrechnung' === $format) {
            array_push($seller, 'contact', 'phone');
        }
        foreach (['company' => $seller, 'customer' => ['name', 'address', 'postal', 'city', 'country', 'email']] as $party => $fields) {
            foreach ($fields as $field) {
                if ('' === trim(self::stringOrEmpty($parties[$party][$field] ?? null))) {
                    $errors[] = 'billing.einvoice_missing.'.$party.'.'.$field;
                }
            }
        }
        foreach (['company', 'customer'] as $party) {
            if (!preg_match('/^[A-Z]{2}$/D', self::stringOrEmpty($parties[$party]['country'] ?? null))) {
                $errors[] = 'billing.einvoice_invalid_country.'.$party;
            }
            if (!filter_var(self::stringOrEmpty($parties[$party]['email'] ?? null), FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'billing.einvoice_invalid_email.'.$party;
            }
        }
        if (empty($parties['company']['vat_id']) && empty($parties['company']['tax_number'])) {
            $errors[] = 'billing.einvoice_missing.company.tax_number';
        }
        if ('xrechnung' === $format && self::stringOrEmpty($electronic['buyer_reference'] ?? null) === '') {
            $errors[] = 'billing.einvoice_missing.buyer_reference';
        }
        if (empty($electronic['service_date'])) {
            $errors[] = 'billing.einvoice_missing.service_date';
        }
        if ('reverse_charge' === ($details['tax_mode'] ?? '') && (empty($parties['customer']['vat_id']) || empty($parties['company']['vat_id']))) {
            $errors[] = 'billing.einvoice_reverse_charge_ids';
        }
        if (!isset($details['items'], $details['tax_breakdown'], $details['net_cents'], $details['tax_cents'], $details['gross_cents'], $document['number'], $document['currency'], $document['issue_date'], $document['due_date'], $document['total_cents'])) {
            $errors[] = 'billing.einvoice_missing_snapshot';
        }

        return array_values(array_unique($errors));
    }

    /** @return array<array-key, mixed> */
    private static function recordOrEmpty(mixed $value): array
    {
        return JsonArray::orEmpty($value);
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
