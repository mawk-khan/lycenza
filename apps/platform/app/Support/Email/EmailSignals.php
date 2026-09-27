<?php

namespace App\Support\Email;

use App\Models\EmailEvent;
use App\Models\EmailProviderReference;
use App\Models\EmailSuppression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0055 section 16: bounded, platform-scoped email facts for operations
 * status and the metrics scrape -- the backlog mirror
 * (`operational_work_backlog`, source `email:<purpose>`), the event and
 * reference tables. No School context, no tenant table, no address.
 */
final class EmailSignals
{
    /** @return array<string, array{pending: int, submitting: int, oldest_age_seconds: int}> purpose => backlog */
    public function backlog(): array
    {
        $now = now();
        $result = array_fill_keys(EmailPurpose::values(), ['pending' => 0, 'submitting' => 0, 'oldest_age_seconds' => 0]);

        $rows = DB::table('operational_work_backlog')->where('source', 'like', 'email:%')
            ->selectRaw('source, state, count(*) as total, min(state_since) as oldest')
            ->groupBy('source', 'state')->get();

        foreach ($rows as $row) {
            $purpose = substr((string) $row->source, 6);
            if (! isset($result[$purpose]) || ! in_array($row->state, ['pending', 'submitting'], true)) {
                continue;
            }
            $result[$purpose][$row->state] = (int) $row->total;
            $age = $row->oldest !== null ? (int) Carbon::parse($row->oldest)->diffInSeconds($now, true) : 0;
            $result[$purpose]['oldest_age_seconds'] = max($result[$purpose]['oldest_age_seconds'], $age);
        }

        return $result;
    }

    public function lastEventAt(): ?Carbon
    {
        $at = EmailEvent::query()->max('received_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    public function lastAcceptedAt(): ?Carbon
    {
        $at = EmailProviderReference::query()->max('created_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    public function activeSuppressions(): int
    {
        return EmailSuppression::query()->active()->count();
    }
}
