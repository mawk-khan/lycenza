<?php

namespace Tests\Feature\Audit;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SchoolAuditEventReader;
use App\Support\Audit\SchoolAuditSubjectHistoryEntry;
use App\Support\Audit\SchoolAuditSubjectHistoryReader;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * V11.2 (ADR 0075): the entity-history read contract over the School
 * audit ledger (ADR 0042 section 10 "event-type metadata allowlist").
 */
class SchoolAuditSubjectHistoryReaderTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function reader(): SchoolAuditSubjectHistoryReader
    {
        return app(SchoolAuditSubjectHistoryReader::class);
    }

    private function record(School $school, string $type, ?Student $subject = null, array $metadata = []): void
    {
        app(TenantContext::class)->withSchool($school, fn () => app(AuditRecorder::class)->school($school, $type, subject: $subject, metadata: $metadata));
    }

    #[Test]
    public function it_returns_subject_and_linked_events_with_only_allow_listed_plain_metadata(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $this->record($school, 'test.subject_event', $student, ['keep' => 'yes', 'drop' => 'secret']);
        $this->record($school, 'test.linked_event', null, ['studentId' => $student->id, 'list' => ['a', 'b'], 'nested' => ['k' => 'v']]);
        $this->record($school, 'test.unlisted_event', $student, ['keep' => 'never read']);

        $history = $this->reader()->history($school, Student::class, $student->id, 'studentId', [
            'test.subject_event' => ['keep'],
            'test.linked_event' => ['list', 'nested'],
        ]);

        $byType = collect($history->entries)->keyBy(fn (SchoolAuditSubjectHistoryEntry $e) => $e->eventType);
        $this->assertSame(['test.linked_event', 'test.subject_event'], $byType->keys()->sort()->values()->all());
        $this->assertSame(['keep' => 'yes'], $byType['test.subject_event']->details);
        $this->assertSame(['list' => ['a', 'b']], $byType['test.linked_event']->details, 'Nested structures are never returned.');
        $this->assertFalse($history->truncated);
    }

    #[Test]
    public function another_schools_events_and_another_subjects_events_never_match(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $student = $this->createStudent($school);
        $neighbour = $this->createStudent($school);
        // Another School naming the same Student id in its metadata (forged or coincidental).
        $this->record($other, 'test.linked_event', null, ['studentId' => $student->id]);
        $this->record($school, 'test.linked_event', null, ['studentId' => $neighbour->id]);
        $this->record($school, 'test.linked_event', null, ['studentId' => $student->id]);

        $history = $this->reader()->history($school, Student::class, $student->id, 'studentId', ['test.linked_event' => []]);

        $this->assertCount(1, $history->entries);
    }

    #[Test]
    public function the_limit_is_honoured_and_truncation_is_reported(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        foreach (range(1, 3) as $i) {
            $this->record($school, 'test.subject_event', $student);
        }

        $history = $this->reader()->history($school, Student::class, $student->id, 'studentId', ['test.subject_event' => []], 2);

        $this->assertCount(2, $history->entries);
        $this->assertTrue($history->truncated);
    }

    #[Test]
    public function an_empty_allowlist_reads_nothing_and_an_unsafe_link_key_is_refused(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $this->record($school, 'test.subject_event', $student);

        $this->assertSame([], $this->reader()->history($school, Student::class, $student->id, 'studentId', [])->entries);

        $this->expectException(InvalidArgumentException::class);
        $this->reader()->history($school, Student::class, $student->id, "studentId' OR '1'='1", ['test.subject_event' => []]);
    }

    #[Test]
    public function the_contract_never_selects_actor_or_network_columns_and_the_review_reader_is_untouched(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SchoolAuditSubjectHistoryReader::class))->getFileName());
        $select = preg_match('/->select\\(\\[(.*?)\\]\\)/s', $source, $m) === 1 ? $m[1] : '';

        $this->assertNotSame('', $select);
        foreach (['actor_user_id', 'ip_address', 'user_agent', 'request_id', 'elevation_id', '*'] as $never) {
            $this->assertStringNotContainsString("'{$never}'", $select);
        }
        $this->assertStringNotContainsString('PlatformAuditEvent', $source);
        $this->assertStringNotContainsString('->create(', $source);
        // The School audit-log review contract still never loads metadata (ComplianceArchitectureGuardTest).
        $this->assertSame(
            ['id', 'occurred_at', 'actor_user_id', 'event_type', 'subject_type', 'subject_id', 'request_id'],
            (new ReflectionClass(SchoolAuditEventReader::class))->getReflectionConstant('COLUMNS')->getValue(),
        );
        $this->assertSame(['id', 'occurredAt', 'eventType', 'subjectType', 'subjectId', 'details'], array_map(
            fn ($p) => $p->getName(),
            (new ReflectionClass(SchoolAuditSubjectHistoryEntry::class))->getProperties(),
        ));
    }
}
