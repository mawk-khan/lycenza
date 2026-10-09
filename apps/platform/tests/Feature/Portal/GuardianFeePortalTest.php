<?php

namespace Tests\Feature\Portal;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\Portal\GuardianOffboardingService;
use App\Domain\Identity\Application\Portal\GuardianPortalAccessDeniedException;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Payments\Application\Portal\GuardianFeeReadService;
use App\Domain\Payments\Application\StudentFeeStatementReadService;
use App\Http\Middleware\EnsurePortalDevelopmentOnly;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Portal\PortalUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * POR.3 (ADR 0070 §26): a linked Student's fees through the Guardian portal --
 * capability + ActingGuardian + live GuardianStudentScope + MFA +
 * PortalAvailability; authoritative balances; a shared (sibling) Payment shown
 * only as the amount applied to this Student; the same 404 for anything else.
 */
class GuardianFeePortalTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesMfaFixtures, CreatesReceiptFixtures, CreatesTenancyFixtures;

    /**
     * Guardian G of Student A (legal guardian). A's charges: 1000.00 (a 200.00
     * concession), 75.00 cancelled, 300.00 unpaid. Sibling B (not G's): a
     * 640.00 charge. One SHARED Payment of 940.00: 300.00 to A, 640.00 to B.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $w = $this->concessionWorld();
        $g = $this->portalGuardian($w['school']);
        $a = $g['student'];
        $b = $this->createStudent($w['school']);

        $w['a1'] = $this->assessCharge($w['school'], $a, $w['year'], $w['receivable'], $w['revenue'], '1000.00', description: 'Tuition A');
        $w['a2'] = $this->assessCharge($w['school'], $a, $w['year'], $w['receivable'], $w['revenue'], '75.00', description: 'Cancelled A');
        app(ChargeService::class)->cancel($w['school'], $w['a2']->id);
        $w['a3'] = $this->assessCharge($w['school'], $a, $w['year'], $w['receivable'], $w['revenue'], '300.00', description: 'Transport A');
        $w['b1'] = $this->assessCharge($w['school'], $b, $w['year'], $w['receivable'], $w['revenue'], '640.00', description: 'Sibling fee B');
        $w['b2'] = $this->assessCharge($w['school'], $b, $w['year'], $w['receivable'], $w['revenue'], '50.00', description: 'Sibling fee B2');

        $concession = $this->requestTargeted($w, '200.00', charge: $w['a1']);
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        $w['shared'] = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['a1'], '300.00'], [$w['b1'], '640.00']], '940.00');
        $w['bOnly'] = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['b2'], '50.00']], '50.00');

        $this->enrollActiveMfaFactor($g['user']);

        return [...$w, 'g' => $g, 'a' => $a, 'b' => $b];
    }

    private function asGuardian(User $user, $school): static
    {
        $this->signInTo($user, $school);
        session(['mfa_verified_at' => now()->toIso8601String()]);

        return $this;
    }

    private function url(string $studentId, ?string $paymentId = null): string
    {
        return "/app/portal/fees/students/{$studentId}".($paymentId === null ? '' : "/payments/{$paymentId}");
    }

    private function audits($school, string $type): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('event_type', $type)->get());
    }

    #[Test]
    public function the_statement_is_this_students_with_authoritative_balances_and_only_the_amount_applied_here(): void
    {
        $w = $this->world();

        $response = $this->asGuardian($w['g']['user'], $w['school'])->get($this->url($w['a']->id))->assertOk();
        $response->assertInertia(fn ($page) => $page->component('App/Portal/Fees/Show')
            ->where('statement.totals', ['charged' => '1300.00', 'adjusted' => '200.00', 'paid' => '300.00', 'outstanding' => '800.00'])
            ->has('statement.lines', 3)
            ->where('statement.lines.0.description', 'Tuition A')
            ->where('statement.lines.0.amount', '1000.00')->where('statement.lines.0.adjustedTotal', '200.00')
            ->where('statement.lines.0.paidTotal', '300.00')->where('statement.lines.0.outstanding', '500.00')->where('statement.lines.0.status', 'outstanding')
            ->where('statement.lines.0.payments.0.appliedAmount', '300.00')
            ->where('statement.lines.0.payments.0.receiptNumber', fn ($n) => is_string($n) && $n !== '')
            ->where('statement.lines.1.status', 'cancelled')->where('statement.lines.1.outstanding', '0.00')
            ->where('statement.lines.2.status', 'outstanding')->where('statement.lines.2.outstanding', '300.00'));

        // Nothing of the sibling, the shared Payment's total or its other allocation.
        $content = (string) $response->getContent();
        foreach ([$w['b']->id, 'Sibling fee B', '940.00', '640.00', $w['b1']->id, $w['bOnly']->paymentId] as $leak) {
            $this->assertStringNotContainsString($leak, $content);
        }

        // The same balance the authoritative staff statement computes for A (all years).
        $staff = app(StudentFeeStatementReadService::class)->statementFor($w['school'], $w['a']->id, null, $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']));
        $this->assertSame($staff->totals['outstanding'], '800.00');
        $this->assertSame($staff->totals, ['charged' => '1300.00', 'adjusted' => '200.00', 'paid' => '300.00', 'outstanding' => '800.00']);

        $metadata = json_decode($this->audits($w['school'], GuardianFeeReadService::STATEMENT_VIEWED)->sole()->metadata, true);
        $this->assertSame(['academicYearId', 'accountLinkId', 'guardianId', 'lineCount', 'studentId', 'surface'], collect($metadata)->keys()->sort()->values()->all());
        $this->assertSame([$w['a']->id, 3], [$metadata['studentId'], $metadata['lineCount']]);
    }

    #[Test]
    public function a_shared_payment_is_shown_only_as_the_amount_applied_to_this_student(): void
    {
        $w = $this->world();

        $response = $this->asGuardian($w['g']['user'], $w['school'])->get($this->url($w['a']->id, $w['shared']->paymentId))->assertOk();
        $response->assertInertia(fn ($page) => $page->component('App/Portal/Fees/Payment')
            ->where('payment.appliedTotal', '300.00')
            ->where('payment.lines', [['description' => 'Tuition A', 'feeHeadName' => null, 'billingPeriodLabel' => null, 'appliedAmount' => '300.00']])
            ->missing('payment.amount')->missing('payment.studentIds')->missing('payment.manualReference'));
        $content = (string) $response->getContent();
        foreach ([$w['b']->id, 'Sibling fee B', '940.00', '640.00'] as $leak) {
            $this->assertStringNotContainsString($leak, $content);
        }

        $metadata = json_decode($this->audits($w['school'], GuardianFeeReadService::PAYMENT_VIEWED)->sole()->metadata, true);
        $this->assertSame([$w['a']->id, $w['shared']->paymentId, 1], [$metadata['studentId'], $metadata['paymentId'], $metadata['allocationCount']]);
        $this->assertStringNotContainsString('300.00', json_encode($metadata));
    }

    #[Test]
    public function a_guardian_of_both_siblings_sees_each_separately_and_no_family_total(): void
    {
        $w = $this->world();
        $this->createStudentGuardianRelationship($w['b'], $w['g']['guardian'], ['is_legal_guardian' => true]);
        $this->asGuardian($w['g']['user'], $w['school']);

        $this->get('/app/portal/fees')->assertOk()->assertInertia(fn ($page) => $page->component('App/Portal/Fees/Index')->has('students', 2));
        $this->get($this->url($w['b']->id))->assertOk()->assertInertia(fn ($page) => $page
            ->where('statement.totals', ['charged' => '690.00', 'adjusted' => '0.00', 'paid' => '690.00', 'outstanding' => '0.00'])
            ->has('statement.lines', 2)->where('statement.lines.0.description', 'Sibling fee B'));
        $this->get($this->url($w['b']->id, $w['shared']->paymentId))->assertOk()->assertInertia(fn ($page) => $page->where('payment.appliedTotal', '640.00'));
        $this->get($this->url($w['a']->id))->assertInertia(fn ($page) => $page->where('statement.totals.outstanding', '800.00'));
    }

    #[Test]
    public function other_years_show_only_what_is_still_due(): void
    {
        $w = $this->world();
        $earlier = $this->createAcademicYear($w['school'], ['code' => 'AY2025', 'name' => '2025-26', 'starts_on' => '2025-06-01', 'ends_on' => '2026-05-31', 'status' => 'closed']);
        $settled = $this->assessCharge($w['school'], $w['a'], $earlier, $w['receivable'], $w['revenue'], '111.00', description: 'Old settled');
        $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$settled, '111.00']], '111.00');
        $this->assessCharge($w['school'], $w['a'], $earlier, $w['receivable'], $w['revenue'], '222.00', description: 'Old unpaid');

        $this->asGuardian($w['g']['user'], $w['school'])->get($this->url($w['a']->id))->assertInertia(fn ($page) => $page
            ->has('statement.lines', 4)
            ->where('statement.lines', fn ($lines) => collect($lines)->pluck('description')->contains('Old unpaid') && ! collect($lines)->pluck('description')->contains('Old settled')
                && collect($lines)->firstWhere('description', 'Old unpaid')['currentYear'] === false)
            ->where('statement.totals.outstanding', '1022.00'));

        $staff = app(StudentFeeStatementReadService::class)->statementFor($w['school'], $w['a']->id, null, $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']));
        $this->assertSame('1022.00', $staff->totals['outstanding'], 'Nothing owed is hidden: the Guardian total is the authoritative all-years outstanding.');
    }

    #[Test]
    public function anything_not_this_guardians_student_or_payment_is_the_same_404(): void
    {
        $w = $this->world();
        $school = $w['school'];
        $nonLegal = $this->createStudent($school);
        $this->createStudentGuardianRelationship($nonLegal, $w['g']['guardian'], ['is_legal_guardian' => false]);
        $withdrawn = $this->createStudent($school, ['status' => 'withdrawn']);
        $this->createStudentGuardianRelationship($withdrawn, $w['g']['guardian'], ['is_legal_guardian' => true]);
        $elsewhere = $this->createSchool();
        $foreign = $this->portalGuardian($elsewhere)['student'];

        $this->asGuardian($w['g']['user'], $school);
        $bodies = [];
        foreach ([$w['b']->id, $nonLegal->id, $withdrawn->id, $foreign->id, (string) Str::uuid7()] as $student) {
            $bodies[] = $this->get($this->url($student))->assertNotFound()->getContent();
        }
        // A Payment only for the sibling, guessed under A; an unknown Payment; A's own Payment under the sibling.
        foreach ([[$w['a']->id, $w['bOnly']->paymentId], [$w['a']->id, (string) Str::uuid7()], [$w['b']->id, $w['shared']->paymentId]] as [$student, $payment]) {
            $bodies[] = $this->get($this->url($student, $payment))->assertNotFound()->getContent();
        }
        $this->assertCount(1, array_unique($bodies));
        $this->assertCount(0, $this->audits($school, GuardianFeeReadService::STATEMENT_VIEWED));
        $this->assertCount(0, $this->audits($school, GuardianFeeReadService::PAYMENT_VIEWED));
    }

    #[Test]
    public function mfa_capability_and_the_production_block_are_all_required(): void
    {
        $w = $this->world();
        $url = $this->url($w['a']->id);

        $this->signInTo($w['g']['user'], $w['school'])->get($url)->assertStatus(401);
        session(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get($url)->assertOk();
        $this->travel(61)->minutes();
        $this->get($url)->assertStatus(401);

        // Without the fees capability on the Guardian role: refused.
        session(['mfa_verified_at' => now()->toIso8601String()]);
        DB::table('role_capabilities')->where('role_id', Role::query()->where('key', Role::GUARDIAN)->value('id'))->where('capability_key', 'portal.fees.view')->delete();
        app(CapabilityResolver::class)->forgetCache($w['g']['user'], $w['school']);
        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function production_refuses_and_each_service_method_refuses_on_its_own(): void
    {
        $w = $this->world();
        $this->asGuardian($w['g']['user'], $w['school']);
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->app['env'] = 'production';
        $this->get($this->url($w['a']->id))->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);
        $this->app['env'] = 'testing';

        $guardian = app(ActingGuardianResolver::class)->require($w['g']['user'], $w['school']);
        $service = app(GuardianFeeReadService::class);
        $this->app['env'] = 'production';
        try {
            $this->assertThrows(fn () => $service->students($w['school'], $guardian), PortalUnavailableException::class);
            $this->assertThrows(fn () => $service->statement($w['school'], $guardian, $w['g']['user'], $w['a']->id), PortalUnavailableException::class);
            $this->assertThrows(fn () => $service->payment($w['school'], $guardian, $w['g']['user'], $w['a']->id, $w['shared']->paymentId), PortalUnavailableException::class);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->withoutMiddleware(EnsurePortalDevelopmentOnly::class);
        $this->app['env'] = 'production';
        $this->get($this->url($w['a']->id))->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);
        $this->app['env'] = 'testing';
    }

    #[Test]
    public function a_finance_administrator_who_is_a_parent_gets_only_their_child_and_the_lifecycles_stay_apart(): void
    {
        $w = $this->world();
        $school = $w['school'];
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');

        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $p = $this->portalGuardian($school, user: $user, membership: $membership);
        $this->enrollActiveMfaFactor($user);

        $this->asGuardian($user, $school)->get($this->url($p['student']->id))->assertOk();
        $this->get($this->url($w['a']->id))->assertNotFound();
        $this->get($this->url($w['b']->id))->assertNotFound();

        app(StaffAccessService::class)->suspend($school, $admin, $membership->id);
        $this->get($this->url($p['student']->id))->assertOk();
        $this->get('/app/finance/fee-statements')->assertForbidden();

        app(StaffAccessService::class)->reactivate($school, $admin, $membership->id, ['school_admin']);
        app(GuardianOffboardingService::class)->offboard($school, $this->portalAdmin($school), $p['guardian']);
        $this->get($this->url($p['student']->id))->assertForbidden();
        $this->get('/app/finance/fee-statements')->assertOk();
    }

    #[Test]
    public function the_guardian_capability_never_opens_staff_finance(): void
    {
        $w = $this->world();
        $this->asGuardian($w['g']['user'], $w['school']);

        $this->get('/app/finance/fee-statements')->assertForbidden();
        $this->get("/app/finance/payments/{$w['shared']->paymentId}/receipt")->assertForbidden();
    }

    #[Test]
    public function payment_dates_are_the_schools_calendar_days(): void
    {
        $w = $this->world();
        $this->assertSame('Asia/Kolkata', $w['school']->timezone);
        $dated = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['a3'], '100.00']], '100.00', occurredOn: '2026-10-05');

        $this->asGuardian($w['g']['user'], $w['school'])->get($this->url($w['a']->id))->assertInertia(fn ($page) => $page
            ->where('statement.lines.2.payments.0.settledOn', '2026-10-05'));
        $this->get($this->url($w['a']->id, $dated->paymentId))->assertInertia(fn ($page) => $page->where('payment.settledOn', '2026-10-05'));
    }

    #[Test]
    public function the_service_rechecks_the_fees_capability_itself(): void
    {
        $w = $this->world();
        $guardian = app(ActingGuardianResolver::class)->require($w['g']['user'], $w['school']);
        DB::table('role_capabilities')->where('role_id', Role::query()->where('key', Role::GUARDIAN)->value('id'))->where('capability_key', 'portal.fees.view')->delete();
        app(CapabilityResolver::class)->forgetCache($w['g']['user'], $w['school']);

        $this->assertThrows(fn () => app(GuardianFeeReadService::class)->statement($w['school'], $guardian, $w['g']['user'], $w['a']->id), GuardianPortalAccessDeniedException::class);
    }
}
