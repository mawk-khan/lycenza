<?php

namespace App\Support\Settings;

use InvalidArgumentException;

/**
 * One registered, typed School setting. Storage (SchoolSetting.value)
 * is JSONB -- this is what makes it "typed schema/validation" rather
 * than "arbitrary unserialized PHP blobs" (section 30): every read and
 * write goes through SchoolSettingsService, which validates against
 * the definition registered here.
 */
final class SettingDefinition
{
    /** Valid values for $type -- enforced in the constructor below, not just documented. */
    private const VALID_TYPES = ['string', 'bool', 'int', 'locale', 'timezone'];

    /**
     * @param  string  $type  One of self::VALID_TYPES -- kept as plain
     *                        `string` (not a `'string'|'bool'|...` literal-union docblock)
     *                        because that union isn't a compiler-enforced guarantee, only
     *                        the constructor guard below is; asserting it in a type-checker
     *                        -visible docblock previously made every later match/in_array
     *                        check against the same set look "provably always true" to
     *                        PHPStan, even the runtime guard meant to establish it.
     * @param  array<int, string>|null  $allowed  Optional closed set of allowed values.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $default,
        public readonly ?array $allowed = null,
    ) {
        if (! in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Unknown setting type '{$type}' for key '{$key}'.");
        }
    }

    public function validate(mixed $value): bool
    {
        $typeValid = match ($this->type) {
            'string', 'locale', 'timezone' => is_string($value),
            'bool' => is_bool($value),
            'int' => is_int($value),
            default => false,
        };

        if (! $typeValid) {
            return false;
        }

        return $this->allowed === null || in_array($value, $this->allowed, true);
    }
}
