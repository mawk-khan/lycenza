<?php

namespace Tests\Feature\Configuration;

use App\Http\Middleware\ApplySecurityHeaders;
use App\Support\Presentation\BrandColor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DS-1 (ADR 0082): the design tokens in resources/css/app.css stay
 * self-hosted and keep the DS-0 §5 accessibility corrections. Checked
 * statically -- the repository has no browser test runner (rule 2).
 */
class DesignTokensTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(base_path('resources/css/app.css'));
    }

    /** @return array<string, string> the hex --ds-* values of the first :root block */
    private function rootHexTokens(): array
    {
        $this->assertSame(1, preg_match('/^:root \{(.*?)^\}/ms', $this->css(), $block), 'one plain :root token block');
        preg_match_all('/--ds-([a-z0-9-]+):\s*(#[0-9a-f]{6});/i', $block[1], $pairs, PREG_SET_ORDER);

        return collect($pairs)->mapWithKeys(fn (array $m) => [$m[1] => strtoupper($m[2])])->all();
    }

    #[Test]
    public function fonts_are_self_hosted_and_the_csp_needs_no_exception(): void
    {
        $css = $this->css();

        $this->assertDoesNotMatchRegularExpression('/@import\s+(url\()?[\'"]?https?:/i', $css, 'no remote stylesheet or font');
        $this->assertDoesNotMatchRegularExpression('/fonts\.(googleapis|gstatic|bunny)/i', $css);
        foreach (['@fontsource/barlow/400.css', '@fontsource/barlow-condensed/600.css'] as $import) {
            $this->assertStringContainsString("@import '{$import}';", $css);
        }
        $this->assertStringContainsString("font-src 'self'", ApplySecurityHeaders::APP_CSP);
        $this->assertStringContainsString("style-src 'self'", ApplySecurityHeaders::APP_CSP);

        $package = json_decode((string) file_get_contents(base_path('package.json')), true);
        foreach (['@fontsource/barlow', '@fontsource/barlow-condensed'] as $name) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $package['devDependencies'][$name] ?? '', "{$name} pinned exactly");
        }
    }

    #[Test]
    public function the_token_pairs_meet_wcag_aa(): void
    {
        $t = $this->rootHexTokens();
        foreach (['ground', 'ink', 'ink-muted', 'control-line', 'accent', 'accent-strong', 'on-accent', 'danger', 'accent-900'] as $key) {
            $this->assertArrayHasKey($key, $t, "--ds-{$key}");
        }
        $this->assertSame(BrandColor::GROUND, $t['ground'], 'BrandColor::strong() targets the same ground');

        $pairs = [
            ['ink', 'ground', 4.5],
            ['ink-muted', 'ground', 4.5],          // secondary text (never graphite-600)
            ['accent-strong', 'ground', 4.5],      // accent text
            ['on-accent', 'accent-strong', 4.5],   // primary button label on its fill
            ['danger', 'ground', 4.5],             // error text
            ['ground', 'accent-900', 4.5],         // light text on the deep accent (top bar)
            ['control-line', 'ground', 3.0],       // form control boundary (WCAG 1.4.11)
        ];
        foreach ($pairs as [$fg, $bg, $min]) {
            $this->assertGreaterThanOrEqual($min, BrandColor::contrastRatio($t[$fg], $t[$bg]), "--ds-{$fg} on --ds-{$bg}");
        }
    }

    #[Test]
    public function a_school_accent_drives_the_ramp_and_text_uses_the_strong_step(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(':root[data-brand-accent] {', $css);
        $this->assertStringContainsString('--ds-accent-700: var(--ds-accent-strong);', $css);
        $this->assertStringContainsString('@theme inline {', $css);
        $this->assertStringContainsString('--color-accent-strong: var(--ds-accent-strong);', $css);

        $layout = (string) file_get_contents(base_path('resources/js/Layouts/AccountLayout.vue'));
        $this->assertStringContainsString("root.style.setProperty('--ds-accent-strong', accent.accentStrong)", $layout, 'set through the CSSOM, never markup');
        $this->assertStringContainsString("root.removeAttribute('data-brand-accent')", $layout, 'cleared when no accent applies');
    }
}
