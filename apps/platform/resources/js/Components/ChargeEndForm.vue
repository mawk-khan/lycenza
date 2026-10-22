<script setup lang="ts">
// ORG.8G (ADR 0076 §65): ending a Charge (cancel, fee-assessment void,
// late-fee void) names a listed reason, a note when the reason is "other",
// and a fresh authentication code. The server enforces every part; the
// reasons come from the server, never the client.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MfaCodeField from './MfaCodeField.vue';
import SelectField from './Form/SelectField.vue';
import TextField from './Form/TextField.vue';

const props = defineProps<{
    id: string;
    url: string;
    reasons: string[];
    hasMfaFactor: boolean;
    action: string;
    submitLabel: string;
    confirmation: string;
}>();

const emit = defineEmits<{ done: [] }>();

const form = useForm({ reason_code: '', reason: '', mfa_code: '' });

const options = computed(() =>
    props.reasons.map((r) => ({
        value: r,
        label: (r.charAt(0).toUpperCase() + r.slice(1)).replaceAll('_', ' '),
    })),
);

// Refusals the server reports outside the three fields.
const refusal = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    return errors.cancellation ?? errors.action ?? null;
});

function submit(): void {
    if (!window.confirm(props.confirmation)) return;
    form.post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('done');
        },
        onError: () => form.reset('mfa_code'),
    });
}
</script>

<template>
    <form class="mt-3 space-y-3" @submit.prevent="submit">
        <SelectField
            :id="`${id}-reason-code`"
            v-model="form.reason_code"
            label="Reason"
            :options="options"
            placeholder="Choose a reason"
            required
            :error="form.errors.reason_code"
        />
        <TextField
            :id="`${id}-reason`"
            v-model="form.reason"
            :label="form.reason_code === 'other' ? 'Note' : 'Note (optional)'"
            :required="form.reason_code === 'other'"
            :maxlength="255"
            :error="form.errors.reason"
        />
        <MfaCodeField
            :id="`${id}-mfa`"
            v-model="form.mfa_code"
            class="w-72"
            :has-mfa-factor="hasMfaFactor"
            :action="action"
            :error="form.errors.mfa_code"
        />
        <p v-if="refusal" class="text-sm text-red-700">{{ refusal }}</p>
        <button
            type="submit"
            :disabled="form.processing || form.reason_code === '' || form.mfa_code === ''"
            class="rounded border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
        >
            {{ submitLabel }}
        </button>
    </form>
</template>
