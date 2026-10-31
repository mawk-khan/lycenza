<?php

namespace Tests\Feature\Students\Communities;

use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Groups\ClubResponsibility;
use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\StudentCommunityService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentGroupMembership;
use App\Domain\Students\Infrastructure\StudentGroupResponsibility;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.8 (§43): a real enrollment rollover -- plan, mapping, dry run,
 * executed item -- leaves a Student's House, Clubs, Activities and Club
 * responsibility exactly as they were: no row ended, created or rewritten.
 * (The browser rollover is not replayed on the canonical demo: its rollover
 * is already executed and a second would rewrite the demo world.)
 */
class StudentCommunityRolloverTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function an_executed_rollover_never_creates_ends_or_rewrites_a_community_membership(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $context = app(TenantContext::class);
        $campus = $this->createCampus($school);
        $source = $this->createAcademicYear($school, ['code' => 'SRC', 'status' => 'active', 'starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->addMonths(6)->toDateString()]);
        $target = $this->createAcademicYear($school, ['code' => 'TGT', 'starts_on' => now()->addMonths(7)->toDateString(), 'ends_on' => now()->addMonths(17)->toDateString()]);
        $g5 = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $g6 = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $from = $this->createSection($source, $campus, $g5, ['name' => '5A', 'code' => '5A']);
        $to = $this->createSection($target, $campus, $g6, ['name' => '6A', 'code' => '6A']);
        $student = $this->createStudent($school, ['student_number' => 'S-1']);
        app(StudentEnrollmentService::class)->enroll($student, $from, '7', $source->starts_on->toDateString(), $admin);

        // House + two communities + a Club responsibility.
        $red = app(HouseAdministrationService::class)->create($school, $admin, ['name' => 'Red', 'code' => 'RED']);
        $houses = app(StudentHouseService::class);
        $houses->assign($school, $admin, $student->id, $red->id, null, $houses->history($school, $admin, $student->id)['version']);
        $communities = app(CommunityAdministrationService::class);
        $service = app(StudentCommunityService::class);
        $chess = $service->join($school, $admin, $student->id, $communities->create($school, $admin, StudentGroupType::CLUB, ['name' => 'Chess', 'code' => 'CHESS'])->id);
        $service->join($school, $admin, $student->id, $communities->create($school, $admin, StudentGroupType::ACTIVITY, ['name' => 'Football', 'code' => 'FOOT'])->id);
        $service->assignResponsibility($school, $admin, $student->id, $chess->id, ClubResponsibility::PRESIDENT);
        $snapshot = fn () => $context->withSchool($school, fn () => [
            StudentGroupMembership::query()->orderBy('id')->get(['id', 'student_group_id', 'starts_on', 'ends_on', 'version'])->toArray(),
            StudentGroupResponsibility::query()->orderBy('id')->get(['id', 'ends_on', 'version'])->toArray(),
        ]);
        $before = $snapshot();

        $plan = $this->createEnrollmentRolloverPlan($source, $target);
        $plans = app(EnrollmentRolloverPlanService::class);
        $plans->upsertMapping($plan, $g5, null, $g6, $to);
        $fresh = fn () => $context->withSchool($school, fn () => $plan->fresh());
        app(EnrollmentRolloverDryRunService::class)->run($fresh());
        $item = $context->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->firstOrFail());
        $plans->setItemDecision($fresh(), $item, 'promote', null, 'explicit', '11');
        $this->assertTrue(app(EnrollmentRolloverDryRunService::class)->run($fresh())['validated']);
        $item = $context->withSchool($school, fn () => EnrollmentRolloverItem::query()->findOrFail($item->id));
        app(EnrollmentRolloverItemExecutionService::class)->execute($item, $admin);

        $this->assertSame(1, $context->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('academic_year_id', $target->id)->count()), 'the rollover really ran');
        $this->assertSame($before, $snapshot(), 'House, Club, Activity and responsibility rows are untouched');
    }
}
