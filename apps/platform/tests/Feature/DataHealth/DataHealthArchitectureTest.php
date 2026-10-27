<?php

namespace Tests\Feature\DataHealth;

use App\Support\DataHealth\DataHealthRegistry;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * SIS 8.1 (ADR 0075 §10.3): Data Health detects; the owning workflow
 * repairs. Pinned on the code of every registered check:
 *
 * - it lives in its owning module's Application/DataHealth;
 * - it contains no write, lock or service mutation (DataHealthStudentIdentityTest
 *   proves the same at run time: only SELECTs reach the database);
 * - a check of another module never reads Students' placement table or
 *   model itself (CLAUDE.md rule 4): it asks a Students read service.
 */
class DataHealthArchitectureTest extends TestCase
{
    private const array WRITES = [
        '->update(', '->save(', '->create(', '->delete(', '->insert(', '->forceFill(', '->increment(', '->decrement(',
        'DB::statement', 'DB::unprepared', 'DB::transaction', 'lockForUpdate', 'sharedLock', '->upsert(', 'Audit', 'Outbox',
    ];

    #[Test]
    public function every_check_is_domain_owned_read_only_and_reads_placements_only_through_students(): void
    {
        $checks = app(DataHealthRegistry::class)->all();
        $this->assertNotEmpty($checks);

        foreach ($checks as $code => $check) {
            $class = new ReflectionClass($check);
            $this->assertMatchesRegularExpression('/^App\\\\Domain\\\\(\w+)\\\\Application\\\\DataHealth\\\\/', $class->getName(), "{$code} lives in its owning module");
            $source = (string) file_get_contents((string) $class->getFileName());
            $codeOnly = (string) preg_replace('/^\s*(\*|\/\/|\/\*).*$/m', '', $source);

            foreach (self::WRITES as $write) {
                $this->assertStringNotContainsString($write, $codeOnly, "{$code} is read-only ({$write})");
            }
            $this->assertFalse($check->definition()->automaticRepair, "{$code}: no automatic repair");

            if (! str_starts_with($class->getName(), 'App\\Domain\\Students\\')) {
                foreach (['student_enrollments', 'StudentEnrollment::', 'Student::query', "'students'"] as $foreign) {
                    $this->assertStringNotContainsString($foreign, $codeOnly, "{$code} reads Students data only through a Students read service");
                }
            }
        }
    }
}
