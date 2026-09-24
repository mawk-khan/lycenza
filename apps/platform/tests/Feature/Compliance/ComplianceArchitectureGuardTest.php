<?php

namespace Tests\Feature\Compliance;

use App\Support\Audit\SchoolAuditEventEntry;
use App\Support\Audit\SchoolAuditEventReader;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Phase 0L.4 -- ADR 0042's boundary, checked on the source itself:
 * Compliance is read-only, reads the audit ledger only through the
 * App\Support\Audit read contract, never touches the platform ledger,
 * runs no jobs/listeners/schedules, and no other module depends on it.
 */
class ComplianceArchitectureGuardTest extends TestCase
{
    private const COMPLIANCE_DIR = 'app/Domain/Compliance';

    /** The only App\ classes Compliance code may import. */
    private const ALLOWED_IMPORTS = [
        'App\\Models\\School',
        'App\\Models\\User',
        'App\\Support\\Audit\\AuditRecorder',
        'App\\Support\\Audit\\SchoolAuditEventEntry',
        'App\\Support\\Audit\\SchoolAuditEventReader',
        'App\\Support\\Authorization\\AuthorizesCapability',
    ];

    /** @return array<string, string> path => contents */
    private function complianceSources(): array
    {
        $sources = [];
        foreach (Finder::create()->files()->name('*.php')->in(base_path(self::COMPLIANCE_DIR)) as $file) {
            $sources[$file->getRelativePathname()] = $file->getContents();
        }
        $this->assertNotEmpty($sources);

        return $sources;
    }

    #[Test]
    public function compliance_imports_only_the_audit_read_contract_and_authorization(): void
    {
        foreach ($this->complianceSources() as $path => $source) {
            preg_match_all('/^use (App\\\\[^;\s]+)/m', $source, $matches);
            foreach ($matches[1] as $import) {
                $this->assertContains($import, self::ALLOWED_IMPORTS, "{$path} imports {$import}");
            }
        }
    }

    #[Test]
    public function compliance_never_writes_source_records_or_touches_a_ledger_directly(): void
    {
        foreach ($this->complianceSources() as $path => $source) {
            // The ledger models themselves -- not the read contract's
            // SchoolAuditEventReader/Entry, which share the prefix.
            $this->assertDoesNotMatchRegularExpression('/\\b(SchoolAuditEvent|PlatformAuditEvent)\\b(?!Reader|Entry|Page)/', $source, "{$path} references a ledger model");
        }

        $forbidden = [
            'platform_audit_events', 'school_audit_events',
            'DB::', '->save(', '->update(', '->delete(', '::create(', '->insert(', '->forceDelete(',
            'Schema::', 'dispatch(', 'Notification', 'ShouldQueue', 'Schedule',
        ];

        foreach ($this->complianceSources() as $path => $source) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$path} contains {$needle}");
            }
        }
    }

    #[Test]
    public function no_other_module_depends_on_compliance(): void
    {
        $allowed = [
            base_path(self::COMPLIANCE_DIR),
            base_path('app/Http/Controllers/App/Compliance'),
        ];

        foreach (Finder::create()->files()->name('*.php')->in(base_path('app')) as $file) {
            $inAllowed = collect($allowed)->contains(fn (string $dir) => str_starts_with($file->getPathname(), $dir));
            if (! $inAllowed) {
                $this->assertStringNotContainsString('App\\Domain\\Compliance', $file->getContents(), $file->getRelativePathname().' depends on Compliance');
            }
        }

        $this->assertStringNotContainsString('Compliance', (string) file_get_contents(base_path('routes/console.php')));
        $this->assertStringNotContainsString('Compliance', (string) file_get_contents(base_path('routes/api.php')));
    }

    #[Test]
    public function the_read_contract_never_selects_metadata_and_never_reads_the_platform_ledger(): void
    {
        $columns = (new ReflectionClass(SchoolAuditEventReader::class))->getReflectionConstant('COLUMNS')->getValue();

        $this->assertSame(['id', 'occurred_at', 'actor_user_id', 'event_type', 'subject_type', 'subject_id', 'request_id'], $columns);
        $this->assertSame(['id', 'occurredAt', 'eventType', 'actorUserId', 'subjectType', 'subjectId', 'requestId'], SchoolAuditEventEntry::FIELDS);

        $source = (string) file_get_contents((new ReflectionClass(SchoolAuditEventReader::class))->getFileName());
        $this->assertStringNotContainsString('PlatformAuditEvent', $source);
        $this->assertStringNotContainsString("'metadata'", $source);
        $this->assertStringNotContainsString('->create(', $source);
    }
}
