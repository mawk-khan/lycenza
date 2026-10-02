<?php

namespace Tests\Feature\Retention;

use App\Support\Retention\GuardianRetention;
use App\Support\Retention\ReferencingRows;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3C (E21.2G G1): every table that references a Guardian-rooted parent
 * is classified here, read against the live FK catalog. The Guardian purge
 * already fails closed on an unclassified table (any referencing row keeps
 * the Guardian, even an ON DELETE CASCADE one: the root is deleted last,
 * after the handled rows); this makes the decision explicit.
 */
class GuardianRetentionClassificationTest extends TestCase
{
    /** parent => referencing table => how G1 treats it */
    public const CLASSIFICATION = [
        'guardians' => [
            'guardian_contacts' => 'G1: Guardian personal data, purged with the Guardian',
            'documents' => 'D5 inherits the Guardian: purged with it (DocumentParentRetention)',
            'student_guardian_account_links' => 'D6/I4: a revoked link past AUTHORITY_HISTORY_RETENTION_YEARS goes with the Guardian; an active or younger one keeps it',
            'communication_domain_consent_events' => 'G1/C4: the Guardian subject\'s consent evidence, purged with the Guardian (Communications participant, Guardian-floored function)',
            'communication_domain_preferences' => 'G1/C5: purged with the Guardian (Communications participant)',
            'student_guardian_relationships' => 'retained, blocks: any relationship stops the clock (the Guardian is related, never eligible)',
            'communication_thread_participants' => 'retained, blocks: Communications D3 removes it with its thread first',
            'communication_recipients' => 'retained, blocks: Communications D3 removes it with its message first',
            'communication_announcement_recipients' => 'retained, blocks: Communications D3 removes it with its announcement first',
            'communication_announcement_domain_audience_members' => 'retained, blocks: Communications D3 removes it with its announcement first (never cascaded by the Guardian purge)',
            'communication_delivery_policy_decisions' => 'retained, blocks: Communications D3 (1 y)',
            'identity_account_invitations' => 'retained, blocks: an ended one expires 7 d after it ended (E21.3B); a usable one keeps the Guardian',
        ],
        'guardian_contacts' => [],
        'student_guardian_account_links' => [],
    ];

    #[Test]
    public function every_reference_to_a_guardian_rooted_parent_is_classified(): void
    {
        $references = app(ReferencingRows::class);

        foreach (array_keys(self::CLASSIFICATION) as $parent) {
            $live = array_values(array_unique(array_column($references->to($parent), 'table')));
            sort($live);
            $classified = array_keys(self::CLASSIFICATION[$parent]);
            sort($classified);

            $this->assertSame($classified, $live, "references to {$parent} changed: classify them for E21.2G G1");
        }
    }

    #[Test]
    public function the_participants_remove_exactly_the_participant_classified_tables(): void
    {
        $participants = array_merge(...array_map(fn ($p) => $p->tables(), app(GuardianRetention::class)->participants()));
        sort($participants);

        $this->assertSame(['communication_domain_consent_events', 'communication_domain_preferences'], $participants);
    }
}
