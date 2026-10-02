<?php

namespace Tests\Feature\Retention;

use App\Support\Retention\ReferencingRows;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3E (E21.2G C1/C2/O2/O3/O4): every table that references a residual
 * row is classified here, read against the live FK catalog. The expiries
 * already fail closed on an unclassified table (any referencing row keeps
 * the row); this makes the decision explicit. A child the parent's own
 * delete removes must really be ON DELETE CASCADE.
 */
class ResidualRetentionClassificationTest extends TestCase
{
    /** parent => referencing table => how E21.3E treats it */
    public const CLASSIFICATION = [
        'communication_announcements' => [
            'communication_messages' => 'blocks: a message means the announcement was sent (D3 owns it)',
            'communication_announcement_recipients' => 'blocks: recipients exist only for a published announcement (D3)',
            'communication_announcement_audience_members' => 'owned: the draft audience goes with a never-sent announcement (cascade)',
            'communication_announcement_channels' => 'owned: goes with it (cascade)',
            'communication_attachments' => 'owned: goes with it, bytes after commit (cascade)',
            'communication_approval_requests' => 'owned: the approval history goes with it (cascade)',
            'communication_announcement_domain_audience_members' => 'owned: goes with it (cascade)',
            'communication_announcement_academic_cohorts' => 'owned: goes with it (cascade)',
        ],
        'communication_threads' => [
            'communication_thread_participants' => 'owned: participants go with an empty thread (cascade)',
            'communication_messages' => 'blocks: a thread with a message is content (D3), never empty',
            'communication_attachments' => 'blocks: an attachment is content',
        ],
        'transport_route_assignments' => [],
        'visitors' => [
            'visitor_visits' => 'O3: the visitor goes only once no visit remains',
        ],
        'visitor_visits' => [],
        'automation_executions' => [
            'automation_execution_attempts' => 'owned: the execution\'s own history goes with it (cascade)',
            'automation_review_items' => 'owned: goes with it (cascade)',
        ],
    ];

    #[Test]
    public function every_reference_to_a_residual_row_is_classified(): void
    {
        $references = app(ReferencingRows::class);

        foreach (array_keys(self::CLASSIFICATION) as $parent) {
            $live = array_values(array_unique(array_column($references->to($parent), 'table')));
            sort($live);
            $classified = array_keys(self::CLASSIFICATION[$parent]);
            sort($classified);

            $this->assertSame($classified, $live, "references to {$parent} changed: classify them for E21.3E");
        }
    }

    #[Test]
    public function every_owned_child_is_removed_by_its_parents_cascade(): void
    {
        foreach (self::CLASSIFICATION as $parent => $children) {
            foreach ($children as $child => $treatment) {
                if (! str_starts_with($treatment, 'owned')) {
                    continue;
                }
                $action = DB::selectOne("SELECT confdeltype FROM pg_constraint WHERE contype = 'f' AND conrelid = ?::regclass AND confrelid = ?::regclass", ['public.'.$child, 'public.'.$parent])->confdeltype;
                $this->assertSame('c', $action, "{$child} -> {$parent} must be ON DELETE CASCADE to be owned");
            }
        }
    }
}
