<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5A.10 -- explicit, server-side-only dispatch mode. Deliberately
 * separate from both CommunicationPriority and CommunicationRequirement:
 * CRITICAL priority does not imply Emergency, and REQUIRED requirement
 * does not imply Emergency (though Emergency itself must be Required --
 * see AnnouncementService's dispatch-mode validation). Never inferred
 * from priority, requirement, wording, template, audience size, sender
 * role, or any automated classification -- always an explicit human
 * choice, gated by the `communications.emergency` capability.
 */
enum CommunicationDispatchMode: string
{
    case Standard = 'standard';
    case Emergency = 'emergency';
}
