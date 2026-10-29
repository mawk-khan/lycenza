<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 12): one School Group as its Group Admin sees
// it. ORG.12D (ADR 0080 §9, OD-12.11): the network overview and the
// member-School directory -- each School's name, code, lifecycle and the
// Group's own classification (relationship, Region, Zone). No School data,
// no count of anything inside a School. Read-only: the network's structure is
// managed by the platform. The only action is entering ONE member School
// through elevated access, which grants no School permissions.
// Phase 0N.11 (ADR 0048): a link to the Group curriculum coverage report
// when the actor holds group.reporting.view.
import GroupDirectory from '../../../Components/Groups/GroupDirectory.vue';
import GroupOverviewPanel from '../../../Components/Groups/GroupOverviewPanel.vue';
import type { DirectoryRow, GroupOverview, Option, Paginated } from '../../../groupNetwork';

const props = defineProps<{
    group: { id: string; name: string; status: string };
    overview: GroupOverview;
    directory: Paginated<DirectoryRow>;
    filters: Record<string, string | undefined>;
    relationships: Option[];
    canViewReports: boolean;
}>();
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <h1 class="text-xl font-semibold" data-testid="group-name">{{ group.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">School Group (network) — {{ group.status }}</p>

        <GroupOverviewPanel :overview="overview" />

        <GroupDirectory
            :base-url="`/app/groups/${group.id}`"
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
                    :href="`/app/groups/${props.group.id}/elevation?school=${school.id}`"
                    >Enter (elevated access)<span class="sr-only"> {{ school.name }}</span></a
                >
                <span v-else-if="school.isMember" class="text-slate-500"
                    >You are a member — select it from your School list</span
                >
            </template>
        </GroupDirectory>

        <p v-if="canViewReports" class="mt-6 text-sm" data-testid="group-reports">
            <a class="underline" :href="`/app/groups/${group.id}/reports/curriculum-coverage`"
                >Curriculum coverage report</a
            >
        </p>

        <p class="mt-6 text-sm text-slate-600">
            Entering a School uses elevated access: at most 30 minutes, a fresh multi-factor code,
            and no School permissions. The network's members and structure are managed by the
            platform.
        </p>
        <p class="mt-4 text-sm"><a class="underline" href="/app/groups">All your Groups</a></p>
    </main>
</template>
