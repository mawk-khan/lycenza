<script setup lang="ts">
// HR.16C: the staff attendance register and month view are server-paged
// (no population cap). This shows where the visible page sits in the WHOLE
// population and moves between pages, keeping the chosen date or month.
import { computed } from 'vue';

const props = defineProps<{
    page: { page: number; perPage: number; total: number; lastPage: number };
    url: string;
    params: Record<string, string>;
}>();

const from = computed(() =>
    props.page.total === 0 ? 0 : (props.page.page - 1) * props.page.perPage + 1,
);
const to = computed(() => Math.min(props.page.page * props.page.perPage, props.page.total));

function href(page: number): string {
    return `${props.url}?${new URLSearchParams({ ...props.params, page: String(page) }).toString()}`;
}
</script>

<template>
    <nav class="mt-4 flex flex-wrap items-center gap-4 text-sm" aria-label="Register pages">
        <p aria-live="polite">
            Showing {{ from }}–{{ to }} of {{ page.total }} staff (page {{ page.page }} of
            {{ page.lastPage }})
        </p>
        <a v-if="page.page > 1" class="underline" :href="href(page.page - 1)">Previous page</a>
        <a v-if="page.page < page.lastPage" class="underline" :href="href(page.page + 1)"
            >Next page</a
        >
    </nav>
</template>
