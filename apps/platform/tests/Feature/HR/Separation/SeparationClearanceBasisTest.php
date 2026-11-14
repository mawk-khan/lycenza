<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseReadService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsChangeReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsService;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\HR\Infrastructure\SeparationClearanceRequirement;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;
use Throwable;

/**
 * HR.R1-C35 WO-21 (HRC-8; owner decisions D-C35-4, D-WO21-1).
 *
 * The approval records the basis of its clearance checklist, frozen: the staff type it used and, for an
 * UNCLASSIFIED employment, every active staff-type requirement it therefore did not apply. The case names
 * them ("Unclassified"; each requirement by label, staff type and mandatory flag); they are never clearance
 * items, so never CLEAR; nothing new blocks completion (D-C35-4: show, do not require). A classified
 * employment's checklist is unchanged. Before approval the page previews it; after approval a reclassification
 * or a requirement change never rewrites the record; the database re-derives and freezes the basis.
 *
 * Reproduced first at 0a867e21 (a throwaway test, deleted): an unclassified employment's case never showed
 * its staff type and nowhere named the mandatory Teaching requirement it did not get.
 */
class SeparationClearanceBasisTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private User $approver;

    /** @var array<string, SeparationClearanceRequirement> */
    private array $req = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure']);
        $this->approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $this->req['KEYS'] = $this->requirement('keys', 'Keys returned', true, null);
        $this->req['lab'] = $this->requirement('lab', 'Lab equipment returned', true, StaffType::TEACHING);
        $this->req['stores'] = $this->requirement('stores', 'Stores handed over', false, StaffType::NON_TEACHING);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function requirement(string $code, string $label, bool $mandatory, ?StaffType $type): SeparationClearanceRequirement
    {
        return app(SeparationClearanceRequirementService::class)->create($this->school, $code, $label, ClearanceResponsibility::HR, $mandatory, $type, $this->hr);
    }

    private function employment(?StaffType $type): EmploymentRecord
    {
        return app(EmploymentService::class)->create($this->createEmployee($this->school, ['user_id' => null]),
            ['employment_type' => 'permanent', 'starts_on' => '2022-01-01', ...($type === null ? [] : ['staff_type' => $type->value])], $this->hr);
    }

    private function cases(): SeparationCaseService
    {
        return app(SeparationCaseService::class);
    }

    private function recorded(EmploymentRecord $employment): EmploymentSeparationCase
    {
        return $this->cases()->record($this->school, $employment->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-06-15', $this->hr);
    }

    private function approved(EmploymentRecord $employment): EmploymentSeparationCase
    {
        $case = $this->recorded($employment);

        return $this->cases()->approve($this->school, $case->id, $case->version, $this->approver, $this->freshMfaProofFor($this->approver));
    }

    /** @return array<string, mixed> */
    private function basis(EmploymentSeparationCase $case, ?User $viewer = null): array
    {
        return app(SeparationCaseReadService::class)->detail($this->school, $viewer ?? $this->hr, $case->id)['clearanceBasis'];
    }

    /** @return list<string> */
    private function items(EmploymentSeparationCase $case): array
    {
        return DB::table('separation_clearance_items')->where('case_id', $case->id)->orderBy('code')->pluck('code')->all();
    }

    /** @return list<string> */
    private static function sorted(string ...$ids): array
    {
        sort($ids, SORT_STRING);

        return $ids;
    }

    #[Test]
    public function an_unclassified_employment_names_every_staff_type_requirement_it_did_not_get(): void
    {
        $case = $this->approved($this->employment(null));

        $this->assertSame(['KEYS'], $this->items($case), 'the checklist itself is unchanged');
        $this->assertNull($case->clearance_staff_type);
        $this->assertSame(self::sorted($this->req['lab']->id, $this->req['stores']->id), $case->clearanceSkippedRequirementIds());

        $basis = $this->basis($case);
        $this->assertSame(['recorded', true, null], [$basis['state'], $basis['unclassified'], $basis['staffTypeLabel']]);
        $this->assertSame([['LAB', 'Lab equipment returned', 'Teaching', true, false], ['STORES', 'Stores handed over', 'Non-teaching', false, false]],
            array_map(fn (array $r) => [$r['code'], $r['label'], $r['staffTypeLabel'], $r['mandatory'], $r['retired']], $basis['skipped']));
        $audit = DB::table('school_audit_events')->where('event_type', 'hr.separation.approved')->sole();
        $this->assertSame(self::sorted($this->req['lab']->id, $this->req['stores']->id), json_decode((string) $audit->metadata, true)['skippedRequirementIds']);
    }

    #[Test]
    public function a_skipped_requirement_is_never_a_clearance_item_and_adds_no_completion_blocker(): void
    {
        $employment = $this->employment(null);
        $case = $this->approved($employment);
        $case = $this->cases()->finalize($this->school, $case->id, $case->version, $this->hr, $this->freshMfaProofFor($this->hr));
        $keys = DB::table('separation_clearance_items')->where('case_id', $case->id)->value('id');
        $this->cases()->clearItem($this->school, $case->id, (string) $keys, $this->hr);

        $this->assertSame([], DB::table('separation_clearance_items')->where('case_id', $case->id)->whereIn('code', ['LAB', 'STORES'])->pluck('code')->all(), 'never CLEAR, never pending: not evaluated');
        $completed = $this->cases()->complete($this->school, $case->id, $case->refresh()->version, $this->hr);
        $this->assertSame('completed', $completed->status, 'D-C35-4: shown, not required');
        $this->assertSame(2, count($this->basis($completed)['skipped']), 'still named after completion');
    }

    /** @return array<string, array{0: StaffType, 1: list<string>}> */
    public static function classified(): array
    {
        return [
            'teaching' => [StaffType::TEACHING, ['KEYS', 'LAB']],
            'non-teaching' => [StaffType::NON_TEACHING, ['KEYS', 'STORES']],
            'outsourced' => [StaffType::OUTSOURCED, ['KEYS']],
        ];
    }

    #[Test]
    #[DataProvider('classified')]
    public function a_classified_employment_keeps_its_checklist_and_skips_nothing(StaffType $type, array $items): void
    {
        $case = $this->approved($this->employment($type));

        $this->assertSame($items, $this->items($case));
        $this->assertSame([$type->value, []], [$case->clearance_staff_type, $case->clearanceSkippedRequirementIds()]);
        $basis = $this->basis($case);
        $this->assertSame(['recorded', false, $type->label(), []], [$basis['state'], $basis['unclassified'], $basis['staffTypeLabel'], $basis['skipped']]);
    }

    #[Test]
    public function unclassified_with_no_staff_type_requirement_is_still_unclassified_and_skips_nothing(): void
    {
        foreach (['lab', 'stores'] as $code) {
            app(SeparationClearanceRequirementService::class)->retire($this->school, $this->req[$code]->id, $this->hr);
        }
        $case = $this->approved($this->employment(null));

        $basis = $this->basis($case);
        $this->assertSame(['recorded', true, []], [$basis['state'], $basis['unclassified'], $basis['skipped']], 'evaluated: none left out, and the fact stays visible');
        $this->assertSame([], $case->clearanceSkippedRequirementIds(), 'an empty list, not "not recorded"');
    }

    #[Test]
    public function before_approval_the_page_previews_and_follows_a_reclassification(): void
    {
        $employment = $this->employment(null);
        $case = $this->recorded($employment);
        $preview = $this->basis($case);
        $this->assertSame(['preview', true], [$preview['state'], $preview['unclassified']]);
        $this->assertSame(['LAB', 'STORES'], array_column($preview['skipped'], 'code'));
        $this->assertNull($case->refresh()->clearanceSkippedRequirementIds(), 'a preview records nothing');

        app(EmploymentTermsService::class)->change($employment->fresh(), '2026-06-01', EmploymentType::PERMANENT, StaffType::TEACHING, null, EmploymentTermsChangeReason::RECLASSIFICATION, $this->hr);
        $this->assertSame(['preview', false, []], array_values(array_intersect_key($this->basis($case), array_flip(['state', 'unclassified', 'skipped']))));

        $case = $this->cases()->approve($this->school, $case->id, $case->refresh()->version, $this->approver, $this->freshMfaProofFor($this->approver));
        $this->assertSame(['KEYS', 'LAB'], $this->items($case));
        $this->assertSame(['teaching', []], [$case->clearance_staff_type, $case->clearanceSkippedRequirementIds()]);
    }

    #[Test]
    public function after_approval_a_reclassification_or_a_requirement_change_never_rewrites_the_record(): void
    {
        $employment = $this->employment(null);
        $case = $this->approved($employment);
        $recorded = $case->clearanceSkippedRequirementIds();

        app(SeparationClearanceRequirementService::class)->retire($this->school, $this->req['stores']->id, $this->hr);
        $this->requirement('badge', 'Badge returned', true, StaffType::TEACHING);

        $case->refresh();
        $this->assertSame($recorded, $case->clearanceSkippedRequirementIds());
        $this->assertNull($case->clearance_staff_type);
        $basis = $this->basis($case);
        $this->assertSame(['LAB', 'STORES'], array_column($basis['skipped'], 'code'), 'a later requirement is not added');
        $this->assertSame([false, true], array_column($basis['skipped'], 'retired'), 'a retired one is still named, marked retired');
    }

    #[Test]
    public function the_page_shows_today_s_staff_type_beside_the_recorded_one(): void
    {
        $employment = $this->employment(null);
        $case = $this->approved($employment);
        // The employment is in its notice period now; terms still change through their one writer.
        app(EmploymentTermsService::class)->change($employment->fresh(), '2026-06-10', EmploymentType::PERMANENT, StaffType::OUTSOURCED, null, EmploymentTermsChangeReason::CORRECTION, $this->hr);

        $detail = app(SeparationCaseReadService::class)->detail($this->school, $this->hr, $case->id);
        $this->assertSame([true, 'Outsourced'], [$detail['clearanceBasis']['unclassified'], $detail['employment']['staffTypeLabel']]);
        $this->assertNull($case->refresh()->clearance_staff_type, 'today\'s classification is not the checklist\'s history');
    }

    #[Test]
    public function a_requirement_added_under_the_approval_is_refused_and_nothing_is_approved(): void
    {
        $case = $this->recorded($this->employment(null));
        $fired = false;
        DB::listen(function ($query) use (&$fired): void {
            if (! $fired && str_contains($query->sql, 'from "separation_clearance_requirements"') && str_contains($query->sql, 'order by "sequence"')) {
                $fired = true;
                $this->requirement('badge', 'Badge returned', true, StaffType::TEACHING);
            }
        });

        $this->assertSame('SEPARATION_REQUIREMENTS_CHANGED', $this->refusal(fn () => $this->cases()->approve($this->school, $case->id, $case->version, $this->approver, $this->freshMfaProofFor($this->approver))));
        $this->assertTrue($fired);
        $this->assertSame(['requested', null], [$case->refresh()->status, $case->clearanceSkippedRequirementIds()]);
    }

    #[Test]
    public function only_a_separation_viewer_of_this_school_sees_it(): void
    {
        $case = $this->approved($this->employment(null));
        $this->assertSame('AuthorizationException', $this->refusal(fn () => app(SeparationCaseReadService::class)->detail($this->school, $this->createUserWithCapabilities($this->school, ['hr.employees.view']), $case->id)));
        $other = $this->createSchool();
        $this->assertSame('SEPARATION_NOT_FOUND', $this->refusal(fn () => app(SeparationCaseReadService::class)->detail($other, $this->createUserWithCapabilities($other, ['hr.separations.view']), $case->id)));
    }

    #[Test]
    public function the_runtime_role_cannot_forge_omit_or_rewrite_the_basis(): void
    {
        $employment = $this->employment(null);
        $case = $this->recorded($employment);
        $guard = '(hr_separation_clearance_basis_guard)';
        $approve = fn (?string $type, ?array $skipped) => fn () => DB::table('employment_separation_cases')->where('id', $case->id)->update([
            'status' => 'approved', 'decided_by_user_id' => $this->approver->id, 'decided_at' => now(), 'version' => DB::raw('version + 1'),
            'clearance_staff_type' => $type, 'clearance_skipped_requirement_ids' => $skipped === null ? null : EmploymentSeparationCase::uuidArray($skipped),
        ]);
        $all = self::sorted($this->req['lab']->id, $this->req['stores']->id);

        $this->assertSame($guard, $this->raw($approve(null, null)), 'omitted');
        $this->assertSame($guard, $this->raw($approve(null, [])), 'nothing said to be skipped');
        $this->assertSame($guard, $this->raw($approve(null, [$this->req['lab']->id])), 'a partial list');
        $this->assertSame($guard, $this->raw($approve(null, [...$all, $this->req['KEYS']->id])), 'a universal requirement called skipped');
        $this->assertSame($guard, $this->raw($approve('teaching', $all)), 'a classified checklist skips nothing (the guard refuses before the shape CHECK)');
        $this->assertSame($guard, $this->raw($approve('teaching', [])), 'a staff type it did not have');
        $this->assertSame('ok', $this->raw($approve(null, $all)), 'the exact basis is the positive control');

        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $case->id)
            ->update(['clearance_skipped_requirement_ids' => EmploymentSeparationCase::uuidArray([$this->req['lab']->id]), 'version' => DB::raw('version + 1')])), 'rewritten');
        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $case->id)
            ->update(['clearance_skipped_requirement_ids' => null, 'version' => DB::raw('version + 1')])), 'erased');
        $other = $this->recorded($this->employment(StaffType::TEACHING));
        $this->assertSame($guard, $this->raw(fn () => DB::table('employment_separation_cases')->where('id', $other->id)
            ->update(['clearance_staff_type' => 'teaching', 'clearance_skipped_requirement_ids' => '{}', 'version' => DB::raw('version + 1')])), 'outside the approval');
    }

    #[Test]
    public function a_requirement_named_not_applied_can_never_also_be_an_item(): void
    {
        $case = $this->approved($this->employment(null));
        $item = fn (SeparationClearanceRequirement $r) => fn () => DB::table('separation_clearance_items')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'case_id' => $case->id, 'requirement_id' => $r->id,
            'code' => $r->code, 'label' => $r->label, 'responsibility' => $r->responsibility, 'is_mandatory' => $r->is_mandatory, 'sequence' => $r->sequence,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $error = $this->raw($item($this->req['lab']->fresh()));
        $this->assertStringContainsString('(hr_separation_clearance_item_basis_guard)', $error, 'a skipped Teaching requirement');
        $this->assertSame('ok', $this->raw($item($this->requirement('badge', 'Badge returned', true, null))), 'a universal requirement is the positive control');
    }

    #[Test]
    public function a_case_cancelled_after_approval_keeps_showing_its_recorded_basis(): void
    {
        $case = $this->approved($this->employment(null));
        $case = $this->cases()->cancel($this->school, $case->id, $case->version, SeparationCancelReason::RECORDED_IN_ERROR, $this->hr, $this->freshMfaProofFor($this->hr));

        $basis = $this->basis($case);
        $this->assertSame(['cancelled', 'recorded', true], [$case->status, $basis['state'], $basis['unclassified']]);
        $this->assertSame(['LAB', 'STORES'], array_column($basis['skipped'], 'code'));
    }

    private function raw(callable $write): string
    {
        try {
            DB::transaction($write);

            return 'ok';
        } catch (QueryException $e) {
            return preg_match('/\(hr_separation_clearance_basis_guard\)|basis_shape/', $e->getMessage(), $m) === 1 ? $m[0] : substr($e->getMessage(), 0, 400);
        }
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (SeparationException $e) {
            return $e->errorCode();
        } catch (AuthorizationException) {
            return 'AuthorizationException';
        } catch (Throwable $e) {
            return class_basename($e).':'.$e->getMessage();
        }
        $this->fail('expected a refusal');
    }
}
