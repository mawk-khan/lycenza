<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\Exceptions\LmsAudienceSectionOutsideOfferingException;
use App\Domain\LMS\Application\Exceptions\LmsOwnerEmployeeInvalidException;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.5B (ADR 0063 section 35) -- the ownership persistence through the LMS
 * services, for both resources:
 *
 * - administrative creation is unchanged: owner NULL, no audience;
 * - a SectionAudience (the only way to create a teacher-owned row, and one
 *   no route supplies) writes the owner and every Section atomically;
 * - bad Sections and a foreign owner are refused with nothing written;
 * - the existing admin API serializes none of it.
 *
 * No teacher access is tested because none exists.
 */
class LmsOwnershipPersistenceTest extends TestCase
{
    use CreatesLmsOwnershipFixtures, CreatesTenancyFixtures;

    private function admin(array $w): User
    {
        return $this->createUserWithCapabilities($w['school'], ['lms.content.view', 'lms.content.manage', 'lms.assignments.view', 'lms.assignments.manage']);
    }

    private function create(string $table, array $w, User $actor, ?SectionAudience $audience = null): LearningContent|Assignment
    {
        return $table === 'learning_content'
            ? app(LearningContentService::class)->create($w['school'], $w['offering']->id, ['title' => 'Reading'], $actor, $audience)
            : app(AssignmentService::class)->create($w['school'], $w['offering']->id, ['title' => 'Worksheet', 'due_on' => '2026-09-30'], $actor, $audience);
    }

    private function ownership(string $table, array $w, string $id)
    {
        $reader = app(LmsResourceOwnershipReader::class);

        return $table === 'learning_content' ? $reader->forLearningContent($w['school'], $id) : $reader->forAssignment($w['school'], $id);
    }

    private function rowCount(string $table, array $w): int
    {
        return $this->withinSchool($w['school'], fn () => DB::table($table)->where('subject_offering_id', $w['offering']->id)->count());
    }

    private function audit(string $table, array $w): array
    {
        $event = $table === 'learning_content' ? 'lms.learning_content.created' : 'lms.assignment.created';

        return $this->withinSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $event)->latest('id')->firstOrFail()->metadata);
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function administrative_creation_stays_offering_wide(string $table): void
    {
        $w = $this->ownershipWorld();
        $resource = $this->create($table, $w, $this->admin($w));

        $ownership = $this->ownership($table, $w, $resource->id);
        $this->assertTrue($ownership->isOfferingWide());
        $this->assertNull($ownership->ownerEmployeeId);
        $this->assertSame([], $ownership->audienceSectionIds);
        $this->assertArrayNotHasKey('ownerEmployeeId', $this->audit($table, $w));
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function a_section_audience_creates_a_teacher_owned_row_atomically(string $table): void
    {
        $w = $this->ownershipWorld();
        $admin = $this->admin($w);

        $one = $this->create($table, $w, $admin, new SectionAudience($w['employee']->id, [$w['sectionA']->id]));
        $two = $this->create($table, $w, $admin, new SectionAudience($w['employee']->id, [$w['sectionA']->id, $w['sectionB']->id]));
        // The deferred check reads the audience under RLS, so it runs with
        // the School context set -- as every LMS service commits.
        $this->withinSchool($w['school'], fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));

        $this->assertSame([$w['sectionA']->id], $this->ownership($table, $w, $one->id)->audienceSectionIds);
        $both = $this->ownership($table, $w, $two->id);
        $this->assertTrue($both->isEmployeeOwned());
        $this->assertSame($w['employee']->id, $both->ownerEmployeeId);
        $this->assertEqualsCanonicalizing([$w['sectionA']->id, $w['sectionB']->id], $both->audienceSectionIds);

        $metadata = $this->audit($table, $w);
        $this->assertSame($w['employee']->id, $metadata['ownerEmployeeId']);
        $this->assertEqualsCanonicalizing([$w['sectionA']->id, $w['sectionB']->id], $metadata['audienceSectionIds']);
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function a_section_outside_the_offering_context_is_refused_with_nothing_written(string $table): void
    {
        $w = $this->ownershipWorld();
        $other = $this->ownershipWorld();
        $admin = $this->admin($w);

        foreach (['otherGrade' => $w['otherGrade'], 'otherCampus' => $w['otherCampus'], 'otherYear' => $w['otherYear'],
            'inactive' => $w['inactive'], 'other School' => $other['sectionA']] as $label => $section) {
            try {
                $this->create($table, $w, $admin, new SectionAudience($w['employee']->id, [$w['sectionA']->id, $section->id]));
                $this->fail("{$label} must be refused.");
            } catch (LmsAudienceSectionOutsideOfferingException $e) {
                $this->assertSame('LMS_AUDIENCE_SECTION_OUTSIDE_OFFERING', $e->errorCode());
            }
        }

        $this->assertSame(0, $this->rowCount($table, $w), 'No parent row survives a refused audience.');
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function an_owner_from_another_school_is_refused_with_nothing_written(string $table): void
    {
        $w = $this->ownershipWorld();
        $other = $this->ownershipWorld();

        try {
            $this->create($table, $w, $this->admin($w), new SectionAudience($other['employee']->id, [$w['sectionA']->id]));
            $this->fail('A foreign owner must be refused.');
        } catch (LmsOwnerEmployeeInvalidException $e) {
            $this->assertSame('LMS_OWNER_EMPLOYEE_INVALID', $e->errorCode());
        }

        $this->assertSame(0, $this->rowCount($table, $w));
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function the_owner_cannot_be_changed_through_the_model(string $table): void
    {
        $w = $this->ownershipWorld();
        $resource = $this->create($table, $w, $this->admin($w), new SectionAudience($w['employee']->id, [$w['sectionA']->id]));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('owner of an LMS resource is immutable');
        $this->withinSchool($w['school'], fn () => DB::transaction(fn () => tap($resource->fresh(), fn (Model $m) => $m->forceFill(['owner_employee_id' => $w['employee2']->id])->save())));
    }

    #[Test]
    public function a_section_audience_is_one_or_more_distinct_sections(): void
    {
        foreach ([[], ['a', 'a']] as $sections) {
            try {
                new SectionAudience('owner', $sections);
                $this->fail('Refused: '.json_encode($sections));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function an_unknown_or_other_school_resource_has_no_ownership_to_read(string $table): void
    {
        $w = $this->ownershipWorld();
        $other = $this->ownershipWorld();
        $theirs = $this->create($table, $other, $this->admin($other));

        foreach ([$theirs->id, '01a0f3f1-0000-7000-8000-000000000000'] as $id) {
            try {
                $this->ownership($table, $w, $id);
                $this->fail('Not readable: '.$id);
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function the_administrative_api_serializes_no_ownership(string $table): void
    {
        $w = $this->ownershipWorld();
        $admin = $this->admin($w);
        $resource = $this->create($table, $w, $admin, new SectionAudience($w['employee']->id, [$w['sectionA']->id]));
        $path = $table === 'learning_content' ? 'learning-content' : 'assignments';

        $data = $this->actingAs($admin)->withHeader('X-School-Id', $w['school']->id)
            ->getJson("/api/v1/schools/{$w['school']->id}/{$path}/{$resource->id}")->assertOk()->json('data');

        $expected = $table === 'learning_content'
            ? ['id', 'subjectOfferingId', 'title', 'description', 'sequence', 'status']
            : ['id', 'subjectOfferingId', 'title', 'instructions', 'dueOn', 'status'];
        $this->assertEqualsCanonicalizing($expected, array_keys($data));
    }
}
