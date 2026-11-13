<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Accrual\LeaveAccrualMonthStatusReader;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Models\UserMfaRecoveryCode;
use App\Support\Approval\ApprovalWorkflowRegistry;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use ReflectionClass;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;
use Throwable;

/**
 * HR.R1-C35 WO-19 (LVA-4, D-4; owner decision D-C35-3; S-7).
 *
 * - **Fresh MFA** on Leave allocation and the annual allocation run, web and API: a missing, stale (an
 *   expired TOTP step), reused or another User's code is refused and nothing is written; the service
 *   itself refuses a proof minted for someone else (adjustment and opening too, which since HR.10 took
 *   the code only at the HTTP layers).
 * - **Fresh authority** at every Leave ledger and accrual write entry point -- allocate, allocation run,
 *   adjust, opening, accrual start and every accrual batch, the year close: the actor's CURRENT
 *   `hr.leave.manage` is re-read inside the write transaction, so the 60 s cached answer that still says
 *   yes never writes; each way of losing it (grant revoked or replaced, membership suspended, account
 *   disabled, Leave module disabled, School suspended) refuses.
 * - A completed write is never undone by a later revocation; an accrual run refused mid-way is left
 *   `failed` (LEAVE_ACCRUAL_AUTHORITY_REFUSED), never completed, and an authorized operator resumes it
 *   without a double credit. The read paths take no code and write nothing.
 *
 * Reproduced first at 5cbc6abd (a throwaway test, deleted): all seven entry points wrote behind a revoked
 * grant the cache still showed, and web and API allocation and run each wrote with no code at all.
 * Leave year 2026-04-01..2027-03-31; today is Monday 2026-10-05 unless a test moves. Synthetic data only.
 */
