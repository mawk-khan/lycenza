<?php

namespace App\Domain\Finance\Infrastructure;

use App\Support\Tenancy\BelongsToSchool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * E21.3A (ADR 0064): one School financial year. Rows are created only by
 * `ensureContaining()` (on a School's first posting in a year, or on
 * demand), and closed only by `FinancialPeriodCloseService`. Boundaries never
 * change, a closed period never changes, and nothing is deleted
 * (`financial_periods_guard`, runtime DELETE revoked).
 *
 * @property string $id
 * @property string $school_id
 * @property string $period_key
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 * @property Carbon|null $closed_at
 * @property string|null $closed_by_user_id
 * @property string|null $close_fingerprint
 */
class FinancialPeriod extends Model
{
    use BelongsToSchool;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'financial_periods';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The id of the School's period containing the School-local date of
     * `$instant`, creating it (open, UUIDv7 id per ADR 0019) when missing.
     * Boundaries come from the database's one boundary rule
     * (`finance_period_bounds`). Racing creators are safe: the guard
     * trigger serializes them and skips an identical second row. Needs the
     * School's TenantContext (RLS).
     */
    public static function ensureContaining(string $schoolId, string $timezone, CarbonInterface $instant): string
    {
        $local = $instant->copy()->setTimezone($timezone)->toDateString();
        $existing = self::containing($schoolId, $local);
        if ($existing !== null) {
            return $existing;
        }

        // A start-month change that commits while this insert waits for the
        // period lock makes the bounds read above stale; the guard refuses
        // that, and one retry (a new statement) reads the new month.
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(fn () => DB::insert(
                    "INSERT INTO financial_periods (id, school_id, period_key, starts_on, ends_on, status, created_at, updated_at)
                     SELECT ?, ?, b.period_key, b.starts_on, b.ends_on, 'open', now(), now() FROM finance_period_bounds(?, ?::date) b
                     ON CONFLICT (school_id, starts_on) DO NOTHING",
                    [(string) new UuidV7, $schoolId, $schoolId, $local],
                ));
                break;
            } catch (QueryException $e) {
                if ($attempt > 1 || ! str_contains($e->getMessage(), 'no longer match the start month')) {
                    throw $e;
                }
            }
        }

        return self::containing($schoolId, $local)
            ?? throw new RuntimeException("No financial period could be resolved for {$local}.");
    }

    private static function containing(string $schoolId, string $localDate): ?string
    {
        $id = self::query()->where('school_id', $schoolId)
            ->where('starts_on', '<=', $localDate)->where('ends_on', '>=', $localDate)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }
}
