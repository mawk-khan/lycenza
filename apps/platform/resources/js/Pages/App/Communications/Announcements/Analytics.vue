<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';

interface ChannelBucket {
    planned: number;
    available?: number;
    read?: number;
    unread?: number;
    sent?: number;
    failed?: number;
    inProgress?: number;
    suppressed?: number;
}

interface FailureRow {
    channel: string;
    failureCode: string | null;
    count: number;
}

interface EmergencyEvidence {
    declaredByUserId: string | null;
    declaredAt: string | null;
    quietHoursBypassChannels: string[];
    eligibleDeliveryCounts: Record<string, number>;
}

interface Summary {
    recipients: number;
    channels: Record<string, ChannelBucket>;
    deferredQuietHours: Record<string, number>;
    failures: FailureRow[];
    emergency: EmergencyEvidence | null;
}

interface DeliveryRow {
    id: string;
    recipientName: string | null;
    channel: string;
    status: string;
    failureCode: string | null;
    attempts: number;
    queuedAt: string | null;
    sentAt: string | null;
    readAt: string | null;
    failedAt: string | null;
}

interface Props {
    announcement: {
        id: string;
        title: string;
        status: string;
        dispatchMode: string;
        createdByName: string | null;
        publishedAt: string | null;
    };
    summary: Summary;
    deliveries: DeliveryRow[];
    deliveriesMeta: { currentPage: number; lastPage: number; total: number };
    filters: { channel: string | null; status: string | null; failureCode: string | null };
    totalUnreadCount: number;
    canAnnounce: boolean;
    canManage: boolean;
    canManageTemplates: boolean;
}

const props = defineProps<Props>();

const channelLabels: Record<string, string> = { in_app: 'In-app', email: 'Email' };

