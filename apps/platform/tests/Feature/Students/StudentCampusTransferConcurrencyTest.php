<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7F-3 (ADR 0076 §47): the Campus transfer serializes with every write
 * that decides it -- proven with two GENUINELY separate OS processes whose
 * transactions overlap (the contender is observed blocked), in both orders,
 * with no deadlock:
 *
 * - inactivation, a year switch, a destination Campus deactivation and a
 *   second transfer: whoever locks first wins, the other sees the result;
 * - a Hostel residency, a Transport rider assignment or an elective on the
 *   source Campus: committed first, it BLOCKS the transfer; arriving later,
 *   it is refused (the Student is no longer on the source Campus / the
 *   anchor placement is no longer current) -- never both;
 * - a name edit, a Library checkout and a Canteen order: committed first,
 *   they do not block; arriving later, the source-Campus actor is refused.
 */
class StudentCampusTransferConcurrencyTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    /** @var list<string> */
    private array $userIds = [];

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $next;

    private Section $sectionB;

    private Student $student;

    private User $admin;

    private User $principalAB;

    private User $principalA;

    private HostelBed $bed;

    private TransportRoute $route;

    private SubjectOffering $elective;

    private LibraryCopy $copy;

    private CanteenOutlet $outlet;

    private CanteenItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->schoolIds[] = $this->school->id;
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'name' => 'Y1', 'status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(30)->toDateString()]);
        $this->next = $this->createAcademicYear($this->school, ['code' => 'Y2', 'name' => 'Y2', 'status' => 'draft', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(300)->toDateString()]);
        $sectionA = $this->createSection($year, $this->a, $grade, ['code' => 'SA', 'status' => 'active']);
        $this->sectionB = $this->createSection($year, $this->b, $grade, ['code' => 'SB', 'status' => 'active']);
        $nextB = $this->createSection($this->next, $this->b, $grade, ['code' => 'NB', 'status' => 'active']);

        $this->student = $this->createStudent($this->school, ['first_name' => 'Original', 'status' => 'active']);
        $this->createStudentEnrollment($this->student, $sectionA, ['status' => 'active', 'starts_on' => now()->subDays(60)->toDateString()]);
        $this->createStudentEnrollment($this->student, $nextB, ['status' => 'active', 'starts_on' => now()->subDay()->toDateString()]);

        $this->admin = $this->user('school_admin', [null]);
        $this->principalAB = $this->user('principal', [$this->a->id, $this->b->id]);
        $this->principalA = $this->user('principal', [$this->a->id]);
        $this->bed = $this->createHostelBed($this->createHostelRoom($this->createHostel($this->school, $this->a, ['status' => 'active']), ['status' => 'active']), ['status' => 'active']);
        $this->route = $this->createTransportRoute($this->school, ['campus_id' => $this->a->id, 'status' => 'active']);
        $this->elective = $this->createSubjectOffering($year, $this->a, $grade, $this->createSubject($this->school), ['is_required' => false, 'status' => 'active']);
        $this->copy = $this->createLibraryCopy($this->createLibraryTitle($this->school), ['campus_id' => $this->a->id]);
        $this->outlet = $this->createCanteenOutlet($this->school, $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'status' => 'active']), ['campus_id' => $this->a->id, 'status' => 'active']);
        $this->item = $this->createCanteenItem($this->school, ['price' => '10.00', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin($this->schoolIds);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    /** @param  list<?string>  $campusIds */
    private function user(string $role, array $campusIds): User
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }

        return $user;
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-eligibility-op.php', $operation, $this->school->id, ...$args];
    }

    private function transfer(): array
    {
        return $this->op('campus-transfer', $this->principalAB->id, $this->student->id, $this->sectionB->id);
    }

    /** @return array<int, string> */
    private function other(string $name): array
    {
        return match ($name) {
            'inactivate' => $this->op('student-inactivate', $this->student->id),
            'year' => $this->op('year-activate', $this->next->id),
            'campus' => $this->op('campus-inactivate', $this->admin->id, $this->b->id),
            'transfer' => $this->transfer(),
            'hostel' => $this->op('hostel-assign', $this->user('hostel_warden', [$this->a->id])->id, $this->student->id, $this->bed->id),
            'transport' => $this->op('transport-assign', $this->user('transport_coordinator', [$this->a->id])->id, $this->student->id, $this->route->id),
            'elective' => $this->op('elective-enroll', $this->student->id, $this->elective->id),
            'rename' => $this->op('student-rename', $this->principalA->id, $this->student->id, 'Renamed'),
            'library' => $this->op('library-checkout', $this->user('librarian', [$this->a->id])->id, $this->student->id, $this->copy->id),
            'canteen' => $this->op('canteen-place', $this->user('canteen_operator', [$this->a->id])->id, $this->student->id, $this->outlet->id, $this->item->id),
        };
    }

    private function currentCampus(): ?string
    {
        return DB::connection('pgsql_admin')->table('student_enrollments as e')->join('academic_years as y', 'y.id', '=', 'e.academic_year_id')
            ->where('e.student_id', $this->student->id)->where('e.status', 'active')->where('y.status', 'active')->value('e.campus_id');
    }

    /** @return array<string, array{0: string, 1: string}> other => [its result when the transfer locked first, the transfer's result when it committed first] */
    public static function matrix(): array
    {
        return [
            'Student inactivation' => ['inactivate', 'rejected:ModelNotFoundException'],
            // The year lock either waits on the old active year (none left: NOT
            // FOUND) or reads the new one (wrong year: the generic refusal).
            'active-year switch' => ['year', 'refused'],
            'destination Campus deactivation' => ['campus', 'rejected:StudentTransferUnavailableException'],
            'second transfer' => ['transfer', 'refused'],
            'Hostel residency' => ['hostel', 'rejected:StudentTransferUnavailableException'],
            'Transport rider assignment' => ['transport', 'rejected:StudentTransferUnavailableException'],
            'elective enrollment' => ['elective', 'rejected:StudentTransferUnavailableException'],
            'name edit' => ['rename', 'ok'],
            'Library checkout' => ['library', 'ok'],
            'Canteen order' => ['canteen', 'ok'],
        ];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function a_transfer_that_locks_first_commits_and_the_other_write_waits(string $other, string $transferWhenSecond): void
    {
        $this->assertNotSame('', $transferWhenSecond);
        [$transfer, $result] = $this->raceWithHeldHolder($this->transfer(), $this->other($other));

        $this->assertSame('ok', $transfer);
        $this->assertSame($this->b->id, $this->currentCampus(), 'the Student is now on Campus B');
        $expected = match ($other) {
            // These do not depend on the Student's Campus: they complete after it.
            'inactivate', 'year', 'campus' => 'ok',
            'transfer' => 'refused',
            'hostel' => 'rejected:HostelResidencyUnavailableException',
            'transport' => 'rejected:TransportAssignmentUnavailableException',
            'elective' => 'rejected:IncompatibleSubjectOfferingException',
            'rename' => 'rejected:ModelNotFoundException',
            'library' => 'rejected:LibraryCheckoutUnavailableException',
            'canteen' => 'rejected:CanteenStudentNotFoundException',
        };
        $this->assertRefusedAs($expected, $result, "{$other} after the transfer", $other === 'transfer');
    }

    #[Test]
    #[DataProvider('matrix')]
    public function the_other_write_committed_first_is_seen_by_the_transfer(string $other, string $transferResult): void
    {
        [$result, $transfer] = $this->raceWithHeldHolder($this->other($other), $this->transfer());

        $this->assertSame('ok', $result, $other);
        $this->assertRefusedAs($transferResult, $transfer, "the transfer after {$other}", $other === 'transfer');
        if ($transferResult === 'ok') {
            $this->assertSame($this->b->id, $this->currentCampus());
        }
    }

    /**
     * Some races refuse with NOT FOUND or the generic refusal depending on
     * which statement the loser reached when it blocked -- both are refusals.
     * For two identical transfers, exactly one transfer happened.
     */
    private function assertRefusedAs(string $expected, string $actual, string $message, bool $secondTransfer): void
    {
        if ($expected !== 'refused') {
            $this->assertSame($expected, $actual, $message);

            return;
        }
        $this->assertContains($actual, ['rejected:ModelNotFoundException', 'rejected:StudentTransferUnavailableException'], $message);
        if (! $secondTransfer) {
            return;
        }
        $rows = DB::connection('pgsql_admin')->table('student_enrollments')->where('student_id', $this->student->id)->where('campus_id', $this->b->id)->where('status', 'active')->count();
        $this->assertSame(2, $rows, 'exactly one transfer: the B placement it created plus the Y2 placement');
    }
}
