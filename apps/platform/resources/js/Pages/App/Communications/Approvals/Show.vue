<script setup lang="ts">
import { formatDateTime } from '../../../../datetime';
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
    // ORG.9D-3: a calendar Event's approved terms (from the immutable snapshot).
    kind: 'announcement' | 'event';
    eventId: string | null;
    eventStatus: string | null;
    eventTerms: {
        location: string | null;
        dateMode: string | null;
        startsOn: string | null;
        endsOn: string | null;
        startsAt: string | null;
        endsAt: string | null;
        audienceLabel: string;
    } | null;
    // ORG.9D-2: the approved population DEFINITION of a fee reminder (never balances).
    feeReminder: { status: string; academicYear: string; campus: string; class: string } | null;
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
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <Link class="text-xs text-ink-muted underline" href="/app/communications/approvals"
            >← Approvals</Link
        >
        <div class="mt-1 flex items-center gap-2">
            <h1 class="text-xl font-semibold">{{ request.title }}</h1>
            <span
                class="rounded-xs px-1.5 py-0.5 text-xs font-medium"
                :class="{
                    'bg-accent-100 text-accent-strong': request.status === 'pending',
                    'bg-graphite-100 text-ink': request.status === 'approved',
                    'bg-danger-tint text-danger': request.status === 'rejected',
                    'bg-graphite-200 text-ink-muted': ['cancelled', 'invalidated'].includes(
                        request.status,
                    ),
                }"
            >
                {{ request.status }}
            </span>
        </div>
        <p class="mt-1 text-xs text-ink-muted">
            submitted by {{ request.requestedByName ?? 'Unknown' }} ·
            {{ formatDateTime(request.requestedAt) }}
        </p>

        <div class="mt-4 rounded-xs border border-line p-4">
            <p class="text-sm whitespace-pre-wrap">{{ request.body }}</p>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 text-xs sm:grid-cols-3">
            <div class="rounded-xs border border-line p-3">
                <p class="text-ink-muted">Audience</p>
                <p class="font-medium">
                    {{
                        request.audienceType === 'school_wide'
                            ? 'School-wide'
                            : request.audienceType === 'staff'
                              ? 'Staff only (current employees)'
                              : request.audienceType === 'fee_reminder'
                                ? 'Fee reminder'
                                : request.kind === 'event'
                                  ? ({
                                        school_wide: 'Calendar event — whole School',
                                        staff: 'Calendar event — staff',
                                        grade: 'Calendar event — guardians of a Grade',
                                        section: 'Calendar event — guardians of a Section',
                                    }[request.audienceType ?? ''] ?? 'Calendar event')
                                  : `${request.individualMemberCount} selected members`
                    }}
                </p>
            </div>
            <div class="rounded-xs border border-line p-3">
                <p class="text-ink-muted">Channels</p>
                <p class="font-medium">
                    {{ request.channels.map((c) => channelLabels[c] ?? c).join(', ') }}
                </p>
            </div>
            <div class="rounded-xs border border-line p-3">
                <p class="text-ink-muted">Priority</p>
                <p class="font-medium">{{ request.priority }}</p>
            </div>
            <div class="rounded-xs border border-line p-3">
                <p class="text-ink-muted">Requirement</p>
                <p class="font-medium">{{ request.requirement }}</p>
            </div>
            <div class="rounded-xs border border-line p-3">
                <p class="text-ink-muted">Attachments</p>
                <p class="font-medium">{{ request.attachmentCount }}</p>
            </div>
        </div>

        <section
            v-if="request.kind === 'event' && request.eventTerms"
            class="mt-4 rounded-xs border border-line p-3 text-xs"
            aria-labelledby="approval-event"
        >
            <h2 id="approval-event" class="font-medium text-ink-muted">Calendar event</h2>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
                <dt class="text-ink-muted">When</dt>
                <dd v-if="request.eventTerms.dateMode === 'all_day'">
                    {{ request.eventTerms.startsOn }} – {{ request.eventTerms.endsOn }} (all day)
                </dd>
                <dd v-else>{{ request.eventTerms.startsAt }} – {{ request.eventTerms.endsAt }}</dd>
                <dt class="text-ink-muted">Where</dt>
                <dd>{{ request.eventTerms.location ?? '—' }}</dd>
                <dt class="text-ink-muted">Who can see it</dt>
                <dd>{{ request.eventTerms.audienceLabel }}</dd>
            </dl>
            <p class="mt-2 text-ink-muted">
                Approving lets the preparer publish exactly these terms to the calendar. Publishing
                sends no message; any later change needs a new approval.
            </p>
        </section>

        <section
            v-if="request.feeReminder"
            class="mt-4 rounded-xs border border-line p-3 text-xs"
            aria-labelledby="approval-fee-reminder"
        >
            <h2 id="approval-fee-reminder" class="font-medium text-ink-muted">
                Fee reminder selection
            </h2>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
                <dt class="text-ink-muted">Who</dt>
                <dd>{{ request.feeReminder.status }}</dd>
                <dt class="text-ink-muted">Academic year</dt>
                <dd>{{ request.feeReminder.academicYear }}</dd>
                <dt class="text-ink-muted">Charge campus</dt>
                <dd>{{ request.feeReminder.campus }}</dd>
                <dt class="text-ink-muted">Class</dt>
                <dd>{{ request.feeReminder.class }}</dd>
            </dl>
            <p class="mt-2 text-ink-muted">
                You are approving this message and this selection. Each guardian is emailed the
                amount their Students owe when it is sent (read again then), never an amount shown
                here; anyone who has paid by then is left out.
            </p>
        </section>

        <div
            v-if="request.status !== 'pending'"
            class="mt-4 rounded-xs border border-line bg-graphite-100 p-3 text-xs"
        >
            <p class="font-medium text-ink-muted">
                Decided by {{ request.decidedByName ?? 'Unknown' }}
                <template v-if="request.decidedAt">
                    · {{ formatDateTime(request.decidedAt) }}</template
                >
            </p>
            <p v-if="request.decisionNote" class="mt-1 text-ink-muted">
                {{ request.decisionNote }}
            </p>
        </div>

        <div v-if="canDecide" class="mt-6 space-y-4">
            <div class="rounded-xs border border-graphite-300 bg-graphite-100 p-4">
                <h2 class="text-sm font-semibold text-ink">Approve</h2>
                <textarea
                    v-model="note"
                    rows="2"
                    maxlength="1000"
                    placeholder="Optional note"
                    class="mt-2 w-full rounded-xs border border-graphite-300 p-2 text-sm"
                ></textarea>
                <button
                    type="button"
                    :disabled="approving"
                    class="mt-2 rounded-xs bg-accent-strong px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    @click="approve"
                >
                    Approve
                </button>
            </div>

            <div class="rounded-xs border border-danger/40 bg-danger-tint p-4">
                <h2 class="text-sm font-semibold text-danger">Reject</h2>
                <textarea
                    v-model="reason"
                    rows="2"
                    maxlength="1000"
                    required
                    placeholder="Reason (required)"
                    class="mt-2 w-full rounded-xs border border-danger/40 p-2 text-sm"
                ></textarea>
                <button
                    type="button"
                    :disabled="rejecting || !reason.trim()"
                    class="mt-2 rounded-xs bg-danger px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    @click="reject"
                >
                    Reject
                </button>
            </div>
        </div>

        <p v-else-if="request.status === 'pending'" class="mt-6 text-xs text-ink-muted">
            You cannot decide this request (you are the requester, or lack the required capability).
        </p>

        <Link
            v-if="request.kind === 'event'"
            class="mt-6 block text-xs text-ink-muted underline"
            :href="`/app/communications/events/${request.eventId}`"
            >View event</Link
        >
        <Link
            v-else
            class="mt-6 block text-xs text-ink-muted underline"
            :href="`/app/communications/announcements/${request.announcementId}`"
            >View announcement</Link
        >
    </main>
</template>
