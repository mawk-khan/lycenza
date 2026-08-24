<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import HubNav from '@/Components/App/Communications/HubNav.vue';

interface ChannelBucket {
    planned: number;
    // IN_APP shape
    available?: number;
    read?: number;
    unread?: number;
    // every other channel's shape
    sent?: number;
    failed?: number;
    inProgress?: number;
    suppressed?: number;
}

interface OverviewSummary {
    published: number;
    recipients: number;
    channels: Record<string, ChannelBucket>;
    deferredQuietHours: number;
    suppressed: number;
    emergency: { announcements: number; bypassEvents: number };
}

interface Props {
    summary: OverviewSummary;
    range: { key: string; from: string; to: string };
    schoolTimezone: string;
    totalUnreadCount: number;
    canAnnounce: boolean;
    canManage: boolean;
    canManageTemplates: boolean;
}

defineProps<Props>();

const channelLabels: Record<string, string> = { in_app: 'In-app', email: 'Email' };

function selectRange(key: string) {
    router.get('/app/communications/analytics', { range: key }, { preserveState: true });
}

// Brief §38/§67: never "delivery rate" -- a transport SEND rate, and
// only when there is a meaningful denominator.
function sendRate(bucket: ChannelBucket): string | null {
    if (bucket.planned === 0 || bucket.sent === undefined) {
        return null;
    }
    return `${Math.round(((bucket.sent ?? 0) / bucket.planned) * 100)}%`;
}

function readRate(bucket: ChannelBucket): string | null {
    if (bucket.available === undefined || bucket.available === 0) {
        return null;
    }
    return `${Math.round(((bucket.read ?? 0) / bucket.available) * 100)}%`;
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications"
            >← Communication Hub</a
        >
        <h1 class="mt-1 text-xl font-semibold">Communication Analytics</h1>
        <p class="mt-1 text-xs text-slate-500">
            System operations only -- not a student/educational outcome measure. Timezone:
            {{ schoolTimezone }}.
        </p>

        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-[160px_1fr]">
            <HubNav
                active="analytics"
                :total-unread-count="totalUnreadCount"
                :can-announce="canAnnounce"
                :can-manage="canManage"
                :can-manage-templates="canManageTemplates"
            />

            <div>
                <div class="flex gap-2 text-xs">
                    <button
                        v-for="key in ['today', '7d', '30d']"
                        :key="key"
                        type="button"
                        class="rounded border px-2 py-1"
                        :class="
                            range.key === key
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'border-slate-300 text-slate-600'
                        "
                        @click="selectRange(key)"
                    >
                        {{
                            key === 'today'
                                ? 'Today'
                                : key === '7d'
                                  ? 'Last 7 days'
                                  : 'Last 30 days'
                        }}
                    </button>
                </div>
                <p class="mt-1 text-xs text-slate-400">{{ range.from }} – {{ range.to }}</p>

                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs text-slate-500">Published</p>
                        <p class="text-lg font-semibold">{{ summary.published }}</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs text-slate-500">Recipients</p>
                        <p class="text-lg font-semibold">{{ summary.recipients }}</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs text-slate-500">Deferred (quiet hours)</p>
                        <p class="text-lg font-semibold">{{ summary.deferredQuietHours }}</p>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <p class="text-xs text-slate-500">Suppressed by policy</p>
                        <p class="text-lg font-semibold">{{ summary.suppressed }}</p>
                    </div>
                    <div class="rounded border border-red-200 bg-red-50 p-3">
                        <p class="text-xs text-red-600">Emergency communications</p>
                        <p class="text-lg font-semibold text-red-700">
                            {{ summary.emergency.announcements }}
                        </p>
                    </div>
                    <div class="rounded border border-red-200 bg-red-50 p-3">
                        <p class="text-xs text-red-600">Quiet-hours bypass events</p>
                        <p class="text-lg font-semibold text-red-700">
                            {{ summary.emergency.bypassEvents }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 space-y-4">
                    <div
                        v-for="(bucket, channel) in summary.channels"
                        :key="channel"
                        class="rounded border border-slate-200 p-4"
                    >
                        <h2 class="text-sm font-semibold">
                            {{ channelLabels[channel] ?? channel }}
                        </h2>

                        <dl v-if="channel === 'in_app'" class="mt-2 grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <dt class="text-slate-500">Available</dt>
                                <dd class="font-medium">{{ bucket.available }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Read</dt>
                                <dd class="font-medium">
                                    {{ bucket.read }}
                                    <span v-if="readRate(bucket)" class="text-slate-400"
                                        >({{ readRate(bucket) }})</span
                                    >
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Unread</dt>
                                <dd class="font-medium">{{ bucket.unread }}</dd>
                            </div>
                        </dl>

                        <dl v-else class="mt-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                            <div>
                                <dt class="text-slate-500">Planned</dt>
                                <dd class="font-medium">{{ bucket.planned }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Sent to transport</dt>
                                <dd class="font-medium">
                                    {{ bucket.sent }}
                                    <span v-if="sendRate(bucket)" class="text-slate-400"
                                        >({{ sendRate(bucket) }})</span
                                    >
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Failed</dt>
                                <dd class="font-medium text-red-600">{{ bucket.failed }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Queued / in progress</dt>
                                <dd class="font-medium">{{ bucket.inProgress }}</dd>
                            </div>
                        </dl>
                        <p
                            v-if="channel !== 'in_app' && (bucket.suppressed ?? 0) > 0"
                            class="mt-2 text-xs text-slate-500"
                        >
                            {{ bucket.suppressed }} suppressed by policy/preference
                        </p>
                    </div>

                    <p
                        v-if="Object.keys(summary.channels).length === 0"
                        class="text-sm text-slate-500"
                    >
                        No published Announcements in this range.
                    </p>
                </div>

                <p class="mt-6 text-xs text-slate-400">
                    "Sent to transport" means the configured application mail transport accepted the
                    send -- not that it reached a mailbox. No delivered/bounced/opened/clicked data
                    is available without a provider webhook integration (not implemented).
                </p>
            </div>
        </div>
    </main>
</template>
