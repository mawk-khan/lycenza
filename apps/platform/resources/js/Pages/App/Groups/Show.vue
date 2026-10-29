<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 12): one School Group as its Group Admin sees
// it. ORG.12D (ADR 0080 §9, OD-12.11): the network overview and the
// member-School directory -- each School's name, code, lifecycle and the
// Group's own classification (relationship, Region, Zone). No School data,
// no count of anything inside a School. The only School-facing action is
// entering ONE member School through elevated access (Group Admin only),
// which grants no School permissions.
// Phase 0N.11 (ADR 0048): a link to the Group curriculum coverage report
// when the actor holds group.reporting.view.
// ORG.12E (ADR 0080 §10): Network roles. A read-only Network Viewer sees only
// its Region or Zone; a whole-Group Network Manager gets the governance
// workspace (Regions, Zones, classification, placement, removal) -- none of
// it is School access.
import { computed } from 'vue';
import GroupDirectory from '../../../Components/Groups/GroupDirectory.vue';
import GroupOverviewPanel from '../../../Components/Groups/GroupOverviewPanel.vue';
import NetworkGovernanceWorkspace from '../../../Components/Groups/NetworkGovernanceWorkspace.vue';
import type {
    DirectoryRow,
    GovernanceScope,
    GroupOverview,
    Option,
    Paginated,
} from '../../../groupNetwork';

const props = defineProps<{
    group: { id: string; name: string; status: string };
    overview: GroupOverview;
    directory: Paginated<DirectoryRow>;
    filters: Record<string, string | undefined>;
    relationships: Option[];
    reasons: Option[];
    scope: GovernanceScope;
    canManage: boolean;
    canManageOperators: boolean;
    hasMfaFactor: boolean;
    canViewReports: boolean;
}>();

const pageUrl = `/app/groups/${props.group.id}`;
const scopeLabel = computed(() => {
    if (props.scope.type === 'whole_group') return 'the whole Group';
    const names = [
        ...props.overview.regions
            .filter((r) => props.scope.regionIds.includes(r.id))
            .map((r) => `Region ${r.name}`),
        ...props.overview.zones
            .filter((z) => props.scope.zoneIds.includes(z.id))
            .map((z) => `Zone ${z.name}`),
    ];
    return names.join(', ') || 'a limited part of the Group';
});
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <h1 class="text-xl font-semibold" data-testid="group-name">{{ group.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">School Group (network) — {{ group.status }}</p>
        <p class="mt-2 text-sm" data-testid="group-scope">
            You see <strong>{{ scopeLabel }}</strong
            >.
            <a class="ml-2 underline" :href="`${pageUrl}/access`">Network access</a>
        </p>

        <GroupOverviewPanel :overview="overview" />

        <NetworkGovernanceWorkspace
            v-if="canManage"
            :page-url="pageUrl"
            :action-base="`${pageUrl}/network`"
            :overview="overview"
            :directory="directory"
            :filters="filters"
            :relationships="relationships"
            :reasons="reasons"
            :has-mfa-factor="hasMfaFactor"
            :editable="group.status === 'active'"
        >
            <template #row-actions="{ school }">
                <a
                    v-if="school.canEnter"
                    class="mr-3 underline"
                    :href="`${pageUrl}/elevation?school=${school.id}`"
                    >Enter (elevated access)<span class="sr-only"> {{ school.name }}</span></a
                >
            </template>
        </NetworkGovernanceWorkspace>

        <GroupDirectory
            v-else
            :base-url="pageUrl"
            :directory="directory"
            :filters="filters"
            :relationships="relationships"
            :regions="overview.regions"
            :zones="overview.zones"
        >
            <template #actions="{ school }">
                <a
                    v-if="school.canEnter"
                    class="underline"
                    :href="`${pageUrl}/elevation?school=${school.id}`"
                    >Enter (elevated access)<span class="sr-only"> {{ school.name }}</span></a
                >
                <span v-else-if="school.isMember" class="text-slate-500"
                    >You are a member — select it from your School list</span
                >
            </template>
        </GroupDirectory>

        <p v-if="canViewReports" class="mt-6 text-sm" data-testid="group-reports">
            <a class="underline" :href="`${pageUrl}/reports/curriculum-coverage`"
                >Curriculum coverage report</a
            >
        </p>

        <p class="mt-6 text-sm text-slate-600">
            Network roles, Regions and Zones organise the network; they never give anyone access to
            a School. Entering a School uses elevated access (Group Admin only): at most 30 minutes,
            a fresh multi-factor code, and no School permissions.
        </p>
        <p class="mt-4 text-sm"><a class="underline" href="/app/groups">All your Groups</a></p>
    </main>
</template>
