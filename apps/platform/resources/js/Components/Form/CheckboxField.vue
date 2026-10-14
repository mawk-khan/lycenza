<script setup lang="ts">
// SWC.2 (ADR 0073 section 8): a labelled checkbox with associated error/hint.
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{ id: string; label: string; error?: string; hint?: string }>(),
    { error: undefined, hint: undefined },
);

const model = defineModel<boolean>();

const describedBy = computed(
    () =>
        [props.hint ? `${props.id}-hint` : null, props.error ? `${props.id}-error` : null]
            .filter(Boolean)
            .join(' ') || undefined,
);
</script>

<template>
    <div>
        <div class="flex items-center gap-2">
            <input
                :id="id"
                v-model="model"
                type="checkbox"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="h-4 w-4 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-amber-500"
            />
            <label :for="id" class="text-sm font-medium text-slate-700">{{ label }}</label>
        </div>
        <p v-if="hint" :id="`${id}-hint`" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
