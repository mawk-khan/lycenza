<?php

namespace Tests\Unit\Privacy;

use App\Support\Privacy\PhoneNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1A.3: E.164 is the only accepted canonical form -- this
 * checkpoint deliberately does not guess a country for a local number.
 */
class PhoneNormalizerTest extends TestCase
{
    #[Test]
    public function a_valid_e164_number_is_accepted(): void
    {
        $this->assertSame('+919876543210', (new PhoneNormalizer)->normalize('+919876543210'));
        $this->assertSame('+12125551212', (new PhoneNormalizer)->normalize('+12125551212'));
    }

    #[Test]
    public function surrounding_whitespace_is_trimmed_deterministically(): void
    {
        $this->assertSame('+919876543210', (new PhoneNormalizer)->normalize('  +919876543210  '));
    }

    #[Test]
    public function a_local_number_without_country_code_is_rejected_not_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PhoneNormalizer)->normalize('9876543210');
    }

    #[Test]
    public function a_punctuation_formatted_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PhoneNormalizer)->normalize('(212) 555-1212');
    }

    #[Test]
    public function a_number_with_internal_spaces_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PhoneNormalizer)->normalize('+91 98765 43210');
    }

    #[Test]
    public function a_number_starting_with_zero_after_the_plus_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PhoneNormalizer)->normalize('+0123456789');
    }
}
