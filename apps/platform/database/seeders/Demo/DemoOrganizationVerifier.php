<?php

namespace Database\Seeders\Demo;

use App\Domain\Identity\Application\Access\SensitiveDataCatalogue;
use App\Domain\Platform\Application\Groups\GroupGovernanceAccess;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\NavigationPresentation;
use App\Support\Presentation\BrandResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12E-D1: proves the canonical Organization demo world after every
 * build -- the Network topology, all four relationships plus the legacy
 * unclassified membership, the standalone customers, and that each demo
 * persona sees exactly what it should (resolved through the real resolvers,
 * never by trusting the seed). DEMO-ONLY: run by DemoSeeder after
 * `ddev demo-reset` and by the automated suite; never a production health
 * check, endpoint or command.
 */
final class DemoOrganizationVerifier
{
    /** @return list<string> problems (empty = the canonical world is intact) */
    public function problems(): array
    {
        $problems = [];
        $check = function (bool $ok, string $message) use (&$problems): void {
            if (! $ok) {
                $problems[] = $message;
            }
        };

        $network = SchoolGroup::query()->where('slug', DemoOrganizationData::NETWORK_SLUG)->where('status', SchoolGroup::STATUS_ACTIVE)->first();
        if ($network === null) {
            return ['the Northstar Education Network is missing'];
        }
        $check(DB::table('school_group_regions')->where('school_group_id', $network->id)->orderBy('code')->pluck('code')->all() === ['KARNATAKA', 'TELANGANA'], 'Regions are not exactly Telangana and Karnataka');
        $zones = DB::table('school_group_zones as z')->join('school_group_regions as r', 'r.id', '=', 'z.region_id')
            ->where('z.school_group_id', $network->id)->orderBy('z.code')
            ->selectRaw('z.code as zone_code, r.code as region_code')->pluck('region_code', 'zone_code')->all();
        $check($zones === ['BLR-NORTH' => 'KARNATAKA', 'HYD-CENTRAL' => 'TELANGANA', 'HYD-WEST' => 'TELANGANA'], 'Zones are not exactly Hyderabad Central/West (Telangana) and Bengaluru North (Karnataka)');

        $relationships = DB::table('school_group_members')->where('school_group_id', $network->id)->pluck('relationship_type')->all();
        foreach (['owned', 'franchise', 'managed', 'affiliate'] as $type) {
            $check(in_array($type, $relationships, true), "no {$type} membership");
        }
        $check(in_array(null, $relationships, true), 'no legacy unclassified membership');
        $check(count($relationships) === 6, 'the Network does not have 6 member Schools');

        $school = fn (string $slug): ?School => School::query()->where('slug', $slug)->first();
        $campuses = fn (School $s): int => app(TenantContext::class)->withSchool($s, fn () => Campus::query()->where('school_id', $s->id)->count());
        foreach (['greenfield-public' => 1, 'horizon-academy' => 3] as $slug => $count) {
            $s = $school($slug);
            $check($s !== null && DB::table('school_group_members')->where('school_id', $s->id)->doesntExist(), "{$slug} is not a standalone School");
            $check($s !== null && $campuses($s) === $count, "{$slug} does not have {$count} Campus(es)");
        }
        $gachibowli = $school('northstar-gachibowli');
        $check($gachibowli !== null && $campuses($gachibowli) === 2, 'the owned Network School does not have 2 Campuses');
        // campus_services is RLS-protected: read it one School at a time.
        $services = School::query()->get()->flatMap(fn (School $s) => app(TenantContext::class)->withSchool($s,
            fn () => DB::table('campus_services')->where('school_id', $s->id)->pluck('service_code')->all()))->unique()->sort()->values()->all();
        $check($services === ['boarding', 'day_school', 'early_years', 'meals', 'transport'], 'not every Campus service code is demonstrated');

        // Personas, resolved through the real resolvers.
        $visible = function (string $email) use ($network): ?array {
            $user = User::query()->where('email', $email)->first();
            $scope = $user === null ? null : app(GroupGovernanceAccess::class)->viewScope($user, $network);

            return $scope === null ? null : collect(app(SchoolGroupDirectoryReadService::class)->directory($network, [], 1, $scope)->items())->pluck('code')->sort()->values()->all();
        };
        $check($visible('network.viewer@example.test') === ['NAC-SEC', 'NEL-JBH', 'NHS-MYS', 'NIS-GCB', 'NPS-HBL', 'NSW-WGL'], 'the whole-Network viewer does not see all six Schools');
        $check($visible('network.region@example.test') === ['NAC-SEC', 'NEL-JBH', 'NIS-GCB', 'NSW-WGL'], 'the Telangana viewer does not see exactly Telangana');
        $check($visible('network.zone@example.test') === ['NAC-SEC', 'NEL-JBH'], 'the Hyderabad Central viewer does not see exactly its Zone');
        $check($visible('network.shared@example.test') === ['NPS-HBL'], 'the shared persona does not see exactly Karnataka');

        $manager = User::query()->where('email', 'network.manager@example.test')->first();
        $check($manager !== null && app(GroupGovernanceAccess::class)->mayManage($manager, $network), 'the Network Manager cannot manage the Network');
        $resolver = app(CapabilityResolver::class);
        foreach (DB::table('school_group_members')->where('school_group_id', $network->id)->pluck('school_id') as $schoolId) {
            $member = School::query()->findOrFail($schoolId);
            $check($manager !== null && $resolver->schoolCapabilities($manager, $member) === [], 'the Network Manager holds a School capability');
        }

        $cashier = User::query()->where('email', 'gachibowli.cashier@example.test')->first();
        if ($cashier !== null && $gachibowli !== null) {
            $main = app(TenantContext::class)->withSchool($gachibowli, fn () => Campus::query()->where('code', 'GCB-MAIN')->value('id'));
            $byCampus = $resolver->campusCapabilities($cashier, $gachibowli);
            $check(array_keys($byCampus) === [$main] && in_array('finance.payments.view', $byCampus[$main], true), 'the Cashier is not effective on exactly Gachibowli Main Campus');
            $check(! in_array('finance.payments.view', $resolver->schoolCapabilities($cashier, $gachibowli), true), 'the Campus Cashier holds School-wide Finance access');
        } else {
            $problems[] = 'the Campus-scoped Cashier persona is missing';
        }

        $shared = User::query()->where('email', 'network.shared@example.test')->first();
        $secunderabad = $school('northstar-secunderabad');
        $check($shared !== null && $gachibowli !== null && in_array('students.view', $resolver->schoolCapabilities($shared, $gachibowli), true), 'the shared persona is not Principal of Gachibowli');
        $check($shared !== null && $secunderabad !== null && $resolver->schoolCapabilities($shared, $secunderabad) === [], 'the shared persona holds access in another School');

        // ORG.12F: presentation semantics (not every cosmetic value).
        $brands = app(BrandResolver::class);
        $jubilee = $school('northstar-jubilee-hills');
        $greenfield = $school('greenfield-public');
        $check($gachibowli !== null && $brands->forSchool($gachibowli)['source'] === 'network' && ($brands->forSchool($gachibowli)['network']['id'] ?? null) === $network->id, 'Gachibowli does not inherit the Northstar brand');
        $check($jubilee !== null && $brands->forSchool($jubilee)['source'] === 'school', 'Jubilee Hills does not keep its own brand');
        $check($greenfield !== null && $brands->forSchool($greenfield)['source'] === 'system', 'Greenfield is not on the system default');
        if ($gachibowli !== null) {
            $prefs = app(NavigationPresentation::class)->overrides($gachibowli)['staff_web'] ?? [];
            $check((bool) ($prefs['data-health']->hidden ?? false) && ($prefs['students']->label_override ?? null) === 'Learners', 'Gachibowli navigation profile is missing (hidden Data health, Learners)');
        }

        $check(count(SensitiveDataCatalogue::FAMILIES) === 8 && array_filter(array_column(SensitiveDataCatalogue::FAMILIES, 'configurable')) === [], 'the sensitive-data catalogue is not the eight non-configurable families');

        return $problems;
    }

    /** @return array<string, int|string> a compact topology summary, also used to compare resets */
    public function summary(): array
    {
        $network = SchoolGroup::query()->where('slug', DemoOrganizationData::NETWORK_SLUG)->firstOrFail();

        return [
            'network' => $network->name,
            'regions' => DB::table('school_group_regions')->where('school_group_id', $network->id)->count(),
            'zones' => DB::table('school_group_zones')->where('school_group_id', $network->id)->count(),
            'networkSchools' => DB::table('school_group_members')->where('school_group_id', $network->id)->count(),
            // The demo world's own Schools (other data may coexist, e.g. in the suite).
            'schools' => School::query()->where(fn ($q) => $q->where('slug', 'like', 'northstar-%')->orWhereIn('slug', ['greenfield-public', 'horizon-academy']))->count(),
            'groups' => SchoolGroup::query()->whereIn('slug', [DemoOrganizationData::NETWORK_SLUG, DemoDataBuilder::SCHOOL_GROUP_SLUG])->count(),
            'networkGrants' => DB::table('group_role_assignments')->where('school_group_id', $network->id)->whereNull('revoked_at')->count(),
        ];
    }
}
