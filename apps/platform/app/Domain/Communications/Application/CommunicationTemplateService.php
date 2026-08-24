<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationTemplate;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.4 §4/§10 -- the sole write path for the Template
 * lifecycle, mirroring AnnouncementService's shape (validate -> write
 * -> audit -> emit event -- though no domain event is emitted here;
 * templates are administrative source content, not a Communication
 * Hub delivery-triggering aggregate, matching AnnouncementService::updateDraft()'s
 * own precedent of audit-only, no event, for a content edit).
 */
class CommunicationTemplateService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(
        School $school,
        User $creator,
        string $name,
        string $body,
        ?string $description = null,
        ?string $subject = null,
        ?CommunicationPriority $priority = null,
    ): CommunicationTemplate {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $creator, $name, $body, $description, $subject, $priority) {
            $template = CommunicationTemplate::query()->create([
                'school_id' => $school->id,
                'created_by_user_id' => $creator->id,
                'name' => $name,
                'description' => $description,
                'template_type' => 'announcement',
                'subject' => $subject,
                'body' => $body,
                'priority' => $priority?->value,
                'status' => 'active',
            ]);

            $this->audit->school($school, 'communication_template.created', actor: $creator, subject: $template);

            return $template->fresh();
        }));
    }

    public function update(
        CommunicationTemplate $template,
        User $actor,
        ?string $name = null,
        ?string $body = null,
        ?string $description = null,
        ?string $subject = null,
        ?CommunicationPriority $priority = null,
    ): CommunicationTemplate {
        return $this->context->withSchool($template->school, fn () => DB::transaction(function () use ($template, $actor, $name, $body, $description, $subject, $priority) {
            $template->update(array_filter([
                'name' => $name,
                'body' => $body,
                'description' => $description,
                'subject' => $subject,
                'priority' => $priority?->value,
            ], fn ($value) => $value !== null));

            $this->audit->school($template->school, 'communication_template.updated', actor: $actor, subject: $template);

            return $template->fresh();
        }));
    }

    public function setActive(CommunicationTemplate $template, User $actor, bool $active): CommunicationTemplate
    {
        return $this->context->withSchool($template->school, fn () => DB::transaction(function () use ($template, $actor, $active) {
            $template->update(['status' => $active ? 'active' : 'inactive']);

            $this->audit->school(
                $template->school,
                $active ? 'communication_template.activated' : 'communication_template.deactivated',
                actor: $actor,
                subject: $template,
            );

            return $template->fresh();
        }));
    }
}
