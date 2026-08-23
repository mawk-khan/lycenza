<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;

/**
 * Phase 5A.2 §8: the audience-resolution provider boundary, the same
 * "register one implementation per case, dispatch through a registry"
 * shape App\Domain\Communications\Application\Channels\
 * CommunicationChannelDriver already established for delivery channels
 * (Phase 5A.1 §2.8) -- deliberately not the same interface, since
 * resolving WHO an announcement targets and delivering a message to a
 * known recipient are different concerns (brief §8: "Audience
 * definition and channel destination resolution are separate
 * concerns"). A resolver returns stable internal identities
 * (User ids), never a provider address.
 */
interface CommunicationAudienceResolver
{
    public function type(): CommunicationAudienceType;

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience;
}
