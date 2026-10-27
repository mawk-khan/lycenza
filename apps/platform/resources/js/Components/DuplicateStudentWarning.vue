<script setup lang="ts">
// SIS 2.3: the duplicate-risk warning shown before a new Student is created
// (Add Student, Admissions conversion, Campus onboarding). A warning, never a
// verdict: two different children may share a name and a birthday. The
// operator either stops, or confirms these are different people -- the form
// is then resubmitted with the acknowledgement and the server checks the
// candidates again before creating anything. Candidates show only what this
// operator may already read (the server decides); nothing is merged,
// linked or reactivated.
import { nextTick, onMounted, ref } from 'vue';
import { formatCalendarDate } from '../datetime';

export interface DuplicateCandidate {
    id: string;
    studentNumber: string;
    name: string;
    dateOfBirth: string | null;
    status: string;
    placement: string | null;
    href: string;
}

export interface DuplicateWarning {
    acknowledgement: string;
    candidateCount: number;
    hiddenCount: number;
    candidates: DuplicateCandidate[];
}

defineProps<{ warning: DuplicateWarning; processing: boolean; continueLabel: string }>();
const emit = defineEmits<{ continue: []; cancel: [] }>();

const box = ref<HTMLElement | null>(null);
onMounted(async () => {
    await nextTick();
    box.value?.focus();
});
</script>

<template>
    <section
        ref="box"
        role="alert"
        tabindex="-1"
        aria-labelledby="duplicate-warning-title"
        class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-800"
    >
        <h2 id="duplicate-warning-title" class="font-semibold">
            Possible duplicate: a Student with similar identity details already exists
        </h2>
        <p class="mt-1">
            {{
                warning.candidateCount === 1
                    ? 'One existing Student has'
                    : `${warning.candidateCount} existing Students have`
            }}
            the same name and date of birth. Nothing has been saved. Check the record before you
            continue. If this is the same child, use their existing record instead (an inactive
            Student can be reactivated from their profile).
        </p>

        <ul v-if="warning.candidates.length > 0" class="mt-3 divide-y divide-amber-200">
            <li v-for="c in warning.candidates" :key="c.id" class="py-2">
                <a :href="c.href" target="_blank" rel="noopener" class="font-medium underline">
                    {{ c.name }}<span class="sr-only"> (opens in a new tab)</span>
                </a>
                <span class="block break-words sm:inline">
                    <span class="sm:ml-2">Student number {{ c.studentNumber }}</span>
                    <span v-if="c.dateOfBirth">
                        · Born {{ formatCalendarDate(c.dateOfBirth) }}</span
                    >
                    ·
                    <span :class="c.status === 'active' ? '' : 'font-semibold'">
                        {{ c.status === 'active' ? 'Active' : 'Inactive' }}
                    </span>
                    <span v-if="c.placement"> · {{ c.placement }}</span>
                </span>
            </li>
        </ul>
        <p v-if="warning.hiddenCount > 0" class="mt-2">
            {{
                warning.hiddenCount === 1
                    ? 'One matching record is'
                    : `${warning.hiddenCount} matching records are`
            }}
            not shown because you cannot view Student records. Ask someone who can.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button
                type="button"
                class="rounded border border-slate-400 bg-white px-3 py-1.5 font-medium text-slate-900 hover:bg-slate-50"
                @click="emit('cancel')"
            >
                Cancel — do not create
            </button>
            <button
                type="button"
                :disabled="processing"
                class="rounded bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                @click="emit('continue')"
            >
                {{ continueLabel }}
            </button>
        </div>
    </section>
</template>
