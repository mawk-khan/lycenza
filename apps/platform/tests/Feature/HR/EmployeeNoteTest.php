<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\EmployeeNote;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A closure correction (item 5) -- proves the EmployeeNote
 * entity, finally attaching the `hr.employees.notes.view`/`.manage`
 * capability pair (pre-registered at 8A.10, unused until this
 * correction) to a real feature. Same ownership-verification shape as
 * EmployeeAddressTest -- see that class's docblock.
 */
class EmployeeNoteTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function note_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $note = $this->createEmployeeNote($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($note->id));
    }

    #[Test]
    public function an_employee_may_have_multiple_notes(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeNote($employee);
        $this->createEmployeeNote($employee);
        $this->createEmployeeNote($employee);

        app(TenantContext::class)->set($school);

        $this->assertCount(3, $employee->notes()->get());
    }

    #[Test]
    public function a_note_defaults_to_sensitive_classification(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $note = $this->createEmployeeNote($employee);

        $this->assertSame('sensitive', $note->classification_tier);
    }

    #[Test]
    public function the_confidential_factory_state_produces_a_confidential_note(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $note = $this->createEmployeeNote($employee, ['classification_tier' => 'confidential']);

        $this->assertSame('confidential', $note->classification_tier);
    }

    #[Test]
    public function ownership_is_preserved_when_fetched_through_the_employee(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $note = $this->createEmployeeNote($employee);

        app(TenantContext::class)->set($school);

        $this->assertSame($employee->id, $note->fresh()->employee_id);
        $this->assertTrue($employee->notes()->get()->contains('id', $note->id));
    }

    #[Test]
    public function a_school_a_employee_id_combined_with_school_b_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $author = $this->createUser();

        app(TenantContext::class)->set($schoolB);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employeeA, $schoolB, $author): void {
            EmployeeNote::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'author_user_id' => $author->id,
                'body' => 'Rogue note',
            ]);
        });
    }

    #[Test]
    public function service_add_sets_author_to_the_acting_actor(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $note = app(EmployeeNoteService::class)->add($employee, ['body' => 'Attendance discussion.'], $actor);

        $this->assertSame($actor->id, $note->author_user_id);
    }

    #[Test]
    public function service_add_ignores_caller_supplied_school_employee_and_author_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $otherEmployee = $this->createEmployee($school);
        $otherUser = $this->createUser();
        $actor = $this->fullHrActor($school);

        $note = app(EmployeeNoteService::class)->add($employee, [
            'body' => 'Genuine note.',
            'employee_id' => $otherEmployee->id,
            'author_user_id' => $otherUser->id,
        ], $actor);

        $this->assertSame($employee->id, $note->employee_id);
        $this->assertSame($actor->id, $note->author_user_id);
    }

    #[Test]
    public function updating_a_note_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $noteB = $this->createEmployeeNote($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeNoteService::class)->update($employeeA, $noteB, ['body' => 'Hacked body'], $this->fullHrActor($school));
    }

    #[Test]
    public function removing_a_note_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $noteB = $this->createEmployeeNote($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeNoteService::class)->remove($employeeA, $noteB, $this->fullHrActor($school));
    }

    #[Test]
    public function update_cannot_change_the_original_author(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $originalAuthor = $this->createUser();
        $note = $this->createEmployeeNote($employee, ['author_user_id' => $originalAuthor->id]);
        $differentActor = $this->fullHrActor($school);

        $updated = app(EmployeeNoteService::class)->update($employee, $note, [
            'body' => 'Edited body',
            'author_user_id' => $differentActor->id,
        ], $differentActor);

        $this->assertSame($originalAuthor->id, $updated->author_user_id);
    }

    #[Test]
    public function remove_permanently_deletes_the_row(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $note = $this->createEmployeeNote($employee);

        app(EmployeeNoteService::class)->remove($employee, $note, $this->fullHrActor($school));

        app(TenantContext::class)->set($school);
        $this->assertSame(0, EmployeeNote::query()->where('id', $note->id)->count());
    }

    #[Test]
    public function school_a_cannot_see_school_bs_note(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $this->createEmployeeNote($employeeB);

        app(TenantContext::class)->set($schoolA);

        $this->assertSame(0, EmployeeNote::query()->count());
    }
}
