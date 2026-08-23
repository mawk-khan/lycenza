<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    links: PageLink[];
}

defineProps<Props>();
</script>

<template>
    <!-- Laravel's paginator always includes a Previous/Next pair plus at
         least one page link; 3 is the floor for "more than one page". -->
    <nav
        v-if="links.length > 3"
        aria-label="Pagination"
        class="mt-4 flex flex-wrap items-center gap-1"
    >
        <template v-for="(link, index) in links" :key="index">
            <span
                v-if="!link.url"
                class="rounded px-3 py-1.5 text-sm text-slate-300"
                aria-disabled="true"
                v-html="link.label"
            />
            <Link
                v-else
                :href="link.url"
                preserve-scroll
                class="rounded px-3 py-1.5 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-slate-900"
                :class="
                    link.active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
                "
                :aria-current="link.active ? 'page' : undefined"
            >
                <!-- Laravel's paginator labels are fixed, server-generated
                     strings ("&laquo; Previous", "Next &raquo;", or a plain
                     page number) -- v-html only decodes those HTML
                     entities, never renders user input. -->
                <span v-html="link.label" />
            </Link>
        </template>
    </nav>
</template>
