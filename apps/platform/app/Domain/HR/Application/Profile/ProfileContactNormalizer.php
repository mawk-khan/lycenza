<?php

namespace App\Domain\HR\Application\Profile;

use App\Support\Privacy\EmailNormalizer;

/**
 * HR.2A: the server-side normal form of the profile's contact values -- one
 * place, used by every writer. Emails take the platform canonical form
 * (EmailNormalizer: trimmed, lower-case). Phone numbers are trimmed with
 * internal whitespace collapsed; their allowed characters are decided by
 * PHONE_RULE at validation (digits, spaces, `+ - ( ) .`), never E.164-forced,
 * because a School records local numbers. A contact value is never an
 * identity and never unique (a household may share a number); only the work
 * email keeps its existing School-unique key.
 */
final class ProfileContactNormalizer
{
    public const PHONE_RULE = 'regex:/^\+?[0-9][0-9 ()\-.]{4,30}$/';

    public const EMAIL_FIELDS = ['work_email', 'personal_email'];

    public const PHONE_FIELDS = ['work_phone', 'personal_phone', 'alternate_phone'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalize(array $attributes): array
    {
        foreach (self::EMAIL_FIELDS as $field) {
            if (isset($attributes[$field]) && is_string($attributes[$field])) {
                $value = trim($attributes[$field]);
                $attributes[$field] = $value === '' ? null : EmailNormalizer::canonical($value);
            }
        }
        foreach (self::PHONE_FIELDS as $field) {
            if (isset($attributes[$field]) && is_string($attributes[$field])) {
                $value = trim((string) preg_replace('/\s+/', ' ', $attributes[$field]));
                $attributes[$field] = $value === '' ? null : $value;
            }
        }

        return $attributes;
    }
}
