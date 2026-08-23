<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface AnnouncementDetail {
    id: string;
    title: string;
    body: string;
    createdByName: string | null;
    audienceType: string;
    recipientCount: number | null;
    status: string;
    priority: string;
    publishedAt: string | null;
    createdAt: string | null;
}

interface EmailEligibility {
    eligible: number;
    missing: number;
}

interface AudiencePreview {
    count: number;
    categoryBreakdown: Record<string, number>;
    email: EmailEligibility | null;
}

interface ChannelDeliveryRow {
    status: string;
    failureCode: string | null;
    count: number;
}

const UNAVAILABLE_FAILURE_CODES = [
    'recipient_email_missing',
    'recipient_email_invalid',
    'recipient_ineligible',
    'email_channel_disabled',
];

interface Props {
    announcement: AnnouncementDetail;
    requestedChannels: string[];
    emailChannelEnabled: boolean;
    preview: AudiencePreview | null;
    channelDeliverySummary: Record<string, ChannelDeliveryRow[]> | null;
    canEdit: boolean;
    canAnnounce: boolean;
}

const props = defineProps<Props>();

const channelLabels: Record<string, string> = { in_app: 'In-app', email: 'Email' };

function channelTotal(rows: ChannelDeliveryRow[]): number {
    return rows.reduce((sum, row) => sum + row.count, 0);
}

function channelSucceeded(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter((row) => row.status === 'delivered' || row.status === 'sent')
        .reduce((sum, row) => sum + row.count, 0);
}

function channelUnavailable(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter(
            (row) =>
                row.status === 'failed' &&
                row.failureCode !== null &&
                UNAVAILABLE_FAILURE_CODES.includes(row.failureCode),
        )
        .reduce((sum, row) => sum + row.count, 0);
}

function channelFailed(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter(
            (row) =>
                row.status === 'failed' &&
                (row.failureCode === null || !UNAVAILABLE_FAILURE_CODES.includes(row.failureCode)),
        )
        .reduce((sum, row) => sum + row.count, 0);
}

function channelPending(rows: ChannelDeliveryRow[]): number {
    return rows
        .filter((row) => ['pending', 'queued', 'sending'].includes(row.status))
        .reduce((sum, row) => sum + row.count, 0);
}
const publishing = ref(false);
const cancelling = ref(false);

function publish() {
    publishing.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/publish`,
        {},
        { onFinish: () => (publishing.value = false) },
    );
}

function cancelDraft() {
    cancelling.value = true;
    router.post(
        `/app/communications/announcements/${props.announcement.id}/cancel`,
        {},
        { onFinish: () => (cancelling.value = false) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications/announcements"
            >← Announcements</a
        >

        <div class="mt-2 flex items-center gap-2">
            <h1 class="text-xl font-semibold">{{ announcement.title }}</h1>
            <span
                class="rounded px-1.5 py-0.5 text-xs font-medium"
                :class="{
                    'bg-slate-100 text-slate-600': announcement.status === 'draft',
                    'bg-emerald-100 text-emerald-700': announcement.status === 'published',
                    'bg-red-100 text-red-600': announcement.status === 'cancelled',
                }"
            >
                {{ announcement.status }}
            </span>
        </div>
        <p class="mt-1 text-xs text-slate-500">
            by {{ announcement.createdByName ?? 'Unknown' }}
            <template v-if="announcement.publishedAt">
                · published {{ announcement.publishedAt }}</template
            >
        </p>

        <div class="mt-4 rounded border border-slate-200 p-4">
            <p class="text-sm whitespace-pre-wrap">{{ announcement.body }}</p>
        </div>

        <div class="mt-4 rounded border border-slate-200 p-4">
            <h2 class="text-sm font-semibold">Delivery channels</h2>
            <p class="mt-1 text-xs text-slate-500">
                {{ requestedChannels.map((c) => channelLabels[c] ?? c).join(', ') }}
            </p>
        </div>

        <div v-if="preview" class="mt-4 rounded border border-slate-200 p-4">
            <h2 class="text-sm font-semibold">Audience</h2>
            <p class="mt-1 text-xs text-slate-500">
                {{
                    announcement.audienceType === 'school_wide'
                        ? 'Entire School'
                        : 'Selected Members'
                }}
            </p>
            <p class="mt-2 text-sm">Estimated recipients: {{ preview.count }}</p>
            <ul
                v-if="Object.keys(preview.categoryBreakdown).length > 0"
                class="mt-2 space-y-1 text-xs"
            >
                <li
                    v-for="(count, label) in preview.categoryBreakdown"
                    :key="label"
                    class="flex justify-between text-slate-500"
                >
                    <span>{{ label }}</span>
                    <span>{{ count }}</span>
                </li>
            </ul>

            <div v-if="preview.email" class="mt-3 border-t border-slate-100 pt-2 text-xs">
                <div class="flex justify-between text-slate-500">
                    <span>Email eligible</span>
                    <span>{{ preview.email.eligible }}</span>
                </div>
                <div v-if="preview.email.missing > 0" class="flex justify-between text-amber-600">
                    <span>Missing email</span>
                    <span>{{ preview.email.missing }}</span>
                </div>
            </div>
        </div>

        <div
            v-else-if="announcement.recipientCount !== null"
            class="mt-4 rounded border border-slate-200 p-4"
        >
            <h2 class="text-sm font-semibold">Delivered to</h2>
            <p class="mt-1 text-sm">
                {{ announcement.recipientCount }} recipients (resolved at publish time)
            </p>

            <div
                v-if="channelDeliverySummary"
                class="mt-3 space-y-3 border-t border-slate-100 pt-3"
            >
                <div
                    v-for="(rows, channel) in channelDeliverySummary"
                    :key="channel"
                    class="text-xs"
                >
                    <p class="font-medium text-slate-600">
                        {{ channelLabels[channel] ?? channel }}
                    </p>
                    <p v-if="channel === 'in_app'" class="mt-1 text-slate-500">
                        {{ channelSucceeded(rows) }} / {{ channelTotal(rows) }}
                    </p>
                    <ul v-else class="mt-1 space-y-0.5 text-slate-500">
                        <li v-if="channelSucceeded(rows) > 0">{{ channelSucceeded(rows) }} sent</li>
                        <li v-if="channelPending(rows) > 0">{{ channelPending(rows) }} pending</li>
                        <li v-if="channelFailed(rows) > 0" class="text-red-600">
                            {{ channelFailed(rows) }} failed
                        </li>
                        <li v-if="channelUnavailable(rows) > 0">
                            {{ channelUnavailable(rows) }} unavailable
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div v-if="canEdit" class="mt-6 flex gap-2">
            <button
                type="button"
                :disabled="publishing"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                @click="publish"
            >
                Publish
            </button>
            <button
                type="button"
                :disabled="cancelling"
                class="rounded border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 disabled:opacity-50"
                @click="cancelDraft"
            >
                Cancel Draft
            </button>
        </div>
    </main>
</template>
