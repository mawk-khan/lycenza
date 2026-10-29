<script setup lang="ts">
// ORG.12H (ADR 0080 §13.3): a School Group's Network MIS -- read-only, Group
// context only, within the viewer's Network scope (whole Group, Region or
// Zone). Member Schools and the Group's own governance metadata only: no
// Campus, setup, person, money or other business data, and no link into a
// School (entering one stays the Group page's elevated access).
import GroupDirectory from '../../../Components/Groups/GroupDirectory.vue';
import type {
    DirectoryRow,
    GovernanceScope,
    GroupOverview,
    Option,
    Paginated,
} from '../../../groupNetwork';

interface DistributionZone {
    code: string;
    name: string;
    status: string;
    schools: number;
}

const props = defineProps<{
    group: { id: string; name: string };
    scope: GovernanceScope;
    overview: GroupOverview;
    lifecycle: Record<string, number>;
    distribution: {
        regions: Array<{
            code: string;
            name: string;
            status: string;
            schools: number;
            regionOnly: number;
            zones: DistributionZone[];
        }>;
        zonesWithoutRegion: DistributionZone[];
        unplaced: number | null;
    };
    directory: Paginated<DirectoryRow>;
    filters: Record<string, string | undefined>;
    relationships: Option[];
    sorts: string[];
}>();

