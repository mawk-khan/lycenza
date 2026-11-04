<script setup lang="ts">
// ORG.9D-3 (ADR 0076 §75): one School calendar Event and its lifecycle.
// A published Event is never edited: to change it, cancel it and publish a
// corrected copy (a new draft with its own approval).
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ErrorSummary from '../../../../Components/Form/ErrorSummary.vue';
import { EVENT_STATUS_LABELS, eventWhen, type SchoolEvent } from '../../../../schoolEvents';

const props = defineProps<{
    event: SchoolEvent;
    schoolTimezone: string;
    latestApprovalRequest: {
        status: string;
        requestedByName: string | null;
        requestedAt: string | null;
        decidedByName: string | null;
        decidedAt: string | null;
        decisionNote: string | null;
    } | null;
    approvalCurrent: boolean;
    can: { edit: boolean; submit: boolean; withdraw: boolean; publish: boolean; cancel: boolean };
}>();

const errors = computed(() => usePage().props.errors as Record<string, string | undefined>);

function act(path: string, confirmText?: string): void {
    if (confirmText && !window.confirm(confirmText)) {
        return;
    }
    router.post(
        `/app/communications/events/${props.event.id}/${path}`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="event.title" />
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/communications/events">← Events</a>
        <h1 class="mt-2 text-xl font-semibold" :class="event.cancelled ? 'line-through' : ''">
            {{ event.title }}
        </h1>
        <p class="mt-1 text-sm">
            <span class="rounded-xs bg-graphite-200 px-2 py-0.5 text-xs">{{
                EVENT_STATUS_LABELS[event.status] ?? event.status
            }}</span>
        </p>

        <ErrorSummary
            :errors="errors"
            :fields="['event', 'ends_on', 'section_id', 'grade_level_id']"
        />

        <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
            <dt class="text-ink-muted">When</dt>
            <dd class="col-span-2">{{ eventWhen(event) }} ({{ schoolTimezone }})</dd>
            <dt class="text-ink-muted">Where</dt>
            <dd class="col-span-2">{{ event.location ?? '—' }}</dd>
            <dt class="text-ink-muted">Who can see it</dt>
            <dd class="col-span-2">{{ event.audienceLabel }}</dd>
            <dt class="text-ink-muted">Prepared by</dt>
            <dd class="col-span-2">{{ event.createdByName ?? '—' }}</dd>
        </dl>
        <p class="mt-4 text-sm whitespace-pre-wrap">{{ event.body }}</p>

        <section
            v-if="latestApprovalRequest"
            class="mt-6 rounded-xs border border-line p-3 text-xs"
            aria-label="Approval"
        >
            <p>
                Approval: <strong>{{ latestApprovalRequest.status }}</strong>
                <span v-if="latestApprovalRequest.requestedByName">
                    · requested by {{ latestApprovalRequest.requestedByName }}</span
                >
                <span v-if="latestApprovalRequest.decidedByName">
                    · decided by {{ latestApprovalRequest.decidedByName }}</span
                >
            </p>
            <p v-if="latestApprovalRequest.decisionNote" class="mt-1">
                {{ latestApprovalRequest.decisionNote }}
            </p>
            <p v-if="event.status === 'approved' && !approvalCurrent" class="mt-1 text-danger">
                The approval no longer matches this event; submit it again.
            </p>
        </section>

        <div class="mt-6 flex flex-wrap gap-2">
            <a
                v-if="can.edit"
                class="btn btn-secondary"
                :href="`/app/communications/events/${event.id}/edit`"
                >Edit</a
            >
            <button
                v-if="can.submit"
                type="button"
                class="btn btn-primary"
                @click="act('submit-approval')"
            >
                Submit for approval
            </button>
            <button
                v-if="can.withdraw"
                type="button"
                class="btn btn-secondary"
                @click="act('withdraw-approval')"
            >
                Withdraw from approval
            </button>
            <button
                v-if="can.publish"
                type="button"
                class="btn btn-primary"
                @click="act('publish')"
            >
                Publish to the calendar
            </button>
            <button
                v-if="can.cancel"
                type="button"
                class="rounded-xs border border-danger/40 px-3 py-1.5 text-sm text-danger"
                @click="
                    act(
                        'cancel',
                        event.status === 'published'
                            ? 'Cancel this published event? It stays on the calendar, marked cancelled.'
                            : 'Cancel this event?',
                    )
                "
            >
                Cancel event
            </button>
            <a
                v-if="event.status === 'published' || event.status === 'cancelled'"
                class="btn btn-secondary"
                :href="`/app/communications/events/create?from=${event.id}`"
                >Create a corrected copy</a
            >
        </div>
    </main>
</template>
