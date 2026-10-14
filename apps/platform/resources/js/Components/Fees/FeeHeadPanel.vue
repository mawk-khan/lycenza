<script setup lang="ts">
// SWC.3 (ADR 0073 section 19): one OPF fee-head mapping -- shows the mapped
// head and, for a manager, sets or clears it through the OPF service
// (`PUT .../fee-head`). The server decides what is selectable (an active head
// of this School); the picker only offers those. A change affects fee intent
// recorded afterwards; recorded intent is never rewritten.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '../ConfirmDialog.vue';
import SelectField from '../Form/SelectField.vue';
import { feeHeadText, type FeeHeadLabel } from '../../support/feeIntent';

const props = defineProps<{
    id: string;
    title: string;
    action: string;
    panel: { feeHead: FeeHeadLabel | null; options: FeeHeadLabel[]; canManage: boolean };
    hint?: string;
}>();

const options = computed(() =>
    props.panel.options.map((h) => ({ value: h.id, label: `${h.name} (${h.code})` })),
);
const form = useForm<{ fee_head_id: string | null }>({
    fee_head_id: props.panel.feeHead?.active ? props.panel.feeHead.id : null,
});
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);

function save() {
    form.put(props.action, { preserveScroll: true });
}
function clear() {
    form.fee_head_id = null;
    form.put(props.action, { preserveScroll: true });
}
</script>

<template>
    <section class="mt-8 rounded border border-slate-300 p-4" :aria-labelledby="`${id}-heading`">
        <h2 :id="`${id}-heading`" class="font-semibold">{{ props.title }}</h2>
        <p class="mt-1 text-sm" :data-testid="`${id}-current`">
            <span class="text-slate-600">Current:</span> {{ feeHeadText(props.panel.feeHead) }}
        </p>
        <p v-if="props.hint" class="mt-1 text-sm text-slate-600">{{ props.hint }}</p>
        <form
            v-if="props.panel.canManage"
            class="mt-3 flex flex-wrap items-end gap-3"
            @submit.prevent="save"
        >
            <div class="w-80">
                <SelectField
                    :id="`${id}-select`"
                    v-model="form.fee_head_id"
                    label="Fee head"
                    :options="options"
                    placeholder="Select a fee head"
                    required
                    :error="form.errors.fee_head_id"
                />
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Save fee head
            </button>
            <button
                v-if="props.panel.feeHead"
                type="button"
                :disabled="form.processing"
                class="rounded border border-slate-300 px-3 py-2"
                @click="dialog?.open()"
            >
                Clear
            </button>
        </form>
        <ConfirmDialog
            ref="dialog"
            :title="`Clear ${props.title.toLowerCase()}?`"
            confirm-label="Clear"
            @confirm="clear"
        >
            New fee intent will no longer be recorded from this mapping. Fee intent already recorded
            is not changed.
        </ConfirmDialog>
    </section>
</template>
