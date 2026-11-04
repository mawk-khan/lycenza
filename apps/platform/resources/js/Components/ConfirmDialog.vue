<script setup lang="ts">
// SWC.2 (ADR 0073 section 8): a confirmation for consequential actions
// (deactivate, remove, activate/close a year) on the native modal <dialog>:
// focus moves into it and is contained while open, Escape closes it, and
// the browser returns focus to the control that opened it.
import { ref } from 'vue';

const props = withDefaults(
    defineProps<{ title: string; confirmLabel?: string; tone?: 'danger' | 'neutral' }>(),
    { confirmLabel: 'Confirm', tone: 'danger' },
);
const emit = defineEmits<{ confirm: [] }>();

const dialog = ref<HTMLDialogElement | null>(null);
const titleId = `confirm-title-${Math.random().toString(36).slice(2, 10)}`;

function open() {
    dialog.value?.showModal();
}

function close() {
    dialog.value?.close();
}

function confirm() {
    close();
    emit('confirm');
}

defineExpose({ open, close });
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        class="frame max-w-md overflow-visible bg-white p-6 font-sans text-ink shadow-lg backdrop:bg-accent-900/50"
    >
        <h2 :id="titleId" class="text-lg font-semibold">{{ props.title }}</h2>
        <div class="mt-2 text-sm text-ink"><slot /></div>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" class="btn btn-secondary" @click="close">Cancel</button>
            <button
                type="button"
                class="btn"
                :class="props.tone === 'danger' ? 'btn-danger' : 'btn-primary'"
                data-testid="confirm-action"
                @click="confirm"
            >
                {{ props.confirmLabel }}
            </button>
        </div>
    </dialog>
</template>
