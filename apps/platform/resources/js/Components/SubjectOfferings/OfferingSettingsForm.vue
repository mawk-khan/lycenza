<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): change an Offering's settings through
// SubjectOfferingService -- classification (required/elective), display
// order, weekly periods target and active/inactive (never a delete). The
// classification freeze (ADR 0069) is the server's: once the Offering has
// academic records, a change comes back as a field error, however the
// request was made.
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmDialog from '../ConfirmDialog.vue';
import CheckboxField from '../Form/CheckboxField.vue';
import ErrorSummary from '../Form/ErrorSummary.vue';
import TextField from '../Form/TextField.vue';

const props = defineProps<{
    offering: {
        id: string;
        isRequired: boolean;
        sequence: number | null;
        weeklyPeriodsTarget: number | null;
        status: string;
    };
}>();

const form = useForm<{
    is_required: boolean;
    sequence: number | null;
    weekly_periods_target: number | null;
}>({
    is_required: props.offering.isRequired,
    sequence: props.offering.sequence,
    weekly_periods_target: props.offering.weeklyPeriodsTarget,
});

function submit() {
    form.put(`/app/subject-offerings/${props.offering.id}`, { preserveScroll: true });
}

const statusForm = useForm({ status: '' });
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function setStatus(status: 'active' | 'inactive') {
    statusForm.status = status;
    statusForm.put(`/app/subject-offerings/${props.offering.id}`, { preserveScroll: true });
}
</script>

<template>
    <section
        class="mt-8 rounded border border-slate-300 p-4"
        aria-labelledby="offering-settings-heading"
    >
        <h2 id="offering-settings-heading" class="font-semibold">Offering settings</h2>
        <form class="mt-3 grid gap-3 sm:grid-cols-3" @submit.prevent="submit">
            <CheckboxField
                id="settings-required"
                v-model="form.is_required"
                label="Required for every Student"
                hint="Cannot change once the Offering has academic records."
                :error="form.errors.is_required"
            />
            <TextField
                id="settings-sequence"
                v-model="form.sequence"
                label="Display order"
                type="number"
                :min="0"
                :error="form.errors.sequence"
            />
            <TextField
                id="settings-periods"
                v-model="form.weekly_periods_target"
                label="Weekly periods target"
                type="number"
                :min="0"
                :error="form.errors.weekly_periods_target"
            />
            <div class="sm:col-span-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                >
                    Save settings
                </button>
            </div>
        </form>

        <ErrorSummary :errors="statusForm.errors" :fields="['status', 'is_required']" />
        <p class="mt-4 text-sm">
            <button
                v-if="props.offering.status === 'active'"
                type="button"
                class="text-red-700 underline"
                :disabled="statusForm.processing"
                @click="dialog?.open()"
            >
                Deactivate this Offering
            </button>
            <button
                v-else
                type="button"
                class="underline"
                :disabled="statusForm.processing"
                @click="setStatus('active')"
            >
                Reactivate this Offering
            </button>
        </p>

        <ConfirmDialog
            ref="dialog"
            title="Deactivate this Subject Offering?"
            confirm-label="Deactivate"
            @confirm="setStatus('inactive')"
        >
            It stays in the records, with its enrollment history, and can be reactivated. An
            inactive Offering accepts no new participation.
        </ConfirmDialog>
    </section>
</template>
