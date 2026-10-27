<script setup lang="ts">
import { formatCalendarDate } from '../../../datetime';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * V11.3 (ADR 0075 section 11): complete the placements a closed academic
 * year left open (usually after moving to the next year). Preview first;
 * each placement is then completed on its own, with the year's end date,
 * through the same Complete action as the Student page. Every row reports
 * its own outcome.
 */
interface PreviewRow {
    id: string;
    studentId: string;
    student: string;
    class: string;
    rollNumber: string;
    startsOn: string;
}

type Outcome = 'completed' | 'no_longer_open' | 'not_eligible' | 'date_conflict';

const props = defineProps<{
    years: { id: string; name: string; endsOn: string }[];
    selectedYearId: string;
    preview: { yearName: string; endsOn: string; total: number; placements: PreviewRow[] } | null;
    maxBatch: number;
    results: { rows: { id: string; student: string | null; outcome: Outcome }[] } | null;
}>();

const yearId = ref(props.selectedYearId);

function chooseYear(): void {
    router.get('/app/enrollments/closed-year-placements', {
        academic_year_id: yearId.value || undefined,
    });
}

const form = useForm({
    academic_year_id: props.selectedYearId,
    enrollment_ids: (props.preview?.placements ?? []).map((p) => p.id),
    confirm: false,
});

function submit(): void {
    form.post('/app/enrollments/closed-year-placements/complete', { preserveScroll: true });
}

const outcomeLabels: Record<Outcome, string> = {
    completed: 'Completed',
    no_longer_open: 'Already ended by someone else — not changed',
    not_eligible: 'No longer open in this closed year — not changed',
    date_conflict: 'Starts after the year ended — complete it from the Student page',
};

const completedCount = computed(
    () => props.results?.rows.filter((r) => r.outcome === 'completed').length ?? 0,
);
const notCompleted = computed(
    () => props.results?.rows.filter((r) => r.outcome !== 'completed') ?? [],
);
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 sm:p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/data-health/enrollment.active_in_closed_year"
            >← Data health</a
        >

        <h1 class="mt-2 text-xl font-semibold">Open placements in closed years</h1>
        <p class="mt-1 text-sm text-slate-500">
            When an academic year is closed, its class placements stay open until someone completes
            them. Completing records that the placement ran to the end of its year. Nothing else
            about the Student, their attendance or their other placements changes.
        </p>

        <section
            v-if="results"
            class="mt-6 rounded border px-4 py-3 text-sm"
            :class="
                notCompleted.length === 0
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-amber-200 bg-amber-50'
            "
            role="status"
        >
            <p class="font-medium">
                {{ completedCount }} of {{ results.rows.length }} placement{{
                    results.rows.length === 1 ? '' : 's'
                }}
                completed.
            </p>
            <ul v-if="notCompleted.length > 0" class="mt-2 list-disc pl-5">
                <li v-for="row in notCompleted" :key="row.id">
                    {{ row.student ?? 'A placement' }}: {{ outcomeLabels[row.outcome] }}
                </li>
            </ul>
        </section>

        <EmptyState
            v-if="years.length === 0"
            class="mt-6"
            title="No open placements in closed years"
            description="Every placement in a closed academic year has been completed, withdrawn or transferred."
        />

        <template v-else>
            <div class="mt-6">
                <label class="block text-sm text-slate-600" for="closed-year"
                    >Closed academic year</label
                >
                <select
                    id="closed-year"
                    v-model="yearId"
                    class="mt-1 w-72 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="chooseYear"
                >
                    <option v-for="year in years" :key="year.id" :value="year.id">
                        {{ year.name }}
                    </option>
                </select>
            </div>

            <form
                v-if="preview && preview.placements.length > 0"
                class="mt-6"
                @submit.prevent="submit"
            >
                <h2 class="text-sm font-semibold">
                    These {{ preview.placements.length }} placement{{
                        preview.placements.length === 1 ? '' : 's'
                    }}
                    will be completed with the end date {{ formatCalendarDate(preview.endsOn) }}
                </h2>
                <p
                    v-if="preview.total > preview.placements.length"
                    class="mt-1 text-sm text-slate-500"
                >
                    {{ preview.total }} are open in {{ preview.yearName }}; at most
                    {{ maxBatch }} are completed at a time. Run it again for the rest.
                </p>

                <div class="relative mt-3 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <caption class="sr-only">
                            Placements to complete in
                            {{
                                preview.yearName
                            }}
                        </caption>
                        <thead>
                            <tr class="border-b border-slate-200 text-xs text-slate-500">
                                <th scope="col" class="py-2 font-medium">Student</th>
                                <th scope="col" class="py-2 font-medium">Class</th>
                                <th scope="col" class="py-2 font-medium">Roll</th>
                                <th scope="col" class="py-2 font-medium">Placed since</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in preview.placements" :key="row.id">
                                <td class="py-2">
                                    <a
                                        class="underline"
                                        :href="`/app/students/${row.studentId}#enrollment-history`"
                                        >{{ row.student }}</a
                                    >
                                </td>
                                <td class="py-2 text-slate-600">{{ row.class }}</td>
                                <td class="py-2 text-slate-600">{{ row.rollNumber }}</td>
                                <td class="py-2 text-slate-600">
                                    {{ formatCalendarDate(row.startsOn) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-sm text-slate-500">
                    If a Student actually left before the year ended, withdraw that placement from
                    their Student page instead, with the real date.
                </p>
                <label class="mt-3 flex items-start gap-2 text-sm">
                    <input
                        id="closed-year-confirm"
                        v-model="form.confirm"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    <span
                        >I have checked this list. Complete these placements as of
                        {{ formatCalendarDate(preview.endsOn) }}.</span
                    >
                </label>
                <p
                    v-if="
                        form.errors.confirm ||
                        form.errors.enrollment_ids ||
                        form.errors.academic_year_id
                    "
                    class="mt-1 text-sm text-red-600"
                >
                    {{
                        form.errors.confirm ??
                        form.errors.enrollment_ids ??
                        form.errors.academic_year_id
                    }}
                </p>
                <button
                    type="submit"
                    :disabled="!form.confirm || form.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    Complete {{ preview.placements.length }} placement{{
                        preview.placements.length === 1 ? '' : 's'
                    }}
                </button>
            </form>

            <EmptyState
                v-else-if="preview"
                class="mt-6"
                title="Nothing open in this year"
                description="Every placement in this closed year has already ended."
            />
        </template>
    </main>
</template>
