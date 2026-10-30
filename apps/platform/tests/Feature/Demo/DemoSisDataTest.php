<?php

namespace Tests\Feature\Demo;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Setup\OrganizationSetupReadService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoDataBuilder;
use Database\Seeders\Demo\DemoSisData;
use Database\Seeders\Demo\DemoSisVerifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SIS.EXP-1: the canonical SIS demonstration (Northstar Gachibowli) builds,
 * verifies, and shows each persona exactly what its existing roles allow.
 */
class DemoSisDataTest extends TestCase
{
    private function school(): School
    {
        return School::query()->where('slug', DemoSisData::SCHOOL_SLUG)->firstOrFail();
    }

    private function student(string $first): Student
    {
        return app(TenantContext::class)->withSchool($this->school(), fn () => Student::query()->where('first_name', $first)->where('last_name', 'Menon')->firstOrFail());
    }

    private function signIn(string $email): void
    {
        $this->actingAs(User::query()->where('email', $email)->firstOrFail())->post("/app/schools/{$this->school()->id}/activate");
    }

    #[Test]
    public function the_sis_demonstration_builds_and_verifies(): void
    {
        app(DemoDataBuilder::class)->build();
        $verifier = new DemoSisVerifier;

        $this->assertSame([], $verifier->problems());
        $this->assertSame(['gachibowliStudents' => 17, 'gachibowliGuardians' => 17, 'gachibowliActivePlacements' => 16, 'gachibowliMovements' => 22], $verifier->summary());
    }

    #[Test]
    public function the_school_admin_sees_every_student_360_card_for_aarav(): void
    {
        app(DemoDataBuilder::class)->build();
        $this->signIn('gachibowli.admin@example.test');

        $props = $this->get("/app/students/{$this->student('Aarav')->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['attendance', 'family', 'fees', 'hostel', 'library', 'subjects', 'transport'], collect($props['sections'])->keys()->sort()->values()->all());
        $this->assertSame('Diya Menon', $props['sections']['family']['siblings'][0]['name']);
        $this->assertSame(['30000.00', '12000.00', '18000.00'], [$props['sections']['fees']['billed'], $props['sections']['fees']['paid'], $props['sections']['fees']['outstanding']]);
        $this->assertCount(5, $props['sections']['attendance']['recent']);
        $this->assertSame('Kondapur Route', $props['sections']['transport']['assignments'][0]['routeName']);
        $this->assertSame([], $props['sections']['hostel']['residencies'], 'Hostel is enabled, with no residency: an authorized, empty card');
        $this->assertSame(1, $props['sections']['library']['activeLoans']);
    }

    #[Test]
    public function the_shared_principal_sees_no_fees_card_and_campus_and_network_personas_are_refused(): void
    {
        app(DemoDataBuilder::class)->build();
        $aarav = $this->student('Aarav');

        // Principal of Gachibowli (and a Karnataka Network Viewer): no Finance capability, so no Fees card.
        $this->signIn('network.shared@example.test');
        $props = $this->get("/app/students/{$aarav->id}")->assertOk()->viewData('page')['props'];
        $this->assertArrayNotHasKey('fees', $props['sections']);
        $this->assertArrayHasKey('family', $props['sections']);

        // Early Years Principal: Early Years Students only, never the whole-School profile.
        $this->signIn('gachibowli.ey.principal@example.test');
        $this->assertSame(['Diya', 'Neel', 'Tara'], collect($this->get('/app/students')->assertOk()->viewData('page')['props']['students']['data'])->pluck('firstName')->sort()->values()->all());
        $this->get("/app/students/{$this->student('Diya')->id}")->assertForbidden();
        $this->get("/app/students/{$aarav->id}/overview")->assertNotFound();

        // The Cashier holds no Student capability; the Network Manager and the platform root have no School access.
        $this->signIn('gachibowli.cashier@example.test');
        $this->get('/app/students')->assertForbidden();
        foreach (['network.manager@example.test', 'platform.admin@example.test'] as $email) {
            $this->actingAs(User::query()->where('email', $email)->firstOrFail());
            $this->assertNotSame(200, $this->get("/app/students/{$aarav->id}")->status(), $email);
        }
    }

    #[Test]
    public function the_org_12b_partial_and_blocked_states_moved_to_horizon_and_gachibowli_is_academically_configured(): void
    {
        app(DemoDataBuilder::class)->build();
        $states = function (string $email, string $slug): array {
            $user = User::query()->where('email', $email)->firstOrFail();
            $school = School::query()->where('slug', $slug)->firstOrFail();

            return collect(app(OrganizationSetupReadService::class)->evaluate($school, $user)['items'])
                ->mapWithKeys(fn (array $item) => [$item['key'] => $item['status']])->all();
        };

        $horizon = $states('horizon.admin@example.test', 'horizon-academy');
        $this->assertSame(['partially_configured', 'blocked'], [$horizon['academic.academic_year'], $horizon['academic.structure']]);
        $gachibowli = $states('gachibowli.admin@example.test', DemoSisData::SCHOOL_SLUG);
        $this->assertSame(['configured', 'configured'], [$gachibowli['academic.academic_year'], $gachibowli['academic.structure']]);
        // SIS.EXP-2: Arjun completed 2025-26 and has no 2026-27 placement while still
        // marked active (deactivating needs a fresh code, never fabricated by the seeder):
        // the first natural "Needs attention" in the demo world.
        $this->assertSame('attention_required', $gachibowli['students.student_records']);
    }

    #[Test]
    public function the_movement_register_shows_the_seeded_history_and_refuses_campus_and_network_personas(): void
    {
        app(DemoDataBuilder::class)->build();
        $this->signIn('gachibowli.admin@example.test');
        $rows = fn (array $q) => array_map(fn (array $r) => "{$r['type']}|{$r['student']['name']}", $this->get('/app/students/movements?'.http_build_query($q))->assertOk()->viewData('page')['props']['movements']['data']);

        $this->assertSame(['section_change|Kiara Sen', 'campus_transfer|Kiara Sen', 'retention|Anika Joshi', 'promotion|Rohan Pillai'], $rows(['category' => 'movement']));
        $this->assertSame(['placement_completed|Arjun Mehta'], $rows(['category' => 'exit']));

        // The Early Years Principal reads the Campus directory but never the (whole-School) history.
        $this->signIn('gachibowli.ey.principal@example.test');
        $this->get('/app/students/movements')->assertForbidden();
        foreach (['network.manager@example.test', 'platform.admin@example.test', 'gachibowli.cashier@example.test'] as $email) {
            $this->actingAs(User::query()->where('email', $email)->firstOrFail());
            $this->assertNotSame(200, $this->get('/app/students/movements')->status(), $email);
        }
    }
}
