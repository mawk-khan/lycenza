<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ApprovalDetail {
    id: string;
    announcementId: string;
    announcementStatus: string | null;
    status: string;
    title: string | null;
    body: string | null;
    priority: string | null;
    requirement: string | null;
    dispatchMode: string | null;
    audienceType: string | null;
    individualMemberCount: number;
    channels: string[];
    attachmentCount: number;
    requestedByName: string | null;
    requestedAt: string;
    decidedByName: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
}

interface Props {
    request: ApprovalDetail;
    canDecide: boolean;
}

const props = defineProps<Props>();

const channelLabels: Record<string, string> = { in_app: 'In-app', email: 'Email' };

const note = ref('');
const reason = ref('');
const approving = ref(false);
const rejecting = ref(false);

function approve() {
    approving.value = true;
    router.post(
        `/app/communications/approvals/${props.request.id}/approve`,
        { note: note.value || null },
        { onFinish: () => (approving.value = false) },
    );
}

function reject() {
    if (!reason.value.trim()) {
        return;
    }
    rejecting.value = true;
    router.post(
        `/app/communications/approvals/${props.request.id}/reject`,
        { reason: reason.value },
        { onFinish: () => (rejecting.value = false) },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <Link class="text-xs text-slate-400 underline" href="/app/communications/approvals"
            >← Approvals</Link
        >
        <div class="mt-1 flex items-center gap-2">
            <h1 class="text-xl font-semibold">{{ request.title }}</h1>
            <span
                class="rounded px-1.5 py-0.5 text-xs font-medium"
                :class="{
                    'bg-blue-100 text-blue-700': request.status === 'pending',
                    'bg-teal-100 text-teal-700': request.status === 'approved',
                    'bg-rose-100 text-rose-700': request.status === 'rejected',
                    'bg-slate-100 text-slate-600': ['cancelled', 'invalidated'].includes(
                        request.status,
                    ),
                }"
            >
                {{ request.status }}
            </span>
        </div>
        <p class="mt-1 text-xs text-slate-500">
            submitted by {{ request.requestedByName ?? 'Unknown' }} · {{ request.requestedAt }}
        </p>

        <div class="mt-4 rounded border border-slate-200 p-4">
            <p class="text-sm whitespace-pre-wrap">{{ request.body }}</p>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 text-xs sm:grid-cols-3">
            <div class="rounded border border-slate-200 p-3">
                <p class="text-slate-500">Audience</p>
                <p class="font-medium">
                    {{
                        request.audienceType === 'school_wide'
                            ? 'School-wide'
                            : `${request.individualMemberCount} selected members`
                    }}
                </p>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <p class="text-slate-500">Channels</p>
                <p class="font-medium">
                    {{ request.channels.map((c) => channelLabels[c] ?? c).join(', ') }}
                </p>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <p class="text-slate-500">Priority</p>
                <p class="font-medium">{{ request.priority }}</p>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <p class="text-slate-500">Requirement</p>
                <p class="font-medium">{{ request.requirement }}</p>
            </div>
            <div class="rounded border border-slate-200 p-3">
                <p class="text-slate-500">Attachments</p>
                <p class="font-medium">{{ request.attachmentCount }}</p>
            </div>
        </div>

        <div
            v-if="request.status !== 'pending'"
            class="mt-4 rounded border border-slate-200 bg-slate-50 p-3 text-xs"
        >
            <p class="font-medium text-slate-600">
                Decided by {{ request.decidedByName ?? 'Unknown' }}
                <template v-if="request.decidedAt"> · {{ request.decidedAt }}</template>
            </p>
            <p v-if="request.decisionNote" class="mt-1 text-slate-500">
                {{ request.decisionNote }}
            </p>
        </div>

        <div v-if="canDecide" class="mt-6 space-y-4">
            <div class="rounded border border-teal-200 bg-teal-50 p-4">
                <h2 class="text-sm font-semibold text-teal-800">Approve</h2>
                <textarea
                    v-model="note"
                    rows="2"
                    maxlength="1000"
                    placeholder="Optional note"
                    class="mt-2 w-full rounded border border-teal-300 p-2 text-sm"
                ></textarea>
                <button
                    type="button"
                    :disabled="approving"
                    class="mt-2 rounded bg-teal-700 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    @click="approve"
                >
                    Approve
                </button>
            </div>

            <div class="rounded border border-rose-200 bg-rose-50 p-4">
                <h2 class="text-sm font-semibold text-rose-800">Reject</h2>
                <textarea
                    v-model="reason"
                    rows="2"
                    maxlength="1000"
                    required
                    placeholder="Reason (required)"
                    class="mt-2 w-full rounded border border-rose-300 p-2 text-sm"
                ></textarea>
                <button
                    type="button"
                    :disabled="rejecting || !reason.trim()"
                    class="mt-2 rounded bg-rose-700 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    @click="reject"
                >
                    Reject
                </button>
            </div>
        </div>

        <p v-else-if="request.status === 'pending'" class="mt-6 text-xs text-slate-500">
            You cannot decide this request (you are the requester, or lack the required capability).
        </p>

        <Link
            class="mt-6 block text-xs text-slate-400 underline"
            :href="`/app/communications/announcements/${request.announcementId}`"
            >View announcement</Link
        >
    </main>
</template>
