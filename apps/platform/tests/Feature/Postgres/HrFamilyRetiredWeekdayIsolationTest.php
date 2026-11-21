<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsHrFamilyIsolation;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-26 (R1-06, C38-N1): the 57th HR-family table, `staff_working_weekdays`. It is retired since HR.R1-C22
 * (WO-5): the runtime role holds SELECT only and no application code writes it (`HrWriterBoundaryTest` lists no
 * writer), so its rows can only come from the administrative connection. That connection cannot see a School the
 * test transaction has not committed, so this test commits (like `UserReferenceProbeTest`) and removes its Schools
 * and User through `purgeSchoolsAsAdmin()` in tearDown.
 *
 * The admin seeds; every probe still runs as the runtime role. Its write legs are privilege refusals ("permission
 * denied"), recorded as such, never mistaken for RLS; its read legs are RLS's.
 */
class HrFamilyRetiredWeekdayIsolationTest extends TestCase
{
    use AssertsHrFamilyIsolation, CreatesTenancyFixtures;

    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    private ?string $userId = null;

    protected function tearDown(): void
    {
        DB::connection('pgsql')->statement('RESET app.current_school_id');
        $this->purgeSchoolsAsAdmin(array_map(fn (School $s) => $s->id, $this->schools));
        if ($this->userId !== null) {
            DB::connection('pgsql_admin')->table('users')->where('id', $this->userId)->delete();
        }
        parent::tearDown();
    }

    #[Test]
    public function the_retired_weekday_table_is_read_only_and_isolated_for_the_runtime_role(): void
    {
        $this->schools = [$this->createSchool(), $this->createSchool()];
        $user = $this->createUser();
        $this->userId = $user->id;
        foreach ($this->schools as $school) {
            DB::connection('pgsql_admin')->table('staff_working_weekdays')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'iso_weekday' => 1, 'portion' => 'full',
                'updated_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        [$a, $b] = $this->schools;

        $this->assertFalse((bool) DB::connection('pgsql')->selectOne("select has_table_privilege(current_user, 'public.staff_working_weekdays', 'INSERT') as p")->p);
        $this->assertFalse((bool) DB::connection('pgsql')->selectOne("select has_table_privilege(current_user, 'public.staff_working_weekdays', 'UPDATE') as p")->p);
        $violations = [...$this->roleViolations(), ...$this->policyViolations('staff_working_weekdays'),
            ...$this->isolationViolations('staff_working_weekdays', $a->id, $b->id), ...$this->isolationViolations('staff_working_weekdays', $b->id, $a->id)];

        $this->assertSame([], $violations, implode("\n", $violations));
        $this->assertStringStartsWith('no INSERT privilege', $this->isolationEvidence['staff_working_weekdays insert']);
        $this->assertStringStartsWith('no UPDATE privilege', $this->isolationEvidence['staff_working_weekdays move']);
    }
}
