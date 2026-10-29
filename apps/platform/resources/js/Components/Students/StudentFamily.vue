<script setup lang="ts">
// SIS.EXP-1: other Students who share an active Guardian with this Student.
// The server lists only siblings the viewer may view; nothing here decides
// access, and no family totals (fees or otherwise) are ever shown.
import StatusBadge from '../StatusBadge.vue';
import type { StudentSibling } from './studentProfileSections';

defineProps<{ siblings: StudentSibling[]; truncated: boolean }>();
</script>

<template>
    <section id="family" class="mt-8" aria-labelledby="family-heading">
        <h2 id="family-heading" class="text-sm font-medium text-slate-500">Family</h2>
        <p class="mt-1 text-xs text-slate-500">Students who share a Guardian with this Student.</p>
        <p v-if="siblings.length === 0" class="mt-3 text-sm text-slate-500">
            No other Student shares a Guardian with this Student.
        </p>
        <ul v-else class="mt-3 divide-y divide-slate-200 rounded border border-slate-200">
            <li
                v-for="s in siblings"
                :key="s.id"
                class="flex flex-wrap items-start justify-between gap-2 px-4 py-3 text-sm"
            >
                <div class="min-w-0">
                    <a class="font-medium underline" :href="`/app/students/${s.id}`">{{
                        s.name
                    }}</a>
                    <span class="ml-2 text-slate-500">{{ s.studentNumber }}</span>
                    <p v-if="s.currentPlacement" class="text-slate-600">
                        {{ s.currentPlacement.gradeLevel }} · {{ s.currentPlacement.section }} ·
                        {{ s.currentPlacement.campus }}
                    </p>
                    <p v-if="s.sharedGuardians.length > 0" class="text-xs text-slate-500">
                        Shares: {{ s.sharedGuardians.join(', ') }}
                    </p>
                </div>
                <StatusBadge :status="s.status" />
            </li>
        </ul>
        <p v-if="truncated" class="mt-2 text-xs text-slate-500">
            Only the first {{ siblings.length }} are listed.
        </p>
    </section>
</template>
