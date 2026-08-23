<?php

namespace App\Support\Observability;

/**
 * Phase 0C.4 section 18: the fixed, documented queue topology. A queue
 * name is chosen from here, never invented ad hoc at a dispatch call
 * site -- this is a naming CONVENTION (self-documenting, type-safe),
 * not infrastructure provisioning; Redis/the database queue table
 * create a "queue" implicitly the first time something is pushed to a
 * given name, so listing a case here does not itself create anything
 * or cost anything.
 *
 * Only `Default` and `Integrations` have real traffic today
 * (`App\Jobs\ProcessOutboxEventJob`, `App\Jobs\DeliverWebhookJob`
 * respectively) -- the rest are RESERVED names for the first future
 * module that needs them (root CLAUDE.md rule 2: no speculative
 * infrastructure; a reserved enum case is documentation, not
 * infrastructure).
 */
enum QueueName: string
{
    /** General-purpose background work with no more specific category yet -- App\Jobs\ProcessOutboxEventJob. */
    case Default = 'default';

    /** Outbound calls to third-party systems -- App\Jobs\DeliverWebhookJob and any future outbound integration adapter (ADR 0018). */
    case Integrations = 'integrations';

    /** Reserved: future email/SMS/WhatsApp/in-app notification delivery jobs. None exist yet -- App\Support\Events\Consumers\NotifyActorOfSettingChangeConsumer currently runs synchronously inline, not as its own queued job. */
    case Notifications = 'notifications';

    /** Reserved: future AI-Gateway-triggered asynchronous work. None exists yet -- App\Support\Ai\AiGatewayClient calls are synchronous inline HTTP calls, not queued jobs. */
    case Ai = 'ai';

    /** Reserved: low-priority background work (e.g. a future scheduled pruning job) that should never compete with time-sensitive queues. */
    case Low = 'low';

    /** Reserved: future high-priority financial/compliance-sensitive jobs, once a module needing that guarantee exists. */
    case Critical = 'critical';
}
