<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Application\Exceptions\CopyNotAvailableException;
use App\Domain\Library\Application\Exceptions\LoanAlreadyReturnedException;
use App\Domain\Library\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Library\Application\LibraryLoanService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10A -- circulation lifecycle. Every test exercises the real
 * App\Domain\Library\Application\LibraryLoanService, never a raw
 * model write, mirroring AcademicYearActivationTest's convention.
 */
class LibraryLoanServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function checkout_creates_an_active_loan_and_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $dueAt = Carbon::now()->addDays(14);

        $loan = app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $student, $dueAt, $admin));

        $this->assertSame('active', $loan->status);
        $this->assertSame($copy->id, $loan->library_copy_id);
        $this->assertSame($student->id, $loan->student_id);
        $this->assertSame($dueAt->timestamp, $loan->due_at->timestamp);
        $this->assertNull($loan->checked_in_at);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('school_id', $school->id)
            ->where('event_type', 'library.loan.checked_out')
            ->where('subject_id', $loan->id)
            ->first());
        $this->assertNotNull($event, 'A library.loan.checked_out audit event must be recorded.');
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    #[Test]
    public function checkout_refuses_a_copy_that_already_has_an_active_loan(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $studentOne = $this->createStudent($school, ['status' => 'active']);
        $studentTwo = $this->createStudent($school, ['status' => 'active']);

        app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $studentOne, Carbon::now()->addDays(14), $admin));

        $this->expectException(CopyNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $studentTwo, Carbon::now()->addDays(14), $admin));
    }

    #[Test]
    public function checkout_refuses_an_inactive_copy(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title, ['status' => 'inactive']);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->expectException(CopyNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $student, Carbon::now()->addDays(14), $admin));
    }

    #[Test]
    public function checkout_refuses_an_inactive_student(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'inactive']);

        $this->expectException(StudentNotEligibleException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $student, Carbon::now()->addDays(14), $admin));
    }

    #[Test]
    public function check_in_marks_the_loan_returned_and_records_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $loan = app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $student, Carbon::now()->addDays(14), $admin));

        $returned = app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkIn($loan, $admin));

        $this->assertSame('returned', $returned->status);
        $this->assertNotNull($returned->checked_in_at);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'library.loan.checked_in')
            ->where('subject_id', $loan->id)
            ->first());
        $this->assertNotNull($event);
    }

    #[Test]
    public function check_in_cannot_be_performed_twice(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $loan = app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $student, Carbon::now()->addDays(14), $admin));

        app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkIn($loan, $admin));

        $this->expectException(LoanAlreadyReturnedException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkIn($loan, $admin));
    }

    #[Test]
    public function after_check_in_the_copy_becomes_available_for_a_new_checkout(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $studentOne = $this->createStudent($school, ['status' => 'active']);
        $studentTwo = $this->createStudent($school, ['status' => 'active']);
        $service = app(LibraryLoanService::class);

        $firstLoan = app(TenantContext::class)->withSchool($school, fn () => $service->checkout($copy, $studentOne, Carbon::now()->addDays(14), $admin));
        app(TenantContext::class)->withSchool($school, fn () => $service->checkIn($firstLoan, $admin));

        $secondLoan = app(TenantContext::class)->withSchool($school, fn () => $service->checkout($copy, $studentTwo, Carbon::now()->addDays(14), $admin));

        $this->assertSame('active', $secondLoan->status);
        $this->assertNotSame($firstLoan->id, $secondLoan->id, 'A new checkout must create a fresh Loan row, never reuse/mutate the returned one.');
    }

    #[Test]
    public function historical_loans_remain_valid_after_the_copy_and_title_are_deactivated(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $loan = app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkout($copy, $student, Carbon::now()->addDays(14), $admin));
        app(TenantContext::class)->withSchool($school, fn () => app(LibraryLoanService::class)->checkIn($loan, $admin));

        $copy->update(['status' => 'inactive']);
        $title->update(['status' => 'inactive']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $loan->fresh());
        $this->assertSame('returned', $fresh->status);
        $this->assertSame($copy->id, $fresh->library_copy_id);
    }
}
