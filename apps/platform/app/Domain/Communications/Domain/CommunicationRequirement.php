<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5A.5 §7/§9 -- deliberately separate from CommunicationPriority.
 * `Optional` (default): recipient preferences may suppress an eligible
 * secondary channel. `Required`: recipient preference is not an
 * opt-out on a channel School policy already permits for required
 * communication -- it does NOT mean every requested channel is
 * guaranteed to technically succeed (school policy and global
 * technical availability, e.g. COMMUNICATION_EMAIL_ENABLED, remain
 * fully authoritative regardless of this value). See
 * App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService.
 */
enum CommunicationRequirement: string
{
    case Optional = 'optional';
    case Required = 'required';
}
