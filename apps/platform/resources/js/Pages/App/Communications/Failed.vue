<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import HubNav from '@/Components/App/Communications/HubNav.vue';

interface FailureRow {
    channel: string;
    failureCode: string | null;
    count: number;
}

interface FailedAnnouncement {
    id: string;
    title: string;
    createdByName: string | null;
    publishedAt: string | null;
    failures: FailureRow[];
}

interface Paginated<T> {
    data: T[];
}

interface Props {
    announcements: Paginated<FailedAnnouncement>;
    meta: { currentPage: number; lastPage: number; total: number };
    totalUnreadCount: number;
    canAnnounce: boolean;
    canManage: boolean;
    canManageTemplates: boolean;
    canApprove: boolean;
}

defineProps<Props>();

const channelLabels: Record<string, string> = { in_app: 'In-app', email: 'Email' };
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications"
            >← Communication Hub</a
        >
        <h1 class="mt-1 text-xl font-semibold">Failed Deliveries</h1>
        <p class="mt-1 text-xs text-slate-500">
            Announcements with at least one failed delivery attempt. Policy-suppressed channels (a
            recipient's own preference, or School policy) are not shown here -- see each
            announcement's own delivery summary for that distinction.
        </p>

        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-[160px_1fr]">
            <HubNav
                active="failed"
                :total-unread-count="totalUnreadCount"
                :can-announce="canAnnounce"
                :can-manage="canManage"
                :can-manage-templates="canManageTemplates"
                :can-approve="canApprove"
            />

            <div>
                <div
                    v-if="announcements.data.length === 0"
                    class="rounded border border-dashed border-slate-300 p-8 text-center"
                >
                    <p class="text-sm text-slate-500">No failed deliveries.</p>
                </div>

                <ul v-else class="divide-y divide-slate-200 rounded border border-slate-200">
                    <li v-for="a in announcements.data" :key="a.id" class="px-4 py-3">
                        <a
                            class="text-sm font-medium hover:underline"
                            :href="`/app/communications/announcements/${a.id}`"
                            >{{ a.title }}</a
                        >
                        <p class="mt-0.5 text-xs text-slate-500">
                            by {{ a.createdByName ?? 'Unknown' }}
                            <template v-if="a.publishedAt">
                                · published {{ a.publishedAt }}</template
                            >
                        </p>
                        <ul class="mt-2 space-y-0.5 text-xs text-red-600">
                            <li v-for="(f, idx) in a.failures" :key="idx">
                                {{ channelLabels[f.channel] ?? f.channel }}: {{ f.count }} failed
                                <span v-if="f.failureCode" class="text-slate-400"
                                    >({{ f.failureCode }})</span
                                >
                            </li>
                        </ul>
                    </li>
                </ul>

                <div
                    v-if="meta.lastPage > 1"
                    class="mt-4 flex items-center justify-between text-xs text-slate-400"
                >
                    <Link
                        v-if="meta.currentPage > 1"
                        :href="`/app/communications/failed?page=${meta.currentPage - 1}`"
                        class="underline"
                        >← Newer</Link
                    >
                    <span v-else></span>
                    <span
                        >Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{
                            meta.total
                        }}
                        total)</span
                    >
                    <Link
                        v-if="meta.currentPage < meta.lastPage"
                        :href="`/app/communications/failed?page=${meta.currentPage + 1}`"
                        class="underline"
                        >Older →</Link
                    >
                    <span v-else></span>
                </div>
            </div>
        </div>
    </main>
</template>
