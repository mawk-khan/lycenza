<script setup lang="ts">
import { formatDateTime } from '../../../../datetime';
import { Link } from '@inertiajs/vue3';

interface ApprovalQueueRow {
    id: string;
    announcementId: string | null;
    // ORG.9D-3: a calendar Event's request shares this queue.
    kind: 'announcement' | 'event';
    eventId: string | null;
    title: string;
    requestedByName: string | null;
    requestedAt: string;
    priority: string | null;
    requirement: string | null;
    audienceType: string | null;
    channels: string[];
    attachmentCount: number;
}

interface Props {
    requests: ApprovalQueueRow[];
    meta: { currentPage: number; lastPage: number; total: number };
}

defineProps<Props>();

const channelLabels: Record<string, string> = { in_app: 'In-app', email: 'Email' };
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-xs text-ink-muted underline" href="/app/communications"
            >← Communication Hub</a
        >
        <h1 class="mt-1 text-xl font-semibold">Approvals</h1>
        <p class="mt-1 text-xs text-ink-muted">
            Announcements awaiting review. Emergency communications never appear here -- they are
            governed by the separate Emergency capability and policy.
        </p>

        <div
            v-if="requests.length === 0"
            class="mt-6 rounded-xs border border-dashed border-control-line p-8 text-center"
        >
            <p class="text-sm text-ink-muted">Nothing pending approval.</p>
        </div>

        <ul v-else class="mt-6 divide-y divide-line rounded-xs border border-line">
            <li v-for="r in requests" :key="r.id" class="px-4 py-3">
                <Link
                    class="text-sm font-medium hover:underline"
                    :href="`/app/communications/approvals/${r.id}`"
                    >{{ r.title }}</Link
                >
                <p class="mt-0.5 text-xs text-ink-muted">
                    submitted by {{ r.requestedByName ?? 'Unknown' }} ·
                    {{ formatDateTime(r.requestedAt) }}
                </p>
                <div class="mt-1 flex flex-wrap gap-1.5 text-xs">
                    <span class="rounded-xs bg-graphite-200 px-1.5 py-0.5 text-ink-muted">{{
                        r.audienceType === 'school_wide'
                            ? 'School-wide'
                            : r.audienceType === 'staff'
                              ? 'Staff only'
                              : r.audienceType === 'fee_reminder'
                                ? 'Fee reminder'
                                : r.kind === 'event'
                                  ? 'Calendar event'
                                  : 'Selected members'
                    }}</span>
                    <span
                        v-if="r.requirement === 'required'"
                        class="rounded-xs bg-accent-100 px-1.5 py-0.5 text-accent-strong"
                        >required</span
                    >
                    <span
                        v-if="r.priority"
                        class="rounded-xs bg-graphite-200 px-1.5 py-0.5 text-ink-muted"
                        >{{ r.priority }}</span
                    >
                    <span
                        v-for="c in r.channels"
                        :key="c"
                        class="rounded-xs bg-graphite-200 px-1.5 py-0.5 text-ink-muted"
                        >{{ channelLabels[c] ?? c }}</span
                    >
                    <span
                        v-if="r.attachmentCount > 0"
                        class="rounded-xs bg-graphite-200 px-1.5 py-0.5 text-ink-muted"
                        >{{ r.attachmentCount }} attachment(s)</span
                    >
                </div>
            </li>
        </ul>

        <div
            v-if="meta.lastPage > 1"
            class="mt-4 flex items-center justify-between text-xs text-ink-muted"
        >
            <Link
                v-if="meta.currentPage > 1"
                :href="`/app/communications/approvals?page=${meta.currentPage - 1}`"
                class="underline"
                >← Newer</Link
            >
            <span v-else></span>
            <span>Page {{ meta.currentPage }} of {{ meta.lastPage }} ({{ meta.total }} total)</span>
            <Link
                v-if="meta.currentPage < meta.lastPage"
                :href="`/app/communications/approvals?page=${meta.currentPage + 1}`"
                class="underline"
                >Older →</Link
            >
            <span v-else></span>
        </div>
    </main>
</template>
