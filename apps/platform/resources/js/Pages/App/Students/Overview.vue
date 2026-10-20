<script setup lang="ts">
// ORG.7F-1 (ADR 0076 §45): the Campus-safe Student operational overview.
// Only what the server sent: the Student's identity reference and CURRENT
// placement. Never date of birth, Guardian data, history, the account link
// or another domain's data (owner decisions F1-D1, F1-D2).
import { computed } from 'vue';

interface Props {
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
    canViewPlacement: boolean;
    currentPlacement: {
        campus: string | null;
        gradeLevel: string | null;
        section: string | null;
        rollNumber: string | null;
    } | null;
    canOpenProfile: boolean;
    canEditNames: boolean;
}

const props = defineProps<Props>();

const fullName = computed(() =>
    [props.student.firstName, props.student.middleName, props.student.lastName]
        .filter(Boolean)
        .join(' '),
);

const placementLabel = computed(() => {
    const p = props.currentPlacement;
    if (!p) {
        return 'No current class';
    }
    const cls = [p.gradeLevel, p.section ? `Section ${p.section}` : null]
        .filter(Boolean)
        .join(' · ');
    return [cls, p.campus].filter(Boolean).join(' · ');
});
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/students">← Students</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ fullName }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ student.studentNumber }}</p>
            </div>
            <div class="flex shrink-0 gap-3 text-sm">
                <a v-if="canEditNames" class="underline" :href="`/app/students/${student.id}/names`"
                    >Edit names</a
                >
                <a v-if="canOpenProfile" class="underline" :href="`/app/students/${student.id}`"
                    >Full profile</a
                >
            </div>
        </div>

        <section v-if="canViewPlacement" class="mt-6">
            <h2 class="text-sm font-semibold text-slate-700">Current placement</h2>
            <p class="mt-1 text-sm text-slate-600">{{ placementLabel }}</p>
            <p v-if="currentPlacement?.rollNumber" class="mt-1 text-sm text-slate-500">
                Roll number {{ currentPlacement.rollNumber }}
            </p>
        </section>
    </main>
</template>
