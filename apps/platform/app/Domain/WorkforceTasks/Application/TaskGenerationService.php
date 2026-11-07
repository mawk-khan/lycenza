<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\WorkforceTasks\Infrastructure\TaskGenerationRun;
use App\Domain\WorkforceTasks\Infrastructure\TaskTemplate;
use App\Domain\WorkforceTasks\Infrastructure\TaskTemplateVersion;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * HR.13 (owner decision 2026-11-04, "scheduled, calendar-neutral"): the ONE
 * recurring-task generator, run every minute by `workforce-tasks:generate` and on
 * demand ("generate now"). It creates only TODAY's occurrences (School-local
 * date), never a backlog: a missed day is not filled in.
 *
 * - Templates: every active template's version in effect today (the latest
 *   `effective_from` <= today) that has an occurrence today (TaskFrequency).
 * - Population: the COMPLETE employment roster overlapping today, paged by
 *   id (EmploymentRoster::pageOverlapping, no cap); an employment is eligible
 *   when it is `active` / `notice_period`, of an active Employee, and its
 *   effective-dated facts today (WorkforceAsOfReader::placementsOn: staff
 *   type, department, position) match the version (every set condition).
 * - Idempotent: one task per employment, template and date
 *   (`workforce_tasks_one_occurrence`, INSERT ... ON CONFLICT DO NOTHING);
 *   a re-run, a second worker or a resumed run never duplicates anything.
 * - Resumable: one run per School and date (`workforce_task_generation_runs`),
 *   each batch one transaction under the run row's lock, advancing a cursor;
 *   a failed batch rolls back alone and the next call resumes from the cursor.
 * - Reconciled: created + existing + not_matched = examined x occurring
 *   templates, per pass (a manual "generate now" after the run finished
 *   starts another pass; its counts accumulate).
 *
 * The School must be operational when each batch runs (rule 86).
 */
final class TaskGenerationService
{
    public const int BATCH = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly TaskAuthority $authority,
        private readonly EmploymentRoster $roster,
        private readonly WorkforceAsOfReader $workforce,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    /** "Generate now": the same run, started by a configuring person. */
    public function generateNow(School $school, User $actor): TaskGenerationRun
    {
        $this->authority->require($actor, TaskCapabilities::CONFIGURE, $school);

        return $this->run($school, $actor);
    }

