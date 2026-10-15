<?php

namespace Tests\Feature\Corrections;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\School;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * X-01 (OD-V11-7, ADR 0075 section 12): a success message a controller
 * flashes on its redirect (`flash` or `status`) reaches the next page as
 * the shared `flash.success` prop, exactly once, and the signed-in layout
 * renders it. Validation errors are unaffected.
 */
class FlashMessageSharingTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function manager(School $school): void
    {
        $user = $this->createUserWithCapabilities($school, ['inventory.directory.view', 'inventory.directory.manage']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /** @return array<string, mixed> */
    private function sharedFlash(array $session): array
    {
        $request = Request::create('/app');
        $store = app('session.store');
        $store->flush(); // each case starts from an empty session
        $request->setLaravelSession($store);
        foreach ($session as $key => $value) {
            $request->session()->put($key, $value);
        }

        $flash = app(HandleInertiaRequests::class)->share($request)['flash'];

        return $flash();
    }

    #[Test]
    public function a_flashed_success_message_reaches_the_next_page_once(): void
    {
        $school = $this->createSchool();
        $this->manager($school);

        $this->post('/app/inventory-items', ['code' => 'chalk', 'name' => 'Chalk box', 'unit_of_measure' => 'box'])
            ->assertRedirect('/app/inventory-items');

        $this->assertSame('Item CHALK created.', $this->get('/app/inventory-items')->assertOk()->viewData('page')['props']['flash']['success']);
        $this->assertNull($this->get('/app/inventory-items')->viewData('page')['props']['flash']['success'], 'Shown once, not again.');
    }

    #[Test]
    public function a_validation_failure_shows_errors_and_no_success_message(): void
    {
        $school = $this->createSchool();
        $this->manager($school);

        $this->from('/app/inventory-items')->post('/app/inventory-items', ['code' => '', 'name' => '', 'unit_of_measure' => 'tonne'])
            ->assertSessionHasErrors(['code', 'name', 'unit_of_measure']);

        $props = $this->get('/app/inventory-items')->viewData('page')['props'];
        $this->assertNull($props['flash']['success']);
        $this->assertNotEmpty($props['errors']);
    }

    #[Test]
    public function both_flash_keys_are_shared_under_a_namespaced_prop_and_only_as_strings(): void
    {
        $this->assertSame(['success' => 'Leave request decided.'], $this->sharedFlash(['status' => 'Leave request decided.']));
        $this->assertSame(['success' => 'Item updated.'], $this->sharedFlash(['flash' => 'Item updated.', 'status' => 'other']));
        $this->assertSame(['success' => null], $this->sharedFlash(['flash' => ['not' => 'a string']]));
        $this->assertSame(['success' => null], $this->sharedFlash([]));
    }

    #[Test]
    public function the_signed_in_layout_mounts_one_escaped_banner(): void
    {
        $layout = (string) file_get_contents(resource_path('js/Layouts/AccountLayout.vue'));
        $banner = (string) file_get_contents(resource_path('js/Components/FlashMessage.vue'));

        $this->assertSame(1, substr_count($layout, '<FlashMessage />'));
        $this->assertStringContainsString('{{ message }}', $banner, 'Text interpolation (escaped).');
        $this->assertStringNotContainsString('v-html', $banner);
        $this->assertStringContainsString('role="status"', $banner);
    }
}
