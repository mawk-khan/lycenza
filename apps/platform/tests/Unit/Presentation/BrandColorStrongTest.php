<?php

namespace Tests\Unit\Presentation;

use App\Support\Presentation\BrandColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DS-1 (ADR 0082 §2.4): a School accent's strong step is readable as text on
 * the design-system ground and under white text, for any accent.
 */
class BrandColorStrongTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function accents(): array
    {
        return [
            'white' => ['#FFFFFF'],
            'bright yellow' => ['#F5D90A'],
            'light cyan' => ['#7FFFD4'],
            'mid orange' => ['#F97316'],
            'industry steel' => ['#5980A6'],
            'network blue' => ['#1D4E89'],
            'pure red' => ['#FF0000'],
            'black' => ['#000000'],
        ];
    }

    #[Test]
    #[DataProvider('accents')]
    public function the_strong_step_reaches_text_contrast_on_the_ground_and_under_white(string $accent): void
    {
        $strong = BrandColor::strong($accent);

        $this->assertTrue(BrandColor::isValid($strong), $strong);
        $this->assertGreaterThanOrEqual(BrandColor::TEXT_CONTRAST, BrandColor::contrastRatio($strong, BrandColor::GROUND), "{$accent} -> {$strong} on the ground");
        $this->assertGreaterThanOrEqual(BrandColor::TEXT_CONTRAST, BrandColor::contrastRatio($strong, BrandColor::LIGHT), "{$accent} -> {$strong} under white");
        $this->assertSame($strong, BrandColor::strong($accent), 'deterministic');
    }

    #[Test]
    public function an_accent_that_is_already_strong_is_kept_and_a_weak_one_keeps_its_hue(): void
    {
        $this->assertSame('#1D4E89', BrandColor::strong('#1D4E89'));
        $this->assertSame('#000000', BrandColor::strong('#000000'));

        // Darkening scales every channel together: the hue of a weak accent survives.
        [$r, $g, $b] = array_map('hexdec', str_split(substr(BrandColor::strong('#F97316'), 1), 2));
        $this->assertGreaterThan($g, $r);
        $this->assertGreaterThan($b, $g);
    }

    #[Test]
    public function the_industry_steel_strong_step_matches_the_design_token(): void
    {
        // resources/css/app.css --ds-accent-strong is the Industry accent-700.
        $this->assertGreaterThanOrEqual(BrandColor::TEXT_CONTRAST, BrandColor::contrastRatio('#416180', BrandColor::GROUND));
        $this->assertLessThan(BrandColor::TEXT_CONTRAST, BrandColor::contrastRatio('#5980A6', BrandColor::GROUND), 'the raw steel accent is not text-safe (DS-0 §5)');
    }
}
