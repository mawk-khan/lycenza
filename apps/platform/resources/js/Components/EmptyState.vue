<script setup lang="ts">
interface Props {
    title: string;
    description: string;
}

defineProps<Props>();
</script>

<template>
    <div class="rounded-xs border border-dashed border-control-line px-6 py-12 text-center">
        <h2 class="text-base font-semibold text-ink">{{ title }}</h2>
        <p class="mx-auto mt-1 max-w-sm text-sm text-ink-muted">{{ description }}</p>
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
