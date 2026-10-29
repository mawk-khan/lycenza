<?php

namespace App\Support\Presentation;

use App\Models\School;
use App\Models\SchoolGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12F (ADR 0080 §11.6-§11.7, OD-12.17/OD-12.18): a School's EFFECTIVE
 * brand, per field:
 *   1. the School's own value (label, accent), when set;
 *   2. else the EXPLICITLY selected Network brand source -- only while the
 *      School is still a member (a composite key guarantees it) and the Group
 *      is active; an archived source is ignored and reported as attention;
 *   3. else the system default.
 * Relationship type never decides anything here, and nothing is copied
 * between Network and School rows. Presentation only: the canonical School
 * name stays the School's own and is shown alongside. At most two queries.
 */
final class BrandResolver
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{label: ?string, accent: string, foreground: string, source: string, network: ?array{id: string, name: string}, attention: ?string, school: array{label: ?string, accent: ?string}, networkValues: ?array{label: ?string, accent: ?string}}
     */
    public function forSchool(School $school): array
    {
        $own = $this->context->withSchool($school, fn () => DB::table('school_brand_profiles')
            ->where('school_id', $school->id)->first(['brand_source_group_id', 'display_label', 'primary_accent']));

        $ownLabel = $own->display_label ?? null;
        $ownAccent = $own->primary_accent ?? null;
        $sourceId = $own->brand_source_group_id ?? null;

        $network = null;
        $networkValues = null;
        $attention = null;
        if ($sourceId !== null) {
            $row = DB::table('school_groups as g')->leftJoin('school_group_brand_profiles as b', 'b.school_group_id', '=', 'g.id')
                ->where('g.id', $sourceId)
                ->first(['g.id', 'g.name', 'g.status', 'b.display_label', 'b.primary_accent']);
            if ($row !== null && $row->status === SchoolGroup::STATUS_ACTIVE) {
                $network = ['id' => (string) $row->id, 'name' => (string) $row->name];
                $networkValues = ['label' => $row->display_label, 'accent' => $row->primary_accent];
            } else {
                $attention = 'The selected Network brand is no longer active; the School and system defaults apply.';
            }
        }

        $label = $ownLabel ?? $networkValues['label'] ?? null;
        $accent = $ownAccent ?? $networkValues['accent'] ?? null;
        $networkSupplies = $networkValues !== null && ($networkValues['label'] !== null || $networkValues['accent'] !== null);
        $source = match (true) {
            $ownLabel !== null && $ownAccent !== null => 'school',
            $ownLabel !== null || $ownAccent !== null => $networkSupplies ? 'mixed' : 'school',
            $networkSupplies => 'network',
            default => 'system',
        };

        return [
            'label' => $label,
            'accent' => $accent ?? BrandColor::SYSTEM_ACCENT,
            'foreground' => BrandColor::foreground($accent ?? BrandColor::SYSTEM_ACCENT),
            'source' => $source,
            'network' => $network,
            'attention' => $attention,
            'school' => ['label' => $ownLabel, 'accent' => $ownAccent],
            'networkValues' => $networkValues,
        ];
    }
}
