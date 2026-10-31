<?php

namespace Tests\Feature\Students\Houses;

use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.7: the database is the backstop for every House invariant, proven
 * with raw SQL on the runtime role (`school_os_app`, RLS on) -- never through
 * the service. Closed type, one current membership, no overlap, immutable
 * identity, final history, same-School keys, responsibilities only on a
 * current membership, unique Captain, no runtime DELETE, tenant isolation.
 */
class StudentGroupDatabaseTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private string $studentId;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->studentId = $this->createStudent($this->school)->id;
    }

    private function sql(callable $fn, ?School $school = null): mixed
    {
        return app(TenantContext::class)->withSchool($school ?? $this->school, fn () => DB::connection('pgsql')->transaction(fn () => $fn(DB::connection('pgsql'))));
    }

    private function refused(callable $statement, string $rule): void
    {
        try {
            $statement();
            $this->fail("expected {$rule}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($rule, $e->getMessage());
        }
    }

    private function group(string $code = 'RED', string $type = 'house', ?School $school = null): string
    {
        $id = (string) Str::uuid7();
        $this->sql(fn ($db) => $db->table('student_groups')->insert([
            'id' => $id, 'school_id' => ($school ?? $this->school)->id, 'group_type' => $type, 'name' => "House {$code}", 'code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]), $school);

        return $id;
    }

    /** @param  array<string, mixed>  $row */
    private function membership(string $groupId, array $row = []): string
    {
        $id = (string) Str::uuid7();
        $this->sql(fn ($db) => $db->table('student_group_memberships')->insert([
            'id' => $id, 'school_id' => $this->school->id, 'student_group_id' => $groupId, 'group_type' => 'house',
            'student_id' => $this->studentId, 'starts_on' => '2026-06-01', 'start_reason' => 'assignment',
            'started_by_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(), ...$row,
        ]));

        return $id;
    }

    private function responsibility(string $groupId, string $membershipId, string $kind, ?string $studentId = null): string
    {
        $id = (string) Str::uuid7();
        $this->sql(fn ($db) => $db->table('student_group_responsibilities')->insert([
            'id' => $id, 'school_id' => $this->school->id, 'student_group_id' => $groupId, 'student_group_membership_id' => $membershipId,
            'student_id' => $studentId ?? $this->studentId, 'responsibility' => $kind, 'starts_on' => '2026-06-01',
            'assigned_by_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    #[Test]
    public function the_group_type_is_closed_and_houses_keep_their_identity(): void
    {
        $this->refused(fn () => $this->group('CLUB', 'club'), 'student_groups_type_check');
        $this->refused(fn () => $this->group('red'), 'student_groups_code_check');
        $red = $this->group();
        $this->refused(fn () => $this->group(), 'student_groups_school_id_group_type_code_unique');
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_groups')->where('id', $red)->update(['status' => 'archived', 'version' => 2])), 'student_groups_status_check');
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_groups')->where('id', $red)->update(['name' => 'Ruby'])), 'advances its version');
        $this->sql(fn ($db) => $db->table('student_groups')->where('id', $red)->update(['name' => 'Ruby', 'version' => 2]));
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_groups')->where('id', $red)->delete()), 'permission denied');
    }

    #[Test]
    public function a_student_has_at_most_one_current_and_never_two_overlapping_memberships(): void
    {
        $red = $this->group('RED');
        $blue = $this->group('BLUE');
        $this->membership($red, ['starts_on' => '2026-06-01', 'ends_on' => '2026-06-30', 'end_reason' => 'reassignment', 'ended_by_user_id' => $this->admin->id]);
        $this->membership($blue, ['starts_on' => '2026-07-01']);

        // A second open membership: the overlap trigger refuses it first (the
        // partial unique index `student_group_memberships_one_current` is the
        // second backstop, pinned below).
        $this->refused(fn () => $this->membership($red, ['starts_on' => '2026-08-01']), 'at most one membership');
        $this->refused(fn () => $this->membership($red, ['starts_on' => '2026-06-15', 'ends_on' => '2026-06-20', 'end_reason' => 'correction', 'ended_by_user_id' => $this->admin->id]), 'at most one membership');
        // A same-day replacement's empty interval never overlaps anything.
        $this->membership($red, ['starts_on' => '2026-06-15', 'ends_on' => '2026-06-14', 'end_reason' => 'correction', 'ended_by_user_id' => $this->admin->id]);
        $this->refused(fn () => $this->membership($red, ['starts_on' => '2026-06-15', 'ends_on' => '2026-06-13', 'end_reason' => 'correction', 'ended_by_user_id' => $this->admin->id]), 'student_group_memberships_interval_check');
        $this->refused(fn () => $this->membership($red, ['starts_on' => '2026-05-01', 'ends_on' => '2026-05-10']), 'student_group_memberships_end_shape_check');
        $this->refused(fn () => $this->membership($red, ['starts_on' => '2026-05-01', 'ends_on' => '2026-05-10', 'end_reason' => 'correction', 'ended_by_user_id' => $this->admin->id, 'start_reason' => 'auto_allocated']), 'student_group_memberships_reason_check');
        $this->assertSame(3, $this->sql(fn ($db) => $db->table('student_group_memberships')->count()));
        $index = DB::selectOne("SELECT pg_get_indexdef('student_group_memberships_one_current'::regclass) AS def")->def;
        $this->assertStringContainsString('UNIQUE INDEX', $index);
        $this->assertStringContainsString('(school_id, student_id, group_type) WHERE (ends_on IS NULL)', $index);
    }

    #[Test]
    public function membership_identity_is_immutable_and_an_ended_row_is_final(): void
    {
        $red = $this->group('RED');
        $blue = $this->group('BLUE');
        $id = $this->membership($red);
        $end = ['ends_on' => '2026-06-30', 'end_reason' => 'school_decision', 'ended_by_user_id' => $this->admin->id];

        foreach ([['student_group_id' => $blue], ['starts_on' => '2026-05-01'], ['start_reason' => 'correction'], ['student_id' => $this->createStudent($this->school)->id]] as $change) {
            $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_group_memberships')->where('id', $id)->update([...$change, 'version' => 2])), 'immutable');
        }
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_group_memberships')->where('id', $id)->update($end)), 'advances its version');
        $this->sql(fn ($db) => $db->table('student_group_memberships')->where('id', $id)->update([...$end, 'version' => 2]));
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_group_memberships')->where('id', $id)->update(['ends_on' => '2026-07-31', 'version' => 3])), 'final history');
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_group_memberships')->where('id', $id)->delete()), 'permission denied');
    }

    #[Test]
    public function responsibilities_need_a_current_membership_of_the_same_student_and_captain_is_unique(): void
    {
        $red = $this->group('RED');
        $blue = $this->group('BLUE');
        $mine = $this->membership($red);
        $other = $this->createStudent($this->school)->id;
        $theirs = $this->membership($red, ['student_id' => $other]);

        $this->responsibility($red, $mine, 'house_captain');
        $this->refused(fn () => $this->responsibility($red, $theirs, 'house_captain', $other), 'student_group_responsibilities_unique_holder');
        $this->responsibility($red, $mine, 'prefect');
        $this->responsibility($red, $theirs, 'prefect', $other);
        $this->refused(fn () => $this->responsibility($red, $mine, 'prefect'), 'student_group_responsibilities_one_per_member');
        $this->refused(fn () => $this->responsibility($red, $mine, 'head_of_school'), 'student_group_responsibilities_kind_check');
        // Bound to the membership's own Student and House.
        $this->refused(fn () => $this->responsibility($red, $mine, 'vice_captain', $other), 'student_group_responsibilities_membership_fk');
        $this->refused(fn () => $this->responsibility($blue, $mine, 'vice_captain'), 'student_group_responsibilities_membership_fk');

        // A membership cannot end while a responsibility is current, and none starts on an ended one.
        $end = ['ends_on' => '2026-06-30', 'end_reason' => 'school_decision', 'ended_by_user_id' => $this->admin->id, 'version' => 2];
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_group_memberships')->where('id', $mine)->update($end)), 'current responsibility');
        $this->sql(fn ($db) => $db->table('student_group_responsibilities')->where('student_group_membership_id', $mine)->update(['ends_on' => '2026-06-30', 'ended_by_user_id' => $this->admin->id, 'version' => 2]));
        $this->sql(fn ($db) => $db->table('student_group_memberships')->where('id', $mine)->update($end));
        $this->refused(fn () => $this->responsibility($red, $mine, 'vice_captain'), 'needs a current membership');
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_group_responsibilities')->where('student_group_membership_id', $mine)->delete()), 'permission denied');
    }

    #[Test]
    public function keys_stay_inside_one_school_and_rls_hides_every_other_school(): void
    {
        [, $other] = $this->createSchoolAdmin();
        $foreignHouse = $this->group('RED', 'house', $other);
        $red = $this->group('RED');
        $this->membership($red);

        // A School-A membership naming School B's House fails the same-School key.
        $this->refused(fn () => $this->membership($foreignHouse, ['student_id' => $this->createStudent($this->school)->id]), 'foreign key');

        foreach (['student_groups', 'student_group_memberships', 'student_group_responsibilities'] as $table) {
            $this->assertSame(0, $this->sql(fn ($db) => $db->table($table)->where('school_id', $this->school->id)->count(), $other), "{$table} is invisible from another School");
        }
        $this->assertSame(1, $this->sql(fn ($db) => $db->table('student_groups')->count(), $other));
        $this->assertSame(0, DB::connection('pgsql')->table('student_groups')->count(), 'no tenant context sees nothing');
    }
}
