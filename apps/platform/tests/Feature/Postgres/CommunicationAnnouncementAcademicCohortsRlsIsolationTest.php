<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.3 (mandatory per root CLAUDE.md rule 28) -- tenant isolation
 * for the new communication_announcement_academic_cohorts table.
 * Mirrors CommunicationAnnouncementDomainAudienceMembersRlsIsolationTest's
 * conventions exactly.
 */
class CommunicationAnnouncementAcademicCohortsRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function graph($school): array
    {
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);

        return ['year' => $year, 'grade' => $grade, 'section' => $section];
    }

    private function insertCohort(array $overrides): void
    {
        DB::connection('pgsql')->table('communication_announcement_academic_cohorts')->insert(array_merge([
            'id' => (string) new UuidV7,
            'cohort_type' => 'grade_level',
            'section_id' => null,
            'recipient_kind' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['communication_announcement_academic_cohorts', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade] = $this->graph($school);
        $announcement = $this->createAnnouncement($school, $creator, ['audience_type' => 'grade']);

        app(TenantContext::class)->withSchool($school, function () use ($school, $announcement, $year, $grade): void {
            $this->insertCohort([
                'school_id' => $school->id,
                'announcement_id' => $announcement->id,
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
            ]);
        });

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_announcement_academic_cohorts')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_academic_cohort(): void
    {
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        ['year' => $yearB, 'grade' => $gradeB] = $this->graph($schoolB);
        $announcementB = $this->createAnnouncement($schoolB, $creatorB, ['audience_type' => 'grade']);
        $rowId = (string) new UuidV7;

        app(TenantContext::class)->withSchool($schoolB, function () use ($schoolB, $announcementB, $yearB, $gradeB, $rowId): void {
            $this->insertCohort([
                'id' => $rowId,
                'school_id' => $schoolB->id,
                'announcement_id' => $announcementB->id,
                'academic_year_id' => $yearB->id,
                'grade_level_id' => $gradeB->id,
            ]);
        });

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_announcement_academic_cohorts where id = ?', [$rowId]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_row_claiming_school_bs_id(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        ['year' => $yearA, 'grade' => $gradeA] = $this->graph($schoolA);
        $announcementA = $this->createAnnouncement($schoolA, $creatorA, ['audience_type' => 'grade']);
        $schoolB = $this->createSchool();

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolB, $announcementA, $yearA, $gradeA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolB, $announcementA, $yearA, $gradeA): void {
                    $this->insertCohort([
                        'school_id' => $schoolB->id,
                        'announcement_id' => $announcementA->id,
                        'academic_year_id' => $yearA->id,
                        'grade_level_id' => $gradeA->id,
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'RLS WITH CHECK must reject a row written under School A context but claiming School B.');
    }

    #[Test]
    public function a_cross_school_grade_level_reference_is_rejected_at_insert_time(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $yearA = $this->createAcademicYear($schoolA, ['status' => 'active']);
        $announcementA = $this->createAnnouncement($schoolA, $creatorA, ['audience_type' => 'grade']);
        $schoolB = $this->createSchool();
        $gradeB = $this->createGradeLevel($schoolB);

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $announcementA, $yearA, $gradeB, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $announcementA, $yearA, $gradeB): void {
                    $this->insertCohort([
                        'school_id' => $schoolA->id,
                        'announcement_id' => $announcementA->id,
                        'academic_year_id' => $yearA->id,
                        'grade_level_id' => $gradeB->id,
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against grade_levels(id, school_id) must reject a cross-School reference.');
    }

    #[Test]
    public function a_row_naming_both_grade_level_and_section_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);
        $announcement = $this->createAnnouncement($school, $creator, ['audience_type' => 'grade']);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $announcement, $year, $grade, $section, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $announcement, $year, $grade, $section): void {
                    $this->insertCohort([
                        'school_id' => $school->id,
                        'announcement_id' => $announcement->id,
                        'academic_year_id' => $year->id,
                        'grade_level_id' => $grade->id,
                        'section_id' => $section->id,
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'num_nonnulls(grade_level_id, section_id) = 1 must reject a row naming both.');
    }

    #[Test]
    public function a_row_naming_neither_grade_level_nor_section_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $announcement = $this->createAnnouncement($school, $creator, ['audience_type' => 'grade']);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $announcement, $year, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $announcement, $year): void {
                    $this->insertCohort([
                        'school_id' => $school->id,
                        'announcement_id' => $announcement->id,
                        'academic_year_id' => $year->id,
                        'grade_level_id' => null,
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'num_nonnulls(grade_level_id, section_id) = 1 must reject a row naming neither.');
    }

    #[Test]
    public function a_second_cohort_row_for_the_same_announcement_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade] = $this->graph($school);
        $announcement = $this->createAnnouncement($school, $creator, ['audience_type' => 'grade']);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $announcement, $year, $grade, &$rejected): void {
            $this->insertCohort([
                'school_id' => $school->id,
                'announcement_id' => $announcement->id,
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
            ]);

            try {
                DB::connection('pgsql')->transaction(function () use ($school, $announcement, $year, $grade): void {
                    $this->insertCohort([
                        'school_id' => $school->id,
                        'announcement_id' => $announcement->id,
                        'academic_year_id' => $year->id,
                        'grade_level_id' => $grade->id,
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The unique announcement_id constraint must reject a second cohort row for the same Announcement.');
    }
}
