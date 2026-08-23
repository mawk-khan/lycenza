<?php

namespace Tests\Unit\Privacy;

use App\Support\Privacy\EmailNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1A.3: the deterministic email-normalization contract every
 * future create/update/lookup/duplicate-detection call site must
 * share.
 */
class EmailNormalizerTest extends TestCase
{
    #[Test]
    public function a_valid_email_is_normalized(): void
    {
        $this->assertSame('parent@example.com', (new EmailNormalizer)->normalize('parent@example.com'));
    }

    #[Test]
    public function case_and_whitespace_normalization_is_deterministic(): void
    {
        $normalizer = new EmailNormalizer;

        $this->assertSame('parent@example.com', $normalizer->normalize('  Parent@Example.COM  '));
        $this->assertSame(
            $normalizer->normalize('Parent@Example.com'),
            $normalizer->normalize('  parent@example.com  '),
        );
    }

    #[Test]
    public function dots_and_plus_tags_are_never_stripped(): void
    {
        $normalizer = new EmailNormalizer;

        $this->assertSame('a.b@example.com', $normalizer->normalize('a.b@example.com'));
        $this->assertSame('parent+school@example.com', $normalizer->normalize('parent+school@example.com'));
        $this->assertNotSame(
            $normalizer->normalize('a.b@example.com'),
            $normalizer->normalize('ab@example.com'),
        );
    }

    #[Test]
    public function a_malformed_email_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EmailNormalizer)->normalize('not-an-email');
    }
}
