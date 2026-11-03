<script setup lang="ts">
// HR.2A (S-16, OD-HR-13): an Employee profile record is ARCHIVED with a closed
// reason, never deleted -- it stays as evidence. The reasons come from the
// server (ProfileRecordArchiveReason); the confirmation is the native modal
// <dialog> (ConfirmDialog: focus contained, Escape closes, focus returns).
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ConfirmDialog from '../ConfirmDialog.vue';

const props = defineProps<{
    url: string;
    /** What is archived, e.g. "this address" (button and dialog text). */
    subject: string;
    reasons: { value: string; label: string }[];
    /** A unique, deterministic id for the reason control (label `for`). */
    controlId: string;
}>();

const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
const reason = ref('');
const error = ref<string | null>(null);

function open(): void {
    reason.value = '';
    error.value = null;
    dialog.value?.open();
}

function submit(): void {
    if (reason.value === '') {
        error.value = 'Choose why this record is being archived.';
        dialog.value?.open();
        return;
    }
    router.delete(props.url, {
        data: { reason_code: reason.value },
        preserveScroll: true,
        onError: (errors) => (error.value = errors.reason_code ?? Object.values(errors)[0] ?? null),
    });
}
</script>

<template>
    <button type="button" class="text-red-600 underline" @click="open">Archive</button>
    <ConfirmDialog
        ref="dialog"
        :title="`Archive ${subject}?`"
        confirm-label="Archive"
        @confirm="submit"
    >
        <p>The record stays in the history as archived; it is no longer shown as current.</p>
        <label class="mt-3 block text-sm text-slate-600" :for="controlId">Reason</label>
        <select
            :id="controlId"
            v-model="reason"
            required
            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
        >
            <option value="" disabled>Select a reason</option>
            <option v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
    </ConfirmDialog>
</template>
