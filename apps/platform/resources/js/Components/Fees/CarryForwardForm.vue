<script setup lang="ts">
// SWC.3 (ADR 0073 section 19, X5): next-year carry-forward over the OPF
// service. It is idempotent by construction -- every source is locked and an
// existing link for the year is skipped, behind unique indexes -- so a
// repeated or concurrent submission reports "already linked" and records
// nothing twice. Only open years (draft or active) are offered; the server
// refuses any other.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '../ConfirmDialog.vue';
import SelectField from '../Form/SelectField.vue';

interface Totals {
    academicYearId: string;
    linked: number;
    alreadyLinked: number;
    unmapped: number;
    ended: number;
    notApplicable: number;
}

const props = defineProps<{
    id: string;
    action: string;
    sourceLabel: string;
    openYears: Array<{ id: string; name: string; status: string }>;
    result: Totals | null;
}>();

const yearOptions = computed(() =>
    props.openYears.map((y) => ({ value: y.id, label: `${y.name} (${y.status})` })),
);
const form = useForm<{ academic_year_id: string | null }>({ academic_year_id: null });
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const resultYear = computed(
    () =>
        props.openYears.find((y) => y.id === props.result?.academicYearId)?.name ??
        'the selected year',
);

function run() {
    form.post(props.action, { preserveScroll: true });
}
</script>

<template>
    <section class="mt-6 rounded border border-slate-300 p-4" :aria-labelledby="`${id}-heading`">
        <h2 :id="`${id}-heading`" class="font-semibold">Carry fee intent forward</h2>
        <p class="mt-1 text-sm text-slate-600">
            Records the mapped fee head for every active {{ sourceLabel }} in the chosen year.
            Running it again changes nothing that is already recorded.
        </p>
        <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="dialog?.open()">
            <div class="w-72">
                <SelectField
                    :id="`${id}-year`"
                    v-model="form.academic_year_id"
                    label="Academic year"
                    :options="yearOptions"
                    placeholder="Select an open year"
                    required
                    :error="form.errors.academic_year_id"
                />
            </div>
            <button
                type="submit"
                :disabled="form.processing || !form.academic_year_id"
                class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Carry forward
            </button>
        </form>
        <div
            v-if="props.result"
            role="status"
            aria-live="polite"
            class="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-sm"
            :data-testid="`${id}-result`"
        >
            <p class="font-medium">Carry-forward to {{ resultYear }} finished.</p>
            <ul class="mt-1 list-inside list-disc">
                <li>Newly recorded: {{ props.result.linked }}</li>
                <li>Already recorded: {{ props.result.alreadyLinked }}</li>
                <li>No fee head mapped: {{ props.result.unmapped }}</li>
                <li>
                    Not applicable (no enrollment, structure or optional line):
                    {{ props.result.notApplicable }}
                </li>
                <li>Ended meanwhile: {{ props.result.ended }}</li>
            </ul>
        </div>
        <ConfirmDialog
            ref="dialog"
            title="Carry fee intent forward?"
            confirm-label="Carry forward"
            tone="neutral"
            @confirm="run"
        >
            Fee intent is recorded for the chosen year. It is not a charge: Finance creates charges
            when it assesses fees.
        </ConfirmDialog>
    </section>
</template>
