<script setup lang="ts">
// SWC.2 (ADR 0073 section 8): a labelled native <select> with associated
// error/hint. Options come from the server; the client adds none.
import { computed } from 'vue';

export interface SelectOption {
    value: string;
    label: string;
}

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        options: SelectOption[];
        error?: string;
        hint?: string;
        required?: boolean;
        placeholder?: string;
    }>(),
    { error: undefined, hint: undefined, placeholder: undefined },
);

const model = defineModel<string | null>();

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
        <select
            :id="id"
            v-model="model"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="mt-1 w-full rounded border bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-amber-500"
            :class="error ? 'border-red-600' : 'border-slate-300'"
        >
            <option v-if="placeholder !== undefined" :value="null">{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">
                {{ option.label }}
            </option>
        </select>
        <p v-if="hint" :id="`${id}-hint`" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
