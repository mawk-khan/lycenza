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
        class="max-w-md rounded border border-slate-300 p-6 font-sans text-slate-900 shadow-lg backdrop:bg-slate-900/40"
    >
        <h2 :id="titleId" class="text-lg font-semibold">{{ props.title }}</h2>
        <div class="mt-2 text-sm text-slate-700"><slot /></div>
        <div class="mt-6 flex justify-end gap-3">
            <button
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500"
                @click="close"
            >
                Cancel
            </button>
            <button
                type="button"
                class="rounded px-3 py-1.5 text-sm font-medium text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500"
                :class="
                    props.tone === 'danger'
                        ? 'bg-red-700 hover:bg-red-800'
                        : 'bg-slate-900 hover:bg-slate-700'
                "
                data-testid="confirm-action"
                @click="confirm"
            >
                {{ props.confirmLabel }}
            </button>
        </div>
    </dialog>
</template>
