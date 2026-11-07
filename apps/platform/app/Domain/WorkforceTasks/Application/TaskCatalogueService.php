<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\TaskTemplate;
use App\Domain\WorkforceTasks\Infrastructure\TaskTemplateVersion;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CodeNormalizer;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HR.13: the School's task catalogue -- categories, recurring templates and
 * their immutable versions. A version never changes; a different title,
 * schedule, cohort or due offset is a NEW version effective from a later
 * date (today or after), so every task stays explainable by the version that
 * created it. Categories and templates are retired, never deleted.
 * Applicability references the School's own HR Departments and Positions by
 * id (composite keys), never names; every set condition must match (AND).
 */
final class TaskCatalogueService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly TaskAuthority $authority,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    public function createCategory(School $school, string $code, string $label, User $actor): TaskCategory
    {
        $this->authority->require($actor, TaskCapabilities::CONFIGURE, $school);
        $label = self::text($label, 80, 'TASK_CATEGORY_LABEL', 'Enter a label of at most 80 characters.');

        return $this->inSchool($school, function () use ($school, $code, $label, $actor): TaskCategory {
            try {
                $category = DB::transaction(fn () => TaskCategory::query()->create(['code' => self::code($code), 'label' => $label, 'created_by_user_id' => $actor->id]));
            } catch (UniqueConstraintViolationException) {
                throw TaskException::conflict('TASK_CATEGORY_CODE_TAKEN', 'A category with this code already exists.');
            }
            $this->audit->school($school, 'hr.task_category.created', actor: $actor, subject: $category, metadata: ['code' => $category->code]);

            return $category;
        });
    }

    public function retireCategory(School $school, string $categoryId, User $actor): TaskCategory
    {
        $this->authority->require($actor, TaskCapabilities::CONFIGURE, $school);

        return $this->inSchool($school, function () use ($school, $categoryId, $actor): TaskCategory {
            $category = TaskCategory::query()->where('school_id', $school->id)->whereKey($categoryId)->lockForUpdate()->first() ?? throw TaskException::notFound();
            if ($category->status === 'retired') {
                throw TaskException::conflict('TASK_CATEGORY_RETIRED', 'This category is already retired.');
            }
            $category->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
            $this->audit->school($school, 'hr.task_category.retired', actor: $actor, subject: $category, metadata: ['code' => $category->code]);

            return $category;
        });
    }

    /** @param  array<string, mixed>  $terms  see self::versionTerms() */
    public function createTemplate(School $school, string $code, array $terms, User $actor): TaskTemplate
    {
        $this->authority->require($actor, TaskCapabilities::CONFIGURE, $school);

        return $this->inSchool($school, function () use ($school, $code, $terms, $actor): TaskTemplate {
            try {
                $template = DB::transaction(fn () => TaskTemplate::query()->create(['code' => self::code($code), 'created_by_user_id' => $actor->id]));
            } catch (UniqueConstraintViolationException) {
                throw TaskException::conflict('TASK_TEMPLATE_CODE_TAKEN', 'A template with this code already exists (codes are never reused).');
            }
            $version = $this->insertVersion($school, $template, 1, $terms, $actor);
            $this->audit->school($school, 'hr.task_template.created', actor: $actor, subject: $template, metadata: ['code' => $template->code, 'versionId' => $version->id, 'frequency' => $version->frequency]);

            return $template;
        });
    }

    /** A new version of an active template, effective from today or a later School-local date. */
    public function addVersion(School $school, string $templateId, array $terms, User $actor): TaskTemplateVersion
    {
        $this->authority->require($actor, TaskCapabilities::CONFIGURE, $school);

        return $this->inSchool($school, function () use ($school, $templateId, $terms, $actor): TaskTemplateVersion {
            $template = TaskTemplate::query()->where('school_id', $school->id)->whereKey($templateId)->lockForUpdate()->first() ?? throw TaskException::notFound();
            if ($template->status !== 'active') {
                throw TaskException::conflict('TASK_TEMPLATE_RETIRED', 'A retired template takes no new version.');
            }
            $latest = TaskTemplateVersion::query()->where('template_id', $template->id)->orderByDesc('version_no')->first();
            if ($latest !== null && ($terms['effective_from'] ?? '') <= $latest->effective_from->toDateString()) {
                throw TaskException::invalid('TASK_VERSION_EFFECTIVE_FROM', 'A new version takes effect after the current one.');
            }
            $version = $this->insertVersion($school, $template, $latest === null ? 1 : $latest->version_no + 1, $terms, $actor);
            $this->audit->school($school, 'hr.task_template.versioned', actor: $actor, subject: $template, metadata: ['code' => $template->code, 'versionId' => $version->id, 'versionNo' => $version->version_no]);

            return $version;
        });
    }

    public function retireTemplate(School $school, string $templateId, User $actor): TaskTemplate
    {
        $this->authority->require($actor, TaskCapabilities::CONFIGURE, $school);

        return $this->inSchool($school, function () use ($school, $templateId, $actor): TaskTemplate {
            $template = TaskTemplate::query()->where('school_id', $school->id)->whereKey($templateId)->lockForUpdate()->first() ?? throw TaskException::notFound();
            if ($template->status === 'retired') {
                throw TaskException::conflict('TASK_TEMPLATE_RETIRED', 'This template is already retired.');
            }
            // Retiring stops future generation only; every task it created stays.
            $template->forceFill(['status' => 'retired', 'retired_at' => now(), 'retired_by_user_id' => $actor->id])->save();
            $this->audit->school($school, 'hr.task_template.retired', actor: $actor, subject: $template, metadata: ['code' => $template->code]);

            return $template;
        });
    }

    /** @param  array<string, mixed>  $terms */
    private function insertVersion(School $school, TaskTemplate $template, int $number, array $terms, User $actor): TaskTemplateVersion
    {
        $values = self::versionTerms($terms);
        if ($values['effective_from'] < SchoolTimezone::today($school)) {
            throw TaskException::invalid('TASK_VERSION_EFFECTIVE_FROM', 'A version takes effect today or later, never in the past.');
        }
        $category = TaskCategory::query()->where('school_id', $school->id)->whereKey($values['category_id'])->first();
        if ($category === null || $category->status !== 'active') {
            throw TaskException::invalid('TASK_CATEGORY_UNAVAILABLE', 'Choose an active category.');
        }

        try {
            return DB::transaction(fn () => TaskTemplateVersion::query()->create([...$values, 'template_id' => $template->id, 'version_no' => $number, 'created_by_user_id' => $actor->id]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'schedule_check')) {
                throw TaskException::invalid('TASK_SCHEDULE_INVALID', 'The schedule is not valid for its frequency.');
            }
            if (str_contains($e->getMessage(), 'foreign key')) {
                throw TaskException::invalid('TASK_APPLICABILITY_INVALID', 'Choose a department and position of this School.');
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    public static function versionTerms(array $terms): array
    {
        $frequency = TaskFrequency::tryFrom((string) ($terms['frequency'] ?? '')) ?? throw TaskException::invalid('TASK_FREQUENCY', 'Choose a frequency.');
        $mode = in_array($terms['completion_mode'] ?? null, ['self', 'reviewed'], true) ? $terms['completion_mode'] : throw TaskException::invalid('TASK_MODE', 'Choose how the task is completed.');
        $applies = ['applies_teaching' => (bool) ($terms['applies_teaching'] ?? false), 'applies_non_teaching' => (bool) ($terms['applies_non_teaching'] ?? false), 'applies_outsourced' => (bool) ($terms['applies_outsourced'] ?? false)];
        if (! in_array(true, $applies, true)) {
            throw TaskException::invalid('TASK_APPLIES', 'Choose at least one staff type.');
        }
        $due = (int) ($terms['due_days'] ?? -1);
        if ($due < 0 || $due > 366) {
            throw TaskException::invalid('TASK_DUE_DAYS', 'The due offset is 0 to 366 calendar days.');
        }

        return [
            'title' => self::text((string) ($terms['title'] ?? ''), 120, 'TASK_TITLE', 'Enter a title of at most 120 characters.'),
            'description' => ($terms['description'] ?? null) === null || trim((string) $terms['description']) === '' ? null
                : self::text((string) $terms['description'], 1000, 'TASK_DESCRIPTION', 'The description is at most 1000 characters.'),
            'category_id' => (string) ($terms['category_id'] ?? ''),
            'completion_mode' => $mode,
            'frequency' => $frequency->value,
            'weekdays' => in_array($frequency, [TaskFrequency::DAILY, TaskFrequency::WEEKLY], true) ? (int) ($terms['weekdays'] ?? 0) : null,
            'month_day' => in_array($frequency, [TaskFrequency::MONTHLY, TaskFrequency::YEARLY], true) && empty($terms['last_day']) ? (int) ($terms['month_day'] ?? 0) : null,
            'last_day' => in_array($frequency, [TaskFrequency::MONTHLY, TaskFrequency::YEARLY], true) && ! empty($terms['last_day']),
            'year_month' => $frequency === TaskFrequency::YEARLY ? (int) ($terms['year_month'] ?? 0) : null,
            'due_days' => $due,
            ...$applies,
            'department_id' => ($terms['department_id'] ?? null) ?: null,
            'position_id' => ($terms['position_id'] ?? null) ?: null,
            'effective_from' => (string) ($terms['effective_from'] ?? ''),
        ];
    }

    public static function text(string $value, int $max, string $code, string $message): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw TaskException::invalid($code, $message);
        }

        return $value;
    }

    private static function code(string $code): string
    {
        $normalized = CodeNormalizer::normalize($code);
        if ($normalized === null || preg_match('/^[A-Z0-9_-]{1,40}$/', $normalized) !== 1) {
            throw TaskException::invalid('TASK_CODE', 'A code is 1 to 40 letters, digits, dashes or underscores.');
        }

        return $normalized;
    }

    /**
     * HR.R1-C13 (C12-N1, rule 86): every path, the administrative one included, asks the School first.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inSchool(School $school, callable $work): mixed
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $work): mixed {
            $this->operational->requireOperational($school->id);

            return $work();
        }));
    }
}