class LeaveWriteAssuranceTest extends TestCase
{
    use CreatesLeaveFixtures, ProvidesSensitiveActionMfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
    }

    private function ledger(): LeaveLedgerService
    {
        return app(LeaveLedgerService::class);
    }

    /** @param  array<string, mixed>  $w */
    private function rows(array $w, string $table = 'leave_ledger_entries'): int
    {
        return $this->inSchool($w['school'], fn () => DB::table($table)->count());
    }

    private function outcome(callable $call): string
    {
        try {
            $call();

            return 'ok';
        } catch (AuthorizationException) {
            return 'forbidden';
        } catch (SchoolNotOperationalException) {
            return 'not_operational';
        } catch (Throwable $e) {
            return class_basename($e);
        }
    }

    /** A TOTP code of the user's active factor, $steps time steps from now (negative = the past). */
    private function totp(User $user, int $steps = 0): string
    {
        $this->mfaEnrolled($user);
        $secret = UserMfaFactor::query()->where('user_id', $user->id)->where('status', 'active')->sole()->secret_encrypted;
        $google = app(Google2FA::class);

        return $google->oathTotp($secret, $google->getTimestamp() + $steps);
    }

    /** @param  array<string, mixed>  $w */
    private function revokeBehindTheCache(array $w, User $actor): void
    {
        $this->assertTrue(Gate::forUser($actor)->allows('capability', ['hr.leave.manage', $w['school']]), 'cache warmed');
        $revoked = $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $actor->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertGreaterThan(0, $revoked, 'the fixture really revoked the grant');
        $this->assertTrue(Gate::forUser($actor)->allows('capability', ['hr.leave.manage', $w['school']]), 'the cached floor still says yes');
    }

    /**
     * A monthly-accrual leave type on the world's employment.
     *
     * @param  array<string, mixed>  $w
     */
    private function monthlyType(array $w): LeaveType
    {
        $type = $this->leaveType($w['school'], $w['admin'], 'EL');
        $policy = app(LeavePolicyService::class)->create($w['school'], $type->id, [
            'name' => 'Monthly', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'monthly', 'monthly_accrual_units' => 2, 'accrual_timing' => 'period_start',
        ], null, $w['admin']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $w['employment']->id, $policy->id, '2026-04-01', null, $w['admin']);

        return $type;
    }

    #[Test]
    public function web_allocation_and_run_refuse_a_missing_stale_reused_or_borrowed_code_and_write_nothing(): void
    {
        $w = $this->leaveWorld();
        $other = $this->leaveAdmin($w['school']);
        $this->mfaEnrolled($w['admin']);
        $web = fn () => $this->actingAs($w['admin'])->withHeader('X-School-Id', $w['school']->id);
        $allocation = ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id, 'units' => 24];
        $run = ['leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id];
        $window = (int) config('mfa.totp_window');

        foreach (['/app/leave/allocations' => $allocation, '/app/leave/allocation-runs' => $run] as $path => $body) {
            $web()->post($path, $body)->assertSessionHasErrors('mfa_code');
            $web()->post($path, $body + ['mfa_code' => $this->totp($w['admin'], -($window + 3))])->assertSessionHasErrors('mfa_code');
            $web()->post($path, $body + ['mfa_code' => $this->freshMfaCode($other)])->assertSessionHasErrors('mfa_code');
            $web()->post($path, $body + ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        }
        $this->assertSame([0, 0], [$this->rows($w), $this->rows($w, 'leave_allocation_runs')], 'no refused attempt wrote anything');

        // A viewer is refused on the capability BEFORE any code is read.
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);
        $viewerCode = $this->freshMfaCode($viewer);
        $this->actingAs($viewer)->withHeader('X-School-Id', $w['school']->id)->post('/app/leave/allocations', $allocation + ['mfa_code' => $viewerCode])->assertForbidden();
        $this->actingAs($viewer)->withHeader('X-School-Id', $w['school']->id)->post('/app/leave/allocation-runs', $run + ['mfa_code' => $viewerCode])->assertForbidden();
        $this->assertSame(0, UserMfaRecoveryCode::query()->where('user_id', $viewer->id)->whereNotNull('consumed_at')->count(), 'a refused caller never spends a code');

        $current = $this->totp($w['admin']);
        $web()->post('/app/leave/allocations', $allocation + ['mfa_code' => $current])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rows($w));
        // The step just spent is not accepted again, for the run either.
        $web()->post('/app/leave/allocation-runs', $run + ['mfa_code' => $current])->assertSessionHasErrors('mfa_code');
        $this->assertSame(0, $this->rows($w, 'leave_allocation_runs'));
        $web()->post('/app/leave/allocation-runs', $run + ['mfa_code' => $this->freshMfaCode($w['admin'])])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rows($w, 'leave_allocation_runs'));

        $audit = $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'leave.allocation.granted')->sole());
        $this->assertTrue(json_decode((string) $audit->metadata, true)['freshMfa']);
        $this->assertStringNotContainsString($current, (string) $audit->metadata, 'a code never reaches the audit');
    }

    #[Test]
    public function api_allocation_and_run_need_a_fresh_code_after_the_capability(): void
    {
        $w = $this->leaveWorld();
        $token = $this->mfaToken($w['admin']);
        $api = function (string $path, array $body, ?string $bearer = null) use ($w, $token) {
            $this->app['auth']->forgetGuards();

            return $this->withHeader('Authorization', 'Bearer '.($bearer ?? $token))->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/schools/{$w['school']->id}/leave{$path}", $body);
        };
        $allocation = ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id, 'units' => 24];
        $run = ['leave_type_id' => $w['type']->id, 'leave_year_id' => $w['year']->id];

        foreach (['/allocations' => $allocation, '/allocation-runs' => $run] as $path => $body) {
            $api($path, $body)->assertStatus(422);
            $api($path, $body + ['mfa_code' => 'NOT-A-CODE'])->assertStatus(422);
        }
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);
        $api('/allocations', $allocation + ['mfa_code' => $this->freshMfaCode($viewer)], $viewer->createToken('v')->plainTextToken)->assertForbidden();
        $this->assertSame(0, $this->rows($w));

        $api('/allocations', $allocation + $this->mfaBody($token))->assertCreated()->assertJsonPath('data.kind', 'allocation');
        $other = $this->leaveWorld();
        $otherToken = $this->mfaToken($other['admin']);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$otherToken}")->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/schools/{$other['school']->id}/leave/allocation-runs", ['leave_type_id' => $other['type']->id, 'leave_year_id' => $other['year']->id] + $this->mfaBody($otherToken))
            ->assertCreated()->assertJsonPath('data.allocatedCount', 1);
    }

    #[Test]
    public function the_service_refuses_a_proof_minted_for_someone_else_before_any_write(): void
    {
        $w = $this->leaveWorld();
        $this->allocate($w);
        $actor = $this->leaveAdmin($w['school']);
        $borrowed = new FreshMfaProof($w['admin']->id, CarbonImmutable::now());
        $writes = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b.*\bleave_/i', $sql) === 1) {
                $writes[] = $sql;
            }
        });
        $before = $this->rows($w);

        $ledger = $this->ledger();
        $this->assertSame('LogicException', $this->outcome(fn () => $ledger->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 4, $actor, $borrowed)));
        $this->assertSame('LogicException', $this->outcome(fn () => $ledger->executeRun($w['school'], $w['type']->id, $w['year']->id, $actor, $borrowed)));
        $this->assertSame('LogicException', $this->outcome(fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 2, 'administrative_correction', $actor, $borrowed)));
        $this->assertSame('LogicException', $this->outcome(fn () => $ledger->recordOpening($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 2, $actor, $borrowed)));

        $this->assertSame([], $writes, 'refused before any Leave write was attempted');
        $this->assertSame($before, $this->rows($w));
    }

    /** @return array<string, array{0: string}> */
    public static function entryPoints(): array
    {
        return array_map(fn (string $e) => [$e], array_combine(
            ['allocate', 'allocation run', 'adjust', 'opening', 'accrual start', 'accrual batch', 'year close'],
            ['allocate', 'run', 'adjust', 'opening', 'accrual-start', 'accrual-batch', 'year-close'],
        ));
    }

    #[Test]
    #[DataProvider('entryPoints')]
    public function every_ledger_and_accrual_write_entry_point_re_reads_authority_behind_the_cache(string $entryPoint): void
    {
        if ($entryPoint === 'year-close') {
            $this->travelTo('2027-04-10 10:00:00');
        }
        $w = $this->leaveWorld();
        $actor = $this->leaveAdmin($w['school']);
        $ledger = $this->ledger();
        $accruals = app(LeaveAccrualService::class);
        $proof = $this->freshMfaProofFor($actor);

        $write = match ($entryPoint) {
            'allocate' => fn () => $ledger->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 24, $actor, $proof),
            'run' => fn () => $ledger->executeRun($w['school'], $w['type']->id, $w['year']->id, $actor, $proof),
            'adjust' => (function () use ($w, $ledger, $actor, $proof) {
                $this->allocate($w);

                return fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 2, 'administrative_correction', $actor, $proof);
            })(),
            'opening' => fn () => $ledger->recordOpening($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 4, $actor, $proof),
            'accrual-start' => (function () use ($w, $accruals, $actor) {
                $type = $this->monthlyType($w);

                return fn () => $accruals->start($w['school'], $type->id, $w['year']->id, '2026-09-01', $actor);
            })(),
            'accrual-batch' => (function () use ($w, $accruals, $actor) {
                $type = $this->monthlyType($w);
                $run = $accruals->start($w['school'], $type->id, $w['year']->id, '2026-09-01', $actor);

                return fn () => $accruals->processBatch($w['school'], $run->id, $actor);
            })(),
            'year-close' => (function () use ($w, $actor) {
                $this->workingWeek($w['school'], $w['admin']);
                $this->allocate($w);
                app(LeaveYearService::class)->open($w['school'], '2027-04-01', $w['admin']);

                return fn () => app(LeaveYearCloseService::class)->execute($w['school'], $w['year']->id, $actor);
            })(),
        };
        $state = fn () => $this->inSchool($w['school'], fn () => [
            DB::table('leave_ledger_entries')->count(), DB::table('leave_allocation_runs')->count(), DB::table('leave_accrual_runs')->count(),
            DB::table('leave_accrual_run_items')->count(), DB::table('leave_year_closes')->count(),
        ]);
        $before = $state();

        $this->revokeBehindTheCache($w, $actor);
        $this->assertSame('forbidden', $this->outcome($write), "{$entryPoint}: the cached yes never writes");
        $this->assertSame($before, $state(), "{$entryPoint}: nothing written");

        // The positive control: the same write by a current holder.
        $holder = $this->leaveAdmin($w['school']);
        $this->assertSame('ok', $this->outcome(match ($entryPoint) {
            'allocate' => fn () => $ledger->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 24, $holder, $this->freshMfaProofFor($holder)),
            'run' => fn () => $ledger->executeRun($w['school'], $w['type']->id, $w['year']->id, $holder, $this->freshMfaProofFor($holder)),
            'adjust' => fn () => $ledger->adjust($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 'credit', 2, 'administrative_correction', $holder, $this->freshMfaProofFor($holder)),
            'opening' => fn () => $ledger->recordOpening($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 4, $holder, $this->freshMfaProofFor($holder)),
            'accrual-start', 'accrual-batch' => fn () => $accruals->execute($w['school'], $this->inSchool($w['school'], fn () => LeaveType::query()->where('code', 'EL')->sole()->id), $w['year']->id, '2026-09-01', $holder),
            'year-close' => fn () => app(LeaveYearCloseService::class)->execute($w['school'], $w['year']->id, $holder),
        }), "{$entryPoint}: a current holder writes");
    }

    /** @return array<string, array{0: string}> */
    public static function lostAuthority(): array
    {
        return [
            'grant revoked' => ['revoked'],
            'role replaced by one without the capability' => ['replaced'],
            'membership suspended' => ['membership'],
            'account disabled' => ['disabled'],
            'Leave module disabled' => ['module'],
            'School suspended' => ['school'],
        ];
    }

    #[Test]
    #[DataProvider('lostAuthority')]
    public function each_way_of_losing_authority_refuses_an_allocation_and_an_accrual_batch(string $loss): void
    {
        $w = $this->leaveWorld();
        $type = $this->monthlyType($w);
        $actor = $this->leaveAdmin($w['school']);
        $accruals = app(LeaveAccrualService::class);
        $run = $accruals->start($w['school'], $type->id, $w['year']->id, '2026-09-01', $actor);
        $this->assertTrue(Gate::forUser($actor)->allows('capability', ['hr.leave.manage', $w['school']]), 'cache warmed');
        $membership = fn () => DB::table('school_memberships')->where('school_id', $w['school']->id)->where('user_id', $actor->id);

        match ($loss) {
            'revoked' => $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')->whereIn('school_membership_id', $membership()->pluck('id'))
                ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked'])),
            'replaced' => (function () use ($w, $membership) {
                $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')->whereIn('school_membership_id', $membership()->pluck('id'))
                    ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
                LocalCatalogueFixtures::grantRole(SchoolMembership::query()->findOrFail($membership()->value('id')), $this->createFixtureRole(['hr.leave.view']));
            })(),
            'membership' => $this->inSchool($w['school'], fn () => $membership()->update(['status' => 'suspended'])),
            'disabled' => DB::table('users')->where('id', $actor->id)->update(['is_disabled' => true]),
            'module' => $this->inSchool($w['school'], fn () => DB::table('school_module_entitlements')->where('school_id', $w['school']->id)->where('module_key', 'leave')->update(['enabled' => false])),
            'school' => DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'suspended']),
        };
        $expected = $loss === 'school' ? 'not_operational' : 'forbidden';

        $this->assertSame($expected, $this->outcome(fn () => $this->ledger()->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 24, $actor, $this->freshMfaProofFor($actor))));
        $this->assertSame($expected, $this->outcome(fn () => $accruals->processBatch($w['school'], $run->id, $actor)));
        $this->assertSame(0, $this->rows($w), "{$loss}: no credit of any kind");
        $this->assertSame(0, $this->rows($w, 'leave_accrual_run_items'));
    }

    #[Test]
    public function a_batch_refused_mid_run_leaves_the_run_failed_never_completed_and_a_holder_resumes_it_once(): void
    {
        $w = $this->leaveWorld();
        $type = $this->monthlyType($w);
        $operator = $this->leaveAdmin($w['school']);
        $accruals = app(LeaveAccrualService::class);

        // The revocation lands between the run's start and its first batch (it commits with the start here; the
        // two-process ordering is LeaveWriteAuthorityConcurrencyTest's).
        $fired = false;
        DB::listen(function ($query) use (&$fired, $w, $operator): void {
            if (! $fired && str_starts_with(strtolower($query->sql), 'insert into "leave_accrual_runs"')) {
                $fired = true;
                DB::table('membership_role_assignments')
                    ->whereIn('school_membership_id', DB::table('school_memberships')->where('school_id', $w['school']->id)->where('user_id', $operator->id)->pluck('id'))
                    ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']);
            }
        });

        $this->assertSame('forbidden', $this->outcome(fn () => $accruals->execute($w['school'], $type->id, $w['year']->id, '2026-09-01', $operator)));
        $this->assertTrue($fired);
        $run = $this->inSchool($w['school'], fn () => DB::table('leave_accrual_runs')->sole());
        $this->assertSame(['failed', 'LEAVE_ACCRUAL_AUTHORITY_REFUSED', 0], [$run->status, $run->failure_code, (int) $run->batch_count]);
        $this->assertSame(0, $this->rows($w), 'nothing credited');
        $status = app(LeaveAccrualMonthStatusReader::class)->month($w['school'], $type->id, $w['year']->id, '2026-09-01', $w['admin']);
        $this->assertSame([LeaveAccrualMonthStatusReader::FAILED, 'LEAVE_ACCRUAL_AUTHORITY_REFUSED'], [$status['status'], $status['failureCode']], 'shown as failed, never complete');

        $holder = $this->leaveAdmin($w['school']);
        $resumed = $accruals->execute($w['school'], $type->id, $w['year']->id, '2026-09-01', $holder);
        $this->assertSame([$run->id, 'completed'], [$resumed->id, $resumed->status], 'the same run resumed');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('kind', 'accrual')->count()));
        $this->assertSame(1, $this->rows($w, 'leave_accrual_runs'));
        $this->assertSame(LeaveAccrualMonthStatusReader::COMPLETE, app(LeaveAccrualMonthStatusReader::class)->month($w['school'], $type->id, $w['year']->id, '2026-09-01', $w['admin'])['status']);
    }

    #[Test]
    public function a_completed_write_stands_when_its_author_later_loses_authority(): void
    {
        $w = $this->leaveWorld();
        $actor = $this->leaveAdmin($w['school']);
        $entry = $this->ledger()->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 24, $actor, $this->freshMfaProofFor($actor));
        $this->revokeBehindTheCache($w, $actor);

        $row = $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('id', $entry->id)->sole());
        $this->assertSame([24, $actor->id, 'allocation'], [(int) $row->units, $row->actor_user_id, $row->kind], 'history is not rewritten');
    }

    #[Test]
    public function the_read_paths_take_no_code_and_write_nothing(): void
    {
        $w = $this->leaveWorld();
        $type = $this->monthlyType($w);
        $this->mfaEnrolled($w['admin']);
        $web = $this->actingAs($w['admin'])->withHeader('X-School-Id', $w['school']->id);
        $web->get("/app/leave/entitlements?employment_record_id={$w['employment']->id}&leave_year_id={$w['year']->id}&run_leave_type_id={$w['type']->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('runPreview.candidateCount', 1));
        app(LeaveAccrualService::class)->preview($w['school'], $type->id, $w['year']->id, '2026-09-01', $w['admin']);
        app(LeaveAccrualMonthStatusReader::class)->year($w['school'], $type->id, $w['year']->id, $w['admin']);
        $this->artisan('leave:accrual-status', ['--school' => [$w['school']->id]])->assertSuccessful();

        $this->assertSame([0, 0, 0], [$this->rows($w), $this->rows($w, 'leave_allocation_runs'), $this->rows($w, 'leave_accrual_runs')]);
    }

    #[Test]
    public function one_authority_read_per_run_and_per_batch_never_per_employment(): void
    {
        $w = $this->leaveWorld();
        $type = $this->monthlyType($w);
        foreach (range(1, 20) as $i) {
            $e = $this->currentEmployment($w['school']);
            app(LeavePolicyAssignmentService::class)->assign($w['school'], $e->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
            app(LeavePolicyAssignmentService::class)->assign($w['school'], $e->id, $this->inSchool($w['school'], fn () => DB::table('leave_policies')->where('leave_type_id', $type->id)->value('id')), '2026-04-01', null, $w['admin']);
        }
        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (preg_match('/from "school_memberships".*for share/i', $query->sql) === 1) {
                $reads++;
            }
        });

        $run = $this->ledger()->executeRun($w['school'], $w['type']->id, $w['year']->id, $w['admin'], $this->freshMfaProofFor($w['admin']));
        $this->assertSame([21, 1], [$run->allocated_count, $reads], 'one read for a run of 21 grants');

        $reads = 0;
        $accrual = app(LeaveAccrualService::class)->execute($w['school'], $type->id, $w['year']->id, '2026-09-01', $w['admin']);
        $this->assertSame(['completed', 1], [$accrual->status, (int) $accrual->batch_count]);
        $this->assertSame(2, $reads, 'one read for the start and one for its single batch of 21');
    }

    #[Test]
    public function every_write_method_is_declared_and_takes_its_assurance(): void
    {
        $direct = ApprovalWorkflowRegistry::DIRECT_ASSURED[LeaveLedgerService::class];
        $this->assertSame(['allocate', 'executeRun', 'adjust', 'recordOpening'], $direct['writeMethods']);
        $declared = [LeaveLedgerService::class => [$direct['writeMethods'], ['$this->requireProof($actor, $mfa);', '$this->requireCurrentAuthority($school, $actor);']]];
        foreach (ApprovalWorkflowRegistry::FRESH_AUTHORITY_WRITES as $class => $meta) {
            $this->assertFalse($meta['freshMfa'], $class);
            $declared[$class] = [$meta['writeMethods'], ["\$this->authority->requireCurrent(\$actor, '{$meta['capability']}', \$school);"]];
        }
        $this->assertSame([LeaveLedgerService::class, LeaveAccrualService::class, LeaveYearCloseService::class], array_keys($declared));

        foreach ($declared as $class => [$methods, $needles]) {
            $reflection = new ReflectionClass($class);
            $lines = file((string) $reflection->getFileName());
            $opening = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                if (str_contains($body, 'DB::transaction(')) {
                    $opening[] = $method->getName();
                }
                if (in_array($method->getName(), $methods, true)) {
                    foreach ($needles as $needle) {
                        $this->assertStringContainsString($needle, $body, "{$class}::{$method->getName()}");
                    }
                }
            }
            // Completeness: every public method that opens a write transaction is a declared write method.
            $this->assertEqualsCanonicalizing($methods, $opening, "{$class}: every write transaction is declared");
        }
    }
}
