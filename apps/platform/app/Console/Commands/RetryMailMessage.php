<?php

namespace App\Console\Commands;

use App\Jobs\SubmitEmailMessageJob;
use App\Models\EmailMessage;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Email\EmailSubmissionService;
use App\Support\Observability\QueueName;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Phase 0O.9A (ADR 0055 section 10): an operator makes one WAITING message
 * due now (after fixing a provider outage or credential) and, once in its
 * life, restores its attempt budget. It resurrects nothing: a submitted,
 * failed, suppressed or cancelled message is refused (a finished message's
 * content is already purged -- re-issue it from the product, e.g. resend
 * the invitation), and the submission claim still re-checks expiry, the
 * source, the School lifecycle, suppression and the budgets. Audited on
 * the platform ledger with ids only.
 */
class RetryMailMessage extends Command
{
    protected $signature = 'platform:mail-retry {school : School id} {message : Email message id}';

    protected $description = 'Make one waiting email message due now (ADR 0055; operator only, audited).';

    public function handle(TenantContext $context, EmailSubmissionService $submissions, AuditRecorder $audit): int
    {
        $school = School::query()->find((string) $this->argument('school'));

        if ($school === null) {
            $this->error('No such School.');

            return self::FAILURE;
        }

        $outcome = $context->withSchool($school, function () use ($submissions): string {
            $message = EmailMessage::query()->find((string) $this->argument('message'));

            if ($message === null) {
                return 'not_found';
            }

            return $submissions->retryNow($message) ? 'scheduled' : 'not_waiting:'.$message->status->value;
        });

        $audit->platform('platform.email_message.retry_requested', metadata: [
            'school_id' => $school->id,
            'email_message_id' => (string) $this->argument('message'),
            'outcome' => explode(':', $outcome)[0],
        ]);

        if ($outcome !== 'scheduled') {
            $this->error("Not retried ({$outcome}).");

            return self::FAILURE;
        }

        SubmitEmailMessageJob::dispatch($school->id, (string) $this->argument('message'))->onQueue(QueueName::Notifications->value);
        $this->info('Retry scheduled.');

        return self::SUCCESS;
    }
}
