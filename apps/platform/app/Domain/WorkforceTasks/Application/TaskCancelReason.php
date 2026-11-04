<?php

namespace App\Domain\WorkforceTasks\Application;

/** HR.13: why a staff task is cancelled (closed; `reassigned` is written only by a reassignment). */
enum TaskCancelReason: string
{
    case NO_LONGER_NEEDED = 'no_longer_needed';
    case ASSIGNED_IN_ERROR = 'assigned_in_error';
    case REASSIGNED = 'reassigned';

    public function label(): string
    {
        return match ($this) {
            self::NO_LONGER_NEEDED => 'No longer needed',
            self::ASSIGNED_IN_ERROR => 'Assigned in error',
            self::REASSIGNED => 'Reassigned to someone else',
        };
    }

    /** The reasons a person may choose (a reassignment records its own). @return list<array{value: string, label: string}> */
    public static function choosable(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], [self::NO_LONGER_NEEDED, self::ASSIGNED_IN_ERROR]);
    }
}
