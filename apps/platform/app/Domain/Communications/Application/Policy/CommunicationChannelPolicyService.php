<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationChannelPolicy;
use App\Domain\Communications\Infrastructure\CommunicationPreference;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5A.5 §18/§19 -- the ONE authoritative server-side decision
 * path for "should a delivery be planned for this recipient+channel."
 * Never duplicated in AnnouncementService, a job, a channel driver, or
 * Vue -- App\Domain\Communications\Application\AnnouncementService::publish()
 * is the only caller that plans deliveries, and it calls this
 * exclusively.
 *
 * Performance (brief §55): this class is resolved fresh per HTTP
 * request/command run (never bound as a singleton, same as
 * AnnouncementService itself) and keeps a private IN-MEMORY cache for
 * the lifetime of that one instance -- safe because it never outlives
 * one request/command invocation, so there is no cross-request
 * staleness risk. School policy is loaded ONCE per School (a handful
 * of rows); preferences are loaded in ONE batched query per audience
 * chunk via preloadPreferences(), mirroring the exact batching
 * App\Domain\Communications\Application\AnnouncementService::publish()
 * already uses for email-address resolution.
 */
class CommunicationChannelPolicyService
{
    /** @var array<string, array<string, CommunicationChannelPolicy>> schoolId => channel => override row */
    private array $policyCache = [];

    /** @var array<string, array<string, string|null>> "schoolId:channel" => [schoolMembershipId => preference|null] */
    private array $preferenceCache = [];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Phase 5A.5 §16: the system default when no School override
     * exists. Chosen to exactly match the behavior every School
     * already had before this checkpoint existed (brief §14) --
     * IN_APP and EMAIL both fully permitted, EMAIL additionally
     * respects recipient opt-out (the only channel with a meaningful
     * per-member preference, brief §13). A channel with no meaningful
     * policy yet (SMS/WhatsApp/Push) defaults fully closed -- there is
     * no driver to deliver through regardless.
     */
    public static function defaultPolicy(CommunicationChannel $channel): CommunicationChannelPolicyView
    {
        return match ($channel) {
            CommunicationChannel::InApp => new CommunicationChannelPolicyView(
                optionalAllowed: true, requiredAllowed: true, recipientCanOptOut: false, isOverride: false,
            ),
            CommunicationChannel::Email => new CommunicationChannelPolicyView(
                optionalAllowed: true, requiredAllowed: true, recipientCanOptOut: true, isOverride: false,
            ),
            default => new CommunicationChannelPolicyView(
                optionalAllowed: false, requiredAllowed: false, recipientCanOptOut: false, isOverride: false,
            ),
        };
    }

    public function policyFor(School $school, CommunicationChannel $channel): CommunicationChannelPolicyView
    {
        if (! isset($this->policyCache[$school->id])) {
            $this->policyCache[$school->id] = $this->context->withSchool(
                $school,
                fn () => CommunicationChannelPolicy::query()->where('school_id', $school->id)->get()->keyBy('channel')->all(),
            );
        }

        $override = $this->policyCache[$school->id][$channel->value] ?? null;

        if ($override === null) {
            return self::defaultPolicy($channel);
        }

        return new CommunicationChannelPolicyView(
            optionalAllowed: $override->optional_allowed,
            requiredAllowed: $override->required_allowed,
            recipientCanOptOut: $override->recipient_can_opt_out,
            isOverride: true,
        );
    }

    /**
     * One batched query per (School, channel, NOT-yet-cached membership
     * ids) -- called once per audience chunk, never per recipient
     * (brief §55/§56).
     *
     * @param  array<int, string>  $schoolMembershipIds
     */
    public function preloadPreferences(School $school, array $schoolMembershipIds, CommunicationChannel $channel): void
    {
        $cacheKey = "{$school->id}:{$channel->value}";
        $this->preferenceCache[$cacheKey] ??= [];

        $missing = array_values(array_diff($schoolMembershipIds, array_keys($this->preferenceCache[$cacheKey])));

        if ($missing === []) {
            return;
        }

        $rows = $this->context->withSchool(
            $school,
            fn () => CommunicationPreference::query()
                ->where('school_id', $school->id)
                ->where('channel', $channel->value)
                ->whereIn('school_membership_id', $missing)
                ->get(['school_membership_id', 'preference']),
        );

        foreach ($missing as $id) {
            $this->preferenceCache[$cacheKey][$id] = null;
        }

        foreach ($rows as $row) {
            $this->preferenceCache[$cacheKey][$row->school_membership_id] = $row->preference;
        }
    }

