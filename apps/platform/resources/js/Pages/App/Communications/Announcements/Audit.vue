<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface AuditEntry {
    event: string;
    label: string;
    actorName: string | null;
    occurredAt: string;
    metadata: Record<string, unknown>;
    isEmergency: boolean;
}

interface Props {
    announcement: {
        id: string;
        title: string;
        status: string;
        dispatchMode: string;
        createdByName: string | null;
    };
    entries: AuditEntry[];
    meta: { currentPage: number; lastPage: number; total: number };
}

defineProps<Props>();

function formatMetadataKey(key: string): string {
    return key.replace(/([A-Z])/g, ' $1').replace(/^./, (c) => c.toUpperCase());
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <Link
            class="text-xs text-slate-400 underline"
            :href="`/app/communications/announcements/${announcement.id}`"
            >← {{ announcement.title }}</Link
        >
        <h1 class="mt-1 text-xl font-semibold">Audit Timeline</h1>
        <p class="mt-1 text-xs text-slate-500">
            Every recorded action against this Announcement, in order. Historical events are never
            fabricated or backfilled from current state.
        </p>

        <ol class="mt-6 space-y-4 border-l border-slate-200 pl-4">
            <li v-for="(entry, idx) in entries" :key="idx" class="relative">
                <span
                    class="absolute top-1 -left-[21px] h-2.5 w-2.5 rounded-full"
                    :class="entry.isEmergency ? 'bg-red-600' : 'bg-slate-400'"
                ></span>

                <p class="text-xs text-slate-400">{{ entry.occurredAt }}</p>
                <p
                    class="text-sm font-medium"
                    :class="entry.isEmergency ? 'text-red-700' : 'text-slate-900'"
                >
                    {{ entry.label }}
                    <span
                        v-if="entry.isEmergency"
                        class="ml-1 rounded bg-red-100 px-1 py-0.5 text-xs font-semibold text-red-700"
                        >emergency</span
                    >
                </p>
                <p class="text-xs text-slate-500">by {{ entry.actorName ?? 'Unknown' }}</p>

                <dl
                    v-if="Object.keys(entry.metadata).length > 0"
                    class="mt-1 space-y-0.5 text-xs text-slate-500"
                >
                    <div v-for="(value, key) in entry.metadata" :key="key" class="flex gap-1">
                        <dt class="font-medium">{{ formatMetadataKey(String(key)) }}:</dt>
                        <dd class="whitespace-pre-wrap">{{ value }}</dd>
                    </div>
                </dl>
            </li>

            <li v-if="entries.length === 0" class="text-sm text-slate-500">
                No audit events recorded.
            </li>
        </ol>

        <div
            v-if="meta.lastPage > 1"
            class="mt-4 flex items-center justify-between text-xs text-slate-400"
        >
            <Link
                v-if="meta.currentPage > 1"
                :href="`/app/communications/announcements/${announcement.id}/audit?page=${meta.currentPage - 1}`"
                class="underline"
                >← Earlier</Link
            >
            <span v-else></span>
            <span>Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{ meta.total }} total)</span>
            <Link
                v-if="meta.currentPage < meta.lastPage"
                :href="`/app/communications/announcements/${announcement.id}/audit?page=${meta.currentPage + 1}`"
                class="underline"
                >Later →</Link
            >
            <span v-else></span>
        </div>
    </main>
</template>
