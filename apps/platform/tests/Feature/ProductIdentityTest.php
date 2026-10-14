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
}
