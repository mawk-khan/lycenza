<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.1A (ADR 0076 §24, V-5): the five composite same-School keys declared
 * `ON DELETE SET NULL` now null ONLY their nullable relationship column
 * (`SET NULL (column)`), never the NOT NULL tenant key `school_id`.
 * Deleting the optional parent demotes the child, which survives with its
 * School unchanged; a cross-School reference is still refused.
 */
class TenantCompositeSetNullTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** constraint => the only column its ON DELETE SET NULL may clear */
    private const KEYS = [
        'hr_departments_campus_id_school_id_foreign' => 'campus_id',
        'hr_departments_parent_department_id_school_id_foreign' => 'parent_department_id',
        'employee_assignments_manager_assignment_id_school_id_foreign' => 'manager_assignment_id',
        'communication_messages_reply_to_message_id_school_id_foreign' => 'reply_to_message_id',
        'comm_attachments_message_school_foreign' => 'communication_message_id',
    ];

    private function department(School $school, array $attributes = []): string
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('hr_departments')->insert([
            'id' => $id, 'school_id' => $school->id, 'name' => 'Dept '.$id, 'code' => 'D'.Str::upper(Str::random(6)),
            'created_at' => now(), 'updated_at' => now(), ...$attributes,
        ]));

        return $id;
    }

    /** @return object{campus_id: ?string, parent_department_id: ?string, school_id: string}|null */
    private function departmentRow(School $school, string $id): ?object
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('hr_departments')->where('id', $id)->first());
    }

    private function inRolledBackAdminTransaction(callable $scenario): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->beginTransaction();
        try {
            $scenario($admin);
        } finally {
            $admin->rollBack();
        }
    }

    /** @return array{0: string, 1: string} school id, user id */
    private function adminSchoolAndUser(Connection $admin): array
    {
        $school = (string) Str::uuid7();
        $admin->table('schools')->insert(['id' => $school, 'name' => 'Set Null School', 'slug' => 'set-null-'.Str::lower(Str::random(10)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = (string) Str::uuid7();
        $admin->table('users')->insert(['id' => $user, 'name' => 'Set Null Person', 'email' => 'set.null.'.Str::lower(Str::random(10)).'@example.test', 'password' => bcrypt('irrelevant-password'), 'created_at' => now(), 'updated_at' => now()]);

        return [$school, $user];
    }

    #[Test]
    public function each_key_nulls_only_its_relationship_column(): void
    {
        foreach (self::KEYS as $constraint => $column) {
            $def = DB::connection('pgsql_admin')->scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);
            $this->assertStringEndsWith("ON DELETE SET NULL ({$column})", (string) $def, $constraint);
            $this->assertStringContainsString("FOREIGN KEY ({$column}, school_id)", (string) $def, $constraint);
        }
    }

    #[Test]
    public function deleting_a_campus_or_parent_department_demotes_the_department_and_keeps_its_school(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $parent = $this->department($school);
        $child = $this->department($school, ['campus_id' => $campus->id, 'parent_department_id' => $parent]);

        app(TenantContext::class)->withSchool($school, fn () => DB::table('campuses')->where('id', $campus->id)->delete());
        $row = $this->departmentRow($school, $child);
        $this->assertNotNull($row, 'the department survives');
        $this->assertNull($row->campus_id);
        $this->assertSame($parent, $row->parent_department_id);
        $this->assertSame($school->id, $row->school_id);

        app(TenantContext::class)->withSchool($school, fn () => DB::table('hr_departments')->where('id', $parent)->delete());
        $row = $this->departmentRow($school, $child);
        $this->assertNotNull($row);
        $this->assertNull($row->parent_department_id);
        $this->assertSame($school->id, $row->school_id);
    }

    #[Test]
    public function a_cross_school_parent_department_is_still_refused(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $parentInB = $this->department($schoolB);

        try {
            DB::transaction(fn () => $this->department($schoolA, ['parent_department_id' => $parentInB]));
            $this->fail('A School A department must not have a School B parent.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('hr_departments_parent_department_id_school_id_foreign', $e->getMessage());
        }
    }

    #[Test]
    public function deleting_a_manager_assignment_clears_only_the_reporting_line(): void
    {
        $this->inRolledBackAdminTransaction(function (Connection $admin): void {
            [$school] = $this->adminSchoolAndUser($admin);
            $position = (string) Str::uuid7();
            $admin->table('positions')->insert(['id' => $position, 'school_id' => $school, 'name' => 'Teacher', 'code' => 'TCH', 'created_at' => now(), 'updated_at' => now()]);

            $assignment = function (?string $manager) use ($admin, $school, $position): string {
                $employee = (string) Str::uuid7();
                $admin->table('employees')->insert(['id' => $employee, 'school_id' => $school, 'employee_number' => 'E'.Str::random(8), 'full_name' => 'Staff '.$employee, 'created_at' => now(), 'updated_at' => now()]);
                $record = (string) Str::uuid7();
                $admin->table('employment_records')->insert(['id' => $record, 'school_id' => $school, 'employee_id' => $employee, 'employment_type' => 'full_time', 'starts_on' => '2026-06-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                $id = (string) Str::uuid7();
                $admin->table('employee_assignments')->insert(['id' => $id, 'school_id' => $school, 'employment_record_id' => $record, 'position_id' => $position, 'starts_on' => '2026-06-01', 'manager_assignment_id' => $manager, 'created_at' => now(), 'updated_at' => now()]);

                return $id;
            };
            $manager = $assignment(null);
            $report = $assignment($manager);

            $admin->table('employee_assignments')->where('id', $manager)->delete();

            $row = $admin->table('employee_assignments')->where('id', $report)->first();
            $this->assertNotNull($row, 'the reporting assignment survives');
            $this->assertNull($row->manager_assignment_id);
            $this->assertSame($school, $row->school_id);
        });
    }

    #[Test]
    public function deleting_a_message_clears_only_the_reply_and_attachment_links(): void
    {
        $this->inRolledBackAdminTransaction(function (Connection $admin): void {
            [$school, $user] = $this->adminSchoolAndUser($admin);
            $thread = (string) Str::uuid7();
            $admin->table('communication_threads')->insert(['id' => $thread, 'school_id' => $school, 'created_by_user_id' => $user, 'created_at' => now(), 'updated_at' => now()]);

            $message = function (?string $replyTo) use ($admin, $school, $thread, $user): string {
                $id = (string) Str::uuid7();
                $admin->table('communication_messages')->insert(['id' => $id, 'school_id' => $school, 'thread_id' => $thread, 'sender_user_id' => $user, 'body' => 'Hello', 'reply_to_message_id' => $replyTo, 'created_at' => now(), 'updated_at' => now()]);

                return $id;
            };
            $original = $message(null);
            $reply = $message($original);
            $attachment = (string) Str::uuid7();
            $admin->table('communication_attachments')->insert([
                'id' => $attachment, 'school_id' => $school, 'communication_thread_id' => $thread, 'communication_message_id' => $original,
                'storage_disk' => 'local', 'storage_path' => 'x/'.$attachment, 'original_filename' => 'a.pdf', 'safe_display_name' => 'a.pdf',
                'mime_type' => 'application/pdf', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64), 'created_by_user_id' => $user,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $admin->table('communication_messages')->where('id', $original)->delete();

            $replyRow = $admin->table('communication_messages')->where('id', $reply)->first();
            $this->assertNotNull($replyRow);
            $this->assertNull($replyRow->reply_to_message_id);
            $this->assertSame($school, $replyRow->school_id);

            $attachmentRow = $admin->table('communication_attachments')->where('id', $attachment)->first();
            $this->assertNotNull($attachmentRow);
            $this->assertNull($attachmentRow->communication_message_id);
            $this->assertSame($school, $attachmentRow->school_id);
        });
    }
}
