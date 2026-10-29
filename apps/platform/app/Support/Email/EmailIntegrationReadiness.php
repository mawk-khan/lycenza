<?php

namespace App\Support\Email;

use App\Models\School;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Integrations\IntegrationReadiness;
use App\Support\Integrations\IntegrationReadinessProvider;
use App\Support\Integrations\IntegrationStatus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12G (ADR 0080 §12): `communications.email` readiness for ONE School.
 *
 * Email is operated by Lycenza (ADR 0055): the provider, sending domain and
 * credentials are platform configuration, never a School row and never
 * shown (ADR 0050). This reads only:
 * - whether the platform has email delivery enabled and unblocked
 *   (EmailProviderResolver's configuration checks; no provider call, and
 *   the closed block reason is never shown -- the School cannot act on it);
 * - this School's own `email_messages` (RLS), as three indexed EXISTS
 *   probes: an undelivered message finished in the last 7 days, a backlog
 *   older than the backlog threshold, a message from the last 7 days
 *   accepted for delivery. Never a recipient, subject, provider id, count or
 *   error text. Submission is never delivery (rule 89), so HEALTHY means
 *   "recently accepted for delivery".
 */
final class EmailIntegrationReadiness implements IntegrationReadinessProvider
{
    public const string KEY = 'communications.email';

    public const int WINDOW_DAYS = 7;

    public function __construct(
        private readonly EmailProviderResolver $providers,
        private readonly ProviderAuthPause $authPause,
        private readonly Repository $config,
    ) {}

    public function keys(): array
    {
        return [self::KEY];
    }

    public function evaluate(string $key, School $school): IntegrationReadiness
    {
        if (! $this->providers->enabled()) {
            return new IntegrationReadiness(IntegrationStatus::NOT_CONFIGURED, 'Email delivery is not enabled for this Lycenza environment. Messages wait until Lycenza enables it.');
        }
        if ($this->providers->blockedReason() !== null || $this->authPause->until() !== null) {
            return new IntegrationReadiness(IntegrationStatus::DEGRADED, 'Email delivery is paused by Lycenza. Messages wait and are sent once it resumes.');
        }

        // Three bounded EXISTS probes, each on an index of this School's rows:
        // terminal failures and the waiting backlog use
        // (school_id, status, next_attempt_at); recent acceptance uses
        // (school_id, created_at). Suppressed messages were never sent, so they
        // count as undelivered, as does one that expired while waiting (a
        // withdrawn source is not a failure); a message stuck submitting counts
        // as backlog.
        $high = (int) $this->config->get('observability.thresholds.backlog_high_seconds');
        $since = now()->subDays(self::WINDOW_DAYS);
        $messages = fn () => DB::table('email_messages')->where('school_id', $school->id);
        $failed = $messages()->where('finished_at', '>=', $since)
            ->where(fn ($q) => $q->whereIn('status', ['failed', 'bounced', 'complained', 'suppressed'])
                ->orWhere(fn ($q) => $q->where('status', 'cancelled')->where('status_code', 'expired')))
            ->exists();
        $backlog = $messages()->whereIn('status', ['pending', 'deferred', 'submitting'])->where('created_at', '<', now()->subSeconds($high))->exists();
        $accepted = $messages()->where('created_at', '>=', $since)->whereIn('status', ['submitted', 'delivered'])->exists();

        return match (true) {
            $failed => new IntegrationReadiness(IntegrationStatus::ATTENTION_REQUIRED, 'Some messages from the last 7 days could not be delivered. Check the delivery status of recent communications.'),
            $backlog => new IntegrationReadiness(IntegrationStatus::DEGRADED, 'Some messages are waiting longer than usual to be sent.'),
            $accepted => new IntegrationReadiness(IntegrationStatus::HEALTHY, 'Messages from the last 7 days were accepted for delivery.'),
            default => new IntegrationReadiness(IntegrationStatus::CONFIGURED, 'Email delivery is enabled. No messages were sent in the last 7 days.'),
        };
    }
}
