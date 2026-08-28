<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolSubjectMappingException;
use App\Domain\Students\Application\Exceptions\InvalidSubjectMappingYearException;
use App\Domain\Students\Application\Exceptions\RequiredSubjectOfferingRolloverMappingException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\EnrollmentRolloverSubjectMapping;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1G.1: `enrollment_rollover_subject_mappings` schema/domain
 * foundation and `EnrollmentRolloverPlanService::upsertSubjectMapping()`/
 * `removeSubjectMapping()` -- see
 * docs/students/PHASE-1G-1-SUBJECT-ROLLOVER-MAPPING-FOUNDATION.md.
 * No dry-run/execution integration exists yet -- every test here stays
 * entirely within the new table and the two new service methods.
 */
class EnrollmentRolloverSubjectMappingServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): EnrollmentRolloverPlanService
    {
        return app(EnrollmentRolloverPlanService::class);
    }

    /**
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, grade: GradeLevel, subject: Subject, plan: EnrollmentRolloverPlan, sourceOffering: SubjectOffering, targetOffering: SubjectOffering}
     */
    private function buildElectiveMappingContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        $sourceOffering = $this->createSubjectOffering($sourceYear, $campus, $grade, $subject, ['is_required' => false]);
        $targetOffering = $this->createSubjectOffering($targetYear, $campus, $grade, $subject, ['is_required' => false]);

        return compact('school', 'campus', 'sourceYear', 'targetYear', 'grade', 'subject', 'plan', 'sourceOffering', 'targetOffering');
    }

    private function countAuditEvents(School $school, EnrollmentRolloverPlan $plan): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'enrollment_rollover_plan.configuration_changed')
                ->where('subject_id', $plan->id)
                ->count(),
        );
    }

    // ==================================================================
    // Model / relationships / UUIDv7 / tenancy
    // ==================================================================

    #[Test]
    public function a_mapping_id_is_a_uuid_v7(): void
    {
        ['plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();

        $mapping = $this->service()->upsertSubjectMapping($plan, $source, $target);

        // UUIDv7's version nibble is always "7".
        $this->assertSame('7', $mapping->id[14]);
    }

    #[Test]
    public function school_id_is_auto_filled_from_belongs_to_school(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();

        $mapping = $this->service()->upsertSubjectMapping($plan, $source, $target);

        $this->assertSame($school->id, $mapping->school_id);
    }

    #[Test]
    public function relationships_resolve_plan_and_source_and_target_offering(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $mapping = $this->service()->upsertSubjectMapping($plan, $source, $target);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $mapping->fresh(['plan', 'sourceSubjectOffering', 'targetSubjectOffering']));

        $this->assertSame($plan->id, $fresh->plan->id);
        $this->assertSame($source->id, $fresh->sourceSubjectOffering->id);
        $this->assertSame($target->id, $fresh->targetSubjectOffering->id);
    }

    #[Test]
    public function the_plan_exposes_its_subject_mappings(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $this->service()->upsertSubjectMapping($plan, $source, $target);

        $count = app(TenantContext::class)->withSchool($school, fn () => $plan->subjectMappings()->count());
        $this->assertSame(1, $count);
    }

    // ==================================================================
    // Three-state semantics
    // ==================================================================

    #[Test]
    public function explicit_target_mapping_is_stored_and_is_not_an_omit(): void
    {
        ['plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();

        $mapping = $this->service()->upsertSubjectMapping($plan, $source, $target);

        $this->assertSame($target->id, $mapping->target_subject_offering_id);
        $this->assertFalse($mapping->isExplicitOmit());
    }

    #[Test]
    public function explicit_omit_is_a_row_with_a_null_target_not_the_absence_of_a_row(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildElectiveMappingContext();

        $mapping = $this->service()->upsertSubjectMapping($plan, $source, null);

        $this->assertNull($mapping->target_subject_offering_id);
        $this->assertTrue($mapping->isExplicitOmit());
        $exists = app(TenantContext::class)->withSchool(
            $school,
            fn () => EnrollmentRolloverSubjectMapping::query()->whereKey($mapping->id)->exists(),
        );
        $this->assertTrue($exists, 'explicit omit is a real, durable row');
    }

    #[Test]
    public function an_unconfigured_source_offering_has_no_mapping_row_at_all(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildElectiveMappingContext();

        $exists = app(TenantContext::class)->withSchool(
            $school,
            fn () => EnrollmentRolloverSubjectMapping::query()
                ->where('plan_id', $plan->id)
                ->where('source_subject_offering_id', $source->id)
                ->exists(),
        );
        $this->assertFalse($exists);
    }

    // ==================================================================
    // Uniqueness / cross-School / year / elective validation
    // ==================================================================

    #[Test]
    public function the_database_rejects_a_second_mapping_row_for_the_same_plan_and_source_offering(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $this->createEnrollmentRolloverSubjectMapping($plan, $source, $target);

        $this->expectException(QueryException::class);
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverSubjectMapping::query()->create([
            'school_id' => $school->id,
            'plan_id' => $plan->id,
            'source_subject_offering_id' => $source->id,
            'target_subject_offering_id' => null,
        ]));
    }

    #[Test]
    public function upsert_rejects_a_foreign_school_source_offering(): void
    {
        ['plan' => $plan, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherYear = $this->createAcademicYear($otherSchool, ['code' => 'SRC']);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $otherSubject = $this->createSubject($otherSchool);
        $foreignSource = $this->createSubjectOffering($otherYear, $otherCampus, $otherGrade, $otherSubject, ['is_required' => false]);

        $this->expectException(CrossSchoolSubjectMappingException::class);
        $this->service()->upsertSubjectMapping($plan, $foreignSource, $target);
    }

    #[Test]
    public function upsert_rejects_a_foreign_school_target_offering(): void
    {
        ['plan' => $plan, 'sourceOffering' => $source] = $this->buildElectiveMappingContext();
        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherYear = $this->createAcademicYear($otherSchool, ['code' => 'TGT']);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $otherSubject = $this->createSubject($otherSchool);
        $foreignTarget = $this->createSubjectOffering($otherYear, $otherCampus, $otherGrade, $otherSubject, ['is_required' => false]);

        $this->expectException(CrossSchoolSubjectMappingException::class);
        $this->service()->upsertSubjectMapping($plan, $source, $foreignTarget);
    }

    #[Test]
    public function upsert_rejects_a_source_offering_from_the_wrong_academic_year(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'grade' => $grade, 'plan' => $plan, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        // A source Offering belonging to the TARGET year, not the plan's source year (a distinct Subject avoids the subject_offerings_unique_offering collision with $target).
        $otherSubject = $this->createSubject($school);
        $wrongYearSource = $this->createSubjectOffering($targetYear, $campus, $grade, $otherSubject, ['is_required' => false]);

        $this->expectException(InvalidSubjectMappingYearException::class);
        $this->service()->upsertSubjectMapping($plan, $wrongYearSource, $target);
    }

    #[Test]
    public function upsert_rejects_a_target_offering_from_the_wrong_academic_year(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'grade' => $grade, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildElectiveMappingContext();
        // A target Offering belonging to the SOURCE year, not the plan's target year.
        $otherSubject = $this->createSubject($school);
        $wrongYearTarget = $this->createSubjectOffering($sourceYear, $campus, $grade, $otherSubject, ['is_required' => false]);

        $this->expectException(InvalidSubjectMappingYearException::class);
        $this->service()->upsertSubjectMapping($plan, $source, $wrongYearTarget);
    }

    #[Test]
    public function upsert_rejects_a_required_source_offering(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'grade' => $grade, 'plan' => $plan, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $otherSubject = $this->createSubject($school);
        $requiredSource = $this->createSubjectOffering($sourceYear, $campus, $grade, $otherSubject, ['is_required' => true]);

        $this->expectException(RequiredSubjectOfferingRolloverMappingException::class);
        $this->service()->upsertSubjectMapping($plan, $requiredSource, $target);
    }

    #[Test]
    public function upsert_rejects_a_required_target_offering(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'grade' => $grade, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildElectiveMappingContext();
        $otherSubject = $this->createSubject($school);
        $requiredTarget = $this->createSubjectOffering($targetYear, $campus, $grade, $otherSubject, ['is_required' => true]);

        $this->expectException(RequiredSubjectOfferingRolloverMappingException::class);
        $this->service()->upsertSubjectMapping($plan, $source, $requiredTarget);
    }

    #[Test]
    public function a_failed_mutation_creates_no_row_bumps_no_version_and_writes_no_audit(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'grade' => $grade, 'plan' => $plan, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $otherSubject = $this->createSubject($school);
        $requiredSource = $this->createSubjectOffering($sourceYear, $campus, $grade, $otherSubject, ['is_required' => true]);
        $versionBefore = $plan->configuration_version;

        try {
            $this->service()->upsertSubjectMapping($plan, $requiredSource, $target);
            $this->fail('expected RequiredSubjectOfferingRolloverMappingException');
        } catch (RequiredSubjectOfferingRolloverMappingException) {
            // expected
        }

        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertSame($versionBefore, $freshPlan->configuration_version);
        $this->assertSame(0, $this->countAuditEvents($school, $plan));
        $exists = app(TenantContext::class)->withSchool(
            $school,
            fn () => EnrollmentRolloverSubjectMapping::query()
                ->where('plan_id', $plan->id)
                ->where('source_subject_offering_id', $requiredSource->id)
                ->exists(),
        );
        $this->assertFalse($exists);
    }

    // ==================================================================
    // Idempotency / versioning
    // ==================================================================

    #[Test]
    public function upserting_the_identical_target_twice_is_a_no_op(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $first = $this->service()->upsertSubjectMapping($plan, $source, $target);
        $versionAfterFirst = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh())->configuration_version;

        $second = $this->service()->upsertSubjectMapping($plan, $source, $target);

        $this->assertSame($first->id, $second->id);
        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertSame($versionAfterFirst, $freshPlan->configuration_version, 'a true no-op must not bump configuration_version');
        $this->assertSame(1, $this->countAuditEvents($school, $plan), 'a true no-op must not write a duplicate audit event');
    }

    #[Test]
    public function upserting_omit_twice_is_a_no_op(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildElectiveMappingContext();
        $this->service()->upsertSubjectMapping($plan, $source, null);
        $versionAfterFirst = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh())->configuration_version;

        $this->service()->upsertSubjectMapping($plan, $source, null);

        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertSame($versionAfterFirst, $freshPlan->configuration_version);
        $this->assertSame(1, $this->countAuditEvents($school, $plan));
    }

    #[Test]
    public function changing_the_target_offering_bumps_the_configuration_version_and_audits(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'grade' => $grade, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $targetOne] = $this->buildElectiveMappingContext();
        $otherSubject = $this->createSubject($school);
        $targetTwo = $this->createSubjectOffering($targetYear, $campus, $grade, $otherSubject, ['is_required' => false]);
        $first = $this->service()->upsertSubjectMapping($plan, $source, $targetOne);
        $versionAfterFirst = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh())->configuration_version;

        $second = $this->service()->upsertSubjectMapping($plan, $source, $targetTwo);

        $this->assertSame($first->id, $second->id, 'the same row is updated, not duplicated');
        $this->assertSame($targetTwo->id, $second->target_subject_offering_id);
        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertGreaterThan($versionAfterFirst, $freshPlan->configuration_version);
        $this->assertSame(2, $this->countAuditEvents($school, $plan));
    }

    #[Test]
    public function changing_a_mapped_offering_to_explicit_omit_bumps_the_version(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $this->service()->upsertSubjectMapping($plan, $source, $target);
        $versionAfterFirst = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh())->configuration_version;

        $mapping = $this->service()->upsertSubjectMapping($plan, $source, null);

        $this->assertTrue($mapping->isExplicitOmit());
        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertGreaterThan($versionAfterFirst, $freshPlan->configuration_version);
    }

    #[Test]
    public function changing_an_explicit_omit_to_a_mapped_offering_bumps_the_version(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $this->service()->upsertSubjectMapping($plan, $source, null);
        $versionAfterFirst = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh())->configuration_version;

        $mapping = $this->service()->upsertSubjectMapping($plan, $source, $target);

        $this->assertFalse($mapping->isExplicitOmit());
        $this->assertSame($target->id, $mapping->target_subject_offering_id);
        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertGreaterThan($versionAfterFirst, $freshPlan->configuration_version);
    }

    #[Test]
    public function a_real_change_invalidates_the_plans_validated_status(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        app(TenantContext::class)->withSchool($school, fn () => $plan->update([
            'status' => 'validated',
            'validated_configuration_version' => $plan->configuration_version,
        ]));
        $this->assertTrue(app(TenantContext::class)->withSchool($school, fn () => $plan->fresh())->isValidatedForCurrentConfiguration());

        $this->service()->upsertSubjectMapping(app(TenantContext::class)->withSchool($school, fn () => $plan->fresh()), $source, $target);

        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertFalse($freshPlan->isValidatedForCurrentConfiguration());
    }

    // ==================================================================
    // removeSubjectMapping()
    // ==================================================================

    #[Test]
    public function remove_returns_a_mapped_offering_to_unconfigured(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $this->service()->upsertSubjectMapping($plan, $source, $target);

        $this->service()->removeSubjectMapping($plan, $source);

        $exists = app(TenantContext::class)->withSchool(
            $school,
            fn () => EnrollmentRolloverSubjectMapping::query()
                ->where('plan_id', $plan->id)
                ->where('source_subject_offering_id', $source->id)
                ->exists(),
        );
        $this->assertFalse($exists, 'remove must delete the row entirely, distinct from an explicit-omit row');
    }

    #[Test]
    public function remove_bumps_the_configuration_version_and_audits(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $this->service()->upsertSubjectMapping($plan, $source, $target);
        $versionAfterUpsert = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh())->configuration_version;

        $this->service()->removeSubjectMapping($plan, $source);

        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertGreaterThan($versionAfterUpsert, $freshPlan->configuration_version);
        $this->assertSame(2, $this->countAuditEvents($school, $plan));
    }

    #[Test]
    public function removing_an_already_unconfigured_source_offering_is_a_no_op(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildElectiveMappingContext();
        $versionBefore = $plan->configuration_version;

        $this->service()->removeSubjectMapping($plan, $source);

        $freshPlan = app(TenantContext::class)->withSchool($school, fn () => $plan->fresh());
        $this->assertSame($versionBefore, $freshPlan->configuration_version);
        $this->assertSame(0, $this->countAuditEvents($school, $plan));
    }

    #[Test]
    public function remove_rejects_a_foreign_school_source_offering(): void
    {
        ['plan' => $plan] = $this->buildElectiveMappingContext();
        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherYear = $this->createAcademicYear($otherSchool);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $otherSubject = $this->createSubject($otherSchool);
        $foreignSource = $this->createSubjectOffering($otherYear, $otherCampus, $otherGrade, $otherSubject, ['is_required' => false]);

        $this->expectException(CrossSchoolSubjectMappingException::class);
        $this->service()->removeSubjectMapping($plan, $foreignSource);
    }

    // ==================================================================
    // assertConfigurable() lifecycle
    // ==================================================================

    #[Test]
    public function upsert_is_rejected_once_the_plan_is_executing(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        app(TenantContext::class)->withSchool($school, fn () => $plan->update(['status' => 'executing']));

        $this->expectException(RolloverPlanNoLongerConfigurableException::class);
        $this->service()->upsertSubjectMapping(app(TenantContext::class)->withSchool($school, fn () => $plan->fresh()), $source, $target);
    }

    #[Test]
    public function remove_is_rejected_once_the_plan_is_executing(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();
        $this->service()->upsertSubjectMapping($plan, $source, $target);
        app(TenantContext::class)->withSchool($school, fn () => $plan->update(['status' => 'executing']));

        $this->expectException(RolloverPlanNoLongerConfigurableException::class);
        $this->service()->removeSubjectMapping(app(TenantContext::class)->withSchool($school, fn () => $plan->fresh()), $source);
    }

    // ==================================================================
    // Audit metadata is PII-minimal
    // ==================================================================

    #[Test]
    public function the_configuration_changed_audit_metadata_carries_only_ids_and_change_type(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildElectiveMappingContext();

        $mapping = $this->service()->upsertSubjectMapping($plan, $source, $target);

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'enrollment_rollover_plan.configuration_changed')
                ->where('subject_id', $plan->id)
                ->latest('id')
                ->first(),
        );

        $this->assertNotNull($event);
        $this->assertSame('subject_mapping', $event->metadata['change']);
        $this->assertSame($mapping->id, $event->metadata['subjectMappingId']);
        $this->assertSame($source->id, $event->metadata['sourceSubjectOfferingId']);
        $this->assertSame($target->id, $event->metadata['targetSubjectOfferingId']);
        $this->assertSame(
            ['change', 'subjectMappingId', 'sourceSubjectOfferingId', 'targetSubjectOfferingId'],
            array_keys($event->metadata),
        );
    }
}
