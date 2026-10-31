<?php

namespace Tests\Feature\Students\Communities;

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
 * SIS 11.8: the database backstops for Clubs and Activities, with raw SQL on
 * the runtime role (RLS on), never through the services. Per-group
 * uniqueness (several Clubs at once, never one twice), the House rule left
 * intact, per-type reasons and responsibilities, the Activity-only category,
 * and RLS isolation.
 */
class StudentCommunityDatabaseTest extends TestCase
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

    /** @param  array<string, mixed>  $row */
    private function group(string $type, string $code, array $row = [], ?School $school = null): string
    {
        $id = (string) Str::uuid7();
        $this->sql(fn ($db) => $db->table('student_groups')->insert([
            'id' => $id, 'school_id' => ($school ?? $this->school)->id, 'group_type' => $type, 'name' => "Group {$code}", 'code' => $code,
            'created_at' => now(), 'updated_at' => now(), ...$row,
        ]), $school);

        return $id;
    }

    /** @param  array<string, mixed>  $row */
    private function membership(string $groupId, string $type, array $row = []): string
    {
        $id = (string) Str::uuid7();
        $this->sql(fn ($db) => $db->table('student_group_memberships')->insert([
            'id' => $id, 'school_id' => $this->school->id, 'student_group_id' => $groupId, 'group_type' => $type,
            'student_id' => $this->studentId, 'starts_on' => '2026-06-01', 'start_reason' => $type === 'house' ? 'assignment' : 'joined',
            'started_by_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(), ...$row,
        ]));

        return $id;
    }

    private function responsibility(string $groupId, string $membershipId, string $kind): void
    {
        $this->sql(fn ($db) => $db->table('student_group_responsibilities')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'student_group_id' => $groupId, 'student_group_membership_id' => $membershipId,
            'student_id' => $this->studentId, 'responsibility' => $kind, 'starts_on' => '2026-06-01',
            'assigned_by_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    #[Test]
    public function exactly_three_types_exist_and_only_an_activity_has_a_category(): void
    {
        foreach (['project', 'scholarship', 'tag', 'segment', 'custom'] as $i => $forbidden) {
            $this->refused(fn () => $this->group($forbidden, "F{$i}"), 'student_groups_type_check');
        }
        $this->group('activity', 'FOOT', ['activity_category' => 'sports']);
        $this->group('activity', 'MISC');
        $this->refused(fn () => $this->group('activity', 'BAD', ['activity_category' => 'esports_elite']), 'student_groups_activity_category_check');
        $this->refused(fn () => $this->group('club', 'CHESS', ['activity_category' => 'sports']), 'student_groups_activity_category_check');
        $this->refused(fn () => $this->group('club', 'chess'), 'student_groups_code_check');
        $club = $this->group('club', 'CHESS');
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_groups')->where('id', $club)->update(['group_type' => 'activity', 'version' => 2])), 'immutable');
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_groups')->where('id', $club)->delete()), 'permission denied');
    }

    #[Test]
    public function a_student_is_in_several_clubs_at_once_but_never_one_twice(): void
    {
        $chess = $this->group('club', 'CHESS');
        $debate = $this->group('club', 'DEBATE');
        $foot = $this->group('activity', 'FOOT');
        $music = $this->group('activity', 'MUSIC');
        foreach ([[$chess, 'club'], [$debate, 'club'], [$foot, 'activity'], [$music, 'activity']] as [$g, $t]) {
            $this->membership($g, $t);
        }
        $this->assertSame(4, $this->sql(fn ($db) => $db->table('student_group_memberships')->whereNull('ends_on')->count()));

        // The same group twice: the overlap trigger refuses first; the
        // per-group partial unique index is the second backstop.
        $this->refused(fn () => $this->membership($chess, 'club', ['starts_on' => '2026-07-01']), 'at most one membership');
        $this->refused(fn () => $this->membership($foot, 'activity', ['starts_on' => '2026-07-01']), 'at most one membership');

        // History: a closed earlier period of the same Club is fine; an overlapping one is not.
        $this->membership($chess, 'club', ['starts_on' => '2026-04-01', 'ends_on' => '2026-04-30', 'end_reason' => 'withdrew', 'ended_by_user_id' => $this->admin->id]);
        $this->refused(fn () => $this->membership($chess, 'club', ['starts_on' => '2026-05-15', 'ends_on' => '2026-06-15', 'end_reason' => 'withdrew', 'ended_by_user_id' => $this->admin->id]), 'at most one membership');

        // The House rule is untouched: one current House, whatever else is current.
        $red = $this->group('house', 'RED');
        $blue = $this->group('house', 'BLUE');
        $this->membership($red, 'house');
        $this->refused(fn () => $this->membership($blue, 'house', ['starts_on' => '2026-08-01']), 'at most one membership');
    }

    #[Test]
    public function reasons_are_per_type(): void
    {
        $chess = $this->group('club', 'CHESS');
        $red = $this->group('house', 'RED');
        $foot = $this->group('activity', 'FOOT');
        $closed = ['ends_on' => '2026-04-30', 'ended_by_user_id' => $this->admin->id, 'starts_on' => '2026-04-01'];

        $this->refused(fn () => $this->membership($chess, 'club', ['start_reason' => 'assignment']), 'student_group_memberships_reason_check');
        $this->refused(fn () => $this->membership($red, 'house', ['start_reason' => 'joined']), 'student_group_memberships_reason_check');
        $this->refused(fn () => $this->membership($red, 'house', [...$closed, 'end_reason' => 'withdrew']), 'student_group_memberships_reason_check');
        $this->refused(fn () => $this->membership($chess, 'club', [...$closed, 'end_reason' => 'reassignment']), 'student_group_memberships_reason_check');
        $this->membership($foot, 'activity', [...$closed, 'end_reason' => 'completed']);
        $this->membership($chess, 'club', [...$closed, 'end_reason' => 'left_school']);
    }

    #[Test]
    public function responsibilities_belong_to_their_type_and_unique_club_roles_hold(): void
    {
        $chess = $this->group('club', 'CHESS');
        $foot = $this->group('activity', 'FOOT');
        $red = $this->group('house', 'RED');
        $inChess = $this->membership($chess, 'club');
        $inFoot = $this->membership($foot, 'activity');
        $inRed = $this->membership($red, 'house');

        $this->responsibility($chess, $inChess, 'club_president');
        $this->responsibility($chess, $inChess, 'member_representative');
        $this->responsibility($red, $inRed, 'house_captain');
        $this->refused(fn () => $this->responsibility($chess, $inChess, 'house_captain'), 'does not exist for the group');
        $this->refused(fn () => $this->responsibility($red, $inRed, 'club_president'), 'does not exist for the group');
        $this->refused(fn () => $this->responsibility($foot, $inFoot, 'club_president'), 'does not exist for the group');

        // A second current President of the same Club (another Student).
        $otherStudent = $this->createStudent($this->school)->id;
        $other = (string) Str::uuid7();
        $this->sql(fn ($db) => $db->table('student_group_memberships')->insert([
            'id' => $other, 'school_id' => $this->school->id, 'student_group_id' => $chess, 'group_type' => 'club', 'student_id' => $otherStudent,
            'starts_on' => '2026-06-01', 'start_reason' => 'joined', 'started_by_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->refused(fn () => $this->sql(fn ($db) => $db->table('student_group_responsibilities')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'student_group_id' => $chess, 'student_group_membership_id' => $other,
            'student_id' => $otherStudent, 'responsibility' => 'club_president', 'starts_on' => '2026-06-01',
            'assigned_by_user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ])), 'student_group_responsibilities_unique_holder');
    }

    #[Test]
    public function rls_hides_every_other_schools_communities(): void
    {
        [, $other] = $this->createSchoolAdmin();
        $this->group('club', 'CHESS', [], $other);
        $this->membership($this->group('club', 'CHESS'), 'club');

        $this->assertSame(1, $this->sql(fn ($db) => $db->table('student_groups')->count(), $other));
        foreach (['student_groups', 'student_group_memberships'] as $table) {
            $this->assertSame(0, $this->sql(fn ($db) => $db->table($table)->where('school_id', $this->school->id)->count(), $other), "{$table} is invisible from another School");
        }
    }
}