    /**
     * Phase 5A.5 §6's precedence, implemented exactly in order:
     * 1. eligible? (a null $schoolMembershipId means the caller
     *    already determined the recipient is not a currently-active
     *    member -- see AnnouncementService::publish())
     * 2. IN_APP is always canonical (brief §10) once eligible --
     *    never gated by school policy or preference.
     * 3. any other unsupported channel is closed (defensive; today
     *    only in_app/email are ever requestable --
     *    AnnouncementService::SUPPORTED_CHANNELS).
     * 4. School policy, branched by the communication's own
     *    Requirement -- REQUIRED checks required_allowed,
     *    OPTIONAL checks optional_allowed. These are independent
     *    flags; REQUIRED never bypasses a School's own
     *    required_allowed=false (brief §24).
     * 5. Only for OPTIONAL + recipient_can_opt_out=true is the
     *    recipient's own preference ever consulted.
     *
     * Call preloadPreferences() for this (School, channel) before
     * calling evaluate() for any recipient on that channel, or the
     * preference lookup below simply misses cache and is treated as
     * "no preference" (inherit default) -- callers within this module
     * always do (AnnouncementService::publish()); this method itself
     * performs no I/O to stay a pure, cheap-to-call decision function
     * inside a large recipient loop.
     */
    public function evaluate(
        School $school,
        ?string $schoolMembershipId,
        CommunicationChannel $channel,
        CommunicationRequirement $requirement,
    ): CommunicationPolicyDecision {
        if ($schoolMembershipId === null) {
            return CommunicationPolicyDecision::suppress(CommunicationPolicyReason::RecipientIneligible);
        }

        if ($channel === CommunicationChannel::InApp) {
            return CommunicationPolicyDecision::allow(CommunicationPolicyReason::CanonicalInApp);
        }

        if ($channel !== CommunicationChannel::Email) {
            return CommunicationPolicyDecision::suppress(CommunicationPolicyReason::UnsupportedChannel);
        }

        $policy = $this->policyFor($school, $channel);

        if ($requirement === CommunicationRequirement::Required) {
            return $policy->requiredAllowed
                ? CommunicationPolicyDecision::allow()
                : CommunicationPolicyDecision::suppress(CommunicationPolicyReason::SchoolRequiredChannelDisabled);
        }

        if (! $policy->optionalAllowed) {
            return CommunicationPolicyDecision::suppress(CommunicationPolicyReason::SchoolOptionalChannelDisabled);
        }

        if ($policy->recipientCanOptOut) {
            $preference = $this->preferenceCache["{$school->id}:{$channel->value}"][$schoolMembershipId] ?? null;

            if ($preference === 'disabled') {
                return CommunicationPolicyDecision::suppress(CommunicationPolicyReason::RecipientPreferenceDisabled);
            }
        }

        return CommunicationPolicyDecision::allow();
    }

    /**
     * Phase 5B.1 §16/§18/§20: the SAME policy engine as evaluate()
     * above, for a party with NO SchoolMembership at all (a Guardian,
     * today) -- deliberately NOT a second implementation, just a
     * second entry point that skips evaluate()'s membership-null
     * short-circuit (which exists to suppress a NO-LONGER-active
     * member, not to universally suppress every channel for a party
     * that was never expected to have a membership in the first
     * place). IN_APP is still ALWAYS suppressed here (brief §16: no
     * fake account inbox); EMAIL is evaluated against the exact same
     * School channel-policy row evaluate() consults, with the
     * recipient-preference step skipped entirely (brief §18: no
     * SchoolMembership means no personal CommunicationPreference row
     * could possibly exist for this party -- there is nothing to
     * consult, not "nothing configured yet").
     */
    public function evaluateForDomainParty(
        School $school,
        CommunicationChannel $channel,
        CommunicationRequirement $requirement,
    ): CommunicationPolicyDecision {
        if ($channel === CommunicationChannel::InApp) {
            return CommunicationPolicyDecision::suppress(CommunicationPolicyReason::RecipientIneligible);
        }

        if ($channel !== CommunicationChannel::Email) {
            return CommunicationPolicyDecision::suppress(CommunicationPolicyReason::UnsupportedChannel);
        }

        $policy = $this->policyFor($school, $channel);

        if ($requirement === CommunicationRequirement::Required) {
            return $policy->requiredAllowed
                ? CommunicationPolicyDecision::allow()
                : CommunicationPolicyDecision::suppress(CommunicationPolicyReason::SchoolRequiredChannelDisabled);
        }

        return $policy->optionalAllowed
            ? CommunicationPolicyDecision::allow()
            : CommunicationPolicyDecision::suppress(CommunicationPolicyReason::SchoolOptionalChannelDisabled);
    }
}
