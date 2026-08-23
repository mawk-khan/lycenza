<?php

namespace App\Support\Settings;

use App\Domain\Platform\Events\SchoolSettingChanged;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only sanctioned write path for School settings -- never write
 * SchoolSetting directly. Demonstrates the full pattern every future
 * business action should follow: validate -> write state -> audit ->
 * emit domain event, all inside ONE database transaction (ADR 0025).
 */
class SchoolSettingsService
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function get(School $school, string $key): mixed
    {
        $definition = $this->registry->get($key);

        $row = $this->context->withSchool(
            $school,
            fn () => SchoolSetting::query()->where('school_id', $school->id)->where('key', $key)->first(),
        );

        return $row !== null ? $row->value : $definition->default;
    }

    public function set(School $school, string $key, mixed $value, ?User $actor = null): void
    {
        $definition = $this->registry->get($key);

        if (! $definition->validate($value)) {
            throw new InvalidArgumentException("Invalid value for School setting '{$key}'.");
        }

        $this->context->withSchool($school, function () use ($school, $key, $value, $actor): void {
            DB::transaction(function () use ($school, $key, $value, $actor): void {
                $updatedBy = $actor ?? $this->context->actor();

                $setting = SchoolSetting::query()->updateOrCreate(
                    ['school_id' => $school->id, 'key' => $key],
                    ['value' => $value, 'updated_by_user_id' => $updatedBy?->id],
                );

                $this->audit->school($school, 'school.settings.changed', actor: $actor, subject: $setting, metadata: [
                    'key' => $key,
                    'value' => $value,
                ]);

                // Same transaction as the write above -- this is what
                // makes the outbox row transactional (ADR 0025).
                event(new SchoolSettingChanged($school->id, $key, $value));
            });
        });
    }
}
