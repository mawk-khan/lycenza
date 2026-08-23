<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use RuntimeException;

/**
 * audience_type => resolver map, mirroring
 * App\Domain\Communications\Application\Channels\CommunicationChannelRegistry's
 * shape exactly. Bound as a singleton in App\Providers\PlatformServiceProvider
 * with IndividualMembersAudienceResolver and SchoolWideAudienceResolver
 * registered (§9). A future CampusAudienceResolver/GuardianAudienceResolver/
 * ClassAudienceResolver/... registers here without any change to
 * AnnouncementService (§26).
 */
class CommunicationAudienceResolverRegistry
{
    /** @var array<string, CommunicationAudienceResolver> */
    private array $resolvers = [];

    public function register(CommunicationAudienceResolver $resolver): void
    {
        $this->resolvers[$resolver->type()->value] = $resolver;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        $type = CommunicationAudienceType::from($announcement->audience_type);
        $resolver = $this->resolvers[$type->value] ?? null;

        if ($resolver === null) {
            throw new RuntimeException("No audience resolver registered for '{$type->value}'.");
        }

        return $resolver->resolve($announcement);
    }
}
