<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\School;
use App\Support\Email\Events\FakeEmailEventAdapter;
use App\Support\Email\PlatformEmailScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Phase 0O.9A (ADR 0055 section 22): LOCAL/TESTING ONLY. Builds one signed
 * fake-provider event for a submitted message and sends it through the
 * REAL webhook route in-process (bounds, signature, dedupe, queue), so a
 * DDEV review can walk delivered / soft bounce / hard bounce / complaint /
 * dropped end to end. Refused anywhere else; never contacts a network.
 */
class SendFakeEmailEvent extends Command
{
    protected $signature = 'platform:mail-fake-event {school : School id, or the word platform for an identity-level message} {message : Email message id} {type : delivered|deferred|soft_bounce|hard_bounce|complaint|dropped} {--bounce-class= : e.g. mailbox_unknown, provider_suppressed}';

    protected $description = 'LOCAL ONLY: deliver a signed fake provider event for one message (ADR 0055).';

    public function handle(TenantContext $context): int
    {
        if (! App::environment(['local', 'testing']) || config('email.events.adapter') !== 'fake') {
            $this->error('Fake provider events exist only in local/testing with MAIL_PROVIDER_EVENTS=fake.');

            return self::FAILURE;
        }

        $find = fn () => EmailMessage::query()->find((string) $this->argument('message'))?->provider_message_id;
        if ($this->argument('school') === 'platform') {
            $providerId = app(PlatformEmailScope::class)->run($find);
        } else {
            $school = School::query()->find((string) $this->argument('school'));
            $providerId = $school === null ? null : $context->withSchool($school, $find);
        }

        if ($providerId === null) {
            $this->error('No submitted message with a provider id.');

            return self::FAILURE;
        }

        $body = (string) json_encode(['events' => [array_filter([
            'id' => 'fake-'.bin2hex(random_bytes(8)),
            'type' => (string) $this->argument('type'),
            'message_id' => $providerId,
            'occurred_at' => now()->toIso8601String(),
            'bounce_class' => $this->option('bounce-class'),
        ])]]);

        $secret = (string) (config('email.events.secrets')[0] ?? '');
        $request = Request::create('/api/integrations/email-provider/events', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'HTTP_'.strtoupper(str_replace('-', '_', FakeEmailEventAdapter::HEADER)) => FakeEmailEventAdapter::sign($body, $secret),
        ], content: $body);

        $response = app(HttpKernel::class)->handle($request);
        $this->info('Webhook answered HTTP '.$response->getStatusCode().'.');

        return $response->getStatusCode() === 202 ? self::SUCCESS : self::FAILURE;
    }
}
