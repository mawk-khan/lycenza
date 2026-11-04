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
    public function the_shell_is_styled_only_through_the_design_tokens(): void
    {
        // DS-2 (ADR 0082 §8): the migrated shell files carry no raw palette
        // utility, so a rebrand or a School accent reaches all of it.
        $palette = '/\b(?:bg|text|border|outline|ring|divide|from|to|via)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/';
        foreach (['resources/js/Layouts/AccountLayout.vue', 'resources/js/Components/Navigation/NavigationGroupList.vue'] as $file) {
            $code = preg_replace(['/<!--.*?-->/s', '/^\s*\/\/.*$/m'], '', (string) file_get_contents(base_path($file)));
            $this->assertDoesNotMatchRegularExpression($palette, $code, "{$file} uses a raw palette class");
        }

        $layout = (string) file_get_contents(base_path('resources/js/Layouts/AccountLayout.vue'));
        $this->assertStringContainsString('bg-accent-900', $layout, 'the bar is the deep accent step');
        $this->assertStringContainsString("'bg-accent-strong font-medium text-on-accent'", $layout, 'the active item is the strong step');
        $this->assertSame(1, substr_count($layout, ':style='), 'only the brand label chip sets a style (its server-derived foreground)');

        $css = $this->css();
        $this->assertStringNotContainsString('#29d', preg_replace('/\/\*.*?\*\//s', '', $css), 'the progress bar follows the accent');
    }

    #[Test]
    public function the_shared_components_are_styled_only_through_the_design_tokens(): void
    {
        // DS-3 (ADR 0082 §9). ElevationBanner keeps its amber security
        // styling until the owner decides OD-DS-4 -- deliberately not listed.
        $palette = '/\b(?:bg|text|border|outline|ring|divide|from|to|via)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/';
        foreach ([
            'ConfirmDialog', 'DuplicateStudentWarning', 'EmptyState', 'FlashMessage', 'MfaCodeField', 'Pagination',
            'RefusalMessage', 'StatusBadge', 'Form/CheckboxField', 'Form/ErrorSummary', 'Form/SearchCombobox',
            'Form/SelectField', 'Form/TextField',
        ] as $component) {
            $code = preg_replace(['/<!--.*?-->/s', '/^\s*\/\/.*$/m'], '', (string) file_get_contents(resource_path("js/Components/{$component}.vue")));
            $this->assertDoesNotMatchRegularExpression($palette, $code, "{$component} uses a raw palette class");
        }

        // Status is never colour alone: a glyph (decorative) + the word, from a closed set of families.
        $badge = (string) file_get_contents(resource_path('js/Components/StatusBadge.vue'));
        $this->assertStringContainsString('<span aria-hidden="true">{{ style.glyph }}</span>', $badge);
        $this->assertSame(6, preg_match_all('/^    (positive|info|progress|attention|negative|neutral): \{ glyph:/m', $badge));

        // The component layer exists, and its frame marks stay decorative.
        $css = $this->css();
        foreach (['.btn', '.btn-primary', '.btn-secondary', '.btn-ghost', '.btn-danger', '.input', '.field-label', '.field-hint', '.field-error', '.notice', '.tag', '.data-table', '.frame'] as $class) {
            $this->assertMatchesRegularExpression('/^\s+'.preg_quote($class, '/').' \{/m', $css, $class);
        }
        $this->assertMatchesRegularExpression('/\.frame::before \{[^}]*content: \'\';[^}]*pointer-events: none;/s', $css);
        $this->assertStringContainsString('aria-invalid:border-danger', $css, 'an invalid control is marked by aria-invalid, which the fields already set');
    }

    /**
     * DS-4 (ADR 0082 §10): the page areas already migrated onto the tokens.
     * Each DS-4 slice appends its folders; when every page is listed, the
     * guard becomes "all of resources/js" (ADR 0082 §2.7).
     */
    private const MIGRATED = [
        'resources/js/Pages/App/Students',          // DS-4a
        'resources/js/Components/Students',         // DS-4a
        'resources/js/Components/StudentIdentityFields.vue', // DS-4a
        'resources/js/Pages/App/Guardians',         // DS-4b
        'resources/js/Pages/App/Enrollments',       // DS-4b
        'resources/js/Pages/App/Admissions',        // DS-4b
        'resources/js/Pages/App/EnrollmentRollovers', // DS-4b
        'resources/js/Components/GuardianIdentityFields.vue', // DS-4b
        'resources/js/Components/RelationshipFlags.vue', // DS-4b
        'resources/js/Pages/App/Finance',           // DS-4c
        'resources/js/Components/Fees',             // DS-4c
        'resources/js/Components/ChargeEndForm.vue', // DS-4c
        'resources/js/Pages/App/HR',                // DS-4d
        'resources/js/Pages/App/Leave',             // DS-4d
        'resources/js/Pages/App/Payroll',           // DS-4d
        'resources/js/Pages/App/StaffAttendance',   // DS-4d
        'resources/js/Pages/App/StaffAccounts',     // DS-4d
        'resources/js/Pages/App/My',                // DS-4d
        'resources/js/Components/HR',               // DS-4d
    ];

    #[Test]
    public function migrated_pages_are_styled_only_through_the_design_tokens(): void
    {
        $palette = '/\b(?:bg|text|border|outline|ring|divide|placeholder|decoration|accent|fill|stroke|from|to|via)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/';
        $files = [];
        foreach (self::MIGRATED as $path) {
            $full = base_path($path);
            $files = [...$files, ...(is_dir($full) ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS))) : [new \SplFileInfo($full)])];
        }
        $vue = array_filter($files, fn (\SplFileInfo $f) => $f->getExtension() === 'vue');
        $this->assertGreaterThan(130, count($vue), 'the migrated scope resolves to real files');
        foreach ($vue as $file) {
            $code = preg_replace(['/<!--.*?-->/s', '/^\s*\/\/.*$/m'], '', (string) file_get_contents($file->getPathname()));
            $this->assertDoesNotMatchRegularExpression($palette, $code, $file->getPathname().' uses a raw palette class');
            $this->assertDoesNotMatchRegularExpression('/(?<![\w:-])rounded(?:-(?:sm|md|lg))?(?![\w-])/', $code, $file->getPathname().' uses a rounded corner (the design is square: rounded-xs)');
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
