<?php

namespace App\Support\Validation;

use App\Support\Privacy\PhoneNormalizer;
use Closure;

/**
 * ORG.12C (ADR 0080 §8): the one set of validation rules for institutional
 * profile fields shared by the School and Campus profiles -- contact details
 * of an institution, never a User's identity.
 *
 * - Email: syntactic only; no mailbox verification.
 * - Phone: the repository's E.164 contract (PhoneNormalizer), checked only
 *   when the value CHANGES, so a legacy value saved before ORG.12C never
 *   blocks an unrelated edit. No country-specific rule.
 * - Website: http or https only -- never javascript:, data:, file: or any
 *   other scheme.
 * - Address: the School profile's shape; every part optional, since
 *   countries structure addresses differently. Country: ISO 3166-1 alpha-2
 *   shape (two upper-case letters).
 */
final class InstitutionalProfileRules
{
    /** @return array<string, list<mixed>> */
    public static function contact(?string $currentPhone, string $presence = 'nullable'): array
    {
        return [
            'email' => [...self::presence($presence), 'nullable', 'email', 'max:255'],
            'phone' => [...self::presence($presence), 'nullable', 'string', 'max:32', self::phone($currentPhone)],
        ];
    }

    /** @return list<mixed> */
    public static function website(string $presence = 'nullable'): array
    {
        return [...self::presence($presence), 'nullable', 'string', 'max:255', 'url:http,https'];
    }

    /** @return array<string, list<mixed>> */
    public static function address(string $presence = 'nullable', bool $countryNullable = true): array
    {
        return [
            'address_line1' => [...self::presence($presence), 'nullable', 'string', 'max:255'],
            'address_line2' => [...self::presence($presence), 'nullable', 'string', 'max:255'],
            'city' => [...self::presence($presence), 'nullable', 'string', 'max:120'],
            'state_region' => [...self::presence($presence), 'nullable', 'string', 'max:120'],
            'postal_code' => [...self::presence($presence), 'nullable', 'string', 'max:20'],
            'country_code' => [...self::presence($presence), ...($countryNullable ? ['nullable'] : ['required']), 'string', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    /**
     * Upper-cases a submitted country code and trims text fields, so
     * validation and storage see the canonical form.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $input[$key] = $value === '' ? null : ($key === 'country_code' ? strtoupper($value) : $value);
            }
        }

        return $input;
    }

    private static function phone(?string $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            if (! is_string($value) || $value === $current) {
                return;
            }
            try {
                (new PhoneNormalizer)->normalize($value);
            } catch (\InvalidArgumentException) {
                $fail('Enter the phone number in international format, e.g. +14155550123.');
            }
        };
    }

    /** @return list<string> */
    private static function presence(string $presence): array
    {
        return $presence === 'sometimes' ? ['sometimes'] : [];
    }
}
