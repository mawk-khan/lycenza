<?php

namespace Tests\Feature;

use App\Support\ProductIdentity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SWC.1 (ADR 0073 section 7, owner decision X4): one user-facing product
 * name, Lycenza, on the shell, `/`, the Dashboard and the browser title --
 * and never the development-era "School OS" / phase framing on the surfaces
 * SWC.1 owns.
 */
class ProductIdentityTest extends TestCase
{
    #[Test]
    public function the_product_name_is_lycenza_on_the_server_and_in_the_browser_title(): void
    {
        $this->assertSame('Lycenza', ProductIdentity::NAME);

        $product = (string) file_get_contents(resource_path('js/support/product.ts'));
        $this->assertStringContainsString("export const PRODUCT_NAME = '".ProductIdentity::NAME."';", $product);

        $app = (string) file_get_contents(resource_path('js/app.ts'));
        $this->assertStringContainsString('PRODUCT_NAME', $app);
        $this->assertStringNotContainsString('School OS', $app);
        $this->assertStringNotContainsString('VITE_APP_NAME', $app);

        $this->assertStringContainsString('ProductIdentity::NAME', (string) file_get_contents(resource_path('views/app.blade.php')));
    }

    #[Test]
    public function the_surfaces_swc1_owns_carry_no_development_phase_framing(): void
    {
        foreach (['js/Pages/Home.vue', 'js/Pages/App/Dashboard.vue', 'js/Layouts/AccountLayout.vue', 'js/Components/Navigation/NavigationGroupList.vue', 'views/app.blade.php'] as $file) {
            $source = (string) file_get_contents(resource_path($file));
            foreach (['School OS', 'Phase 0A', 'Phase 0B', 'Planned modules', 'not yet implemented'] as $stale) {
                $this->assertStringNotContainsString($stale, $source, "{$file}: {$stale}");
            }
        }
    }

    /**
     * SWC.5 (ADR 0073 section 20.4): the whole user-facing surface, not only
     * SWC.1's -- rendered Vue template text, rendered Blade (mail and error
     * views) and PHP string literals (exception, validation and email
     * subject messages). Comments, docblocks and technical identifiers
     * (APP_NAME, class names, historical ADRs) are deliberately out of scope.
     */
    #[Test]
    public function no_user_facing_surface_carries_the_old_name_or_development_phase_framing(): void
    {
        $stale = '/School OS|\bPhase 0[A-Z]?\b|\bPhase \d+[A-Z]?(\.\d+)?\b|\bCheckpoint \d|Planned modules|not yet implemented|\(not implemented\)/';
        $hits = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'))) as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/<template>(.*)<\/template>/s', $source, $m) === 1) {
                $rendered = (string) preg_replace('/<!--.*?-->/s', '', $m[1]);
                if (preg_match($stale, $rendered, $hit) === 1) {
                    $hits[] = $file->getPathname().': '.$hit[0];
                }
            }
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $rendered = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
            if (preg_match($stale, $rendered, $hit) === 1) {
                $hits[] = $file->getPathname().': '.$hit[0];
            }
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('/School OS/', $token[1], $hit) === 1) {
                    $hits[] = $file->getPathname().':'.$token[2].': '.$hit[0];
                }
            }
        }

        $this->assertSame([], $hits, 'stale product name or development-phase framing on a user-facing surface');
    }
}
