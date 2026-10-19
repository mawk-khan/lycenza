<?php

namespace Tests\Feature\Library;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-5 (ADR 0076 §41): Library circulation is Campus-adopted. A
 * Campus-scoped librarian lends only their Campuses' Copies to current
 * operational Students of THAT Campus, returns and sees loans only by the
 * loan's frozen Campus, and never touches a School-shared (NULL-Campus) Copy;
 * the catalogue (titles, Copies, Copy moves) and fines stay whole-School.
 */
class LibraryCampusScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private LibraryTitle $title;

    /** @var array<string, array{copy: LibraryCopy, section: Section}> */
    private array $at = [];

    private LibraryCopy $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $year = $this->createAcademicYear($this->school, ['code' => 'L2627', 'name' => '2026-27', 'status' => 'active']);
        $grade = $this->createGradeLevel($this->school);
        $this->title = $this->createLibraryTitle($this->school, ['title' => 'Atlas']);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $copy = $this->createLibraryCopy($this->title, ['campus_id' => $campus->id, 'code' => 'ATL-'.strtoupper($key)]);
            $section = $this->createSection($year, $campus, $grade, ['code' => 'S'.strtoupper($key), 'status' => 'active']);
            $this->at[$key] = compact('copy', 'section');
        }
        $this->shared = $this->createLibraryCopy($this->title, ['campus_id' => null, 'code' => 'ATL-SHARED']);
    }

    private function pupil(string $name, ?string $campus): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $name, 'last_name' => 'Reader', 'status' => 'active']);
        if ($campus !== null) {
            $this->createStudentEnrollment($student, $this->at[$campus]['section'], ['status' => 'active']);
        }

        return $student;
    }

    /** @param  list<?string>  $campusIds */
    private function librarian(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'librarian', $campusId);
        }

        return $user;
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path): string
    {
        return "/api/v1/schools/{$this->school->id}{$path}";
    }

    private function lend(User $user, string $copyId, string $studentId, string $key): TestResponse
    {
        return $this->api($user)->withHeader('Idempotency-Key', $key)->postJson($this->url('/library-loans'), [
            'library_copy_id' => $copyId, 'student_id' => $studentId, 'due_at' => now()->addDays(7)->toIso8601String(),
        ]);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** The error envelope without its request id. */
    private function error(TestResponse $response): array
    {
        return array_diff_key((array) $response->json('error'), ['requestId' => true]);
    }

    #[Test]
    public function a_whole_school_librarian_is_unchanged(): void
    {
        $user = $this->librarian([null]);
        $this->lend($user, $this->shared->id, $this->pupil('Shared', null)->id, 'lib-whole-shared')->assertCreated();
        $this->lend($user, $this->at['a']['copy']->id, $this->pupil('Cross', 'b')->id, 'lib-whole-cross')->assertCreated();
        $this->assertSame([null, $this->a->id], $this->inSchool(fn () => LibraryLoan::query()->orderBy('created_at')->orderBy('id')->pluck('campus_id')->all()), 'each loan froze its Copy\'s Campus');
        $this->api($user)->getJson($this->url('/library-loans'))->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_campus_a_librarian_lends_only_campus_a_copies_to_campus_a_students(): void
    {
        $user = $this->librarian([$this->a->id]);
        $here = $this->pupil('Here', 'a');
        $there = $this->pupil('There', 'b');

        // Pickers: Campus A Copies and current Campus A Students only, minimal fields.
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->assertSame([['id' => $this->at['a']['copy']->id, 'code' => 'ATL-A', 'title' => 'Atlas']], $this->getJson('/app/library/circulation/search/copies?q=atl')->assertOk()->json('data'));
        $this->assertSame([['id' => $here->id, 'studentNumber' => $here->student_number, 'name' => 'Here Reader']], $this->getJson('/app/library/circulation/search/students?q=reader')->assertOk()->json('data'));
        $this->getJson('/app/library/circulation/search/copies?q=%25')->assertOk()->assertJsonCount(0, 'data');

        // Another Campus's or a School-shared Copy is NOT FOUND; another Campus's Student
        // answers exactly like an unknown one; nothing is lent.
        $this->lend($user, $this->at['b']['copy']->id, $here->id, 'lib-b-copy-1')->assertNotFound();
        $this->lend($user, $this->shared->id, $here->id, 'lib-shared')->assertNotFound();
        $other = $this->lend($user, $this->at['a']['copy']->id, $there->id, 'lib-b-student')->assertStatus(422)->assertJsonPath('error.code', 'LIBRARY_CHECKOUT_UNAVAILABLE');
        $unknown = $this->lend($user, $this->at['a']['copy']->id, (string) Str::uuid(), 'lib-unknown')->assertStatus(422);
        $this->assertSame($this->error($other), $this->error($unknown));
        $this->assertSame(0, $this->inSchool(fn () => LibraryLoan::query()->count()));

        // Allowed, frozen on Campus A; the Copy then on loan is the same generic refusal.
        $loanId = $this->lend($user, $this->at['a']['copy']->id, $here->id, 'lib-allowed')->assertCreated()->json('data.id');
        $this->assertSame($this->a->id, $this->inSchool(fn () => LibraryLoan::query()->findOrFail($loanId)->campus_id));
        $this->assertSame($this->error($other), $this->error($this->lend($user, $this->at['a']['copy']->id, $this->pupil('Next', 'a')->id, 'lib-on-loan')->assertStatus(422)));
        $this->api($user)->postJson($this->url("/library-loans/{$loanId}/check-in"))->assertOk()->assertJsonPath('data.status', 'returned');
    }

    #[Test]
    public function returns_and_history_follow_the_loans_frozen_campus(): void
    {
        $userA = $this->librarian([$this->a->id]);
        $userB = $this->librarian([$this->b->id]);
        $reader = $this->pupil('Mover', 'a');
        $bLoan = $this->createLibraryLoan($this->at['b']['copy'], $this->pupil('Southerner', 'b'));
        $sharedLoan = $this->createLibraryLoan($this->shared, $this->pupil('Anyone', null));
        $aLoan = $this->createLibraryLoan($this->at['a']['copy'], $reader);

        // The Student moves to Campus B; the Campus A loan stays Campus A's.
        $this->inSchool(fn () => StudentEnrollment::query()->where('student_id', $reader->id)->update(['status' => 'transferred', 'ends_on' => now()->toDateString()]));
        $this->createStudentEnrollment($reader, $this->at['b']['section'], ['status' => 'active']);

        $this->assertSame([$aLoan->id], $this->api($userA)->getJson($this->url('/library-loans'))->assertOk()->json('data.*.id'));
        $this->assertSame([$bLoan->id], $this->api($userB)->getJson($this->url('/library-loans'))->assertOk()->json('data.*.id'));
        $this->assertSame([], $this->api($userB)->getJson($this->url('/library-loans?student_id='.$reader->id))->assertOk()->json('data'), 'the filter only narrows');
        foreach ([$bLoan, $sharedLoan] as $loan) {
            $this->api($userA)->getJson($this->url("/library-loans/{$loan->id}"))->assertNotFound();
            $this->api($userA)->postJson($this->url("/library-loans/{$loan->id}/check-in"))->assertNotFound();
        }
        $this->api($userB)->postJson($this->url("/library-loans/{$aLoan->id}/check-in"))->assertNotFound();
        $this->actingAs($userB)->post("/app/schools/{$this->school->id}/activate");
        $this->get("/app/library/circulation/{$aLoan->id}")->assertNotFound();
        $this->assertSame('active', $this->inSchool(fn () => LibraryLoan::query()->findOrFail($aLoan->id)->status));

        // Campus A returns it; a later Copy move never re-attributes the loan.
        $this->api($userA)->postJson($this->url("/library-loans/{$aLoan->id}/check-in"))->assertOk();
        $this->inSchool(fn () => $this->at['a']['copy']->forceFill(['campus_id' => $this->b->id])->save());
        $this->api($userA)->getJson($this->url('/library-loans?status=returned'))->assertOk()->assertJsonPath('data.0.id', $aLoan->id);
        $this->api($userB)->getJson($this->url('/library-loans?status=returned'))->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_catalogue_copy_moves_fines_and_student_profiles_stay_whole_school(): void
    {
        $user = $this->librarian([$this->a->id]);
        $student = $this->pupil('Here', 'a');
        $loan = $this->createLibraryLoan($this->at['a']['copy'], $student);
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/library/titles')->assertForbidden();
        $this->patch("/app/library/copies/{$this->at['a']['copy']->id}", ['campus_id' => null])->assertForbidden();
        $this->get('/app/library/fine-policy')->assertForbidden();
        $this->get("/app/students/{$student->id}")->assertForbidden();
        $this->get("/app/library/circulation/{$loan->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canViewFines', false)->where('fine', null));
        foreach (['fees.charges.view', 'finance.charges.view', 'finance.payments.record', 'students.view', 'library.catalogue.view'] as $capability) {
            $this->assertFalse(Gate::forUser($user)->allows('capability', [$capability, $this->school]), $capability);
        }
        $this->api($user)->getJson($this->url("/library-loans/{$loan->id}/fine"))->assertForbidden();
    }

    #[Test]
    public function whole_school_copy_administration_moves_a_copy_only_off_loan_and_onto_an_active_campus(): void
    {
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $this->school), 'school_admin');
        $copy = $this->at['a']['copy'];
        $this->createLibraryLoan($copy, $this->pupil('Here', 'a'));
        $this->actingAs($admin)->post("/app/schools/{$this->school->id}/activate");

        $this->from('/app/library/titles/'.$this->title->id)->patch("/app/library/copies/{$copy->id}", ['campus_id' => $this->b->id])->assertSessionHasErrors(['campus_id' => 'A Copy on loan cannot change Campus. Check it in first.']);
        $this->assertSame($this->a->id, $this->inSchool(fn () => LibraryCopy::query()->findOrFail($copy->id)->campus_id));

        $free = $this->at['b']['copy'];
        $this->inSchool(fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->from('/app/library/titles/'.$this->title->id)->patch("/app/library/copies/{$free->id}", ['campus_id' => $this->a->id])->assertSessionHasErrors('campus_id');
        $this->patch("/app/library/copies/{$free->id}", ['campus_id' => null])->assertRedirect();
        $this->assertNull($this->inSchool(fn () => LibraryCopy::query()->findOrFail($free->id)->campus_id));

        $this->post("/app/library/titles/{$this->title->id}/copies", ['code' => 'ATL-NEW', 'campus_id' => $this->b->id])->assertRedirect();
        $this->assertSame($this->b->id, $this->inSchool(fn () => LibraryCopy::query()->where('code', 'ATL-NEW')->value('campus_id')));
        $this->get('/app/library/titles/'.$this->title->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('campuses', [['id' => $this->b->id, 'name' => 'South']]));
    }

    #[Test]
    public function module_and_campus_state_gate_campus_authority(): void
    {
        $user = $this->librarian([$this->a->id]);
        SchoolFactory::setOptionalModules($this->school, false, ['library']);
        $this->api($user)->getJson($this->url('/library-loans'))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['library']);
        $this->api($user)->getJson($this->url('/library-loans'))->assertOk();

        $this->inSchool(fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url('/library-loans'))->assertForbidden();
    }
}
