<?php

namespace Tests\Feature\Navigation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SWC.1 (ADR 0073 section 8): the accessibility baseline of the navigation
 * shell, checked statically (the repository has no browser test runner, and
 * SWC adds none -- rule 2). The scoped `eslint-plugin-vuejs-accessibility`
 * rules run in the existing `npm run lint` CI step; this test pins that the
 * SWC files stay in its scope and that the shell keeps its landmarks,
 * disclosure state, skip link and server-decided active state.
 */
class NavigationAccessibilityBaselineTest extends TestCase
{
    private function source(string $file): string
    {
        return (string) file_get_contents(base_path($file));
    }

    #[Test]
    public function the_shell_keeps_its_landmarks_disclosure_and_skip_link(): void
    {
        $layout = $this->source('resources/js/Layouts/AccountLayout.vue');

        foreach ([
            'href="#main-content"', 'id="main-content"',                      // skip link and its target
            'aria-controls="primary-navigation"', 'id="primary-navigation"',  // disclosure button -> region
            ':aria-expanded=', "event.key === 'Escape'", 'menuButton.value?.focus()',
            "menuOpen ? 'block' : 'hidden'",                                   // closed = display:none, not focusable
            'type="button"',
            'aria-label="Account"', 'aria-label="Home"', 'aria-label="Guardian portal"', 'aria-label="School"', 'aria-label="Platform"',
            ':aria-current="navigation.home.active ? \'page\' : undefined"',
            'focus-visible:outline',
        ] as $needle) {
            $this->assertStringContainsString($needle, $layout, $needle);
        }

        $groups = $this->source('resources/js/Components/Navigation/NavigationGroupList.vue');
        $this->assertStringContainsString(":aria-current=\"item.active ? 'page' : undefined\"", $groups);
        $this->assertStringContainsString(':aria-labelledby="`nav-group-${group.key}`"', $groups);
        $this->assertStringNotContainsString('<h2', $groups, 'group labels are not headings: the page h1 follows the navigation');
        $this->assertStringNotContainsString('window.location', $groups, 'active state comes from the server');
    }

    #[Test]
    public function the_swc_files_are_in_the_accessibility_lint_scope(): void
    {
        $config = $this->source('eslint.config.js');
        $this->assertStringContainsString("import pluginVueA11y from 'eslint-plugin-vuejs-accessibility';", $config);
        foreach (['resources/js/Layouts/AccountLayout.vue', 'resources/js/Components/Navigation/**/*.vue', 'resources/js/Pages/Home.vue', 'resources/js/Pages/App/Dashboard.vue'] as $file) {
            $this->assertStringContainsString("'{$file}'", $config);
        }

        $package = json_decode($this->source('package.json'), true);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $package['devDependencies']['eslint-plugin-vuejs-accessibility'], 'pinned exactly');
        $this->assertSame('eslint resources/js', $package['scripts']['lint']);
    }
}
