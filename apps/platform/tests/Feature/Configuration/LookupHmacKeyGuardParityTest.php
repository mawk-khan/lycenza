<?php

namespace Tests\Feature\Configuration;

use App\Support\Configuration\ProductionConfigurationGuard;
use App\Support\Privacy\ContactLookupHasher;
use App\Support\Privacy\Exceptions\ContactLookupKeyNotConfiguredException;
use App\Support\Privacy\Exceptions\StatutoryIdentifierLookupKeyNotConfiguredException;
use App\Support\Privacy\StatutoryIdentifierLookupHasher;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * PPR P2-F2 (ADR 0074 section 32): the boot guard and the runtime lookup
 * hashers read the same configuration and agree on it -- a key the guard
 * accepts is one the hasher uses (same config path, same digest), and a key
 * the hasher refuses is one the guard refuses. The guard is stricter only in
 * the documented hardened-only direction (blank and committed values).
 * Synthetic School ids and values only.
 */
class LookupHmacKeyGuardParityTest extends TestCase
{
    private const SCHOOL = '00000000-0000-4000-8000-00000000f2f2';

    /**
     * @return array<string, array{mixed}>
     */
    public static function keys(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'committed dev value' => ['dev-local-only-contact-lookup-hmac-key-change-me'],
            'committed test value' => ['test-only-statutory-identifier-lookup-hmac-key'],
            'generated secret' => [bin2hex(random_bytes(32))],
            'short opaque secret' => ['x'],
        ];
    }

    #[Test]
    #[DataProvider('keys')]
    public function the_guard_never_accepts_a_key_the_runtime_hasher_refuses(mixed $key): void
    {
        foreach ([
            'contact_lookup' => fn () => (new ContactLookupHasher($key, 1))->hash(self::SCHOOL, 'email', 'parent@example.invalid'),
            'statutory_identifier_lookup' => fn () => (new StatutoryIdentifierLookupHasher($key, 1))->hash(self::SCHOOL, 'pan', 'ABCDE1234F'),
        ] as $purpose => $hash) {
            $code = ProductionConfigurationGuard::LOOKUP_KEYS[$purpose];
            $violations = $this->guardWith($purpose, $key);
            $guardAccepts = ! in_array("{$code}_missing", $violations, true) && ! in_array("{$code}_placeholder", $violations, true);

            try {
                $hash();
                $runtimeAccepts = true;
            } catch (ContactLookupKeyNotConfiguredException|StatutoryIdentifierLookupKeyNotConfiguredException) {
                $runtimeAccepts = false;
            }

            if ($guardAccepts) {
                $this->assertTrue($runtimeAccepts, "{$purpose}: the guard accepted a key the hasher refuses");
            }
            if (! $guardAccepts && $runtimeAccepts) {
                $this->assertTrue(
                    trim((string) $key) === '' || in_array($key, ProductionConfigurationGuard::PLACEHOLDER_LOOKUP_KEYS, true),
                    "{$purpose}: the guard is stricter than the hasher only for a blank or committed value",
                );
            }
            if (! $runtimeAccepts) {
                $this->assertContains("{$code}_missing", $violations, "{$purpose}: the hasher refuses it, so the guard must report it missing");
            }
        }
    }

    #[Test]
    public function an_accepted_key_is_the_key_the_running_hashers_use(): void
    {
        $contact = 'contact-'.bin2hex(random_bytes(16));
        $statutory = 'statutory-'.bin2hex(random_bytes(16));
        config([
            'privacy.contact_lookup' => ['hmac_key' => $contact, 'hmac_key_version' => 3],
            'privacy.statutory_identifier_lookup' => ['hmac_key' => $statutory, 'hmac_key_version' => 2],
        ]);
        app()->forgetInstance(ContactLookupHasher::class);
        app()->forgetInstance(StatutoryIdentifierLookupHasher::class);

        $violations = (new ProductionConfigurationGuard(new Repository(['privacy' => config('privacy'), 'app' => ['key' => config('app.key')]])))->violations();
        $this->assertSame([], array_values(array_filter($violations, fn (string $v) => str_contains($v, 'lookup'))));

        $this->assertSame(
            hash_hmac('sha256', 'guardian-contact|'.self::SCHOOL.'|email|parent@example.invalid', $contact),
            app(ContactLookupHasher::class)->hash(self::SCHOOL, 'email', 'parent@example.invalid'),
        );
        $this->assertSame(3, app(ContactLookupHasher::class)->keyVersion());
        $this->assertSame(
            hash_hmac('sha256', 'statutory-identifier|'.self::SCHOOL.'|pan|ABCDE1234F', $statutory),
            app(StatutoryIdentifierLookupHasher::class)->hash(self::SCHOOL, 'pan', 'ABCDE1234F'),
        );
        $this->assertSame(2, app(StatutoryIdentifierLookupHasher::class)->keyVersion());

        app()->forgetInstance(ContactLookupHasher::class);
        app()->forgetInstance(StatutoryIdentifierLookupHasher::class);
    }

    #[Test]
    public function every_committed_lookup_key_value_is_a_refused_placeholder(): void
    {
        // .ddev/config.yaml commits the same two values as .env.example (it is
        // not mounted into the test container, so it is not read here).
        $found = [];
        foreach (['.env.example', 'phpunit.xml'] as $file) {
            preg_match_all('/(?:CONTACT|STATUTORY_IDENTIFIER)_LOOKUP_HMAC_KEY(?:=|" value=")([^"\s]+)/', (string) file_get_contents(base_path($file)), $matches);
            $found = [...$found, ...$matches[1]];
        }

        $this->assertCount(4, $found, '.env.example and phpunit.xml each commit both keys');
        foreach ($found as $value) {
            $this->assertContains($value, ProductionConfigurationGuard::PLACEHOLDER_LOOKUP_KEYS, 'a committed lookup key must be refused in a hardened environment');
        }
    }

    /**
     * @return list<string>
     */
    private function guardWith(string $purpose, mixed $key): array
    {
        $other = $purpose === 'contact_lookup' ? 'statutory_identifier_lookup' : 'contact_lookup';

        try {
            return (new ProductionConfigurationGuard(new Repository([
                'app' => ['key' => 'base64:'.base64_encode(random_bytes(32))],
                'privacy' => [
                    $purpose => ['hmac_key' => $key, 'hmac_key_version' => 1],
                    $other => ['hmac_key' => 'other-'.bin2hex(random_bytes(16)), 'hmac_key_version' => 1],
                ],
            ])))->violations();
        } catch (Throwable $e) {
            $this->fail('the guard must report, never throw: '.$e::class);
        }
    }
}