function filterBy(
    patch: Partial<{
        channel: string | null;
        status: string | null;
        failureCode: string | null;
        page: number;
    }>,
) {
    router.get(
        `/app/communications/announcements/${props.announcement.id}/analytics`,
        {
            channel: patch.channel !== undefined ? patch.channel : props.filters.channel,
            status: patch.status !== undefined ? patch.status : props.filters.status,
            failure_code:
                patch.failureCode !== undefined ? patch.failureCode : props.filters.failureCode,
            page: patch.page ?? 1,
        },
        { preserveState: true },
    );
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <Link
            class="text-xs text-slate-400 underline"
            :href="`/app/communications/announcements/${announcement.id}`"
            >← {{ announcement.title }}</Link
        >
        <h1 class="mt-1 text-xl font-semibold">Delivery Analytics</h1>
        <p class="mt-1 text-xs text-slate-500">
            by {{ announcement.createdByName ?? 'Unknown' }}
            <template v-if="announcement.publishedAt">
                · published {{ announcement.publishedAt }}</template
            >
        </p>

        <div class="mt-4 rounded border border-slate-200 p-4">
            <p class="text-sm">{{ summary.recipients }} recipients (resolved at publish time)</p>
        </div>

        <div
            v-if="summary.emergency"
            class="mt-4 rounded border border-red-200 bg-red-50 p-4 text-xs text-red-800"
        >
            <h2 class="text-sm font-semibold">Emergency timing</h2>
            <p class="mt-1">
                Quiet-hours bypass used on:
                {{
                    summary.emergency.quietHoursBypassChannels.length > 0
                        ? summary.emergency.quietHoursBypassChannels
                              .map((c) => channelLabels[c] ?? c)
                              .join(', ')
                        : 'none'
                }}
            </p>
            <ul
                v-if="Object.keys(summary.emergency.eligibleDeliveryCounts).length > 0"
                class="mt-1"
            >
                <li
                    v-for="(count, channel) in summary.emergency.eligibleDeliveryCounts"
                    :key="channel"
                >
                    {{ count }} eligible deliveries on {{ channelLabels[channel] ?? channel }}
                </li>
            </ul>
            <p class="mt-2 text-red-600">
                This is timing/governance evidence, not a delivery-success metric.
            </p>
        </div>

        <div class="mt-4 space-y-3">
            <div
                v-for="(bucket, channel) in summary.channels"
                :key="channel"
                class="rounded border border-slate-200 p-4"
            >
                <h2 class="text-sm font-semibold">{{ channelLabels[channel] ?? channel }}</h2>

                <dl v-if="channel === 'in_app'" class="mt-2 grid grid-cols-3 gap-2 text-xs">
                    <div>
                        <dt class="text-slate-500">Available</dt>
                        <dd class="font-medium">{{ bucket.available }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Read</dt>
                        <dd class="font-medium">{{ bucket.read }}</dd>
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
                        <dd class="font-medium">{{ bucket.sent }}</dd>
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
                <p v-if="summary.deferredQuietHours[channel]" class="mt-1 text-xs text-slate-500">
                    {{ summary.deferredQuietHours[channel] }} deferred by quiet hours (included in
                    queued / in progress above)
                </p>
            </div>

            <p v-if="Object.keys(summary.channels).length === 0" class="text-sm text-slate-500">
                Not yet published, or no deliveries were created.
            </p>
        </div>

        <div v-if="summary.failures.length > 0" class="mt-4 rounded border border-red-200 p-4">
            <h2 class="text-sm font-semibold text-red-700">Failure breakdown</h2>
            <ul class="mt-2 space-y-0.5 text-xs text-red-600">
                <li v-for="(f, idx) in summary.failures" :key="idx">
                    {{ channelLabels[f.channel] ?? f.channel }}: {{ f.count }}
                    <span v-if="f.failureCode" class="text-slate-400">({{ f.failureCode }})</span>
                </li>
            </ul>
        </div>

        <div class="mt-6">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Delivery detail</h2>
                <div class="flex gap-2 text-xs">
                    <select
                        :value="filters.channel ?? ''"
                        class="rounded border border-slate-300 px-1.5 py-1"
                        @change="
                            filterBy({
                                channel: ($event.target as HTMLSelectElement).value || null,
                            })
                        "
                    >
                        <option value="">All channels</option>
                        <option v-for="c in Object.keys(summary.channels)" :key="c" :value="c">
                            {{ channelLabels[c] ?? c }}
                        </option>
                    </select>
                    <select
                        :value="filters.status ?? ''"
                        class="rounded border border-slate-300 px-1.5 py-1"
                        @change="
                            filterBy({ status: ($event.target as HTMLSelectElement).value || null })
                        "
                    >
                        <option value="">All statuses</option>
                        <option value="sent">Sent</option>
                        <option value="delivered">Delivered</option>
                        <option value="read">Read</option>
                        <option value="failed">Failed</option>
                        <option value="queued">Queued</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>

            <ul class="mt-2 divide-y divide-slate-200 rounded border border-slate-200">
                <li
                    v-for="d in deliveries"
                    :key="d.id"
                    class="flex items-center justify-between px-3 py-2 text-xs"
                >
                    <span>{{ d.recipientName ?? 'Unknown recipient' }}</span>
                    <span class="text-slate-400">{{ channelLabels[d.channel] ?? d.channel }}</span>
                    <span
                        :class="{
                            'text-emerald-600': ['sent', 'delivered', 'read'].includes(d.status),
                            'text-red-600': d.status === 'failed',
                            'text-slate-500': !['sent', 'delivered', 'read', 'failed'].includes(
                                d.status,
                            ),
                        }"
                        >{{ d.status
                        }}<template v-if="d.failureCode"> ({{ d.failureCode }})</template></span
                    >
                    <span class="text-slate-400">{{ d.attempts }} attempt(s)</span>
                </li>
                <li
                    v-if="deliveries.length === 0"
                    class="px-3 py-4 text-center text-xs text-slate-500"
                >
                    No deliveries match this filter.
                </li>
            </ul>

            <div
                v-if="deliveriesMeta.lastPage > 1"
                class="mt-2 flex items-center justify-between text-xs text-slate-400"
            >
                <button
                    v-if="deliveriesMeta.currentPage > 1"
                    type="button"
                    class="underline"
                    @click="filterBy({ page: deliveriesMeta.currentPage - 1 })"
                >
                    ← Newer
                </button>
                <span v-else></span>
                <span
                    >Page {{ deliveriesMeta.currentPage }} of {{ deliveriesMeta.lastPage }} ({{
                        deliveriesMeta.total
                    }}
                    total)</span
                >
                <button
                    v-if="deliveriesMeta.currentPage < deliveriesMeta.lastPage"
                    type="button"
                    class="underline"
                    @click="filterBy({ page: deliveriesMeta.currentPage + 1 })"
                >
                    Older →
                </button>
                <span v-else></span>
            </div>
        </div>
    </main>
</template>
