<?php

namespace Tests\Unit\Privacy;

use App\Support\Privacy\ContactLookupHasher;
use App\Support\Privacy\Exceptions\ContactLookupKeyNotConfiguredException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1A.3: the keyed, tenant-separated exact-match digest. Never
 * asserts the actual secret value -- only the digest's behavioral
 * properties.
 */
class ContactLookupHasherTest extends TestCase
{
    #[Test]
    public function the_same_school_type_and_value_always_produce_the_same_digest(): void
    {
        $hasher = new ContactLookupHasher('test-key', 1);

        $this->assertSame(
            $hasher->hash('school-a', 'email', 'parent@example.com'),
            $hasher->hash('school-a', 'email', 'parent@example.com'),
        );
    }

    #[Test]
    public function the_same_contact_value_in_two_different_schools_produces_different_digests(): void
    {
        $hasher = new ContactLookupHasher('test-key', 1);

        $this->assertNotSame(
            $hasher->hash('school-a', 'email', 'parent@example.com'),
            $hasher->hash('school-b', 'email', 'parent@example.com'),
        );
    }

    #[Test]
    public function the_same_school_and_value_with_a_different_contact_type_produces_a_different_digest(): void
    {
        $hasher = new ContactLookupHasher('test-key', 1);

        $this->assertNotSame(
            $hasher->hash('school-a', 'email', 'value'),
            $hasher->hash('school-a', 'mobile', 'value'),
        );
    }

    #[Test]
    public function changing_the_normalized_value_changes_the_digest(): void
    {
        $hasher = new ContactLookupHasher('test-key', 1);

        $this->assertNotSame(
            $hasher->hash('school-a', 'email', 'parent@example.com'),
            $hasher->hash('school-a', 'email', 'other@example.com'),
        );
    }

    #[Test]
    public function the_digest_is_not_a_plain_unkeyed_hash_of_the_value(): void
    {
        $hasher = new ContactLookupHasher('test-key', 1);
        $digest = $hasher->hash('school-a', 'email', 'parent@example.com');

        $this->assertNotSame(hash('sha256', 'parent@example.com'), $digest);
        $this->assertNotSame(hash('sha256', 'school-a|email|parent@example.com'), $digest);
    }

    #[Test]
    public function it_fails_closed_when_no_key_is_configured(): void
    {
        $hasher = new ContactLookupHasher(null, 1);

        $this->expectException(ContactLookupKeyNotConfiguredException::class);

        $hasher->hash('school-a', 'email', 'parent@example.com');
    }

    #[Test]
    public function it_fails_closed_when_the_configured_key_is_an_empty_string(): void
    {
        $hasher = new ContactLookupHasher('', 1);

        $this->expectException(ContactLookupKeyNotConfiguredException::class);

        $hasher->hash('school-a', 'email', 'parent@example.com');
    }
}