const pageUrl = `/app/groups/${props.group.id}/reports/network`;
const relationshipRows: Array<[string, string]> = [
    ['owned', 'Owned'],
    ['franchise', 'Franchise'],
    ['managed', 'Managed'],
    ['affiliate', 'Affiliate'],
    ['unclassified', 'Unclassified'],
];
const lifecycleLabels: Record<string, string> = {
    provisioning: 'Provisioning',
    active: 'Active',
    suspended: 'Suspended',
    archived: 'Archived',
};
const attentionRows: Array<{ key: string; label: string; count: number }> = [
    {
        key: 'unclassified',
        label: 'Relationship not classified',
        count: props.overview.attention.unclassified,
    },
    {
        key: 'inactive_structure',
        label: 'Placed in an inactive Region or Zone',
        count: props.overview.attention.inInactiveStructure,
    },
    {
        key: 'not_active',
        label: 'School not active',
        count: props.overview.attention.schoolsNotActive,
    },
];
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/groups/${group.id}`">← {{ group.name }}</a>
        <h1 class="mt-2 text-xl font-semibold">Network report</h1>
        <p class="mt-1 text-sm text-slate-600">
            Governance facts about the member Schools of {{ group.name }}, computed from the
            Network's own records each time you open this page.
            <template v-if="scope.type === 'scoped'"
                >Your view is limited to your Region or Zone.</template
            >
            It shows no student, staff, finance or other School business data, and gives no access
            to any School.
        </p>

        <nav aria-label="Report sections" class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm">
            <a class="underline" href="#overview-heading">Overview</a>
            <a class="underline" href="#directory-heading">Schools</a>
            <a class="underline" href="#governance-heading">Governance</a>
            <a class="underline" href="#setup-heading">Setup</a>
        </nav>

        <section
            aria-labelledby="overview-heading"
            class="mt-6 rounded border border-slate-200 p-4"
        >
            <h2 id="overview-heading" class="text-base font-semibold">Overview</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-slate-600">Member Schools</dt>
                    <dd class="text-lg font-semibold">{{ overview.schools.total }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-600">Active</dt>
                    <dd class="text-lg font-semibold">{{ overview.schools.active }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-slate-600">Regions · Zones</dt>
                    <dd class="text-lg font-semibold">
                        {{ overview.regions.length }} · {{ overview.zones.length }}
                    </dd>
                </div>
            </dl>
            <div class="relative mt-4 grid gap-6 sm:grid-cols-2">
                <table class="w-full text-left text-sm">
                    <caption class="text-left font-medium">
                        By relationship
                    </caption>
                    <thead class="sr-only">
                        <tr>
                            <th scope="col">Relationship</th>
                            <th scope="col">Schools</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="[key, label] in relationshipRows" :key="key">
                            <th scope="row" class="py-1 pr-3 font-normal">{{ label }}</th>
                            <td class="py-1 text-right tabular-nums">
                                {{
                                    overview.relationships[
                                        key as keyof GroupOverview['relationships']
                                    ]
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <table class="w-full text-left text-sm">
                    <caption class="text-left font-medium">
                        By lifecycle
                    </caption>
                    <thead class="sr-only">
                        <tr>
                            <th scope="col">Lifecycle</th>
                            <th scope="col">Schools</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(count, status) in lifecycle" :key="status">
                            <th scope="row" class="py-1 pr-3 font-normal">
                                {{ lifecycleLabels[status] ?? status }}
                            </th>
                            <td class="py-1 text-right tabular-nums">{{ count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-500">
                A relationship describes how the School relates to the Network. It gives no one any
                access to the School.
            </p>
        </section>

        <GroupDirectory
            :base-url="pageUrl"
            :directory="directory"
            :filters="filters"
            :relationships="relationships"
            :regions="overview.regions"
            :zones="overview.zones"
            :sorts="sorts"
            with-attention
        />

        <section aria-labelledby="governance-heading" class="mt-8">
            <h2 id="governance-heading" class="text-base font-semibold">Governance</h2>
            <div
                class="relative mt-3 overflow-x-auto"
                tabindex="0"
                role="region"
                aria-label="Region and Zone distribution (scrolls sideways on small screens)"
            >
                <table class="w-full text-left text-sm">
                    <caption class="text-left font-medium">
                        Schools by Region and Zone
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="py-2 pr-3">Region / Zone</th>
                            <th scope="col" class="py-2 pr-3 text-right">Schools</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template v-for="region in distribution.regions" :key="region.code">
                            <tr>
                                <th scope="row" class="py-2 pr-3 font-semibold">
                                    {{ region.name }}
                                    <span v-if="region.status === 'inactive'" class="text-amber-800"
                                        >(inactive)</span
                                    >
                                </th>
                                <td class="py-2 pr-3 text-right tabular-nums font-semibold">
                                    {{ region.schools }}
                                </td>
                            </tr>
                            <tr v-for="zone in region.zones" :key="zone.code">
                                <th scope="row" class="py-1 pr-3 pl-6 font-normal">
                                    {{ zone.name }}
                                    <span v-if="zone.status === 'inactive'" class="text-amber-800"
                                        >(inactive)</span
                                    >
                                </th>
                                <td class="py-1 pr-3 text-right tabular-nums">
                                    {{ zone.schools }}
                                </td>
                            </tr>
                            <tr v-if="region.regionOnly > 0">
                                <th scope="row" class="py-1 pr-3 pl-6 font-normal italic">
                                    Region only (no Zone)
                                </th>
                                <td class="py-1 pr-3 text-right tabular-nums">
                                    {{ region.regionOnly }}
                                </td>
                            </tr>
                        </template>
                        <tr v-for="zone in distribution.zonesWithoutRegion" :key="`z-${zone.code}`">
                            <th scope="row" class="py-1 pr-3 font-normal">
                                {{ zone.name }} (Zone outside any Region)
                            </th>
                            <td class="py-1 pr-3 text-right tabular-nums">{{ zone.schools }}</td>
                        </tr>
                        <tr v-if="distribution.unplaced !== null">
                            <th scope="row" class="py-2 pr-3 font-normal italic">Not placed</th>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ distribution.unplaced }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <table class="relative mt-6 w-full text-left text-sm sm:w-2/3">
                <caption class="text-left font-medium">
                    Governance attention
                </caption>
                <thead class="border-b border-slate-300 text-slate-600">
                    <tr>
                        <th scope="col" class="py-2 pr-3">Attention</th>
                        <th scope="col" class="py-2 pr-3 text-right">Schools</th>
                        <th scope="col" class="py-2 pr-3"><span class="sr-only">List</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="row in attentionRows" :key="row.key">
                        <th scope="row" class="py-2 pr-3 font-normal">{{ row.label }}</th>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ row.count }}</td>
                        <td class="py-2 pr-3 text-right">
                            <a
                                v-if="row.count > 0"
                                class="underline"
                                :href="`${pageUrl}?attention=${row.key}#directory-heading`"
                                :aria-label="`List Schools: ${row.label}`"
                                >List</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section aria-labelledby="setup-heading" class="mt-8 rounded border border-slate-200 p-4">
            <h2 id="setup-heading" class="text-base font-semibold">Setup</h2>
            <p class="mt-1 text-sm text-slate-600">
                Setup readiness and Campus counts are not shown here. Both come from each School's
                own records, which the Network does not read. Each School sees its own setup in
                School setup → Setup &amp; readiness.
            </p>
        </section>
    </main>
</template>
