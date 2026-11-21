<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\CustomerProfile;
use App\Module\Core\Domain\Entity\User;

/** @psalm-type Fields = array{firstname: string, lastname: string, address: string, postal: string, city: string, country: string, phone: string, company: string, vat_id: string} */
final class CustomerBillingDetails
{
    public const COUNTRIES = [
        'AT' => 'Austria', 'AU' => 'Australia', 'BE' => 'Belgium', 'CA' => 'Canada',
        'CH' => 'Switzerland', 'CZ' => 'Czech Republic', 'DE' => 'Germany', 'DK' => 'Denmark',
        'ES' => 'Spain', 'FR' => 'France', 'GB' => 'United Kingdom', 'IT' => 'Italy',
        'NL' => 'Netherlands', 'NO' => 'Norway', 'PL' => 'Poland', 'PT' => 'Portugal',
        'SE' => 'Sweden', 'US' => 'United States',
    ];

    public const LENGTHS = [
        'firstname' => 100, 'lastname' => 100, 'address' => 255, 'postal' => 40, 'city' => 120,
        'country' => 2, 'phone' => 40, 'company' => 160, 'vat_id' => 40,
    ];

    /**
     * @param array<string, string> $input
     * @return Fields
     */
    public static function fields(array $input): array
    {
        return [
            'firstname' => trim($input['firstname'] ?? ''),
            'lastname' => trim($input['lastname'] ?? ''),
            'address' => trim($input['address'] ?? ''),
            'postal' => trim($input['postal'] ?? ''),
            'city' => trim($input['city'] ?? ''),
            'country' => strtoupper(trim($input['country'] ?? '')),
            'phone' => trim($input['phone'] ?? ''),
            'company' => trim($input['company'] ?? ''),
            'vat_id' => strtoupper(str_replace(' ', '', trim($input['vat_id'] ?? ''))),
        ];
    }

    /** @return Fields */
    public static function fromProfile(?CustomerProfile $profile): array
    {
        return self::fields([
            'firstname' => $profile?->getFirstName() ?? '', 'lastname' => $profile?->getLastName() ?? '',
            'address' => $profile?->getAddress() ?? '', 'postal' => $profile?->getPostal() ?? '',
            'city' => $profile?->getCity() ?? '', 'country' => $profile?->getCountry() ?? '',
            'phone' => $profile?->getPhone() ?? '', 'company' => $profile?->getCompany() ?? '',
            'vat_id' => $profile?->getVatId() ?? '',
        ]);
    }

    /**
     * @param Fields $fields
     * @return list<string>
     */
    public static function errors(array $fields): array
    {
        $errors = [];
        foreach ([
            'firstname' => 'error_profile_first_name_required', 'lastname' => 'error_profile_last_name_required',
            'address' => 'error_profile_address_required', 'postal' => 'error_profile_postal_required',
            'city' => 'error_profile_city_required',
        ] as $field => $error) {
            if ('' === $fields[$field]) {
                $errors[] = $error;
            }
        }
        if (!isset(self::COUNTRIES[$fields['country']])) {
            $errors[] = 'error_profile_country_required';
        }
        foreach (self::LENGTHS as $field => $limit) {
            if (mb_strlen($fields[$field]) > $limit || preg_match('/[\x00-\x1f\x7f]/', $fields[$field])) {
                $errors[] = 'error_profile_field_too_long';
                break;
            }
        }
        if ('' !== $fields['vat_id']) {
            if (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,16}$/D', $fields['vat_id'])) {
                $errors[] = 'error_profile_vat_invalid';
            } elseif ('' !== $fields['country'] && !str_starts_with($fields['vat_id'], $fields['country'])) {
                $errors[] = 'error_profile_vat_country_prefix';
            }
            if ('' === $fields['company']) {
                $errors[] = 'error_profile_company_required_with_vat';
            }
        }

        return $errors;
    }

    public static function complete(?CustomerProfile $profile): bool
    {
        return null !== $profile && [] === self::errors(self::fromProfile($profile));
    }

    /** @param Fields $fields */
    public static function apply(User $customer, ?CustomerProfile $profile, array $fields): CustomerProfile
    {
        $profile ??= new CustomerProfile($customer, $fields['firstname'], $fields['lastname'], $fields['address'], $fields['postal'], $fields['city'], $fields['country']);
        $profile->setFirstName($fields['firstname']);
        $profile->setLastName($fields['lastname']);
        $profile->setAddress($fields['address']);
        $profile->setPostal($fields['postal']);
        $profile->setCity($fields['city']);
        $profile->setCountry($fields['country']);
        $profile->setPhone($fields['phone']);
        $profile->setCompany($fields['company']);
        $profile->setVatId($fields['vat_id']);
        $customer->setName(mb_substr($fields['firstname'].' '.$fields['lastname'], 0, 120));

        return $profile;
    }
}
