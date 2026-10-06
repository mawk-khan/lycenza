<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\AdmissionFeeSelectionService;
use App\Domain\Admissions\Application\Exceptions\AdmissionFeeHeadNotSelectableException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Application\Retention\ConvertedApplicationRetentionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\AdmissionFeeHead;
use App\Domain\Admissions\Infrastructure\AdmissionFeeSelection;
use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Application\FeeOptionalSelectionService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Retention\ReferencingRows;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\AssertsTenantRlsIsolation;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * OPF.3 (ADR 0067 §16, §29, D1): a successful conversion records the converted
 * STUDENT's one-time Admission-fee INTENT through the trusted FEE seam; only
 * Finance assessment runs make money; no applicant is ever a financial
 * subject. World: an active year (2026-27) whose active structure has an
 * OPTIONAL one-time Admission line (one instalment ADM 2500.00) and a
 * required Tuition line (T1 5000.00, T2 7000.00); an accepted application
 * for that year, campus and grade.
 */
class AdmissionFeeSelectionTest extends TestCase
{
    use AssertsTenantRlsIsolation;
    use CreatesFeeAssessmentFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['finance'] = $this->createUserWithCapabilities($w['school'], [
            ...self::FEE_SETUP_CAPABILITIES, 'finance.fee_assessments.run', 'finance.charges.view', 'finance.charges.manage',
        ]);
        $w['actor'] = $w['finance'];
        $w['admissions'] = $this->createUserWithCapabilities($w['school'], ['admissions.view', 'admissions.manage']);
        $w['section'] = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['admissionHead'] = $this->makeFeeHead($w, ['code' => 'ADMISSION', 'name' => 'Admission fee']);
        $w['tuition'] = $this->makeFeeHead($w);
        $w['structure'] = $this->activeStructure($w);
        $w['students'] = 0;

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function activeStructure(array $w): FeeStructure
    {
        $service = app(FeeStructureService::class);
        $structure = $this->makeDraftStructure($w, ['code' => 'G-DEF']);
        $admission = $service->addLine($w['school'], $structure->id, ['fee_head_id' => $w['admissionHead']->id, 'amount' => '2500.00', 'is_optional' => true], $w['actor']);
        $service->replaceInstallments($w['school'], $structure->id, $admission->id, [
            ['label' => 'Admission', 'billing_period_key' => 'ADM', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2027-05-31', 'due_date' => '2026-06-10', 'amount' => '2500.00'],
        ], $w['actor']);
        $tuition = $service->addLine($w['school'], $structure->id, ['fee_head_id' => $w['tuition']->id, 'amount' => '12000.00', 'is_optional' => false], $w['actor']);
        $service->replaceInstallments($w['school'], $structure->id, $tuition->id, [
            ['label' => 'Term 1', 'billing_period_key' => 'T1', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2026-10-31', 'due_date' => '2026-06-10', 'amount' => '5000.00'],
            ['label' => 'Term 2', 'billing_period_key' => 'T2', 'period_starts_on' => '2026-11-01', 'period_ends_on' => '2027-05-31', 'due_date' => '2026-11-10', 'amount' => '7000.00'],
        ], $w['actor']);

        return $service->activate($w['school'], $structure->id, $w['actor']);
    }

    private function fees(): AdmissionFeeSelectionService
    {
        return app(AdmissionFeeSelectionService::class);
    }

    /** @param  array<string, mixed>  $w */
    private function map(array $w, ?string $headId = null): void
    {
        $this->fees()->setFeeHead($w['school'], $headId ?? $w['admissionHead']->id, $w['admissions']);
    }

    /** @param  array<string, mixed>  $w */
    private function application(array $w, string $status = 'accepted'): AdmissionApplication
    {
        return $this->createAdmissionApplication($this->createApplicant($w['school']), $w['year'], $w['campus'], $w['grade'], ['status' => $status]);
    }

    /** @param  array<string, mixed>  $w */
    private function convert(array &$w, ?AdmissionApplication $application = null, ?string $roll = null): AdmissionApplication
    {
        $n = ++$w['students'];

        return app(AdmissionConversionService::class)->convert($application ?? $this->application($w), "S-ADM-{$n}", $w['section'], $roll ?? sprintf('%02d', $n), '2026-06-01', null, $w['admissions'])->application;
    }

    /** @param  array<string, mixed>  $w */
    private function selections(array $w, ?string $studentId = null): Collection
    {
        return $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->when($studentId, fn ($q) => $q->where('student_id', $studentId))->get());
    }

    /** @param  array<string, mixed>  $w */
    private function links(array $w): Collection
    {
        return $this->inSchool($w['school'], fn () => AdmissionFeeSelection::query()->orderBy('created_at')->get());
    }

    /** @param  array<string, mixed>  $w */
    private function charges(array $w): Collection
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->get());
    }

    /** @param  array<string, mixed>  $w */
    private function audits(array $w, string $type): Collection
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->get());
    }

    #[Test]
    public function a_conversion_records_the_students_admission_fee_intent_once_and_no_charge(): void
    {
        $w = $this->world();
        $this->map($w);

        $converted = $this->convert($w);

        $studentId = $converted->converted_student_id;
        $this->assertSame('converted', $converted->status, 'the conversion itself is unchanged');
        $selection = $this->selections($w, $studentId)->sole();
        $this->assertSame([FeeOptionalSelection::STATUS_ACTIVE, $w['year']->id, $w['admissionHead']->id, $w['admissions']->id],
            [$selection->status, $selection->academic_year_id, $selection->fee_head_id, $selection->selected_by_user_id]);
        $link = $this->links($w)->sole();
        $this->assertSame([$converted->id, $studentId, $w['year']->id, $w['admissionHead']->id, $selection->id, 'created'],
            [$link->admission_application_id, $link->student_id, $link->academic_year_id, $link->fee_head_id, $link->fee_optional_selection_id, $link->selection_outcome]);
        $this->assertCount(0, $this->charges($w), 'conversion records intent only; it never assesses money');

        $fee = $this->audits($w, 'fee_optional_selection.created')->sole();
        $this->assertSame(['admissions', $converted->id], [$fee->metadata['sourceModule'], $fee->metadata['sourceId']], 'FEE audits the source');
        $this->assertSame($w['admissions']->id, $fee->actor_user_id);
        $linked = $this->audits($w, 'admission_fee_selection.linked')->sole();
        $this->assertSame([$selection->id, $studentId, 'created'], [$linked->metadata['feeOptionalSelectionId'], $linked->metadata['studentId'], $linked->metadata['selectionOutcome']]);
        $this->assertSame($w['admissions']->id, $linked->actor_user_id);

        // A replay of the fee-link records nothing new: one selection, one link, still no charge.
        $this->assertSame('already_linked', $this->fees()->recordForConversion($converted, $w['admissions']));
        $this->assertCount(1, $this->selections($w, $studentId));
        $this->assertCount(1, $this->links($w));
        $this->assertCount(1, $this->audits($w, 'admission_fee_selection.linked'));
        $this->assertCount(0, $this->charges($w));
    }

    #[Test]
    public function an_unconfigured_school_converts_normally_and_the_outcome_is_audited(): void
    {
        $w = $this->world();

        $converted = $this->convert($w);

        $this->assertSame('converted', $converted->status);
        $this->assertCount(0, $this->selections($w));
        $this->assertCount(0, $this->links($w));
        $this->assertCount(0, $this->charges($w));
        $audit = $this->audits($w, 'admission_fee_selection.not_applicable')->sole();
        $this->assertSame(['not_configured', $converted->id, $converted->converted_student_id, null],
            [$audit->metadata['reason'], $audit->metadata['admissionApplicationId'], $audit->metadata['studentId'], $audit->metadata['feeHeadId']]);
    }

    #[Test]
    public function other_not_applicable_cases_are_audited_and_never_fail_the_conversion(): void
    {
        $w = $this->world();

        // The mapped head is not an optional line of the Student's structure (Tuition is required).
        $this->map($w, $w['tuition']->id);
        $requiredLine = $this->convert($w);
        $this->assertSame('converted', $requiredLine->status);

        // The mapped head was deactivated after it was configured.
        $this->map($w);
        app(FeeHeadService::class)->deactivate($w['school'], $w['admissionHead']->id, $w['finance']);
        $inactiveHead = $this->convert($w);
        $this->assertSame('converted', $inactiveHead->status);

        // No active structure for the application's grade.
        $this->map($w, $w['tuition']->id);
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'name' => 'Grade 9', 'sequence' => 9]);
        $section = $this->createSection($w['year'], $w['campus'], $otherGrade, ['code' => 'G9A', 'name' => 'G9 A']);
        $application = $this->createAdmissionApplication($this->createApplicant($w['school']), $w['year'], $w['campus'], $otherGrade, ['status' => 'accepted']);
        $converted = app(AdmissionConversionService::class)->convert($application, 'S-ADM-G9', $section, '01', '2026-06-01', null, $w['admissions'])->application;
        $this->assertSame('converted', $converted->status);

        $this->assertCount(0, $this->links($w));
        $this->assertCount(0, $this->selections($w));
        $this->assertCount(0, $this->charges($w));
        // Each conversion's own outcome (keyed by application: audit rows written in one instant have no stable order).
        $expected = [$requiredLine->id => 'no_optional_line', $inactiveHead->id => 'fee_head_unavailable', $converted->id => 'no_active_structure'];
        $actual = $this->audits($w, 'admission_fee_selection.not_applicable')->mapWithKeys(fn ($a) => [$a->metadata['admissionApplicationId'] => $a->metadata['reason']])->all();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function a_conversion_that_does_not_commit_leaves_no_admission_fee_evidence(): void
    {
        $w = $this->world();
        $this->map($w);

        // 1. A real constraint failure at the enrollment step: the whole conversion rolls back.
        $other = app(StudentService::class)->create($w['school'], ['student_number' => 'S-OTHER', 'first_name' => 'Other', 'date_of_birth' => '2016-01-01']);
        app(StudentEnrollmentService::class)->enroll($other, $w['section'], '09', '2026-06-01');
        $application = $this->application($w);
        $this->assertThrows(fn () => $this->convert($w, $application, '09'), DuplicateEnrollmentRollNumberException::class);

        // 2. A conversion that succeeded inside a caller's transaction which then rolled back.
        $rolledBack = $this->application($w);
        $this->assertThrows(fn () => DB::transaction(function () use (&$w, $rolledBack): void {
            $this->convert($w, $rolledBack);
            $this->assertCount(1, $this->links($w), 'recorded inside the conversion transaction');
            throw new RuntimeException('caller aborts');
        }), RuntimeException::class);

        // 3. An application that is not accepted is refused before anything is written.
        $this->assertThrows(fn () => $this->convert($w, $this->application($w, 'submitted')), InvalidAdmissionApplicationTransitionException::class);

        $this->assertSame(['accepted', 'accepted'], [$this->inSchool($w['school'], fn () => $application->refresh()->status), $this->inSchool($w['school'], fn () => $rolledBack->refresh()->status)]);
        $this->assertCount(0, $this->links($w));
        $this->assertCount(0, $this->selections($w));
        $this->assertCount(0, $this->audits($w, 'admission_fee_selection.linked'));
        $this->assertCount(0, $this->audits($w, 'fee_optional_selection.created'));

        // The fee-link itself refuses an unconverted application.
        $this->assertThrows(fn () => $this->fees()->recordForConversion($application, $w['admissions']), \LogicException::class);
    }

    #[Test]
    public function finance_assesses_the_one_time_amount_once_and_replays_create_neither_intent_nor_money(): void
    {
        $w = $this->world();
        $this->map($w);
        $converted = $this->convert($w);
        $studentId = $converted->converted_student_id;

        // Only the authorized Finance run turns the intent into money: the ADM instalment in full, once.
        $this->executedRun($w, 'ADM');
        $charges = $this->charges($w);
        $this->assertSame(['2500.00'], $charges->pluck('amount')->all(), 'the amount is FEE\'s instalment; Admissions stores none');
        $this->assertSame($studentId, $charges->sole()->student_id, 'the charge subject is the converted Student');
        $this->assertSame($w['admissionHead']->id, $this->inSchool($w['school'], fn () => DB::table('fee_assessments')->where('charge_id', $charges->sole()->id)->value('fee_head_id')));

        // Replaying the fee-link and re-running the period create neither intent nor money.
        $this->fees()->recordForConversion($converted, $w['admissions']);
        $this->executedRun($w, 'ADM');
        $this->assertCount(1, $this->charges($w));
        $this->assertCount(1, $this->selections($w, $studentId));
        $this->assertCount(1, $this->links($w));
    }

    #[Test]
    public function an_existing_finance_selection_is_reused_not_duplicated(): void
    {
        $w = $this->world();

        // Converted while unconfigured; Finance then selected the line by hand; the School then configured the head.
        $converted = $this->convert($w);
        $line = $this->inSchool($w['school'], fn () => DB::table('fee_structure_lines')->where('fee_structure_id', $w['structure']->id)->where('fee_head_id', $w['admissionHead']->id)->value('id'));
        app(FeeOptionalSelectionService::class)->select($w['school'], $converted->converted_student_id, $line, $w['finance']);
        $this->map($w);

        // The (idempotent) fee-link then reuses Finance's canonical selection rather than duplicating it.
        $this->assertSame('linked', $this->fees()->recordForConversion($converted, $w['admissions']));
        $link = $this->links($w)->sole();
        $this->assertSame('reused', $link->selection_outcome);
        $this->assertSame($this->selections($w, $converted->converted_student_id)->sole()->id, $link->fee_optional_selection_id);
    }

    #[Test]
    public function the_mapping_holds_no_amount_and_names_only_an_active_fee_head_of_the_same_school(): void
    {
        $w = $this->world();
        $this->assertStringNotContainsString('amount', implode(',', DB::getSchemaBuilder()->getColumnListing('admission_fee_heads')), 'FEE owns the amount');
        $this->assertStringNotContainsString('amount', implode(',', DB::getSchemaBuilder()->getColumnListing('admission_fee_selections')), 'FEE owns the amount');
        $this->assertStringNotContainsString('applicant', implode(',', DB::getSchemaBuilder()->getColumnListing('admission_fee_selections')), 'no applicant is a financial subject (D1)');
        $this->assertNotContains('applicant_id', DB::getSchemaBuilder()->getColumnListing('charges'), 'charges stay Student-only (D1)');

        $this->map($w);
        $this->assertSame($w['admissionHead']->id, $this->fees()->feeHead($w['school'])['feeHeadId']);
        $this->map($w, $w['tuition']->id);
        $this->fees()->setFeeHead($w['school'], null, $w['admissions']);
        $this->assertNull($this->fees()->feeHead($w['school']));
        $this->assertSame([2, 1], [$this->audits($w, 'admission_fee_head.set')->count(), $this->audits($w, 'admission_fee_head.cleared')->count()]);
        $this->assertSame([null, $w['tuition']->id], [$this->audits($w, 'admission_fee_head.cleared')->sole()->metadata['feeHeadId'], $this->audits($w, 'admission_fee_head.cleared')->sole()->metadata['previousFeeHeadId']]);

        $foreign = $this->makeFeeHead(['school' => $other = $this->createSchool(), 'actor' => $this->createUserWithCapabilities($other, self::FEE_SETUP_CAPABILITIES),
            'receivable' => $this->createLedgerAccount($other, ['code' => 'AR-X', 'type' => 'asset']), 'revenue' => $this->createLedgerAccount($other, ['code' => 'INC-X', 'type' => 'income'])]);
        $this->assertThrows(fn () => $this->fees()->setFeeHead($w['school'], $foreign->id, $w['admissions']), AdmissionFeeHeadNotSelectableException::class);
        app(FeeHeadService::class)->deactivate($w['school'], $w['tuition']->id, $w['finance']);
        $this->assertThrows(fn () => $this->fees()->setFeeHead($w['school'], $w['tuition']->id, $w['admissions']), AdmissionFeeHeadNotSelectableException::class);

        // The database refuses a cross-School head and a second mapping for the School.
        $insert = fn (string $head) => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('admission_fee_heads')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'fee_head_id' => $head, 'created_at' => now(), 'updated_at' => now(),
        ])));
        $this->assertThrows(fn () => $insert($foreign->id), QueryException::class, 'admission_fee_heads_fee_head_fk');
        $insert($w['admissionHead']->id);
        $this->assertThrows(fn () => $insert($w['admissionHead']->id), QueryException::class, 'admission_fee_heads_one_per_school');
    }

    #[Test]
    public function the_provenance_row_is_unique_consistent_and_insert_only_in_the_database(): void
    {
        $w = $this->world();
        $this->map($w);
        $converted = $this->convert($w);
        $link = $this->links($w)->sole();
        $row = fn (array $over) => array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'admission_application_id' => $converted->id,
            'student_id' => $converted->converted_student_id, 'academic_year_id' => $w['year']->id, 'fee_head_id' => $w['admissionHead']->id,
            'fee_optional_selection_id' => $link->fee_optional_selection_id, 'selection_outcome' => 'reused', 'created_at' => now(),
        ], $over);
        $insert = fn (array $over) => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('admission_fee_selections')->insert($row($over))));

        $this->assertThrows(fn () => $insert([]), QueryException::class, 'admission_fee_selections_one_per_application');

        // An unconverted application, another Student, or another Student's selection is refused.
        $pending = $this->application($w);
        $this->assertThrows(fn () => $insert(['admission_application_id' => $pending->id]), QueryException::class, 'is not converted into this Student');
        $second = $this->convert($w);
        $this->assertThrows(fn () => $insert(['admission_application_id' => $second->id]), QueryException::class, 'is not converted into this Student');
        $this->fees()->setFeeHead($w['school'], null, $w['admissions']);
        $third = $this->convert($w);
        $this->assertThrows(fn () => $insert(['admission_application_id' => $third->id, 'student_id' => $third->converted_student_id]), QueryException::class, "not the converted Student's selection");

        // Insert-only: the runtime role can neither rewrite nor delete it; its foreign keys RESTRICT.
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('admission_fee_selections')->where('id', $link->id)->update(['selection_outcome' => 'reused']))), QueryException::class, 'permission denied');
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('admission_fee_selections')->where('id', $link->id)->delete())), QueryException::class, 'permission denied');
        $this->assertSame(['r', 'r', 'r'], array_map(fn ($r) => $r->confdeltype, DB::select(
            "select confdeltype from pg_constraint where conname in ('admission_fee_selections_application_fk', 'admission_fee_selections_selection_fk', 'admission_fee_selections_student_fk') order by conname",
        )));
    }

    #[Test]
    public function the_provenance_keeps_its_converted_application_and_never_touches_the_terminal_application_clock(): void
    {
        $w = $this->world();
        $participant = app(ConvertedApplicationRetentionService::class);
        $blocker = fn (AdmissionApplication $a) => $this->inSchool($w['school'], fn () => $participant->blocker($w['school']->id, $a->converted_student_id, [$a->converted_student_enrollment_id], [], $participant->tables()));
        $references = fn (string $parent) => array_values(array_unique(array_column(app(ReferencingRows::class)->to($parent), 'table')));

        // Unconfigured: the converted application leaves with its Student core record (E21.3B), nothing holds it.
        $plain = $this->convert($w);
        $this->assertNull($blocker($plain));

        // With Admission-fee provenance the converted application -- and so its Student's core purge -- is
        // dependency_blocked by the Finance evidence, as the FEE selection already keeps the Student.
        $this->map($w);
        $charged = $this->convert($w);
        $this->assertSame('admission_fee_selections', $blocker($charged));
        $this->assertContains('admission_fee_selections', $references('admission_applications'));
        $this->assertContains('admission_fee_selections', $references('students'));
        $this->assertContains('fee_optional_selections', $references('students'), 'the selection keeps the Student regardless (Finance D8)');

        // Only converted applications can carry provenance, so the one-year terminal (rejected/withdrawn) clock is untouched.
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('admission_fee_selections as f')
            ->join('admission_applications as a', 'a.id', '=', 'f.admission_application_id')->where('a.status', '!=', 'converted')->count()));
    }

    #[Test]
    public function admissions_authority_records_intent_but_never_assesses_and_finance_cannot_configure_admissions(): void
    {
        $w = $this->world();
        $base = "/api/v1/schools/{$w['school']->id}";
        $as = fn (User $u) => $this->actingAs($u)->withHeader('X-School-Id', $w['school']->id);

        $as($w['admissions'])->putJson("{$base}/admission-fee-head", ['fee_head_id' => $w['admissionHead']->id])
            ->assertOk()->assertJsonPath('data.feeHeadId', $w['admissionHead']->id)->assertJsonPath('data.code', 'ADMISSION');
        $viewer = $this->createUserWithCapabilities($w['school'], ['admissions.view']);
        $as($viewer)->getJson("{$base}/admission-fee-head")->assertOk()->assertJsonPath('data.code', 'ADMISSION');

        // Admissions viewers and Finance staff without Admissions capabilities cannot configure or convert.
        $as($viewer)->putJson("{$base}/admission-fee-head", ['fee_head_id' => null])->assertForbidden();
        $as($w['finance'])->putJson("{$base}/admission-fee-head", ['fee_head_id' => null])->assertForbidden();
        $as($w['finance'])->getJson("{$base}/admission-fee-head")->assertForbidden();
        $application = $this->application($w);
        $payload = ['student_number' => 'S-API-1', 'section_id' => $w['section']->id, 'roll_number' => '41', 'starts_on' => '2026-06-01'];
        $as($w['finance'])->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$base}/admission-applications/{$application->id}/convert", $payload)->assertForbidden();
        $as($viewer)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$base}/admission-applications/{$application->id}/convert", $payload)->assertForbidden();

        // The authorized Admissions conversion records the intent ...
        $as($w['admissions'])->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("{$base}/admission-applications/{$application->id}/convert", $payload)->assertOk();
        $as($viewer)->getJson("{$base}/admission-applications/{$application->id}/fee-selection")->assertOk()
            ->assertJsonPath('data.feeHeadId', $w['admissionHead']->id)->assertJsonPath('data.selectionOutcome', 'created');
        $this->assertCount(0, $this->charges($w));

        // ... but Admissions staff can neither run (or create) an assessment nor make a selection by hand.
        $this->assertThrows(fn () => $this->runs()->create($w['school'], $w['structure']->id, 'ADM', $w['admissions']), AuthorizationException::class);
        $as($w['admissions'])->postJson("{$base}/fee-assessment-runs", ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'ADM'])->assertForbidden();
        $line = $this->inSchool($w['school'], fn () => DB::table('fee_structure_lines')->where('fee_structure_id', $w['structure']->id)->where('fee_head_id', $w['admissionHead']->id)->value('id'));
        $this->assertThrows(fn () => app(FeeOptionalSelectionService::class)->select($w['school'], $this->createStudent($w['school'])->id, $line, $w['admissions']), AuthorizationException::class);

        // Another School can read neither the provenance nor the configuration.
        $as($w['finance'])->getJson("{$base}/admission-applications/{$application->id}/fee-selection")->assertForbidden();
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $this->actingAs($otherAdmin)->withHeader('X-School-Id', $other->id)
            ->getJson("/api/v1/schools/{$other->id}/admission-applications/{$application->id}/fee-selection")->assertNotFound();
        $this->actingAs($otherAdmin)->withHeader('X-School-Id', $other->id)
            ->getJson("/api/v1/schools/{$other->id}/admission-fee-head")->assertOk()->assertJsonPath('data', null);
        $this->assertSame(0, $this->inSchool($other, fn () => AdmissionFeeSelection::query()->count()), 'RLS: no provenance is visible to another School');
    }

    /** OPF.5 closure (rule 28): both Admissions OPF tables are isolated by forced RLS at the raw SQL layer and fail closed. */
    #[Test]
    public function the_admission_fee_tables_are_tenant_isolated_at_the_raw_sql_layer(): void
    {
        $w = $this->world();
        $this->map($w);
        $this->convert($w);
        $other = $this->createSchool();

        $this->assertTenantRlsIsolation([
            'admission_fee_heads' => AdmissionFeeHead::class,
            'admission_fee_selections' => AdmissionFeeSelection::class,
        ], $w['school']->id, $other->id);
    }
}
