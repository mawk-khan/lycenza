<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Infrastructure\CommunicationConversationPolicy;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5D.1 §16 -- the ONE authoritative read path for a School's
 * private-conversation safeguarding policy, mirroring
 * CommunicationChannelPolicyService::policyFor()'s exact shape
 * (per-instance cache, safe to resolve fresh per request/command --
 * never bound as a singleton).
 */
class CommunicationConversationPolicyService
{
    /** @var array<string, CommunicationConversationPolicy|null> schoolId => override row (or null if none exists) */
    private array $cache = [];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Phase 5D.1 §16: Guardian conversations are ALLOWED by default
     * (still gated by the `communications.conversations.guardians`
     * capability -- a School toggle disabling them entirely is an
     * explicit, additional restriction, not the baseline). Student
     * conversations are DISALLOWED by default -- a School must
     * explicitly opt in on top of the separate
     * `communications.conversations.students` capability grant.
     */
    public static function defaultPolicy(): CommunicationConversationPolicyView
    {
        return new CommunicationConversationPolicyView(
            allowGuardianConversations: true,
            allowStudentConversations: false,
            isOverride: false,
        );
    }

    public function policyFor(School $school): CommunicationConversationPolicyView
    {
        if (! array_key_exists($school->id, $this->cache)) {
            $this->cache[$school->id] = $this->context->withSchool(
                $school,
                fn () => CommunicationConversationPolicy::query()->where('school_id', $school->id)->first(),
            );
        }

        $override = $this->cache[$school->id];

        if ($override === null) {
            return self::defaultPolicy();
        }

        return new CommunicationConversationPolicyView(
            allowGuardianConversations: $override->allow_guardian_conversations,
            allowStudentConversations: $override->allow_student_conversations,
            isOverride: true,
        );
    }
}
