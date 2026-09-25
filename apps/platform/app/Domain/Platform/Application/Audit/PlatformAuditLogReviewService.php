<?php

namespace App\Domain\Platform\Application\Audit;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\PlatformAuditEventEntry;
use App\Support\Audit\PlatformAuditEventReader;
use App\Support\Auth\Mfa\Exceptions\MfaRequiredNotEnrolledException;
use App\Support\Auth\Mfa\Exceptions\MfaStepUpRequiredException;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0N.7 (ADR 0046 sections 7-8): reviewing the platform audit ledger
 * -- Highly Sensitive, context-neutral, read-only.
 *
 * 1. `platform.audit.view` (a platform capability: never implied by a
 *    School, Group or elevation);
 * 2. the EXISTING MFA assurance (owner decision, Phase 0N.7): an active
 *    factor and a current `mfa_verified_at` within the configured window,
 *    checked with the same MfaChallengeService calls RequireMfa makes and
 *    refused with its same exceptions/codes. No fresh code per page --
 *    unlike starting an elevation, this is a read;
 * 3. one page from PlatformAuditEventReader (envelope only);
 * 4. exactly one `platform.audit_log.viewed` event, metadata `paged` and
 *    `resultCount` only (the School review's `compliance.audit_log.viewed`
 *    precedent). A refused review writes nothing.
 *
 * Establishes no TenantContext, uses no ElevationContext and no Group.
 */
class PlatformAuditLogReviewService
{
    public const VIEW_CAPABILITY = 'platform.audit.view';

    public const ACCESS_EVENT = 'platform.audit_log.viewed';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly MfaChallengeService $mfa,
        private readonly PlatformAuditEventReader $reader,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{fields: list<string>, entries: list<array<string, string|null>>, nextCursor: string|null, pageSize: int}
     */
    public function review(Request $request, User $actor, ?string $cursor = null): array
    {
        if (! $this->capabilities->canPlatform($actor, self::VIEW_CAPABILITY)) {
            throw new AccessDeniedHttpException('This account cannot review the platform audit log.');
        }

        if (! $this->mfa->userHasActiveFactor($actor)) {
            throw new MfaRequiredNotEnrolledException;
        }

        if (! $this->mfa->hasValidAssurance($request)) {
            throw new MfaStepUpRequiredException;
        }

        $page = $this->reader->page($cursor);

        $this->audit->platform(self::ACCESS_EVENT, actor: $actor, metadata: [
            'paged' => $cursor !== null,
            'resultCount' => count($page->entries),
        ]);

        return [
            'fields' => PlatformAuditEventEntry::FIELDS,
            'entries' => array_map(fn (PlatformAuditEventEntry $entry): array => $entry->toArray(), $page->entries),
            'nextCursor' => $page->nextCursor,
            'pageSize' => PlatformAuditEventReader::PAGE_SIZE,
        ];
    }
}
