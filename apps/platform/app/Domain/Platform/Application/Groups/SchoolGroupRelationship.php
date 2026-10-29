<?php

namespace App\Domain\Platform\Application\Groups;

/**
 * ORG.12D (ADR 0080 §9, OD-12.9): how a School relates to a School Group
 * (Network) -- stored on the membership, never on the School. Code-owned and
 * closed; `school_group_members_relationship_check` lists exactly these.
 *
 * A classification describes a business relationship and grants NOTHING:
 * owned, franchise, managed and affiliate Schools are equally isolated, and
 * no capability, School membership, lifecycle or entitlement follows from it.
 * A legacy membership may be unclassified (NULL) until a platform operator
 * classifies it; every new membership is classified.
 */
enum SchoolGroupRelationship: string
{
    case OWNED = 'owned';
    case FRANCHISE = 'franchise';
    case MANAGED = 'managed';
    case AFFILIATE = 'affiliate';

    public function label(): string
    {
        return match ($this) {
            self::OWNED => 'Owned',
            self::FRANCHISE => 'Franchise',
            self::MANAGED => 'Managed',
            self::AFFILIATE => 'Affiliate',
        };
    }

    /** @return list<array{code: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['code' => $r->value, 'label' => $r->label()], self::cases());
    }
}
