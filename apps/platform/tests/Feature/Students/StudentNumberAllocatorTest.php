<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\StudentNumberAllocator;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-2 (ADR 0076 §46, owner decisions F2-D5 to F2-D7): the School's
 * server-side Student-number sequence -- School-wide, plain decimal, never
 * Campus- or year-coded, never reused, transactional.
 */
class StudentNumberAllocatorTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function allocate(School $school): string
    {
        return DB::transaction(fn () => app(StudentNumberAllocator::class)->allocate($school));
    }

    private function sequence(School $school): ?object
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('student_number_sequences')->where('school_id', $school->id)->first());
    }

    #[Test]
    public function a_school_with_no_numeric_numbers_starts_at_one_and_counts_up(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => 'LDS-0001']);

        $this->assertSame(['1', '2', '3'], [$this->allocate($school), $this->allocate($school), $this->allocate($school)]);
        $this->assertSame(4, (int) $this->sequence($school)->next_value);
        $this->assertSame(3, (int) $this->sequence($school)->last_allocated_value);
    }

    #[Test]
    public function the_next_number_is_above_every_numeric_number_and_nothing_is_rewritten(): void
    {
        $school = $this->createSchool();
        foreach (['0007', 'A-15', '20', '0009'] as $number) {
            $this->createStudent($school, ['student_number' => $number]);
        }

        $this->assertSame('21', $this->allocate($school), '0007 counts as 7, A-15 never counts, the greatest numeric is 20');
        $this->assertSame(['0007', '0009', '20', 'A-15'], app(TenantContext::class)->withSchool($school, fn () => DB::table('students')->where('school_id', $school->id)->orderBy('student_number')->pluck('student_number')->all()));
    }

    #[Test]
    public function a_manually_entered_number_moves_the_sequence_past_it_so_it_is_never_issued(): void
    {
        $school = $this->createSchool();
        $this->assertSame('1', $this->allocate($school));
        $this->createStudent($school, ['student_number' => '0050']);

        // The counter moved past 50 when it was written, so 50 is consumed
        // for good -- even if that Student were later purged.
        $this->assertSame('51', $this->allocate($school));
        $this->assertSame(51, (int) $this->sequence($school)->last_allocated_value);
    }

    #[Test]
    public function an_overlong_digit_string_never_breaks_the_sequence(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['student_number' => str_repeat('9', 30)]);

        $this->assertSame('1', $this->allocate($school), 'digit strings beyond the counter range are ignored by it');
    }

    #[Test]
    public function a_rolled_back_allocation_was_never_held_and_numbers_stay_unique(): void
    {
        $school = $this->createSchool();
        try {
            DB::transaction(function () use ($school): void {
                app(StudentNumberAllocator::class)->allocate($school);

                throw new RuntimeException('onboarding failed after allocation');
            });
        } catch (RuntimeException) {
        }

        $first = $this->allocate($school);
        $this->assertSame('1', $first, 'the rolled-back number belonged to no Student and is issued once, to the next onboarding');
        $this->assertSame('2', $this->allocate($school));
    }

    #[Test]
    public function schools_count_independently(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $this->createStudent($b, ['student_number' => '700']);

        $this->assertSame('1', $this->allocate($a));
        $this->assertSame('701', $this->allocate($b));
        $this->assertSame('2', $this->allocate($a));
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_counter_and_rls_isolates_schools(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $this->allocate($a);
        $this->allocate($b);

        $visible = app(TenantContext::class)->withSchool($a, fn () => DB::table('student_number_sequences')->pluck('school_id')->all());
        $this->assertSame([$a->id], $visible, 'School A sees only its own counter');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied/');
        app(TenantContext::class)->withSchool($a, fn () => DB::table('student_number_sequences')->where('school_id', $a->id)->delete());
    }

    #[Test]
    public function the_counter_never_moves_back_whoever_writes(): void
    {
        $school = $this->createSchool();
        $this->allocate($school);
        $this->allocate($school);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/only moves forward/');
        app(TenantContext::class)->withSchool($school, fn () => DB::table('student_number_sequences')->where('school_id', $school->id)->update(['next_value' => 1, 'last_allocated_value' => null]));
    }
}
