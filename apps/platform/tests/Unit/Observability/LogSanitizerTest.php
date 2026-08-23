<?php

namespace Tests\Unit\Observability;

use App\Support\Observability\LogSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 section 37: the backstop that must catch a sensitive
 * key even when a call site forgets to keep it out of metadata in the
 * first place -- broad substring matching, not an exact allowlist.
 */
class LogSanitizerTest extends TestCase
{
    #[Test]
    #[DataProvider('sensitiveKeys')]
    public function it_redacts_known_sensitive_key_shapes(string $key): void
    {
        $result = (new LogSanitizer)->sanitize([$key => 'super-secret-value']);

        $this->assertSame('[redacted]', $result[$key]);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function sensitiveKeys(): array
    {
        return [
            ['password'],
            ['Password'],
            ['api_key'],
            ['apiKey'],
            ['API_KEY'],
            ['access_key'],
            ['private_key'],
            ['webhook_secret'],
            ['previous_secret_encrypted'],
            ['Authorization'],
            ['token'],
            ['refresh_token'],
            ['credential'],
            ['x-schoolos-signature'],
        ];
    }

    #[Test]
    public function it_leaves_non_sensitive_keys_untouched(): void
    {
        $result = (new LogSanitizer)->sanitize(['school_id' => 'abc', 'note' => 'ping']);

        $this->assertSame(['school_id' => 'abc', 'note' => 'ping'], $result);
    }

    #[Test]
    public function it_recurses_into_nested_arrays(): void
    {
        $result = (new LogSanitizer)->sanitize([
            'metadata' => [
                'nested' => [
                    'client_secret' => 'shh',
                    'note' => 'fine',
                ],
            ],
        ]);

        $this->assertSame('[redacted]', $result['metadata']['nested']['client_secret']);
        $this->assertSame('fine', $result['metadata']['nested']['note']);
    }
}
