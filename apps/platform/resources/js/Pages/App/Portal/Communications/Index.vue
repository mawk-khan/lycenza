<script setup lang="ts">
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * POR.1 — Guardian portal: messages from the School (read-only).
 *
 * Only announcements the School addressed to you as a Guardian in this
 * School. Nothing here can be sent, forwarded or exported. Switch School
 * from the start page to see another School's messages.
 */
interface Item {
    id: string;
    title: string;
    preview: string;
    sender: string | null;
    publishedAt: string | null;
    unread: boolean;
    priority: string;
    hasAttachments: boolean;
}

interface Props {
    schoolName: string;
    filter: 'all' | 'unread';
    items: Item[];
}

defineProps<Props>();

function formatDate(value: string | null): string {
    return value === null ? '' : new Date(value).toLocaleString();
}
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-10">
        <a class="text-sm underline" href="/app">Back</a>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">Messages from {{ schoolName }}</h1>

        <nav class="mt-4 flex gap-4 text-sm" aria-label="Filter">
            <a
                :class="filter === 'all' ? 'font-semibold' : 'underline'"
                href="/app/portal/communications"
                >All</a
            >
            <a
                :class="filter === 'unread' ? 'font-semibold' : 'underline'"
                href="/app/portal/communications?unread=1"
                >Unread</a
            >
        </nav>

        <EmptyState
            v-if="items.length === 0"
            class="mt-6"
            title="No messages"
            :description="
                filter === 'unread'
                    ? 'You have read every message.'
                    : 'No messages addressed to you as a Guardian yet.'
            "
        />

        <ul v-else class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li v-for="item in items" :key="item.id" class="px-4 py-3">
                <a class="block" :href="`/app/portal/communications/announcements/${item.id}`">
                    <span class="flex items-center gap-2">
                        <span v-if="item.unread" class="text-xs font-semibold text-blue-700"
                            >New</span
                        >
                        <span
                            :class="item.unread ? 'font-semibold text-slate-900' : 'text-slate-800'"
                            >{{ item.title }}</span
                        >
                        <span v-if="item.hasAttachments" class="text-xs text-slate-500"
                            >(attachment)</span
                        >
                    </span>
                    <span class="mt-1 block text-sm text-slate-600">{{ item.preview }}</span>
                    <span class="mt-1 block text-xs text-slate-500">
                        {{ item.sender ?? 'School' }} · {{ formatDate(item.publishedAt) }}
                    </span>
                </a>
            </li>
        </ul>
    </main>
</template>