    public function run(School $school, ?User $actor = null, int $batch = self::BATCH): TaskGenerationRun
    {
        return $this->context->withSchool($school, function () use ($school, $actor, $batch): TaskGenerationRun {
            $date = SchoolTimezone::today($school);
            DB::table('workforce_task_generation_runs')->insertOrIgnore([
                'id' => (string) new UuidV7, 'school_id' => $school->id, 'occurrence_on' => $date, 'status' => 'running',
                'started_by_user_id' => $actor?->id, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($actor !== null) {
                // "Generate now" after today's run finished (e.g. a template activated
                // since): a new pass over the whole roster. Inserts stay idempotent; the
                // counts accumulate per pass (existing tasks are counted as existing).
                DB::transaction(fn () => TaskGenerationRun::query()->where('school_id', $school->id)->where('occurrence_on', $date)
                    ->where('status', 'completed')->lockForUpdate()->get()
                    ->each(fn (TaskGenerationRun $r) => $r->forceFill(['status' => 'running', 'cursor' => null, 'finished_at' => null])->save()));
            }

            while (true) {
                $finished = DB::transaction(function () use ($school, $date, $batch): bool {
                    $this->operational->requireOperational($school->id);
                    $run = TaskGenerationRun::query()->where('school_id', $school->id)->where('occurrence_on', $date)->lockForUpdate()->firstOrFail();
                    if ($run->status === 'completed') {
                        return true;
                    }
                    $versions = $this->occurringVersions($school, CarbonImmutable::parse($date));
                    $page = $versions === [] ? [] : $this->roster->pageOverlapping($school, $date, $date, $run->cursor, $batch);
                    if ($page === []) {
                        $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

                        return true;
                    }

                    [$created, $existing, $notMatched] = $this->generateFor($school, $date, $page, $versions);
                    $run->forceFill([
                        'status' => 'running',
                        'cursor' => end($page)['employmentRecordId'],
                        'examined' => $run->examined + count($page),
                        'created' => $run->created + $created,
                        'existing' => $run->existing + $existing,
                        'not_matched' => $run->not_matched + $notMatched,
                    ])->save();

                    return false;
                });
                if ($finished) {
                    break;
                }
            }

            $run = TaskGenerationRun::query()->where('school_id', $school->id)->where('occurrence_on', $date)->firstOrFail();
            if ($actor !== null) {
                $this->audit->school($school, 'hr.task_generation.run', actor: $actor, subject: $run, metadata: [
                    'occurrenceOn' => $date, 'examined' => $run->examined, 'created' => $run->created, 'existing' => $run->existing, 'notMatched' => $run->not_matched,
                ]);
            }

            return $run;
        });
    }

    /** @return list<TaskTemplateVersion> the version in effect today of each active template, when it occurs today */
    public function occurringVersions(School $school, CarbonImmutable $date): array
    {
        $templates = TaskTemplate::query()->where('school_id', $school->id)->where('status', 'active')->pluck('id');
        if ($templates->isEmpty()) {
            return [];
        }

        return TaskTemplateVersion::query()->where('school_id', $school->id)->whereIn('template_id', $templates)
            ->where('effective_from', '<=', $date->toDateString())
            ->orderBy('template_id')->orderByDesc('effective_from')->get()
            ->unique('template_id')
            ->filter(fn (TaskTemplateVersion $v) => TaskFrequency::occursOn($v, $date))
            ->values()->all();
    }

    /**
     * @param  list<array{employmentRecordId: string, employeeId: string, status: string, employeeActive: bool}>  $page
     * @param  list<TaskTemplateVersion>  $versions
     * @return array{int, int, int} created, existing, not matched
     */
    private function generateFor(School $school, string $date, array $page, array $versions): array
    {
        $current = array_values(array_filter($page, fn (array $r) => in_array($r['status'], ['active', 'notice_period'], true) && $r['employeeActive']));
        $placements = $this->workforce->placementsOn($school, array_column($current, 'employeeId'), $date);

        $rows = [];
        $notMatched = (count($page) - count($current)) * count($versions);
        foreach ($current as $employment) {
            $placement = $placements[strtolower($employment['employeeId'])] ?? null;
            foreach ($versions as $version) {
                if ($placement === null || $placement->employmentRecordId !== $employment['employmentRecordId']
                    || ! $version->appliesTo($placement->staffType, $placement->departmentId, $placement->positionId)) {
                    $notMatched++;

                    continue;
                }
                $rows[] = [
                    (string) new UuidV7, $school->id, $employment['employmentRecordId'], $version->template_id, $version->id, $date,
                    $version->title, $version->description, $version->category_id, $version->completion_mode, $date,
                    CarbonImmutable::parse($date)->addDays($version->due_days)->toDateString(),
                    $placement->staffType, $placement->departmentId, $placement->positionId, $placement->campusId,
                ];
            }
        }
        if ($rows === []) {
            return [0, 0, $notMatched];
        }

        $placeholders = implode(', ', array_fill(0, count($rows), "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'generated', ?, ?, ?, ?, ?, ?, now(), now())"));
        $inserted = DB::select(
            'INSERT INTO workforce_tasks (id, school_id, employment_record_id, template_id, template_version_id, occurrence_on, title, description, category_id, completion_mode, assignment_path, available_on, due_on, staff_type, department_id, position_id, campus_id, created_at, updated_at)
             VALUES '.$placeholders.'
             ON CONFLICT (employment_record_id, template_id, occurrence_on) WHERE template_id IS NOT NULL DO NOTHING
             RETURNING id',
            array_merge(...$rows),
        );
        if ($inserted !== []) {
            DB::table('workforce_task_events')->insert(array_map(fn ($row) => [
                'id' => (string) new UuidV7, 'school_id' => $school->id, 'task_id' => $row->id, 'kind' => 'generated', 'actor_user_id' => null, 'occurred_at' => now(),
            ], $inserted));
        }

        return [count($inserted), count($rows) - count($inserted), $notMatched];
    }
}
