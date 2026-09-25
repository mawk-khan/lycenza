<script setup lang="ts">
// Phase 0N.5 (ADR 0045 section 12): one School Group as its Group Admin sees
// it -- the Group's name and status and its member Schools' name and status
// (Confidential), nothing else: no counts and no School data. The only
// action is entering ONE member School through elevated access, which
// grants no School permissions. Changing the Group is platform-only.
// Phase 0N.11 (ADR 0048): a link to the Group curriculum coverage report
// when the actor holds group.reporting.view.
interface School {
    id: string;
    name: string;
    status: string;
    canEnter: boolean;
    isMember: boolean;
}

defineProps<{
    group: { id: string; name: string; status: string };
    schools: School[];
    canViewReports: boolean;
}>();
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold" data-testid="group-name">{{ group.name }}</h1>
        <p class="mt-1 text-sm text-slate-600">School Group — {{ group.status }}</p>

        <h2 class="mt-6 text-sm font-medium text-slate-500">Member Schools</h2>
        <table class="mt-2 w-full text-left text-sm" data-testid="group-schools">
            <thead>
                <tr class="text-slate-500">
                    <th class="py-1 font-medium">School</th>
                    <th class="py-1 font-medium">Status</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="s in schools" :key="s.id" class="border-t border-slate-200">
                    <td class="py-2">{{ s.name }}</td>
                    <td class="py-2">{{ s.status }}</td>
                    <td class="py-2 text-right">
                        <a
                            v-if="s.canEnter"
                            class="underline"
                            :href="`/app/groups/${group.id}/elevation?school=${s.id}`"
                            >Enter (elevated access)</a
                        >
                        <span v-else-if="s.isMember" class="text-slate-500"
                            >You are a member — select it from your School list</span
                        >
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="!schools.length" class="mt-2 text-sm text-slate-600">No member Schools.</p>

        <p v-if="canViewReports" class="mt-6 text-sm" data-testid="group-reports">
            <a class="underline" :href="`/app/groups/${group.id}/reports/curriculum-coverage`"
                >Curriculum coverage report</a
            >
        </p>

        <p class="mt-6 text-sm text-slate-600">
            Entering a School uses elevated access: at most 30 minutes, a fresh multi-factor code,
            and no School permissions. Membership of this Group is managed by the platform.
        </p>
        <p class="mt-4 text-sm"><a class="underline" href="/app/groups">All your Groups</a></p>
    </main>
</template>
