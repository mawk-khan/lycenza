<?php

namespace App\Support\Operations;

use App\Models\School;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 0O.4A (ADR 0050 section 11): validates an ALREADY RESTORED,
 * isolated environment -- it never restores anything and never writes.
 * The application booting to run it is the first check; then the database
 * role model (DatabaseRoleVerifier), migrations, readiness, RLS failing
 * closed and isolating Schools, and a sample of Document objects being
 * present with their recorded size. Output: codes, counts and the observed
 * recovery point (the newest audit/outbox timestamp) -- never a payload,
 * filename, key or personal data.
 */
class RestoreValidator
{
    public function __construct(
        private readonly DatabaseRoleVerifier $database,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<CheckResult>
     */
    public function validate(int $documentSample): array
    {
        $results = [CheckResult::of('application_boot', true), ...$this->database->verify()];

        try {
            $files = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn ($f) => basename($f, '.php'));
            $ran = DB::table('migrations')->pluck('migration');
            $missing = $files->diff($ran)->count();
            $results[] = CheckResult::of('migrations_applied', $missing === 0, $missing === 0 ? '' : "{$missing} pending");
        } catch (Throwable) {
            $results[] = CheckResult::of('migrations_applied', false);
        }

        try {
            $results[] = CheckResult::of('application_readiness', app(OperationalStatusService::class)->readiness() === OperationalStatus::Healthy);
        } catch (Throwable) {
            $results[] = CheckResult::of('application_readiness', false);
        }

        $results = [...$results, ...$this->tenancy()];
        $results[] = $this->documents($documentSample);
        $results[] = $this->recoveryPoint();

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function tenancy(): array
    {
        try {
            $this->context->clearAll();
            $closed = DB::table('campuses')->count() === 0;

            $isolated = true;
            foreach (School::query()->where('status', 'active')->orderBy('id')->limit(2)->get() as $school) {
                $isolated = $isolated && $this->context->withSchool($school, fn () => DB::table('campuses')->where('school_id', '<>', $school->id)->count() === 0
                    && DB::selectOne('select current_setting(?, true) as v', [TenantRls::SESSION_VAR])->v === $school->id);
            }

            return [CheckResult::of('rls_fails_closed_without_school', $closed), CheckResult::of('rls_isolates_schools', $isolated)];
        } catch (Throwable) {
            return [CheckResult::of('rls_fails_closed_without_school', false), CheckResult::of('rls_isolates_schools', false)];
        }
    }

    private function documents(int $sample): CheckResult
    {
        if ($sample <= 0) {
            return new CheckResult('document_objects_readable', CheckResult::EVIDENCE, 'no sample requested');
        }

        try {
            $checked = 0;
            $ok = 0;

            foreach (School::query()->where('status', 'active')->orderBy('id')->get() as $school) {
                if ($checked >= $sample) {
                    break;
                }

                [$c, $o] = $this->context->withSchool($school, function () use ($sample, $checked): array {
                    $c = 0;
                    $o = 0;
                    // Under this School's RLS; only the storage location and size.
                    foreach (DB::table('documents')->where('status', 'active')->inRandomOrder()->limit($sample - $checked)->get(['storage_disk', 'storage_path', 'size_bytes']) as $document) {
                        $c++;
                        $disk = Storage::disk($document->storage_disk);
                        if ($disk->exists($document->storage_path) && $disk->size($document->storage_path) === (int) $document->size_bytes) {
                            $o++;
                        }
                    }

                    return [$c, $o];
                });

                $checked += $c;
                $ok += $o;
            }

            if ($checked === 0) {
                return new CheckResult('document_objects_readable', CheckResult::EVIDENCE, 'no documents to sample');
            }

            return CheckResult::of('document_objects_readable', $checked === $ok, "{$ok}/{$checked}");
        } catch (Throwable) {
            return CheckResult::of('document_objects_readable', false);
        }
    }

    private function recoveryPoint(): CheckResult
    {
        try {
            $point = collect([
                DB::table('platform_audit_events')->max('occurred_at'),
                DB::table('domain_event_outbox')->max('occurred_at'),
            ])->filter()->max();

            return new CheckResult('observed_recovery_point', $point === null ? CheckResult::EVIDENCE : CheckResult::PASS, $point === null ? 'no events' : (string) $point);
        } catch (Throwable) {
            return CheckResult::of('observed_recovery_point', false);
        }
    }
}
