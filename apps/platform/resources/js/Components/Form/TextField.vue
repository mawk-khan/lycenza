<script setup lang="ts">
// SWC.2 (ADR 0073 section 8): a labelled input whose error and hint are
// programmatically associated (aria-describedby, aria-invalid). Text, number,
// date and email inputs only -- deliberately not a form framework.
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        type?: 'text' | 'number' | 'date' | 'email' | 'tel';
        error?: string;
        hint?: string;
        required?: boolean;
        min?: number;
        maxlength?: number;
        autocomplete?: string;
    }>(),
    {
        type: 'text',
        error: undefined,
        hint: undefined,
        min: undefined,
        maxlength: undefined,
        autocomplete: 'off',
    },
);

const model = defineModel<string | number | null>();

const describedBy = computed(
    () =>
        [props.hint ? `${props.id}-hint` : null, props.error ? `${props.id}-error` : null]
            .filter(Boolean)
            .join(' ') || undefined,
);
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-slate-700"
            >{{ label }}<span v-if="required" class="text-slate-500"> (required)</span></label
        >
        <input
            :id="id"
            v-model="model"
            :type="type"
            :required="required"
            :min="min"
            :maxlength="maxlength"
            :autocomplete="autocomplete"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="mt-1 w-full rounded border px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-amber-500"
            :class="error ? 'border-red-600' : 'border-slate-300'"
        />
        <p v-if="hint" :id="`${id}-hint`" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
