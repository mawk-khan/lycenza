<script setup lang="ts">
interface Props {
    title: string;
    description: string;
}

defineProps<Props>();
</script>

<template>
    <div class="rounded border border-dashed border-slate-300 px-6 py-12 text-center">
        <h2 class="text-sm font-medium text-slate-900">{{ title }}</h2>
        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">{{ description }}</p>
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
