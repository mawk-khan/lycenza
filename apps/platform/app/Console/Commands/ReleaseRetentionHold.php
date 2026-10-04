<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditRecorder;
use App\Support\Retention\RetentionHolds;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * E21-RH.3 (ADR 0066 §6): EXPLICITLY releases the active retention hold of
 * one School or of the platform. Removing a value from configuration never
 * does this. Operator console only: `retention_hold_release()` on the
 * migration/owner connection records the release on the hold row (who --
 * the authenticated maintenance login -- when, why, which change
 * reference); the row itself is kept forever. Interactive confirmation
 * (type the scope), or `--force` for trusted operator automation. Audited
 * as `platform.retention_hold.released`.
 */
class ReleaseRetentionHold extends Command
{
    protected $signature = 'platform:retention-hold-release
        {--school= : The School id whose hold to release}
        {--platform : Release the platform hold}
        {--reason= : matter_concluded|inquiry_closed|audit_closed|placed_in_error|other}
        {--reference= : The change/ticket reference (letters, digits, . _ : / -; at most 64)}
        {--force : Skip the interactive confirmation (trusted operator automation only)}';

    protected $description = 'Releases the active retention hold of one School or of the platform (E21-RH.3; operator only, explicit, audited).';

    public function handle(RetentionHolds $holds, AuditRecorder $audit): int
    {
        $school = $this->option('school');
        if (($school === null) === ! $this->option('platform')) {
            $this->error('Give exactly one of --school=<id> or --platform.');

            return self::FAILURE;
        }

        $scope = $school === null ? 'platform' : (string) $school;
        if (! $this->option('force') && $this->ask("Releasing a retention hold allows destructive retention again. Type {$scope} to confirm") !== $scope) {
            $this->error('Refused: the confirmation did not match. Nothing was released.');

            return self::FAILURE;
        }

        try {
            $id = $holds->release($school, (string) $this->option('reason'), $this->option('reference'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'retention_hold_not_active')) {
                $this->error('No active hold for that scope; nothing was released.');

                return self::FAILURE;
            }

            throw $e;
        }

        $audit->platform('platform.retention_hold.released', metadata: [
            'hold_id' => $id, 'scope' => $school === null ? 'platform' : 'school', 'school_id' => $school,
            'release_reason_code' => $this->option('reason'), 'release_reference' => $this->option('reference'),
        ]);
        $this->info("Released hold {$id}. Its history is kept.");

        return self::SUCCESS;
    }
}
