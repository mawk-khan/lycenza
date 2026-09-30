<?php

namespace App\Console\Commands;

use App\Domain\Payments\Application\ReceiptBackfillService;
use App\Models\School;
use App\Support\Tenancy\SchoolNotOperationalException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * FEE.4 (ADR 0062 §17.4, owner decision I2): issues receipts for one named
 * School's Payments settled before FEE.4, oldest first, through
 * `ReceiptBackfillService`. Explicit and idempotent: there is no
 * all-Schools mode, it never runs from a migration or a deploy step, and a
 * re-run issues nothing new. A non-active School is refused (rule 86).
 */
class BackfillPaymentReceipts extends Command
{
    protected $signature = 'finance:receipts-backfill
        {school : The id or slug of the one School to backfill}';

    protected $description = 'Issue receipts for one School\'s Payments that were settled before receipts existed (ADR 0062 I2).';

    public function handle(ReceiptBackfillService $backfill): int
    {
        $identifier = strtolower(trim((string) $this->argument('school')));

        $school = School::query()
            ->when(Str::isUuid($identifier), fn ($q) => $q->whereKey($identifier), fn ($q) => $q->where('slug', $identifier))
            ->first();

        if ($school === null) {
            $this->error('Refused: no School matches that id or slug.');

            return self::FAILURE;
        }

        try {
            $result = $backfill->backfill($school);
        } catch (SchoolNotOperationalException) {
            $this->error('Refused: the School is not active. Any receipts issued before it stopped stay issued; run the backfill again after the School resumes.');

            return self::FAILURE;
        }

        $this->info("Backfilled {$result['issued']} receipt(s); {$result['skipped']} Payment(s) already had one.");

        return self::SUCCESS;
    }
}
