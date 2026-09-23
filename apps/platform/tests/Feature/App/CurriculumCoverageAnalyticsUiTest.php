<?php

namespace Tests\Feature\App;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0L.2-1 -- the session-authenticated Curriculum Coverage
 * Analytics page: the seeded School Admin and Principal roles can view
 * it, every other actor gets 403 (source-module capabilities included),
 * and a multi-School member only ever sees the SELECTED School.
 */
class CurriculumCoverageAnalyticsUiTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    private const URL = '/app/analytics/curriculum-coverage';

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->deliveryWorld();
        $this->createDelivery($w['offering'], $w['section'], $w['unit'], [
            'status' => CurriculumDelivery::STATUS_COMPLETED,
            'completed_on' => $this->today()->subDay()->toDateString(),
        ]);

        return $w;
    }

    private function roleUser(array $w, string $roleKey): object
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $w['school']), $roleKey);

        return $user;
    }

    private function as(object $user, string $schoolId): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $schoolId);
    }

    #[Test]
    public function school_admin_and_principal_roles_can_view_the_report(): void
    {
        $w = $this->world();

        foreach (['school_admin', 'principal'] as $role) {
            $this->as($this->roleUser($w, $role), $w['school']->id)->get(self::URL)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('App/Analytics/CurriculumCoverage')
                    ->where('report.academicYearId', $w['year']->id)
                    ->where('report.totals.planned', 1)
                    ->where('report.totals.completed', 1)
                    ->where('report.totals.coveragePercent', '100.0')
                    ->has('report.offerings', 1)
                );
        }
    }

    #[Test]
    public function every_other_actor_is_refused(): void
    {
        $w = $this->world();

        $actors = [
            'no role' => $this->createUserWithCapabilities($w['school'], []),
            'curriculum source access only' => $w['actor'],
            'syllabus + curriculum view' => $this->createUserWithCapabilities($w['school'], ['syllabus.view', 'curriculum.delivery.view']),
            'finance desk' => $this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.charges.view', 'finance.payments.view']),
            'library desk' => $this->createUserWithCapabilities($w['school'], ['library.catalogue.view', 'library.circulation.view']),
            'export only' => $this->createUserWithCapabilities($w['school'], ['analytics.export']),
        ];

        foreach ($actors as $label => $user) {
            $this->as($user, $w['school']->id)->get(self::URL)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
    }

    #[Test]
    public function a_multi_school_member_sees_only_the_selected_school(): void
    {
        $a = $this->world();
        $b = $this->deliveryWorld();

        // Analytics viewer in A; plain member (no analytics.view) in B.
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $a['school']), 'principal');
        $this->createMembership($user, $b['school']);

        $this->as($user, $a['school']->id)->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.academicYearId', $a['year']->id)
                ->where('report.offerings.0.subjectOfferingId', $a['offering']->id)
                ->where('report.academicYears', fn ($years) => collect($years)->pluck('id')->all() === [$a['year']->id])
            );

        $this->app['auth']->forgetGuards();
        $this->as($user, $b['school']->id)->get(self::URL)->assertForbidden();

        // A's session cannot pull B's year through the filter either.
        $this->app['auth']->forgetGuards();
        $this->as($user, $a['school']->id)->get(self::URL.'?academic_year_id='.$b['year']->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.academicYearId', null)
                ->where('report.offerings', [])
            );
    }

    #[Test]
    public function the_filter_is_validated_and_no_other_parameter_is_honoured(): void
    {
        $w = $this->world();
        $admin = $this->roleUser($w, 'school_admin');

        $this->as($admin, $w['school']->id)->get(self::URL.'?academic_year_id=not-a-uuid')
            ->assertSessionHasErrors('academic_year_id');

        $this->as($admin, $w['school']->id)->get(self::URL.'?school_id='.$this->createSchool()->id.'&include_people=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('report.academicYearId', $w['year']->id));
    }

    #[Test]
    public function the_dashboard_link_follows_analytics_view(): void
    {
        $w = $this->world();

        $this->as($this->roleUser($w, 'principal'), $w['school']->id)->get('/app')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewAnalytics', true));

        $this->app['auth']->forgetGuards();
        $this->as($w['actor'], $w['school']->id)->get('/app')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewAnalytics', false));
    }
}
