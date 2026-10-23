<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDateTime } from '../../../datetime';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0L.4 — Compliance: School audit-log review (ADR 0042).
 *
 * Shows one School's audit events, newest first, using only the approved
 * envelope fields. Event metadata is never sent to this page (the v1
 * allowlist is empty). Authorization (`school.audit.view`), the read and
 * the audit of this review all happen on the server. No search or
 * export. This page records what happened; it does not state that
 * anything is legally compliant.
 *
 * ORG.9B (ADR 0076 §68): optional filters by event type or family,
 * subject, and a date range in the School's own time zone. The choices
 * are the School's own event types and subjects; filters only narrow.
 */
interface AuditEntry {
    id: string;
    occurredAt: string;
    eventType: string;
    actorUserId: string | null;
    subjectType: string | null;
    subjectId: string | null;
    requestId: string | null;
}

interface AuditLog {
    fields: string[];
    entries: AuditEntry[];
    nextCursor: string | null;
    pageSize: number;
    filterOptions: { events: string[]; eventFamilies: string[]; subjects: string[] };
}

interface Filters {
    event: string;
    subject: string;
    from: string;
    to: string;
}

const props = defineProps<{ log: AuditLog; filters: Filters; timezone: string }>();

const isFirstPage = !new URLSearchParams(window.location.search).has('cursor');
const event = ref(props.filters.event);
const subject = ref(props.filters.subject);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const filtered = computed(() => Object.values(props.filters).some((value) => value !== ''));
const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);

function query(): Record<string, string | undefined> {
    return {
        event: props.filters.event || undefined,
        subject: props.filters.subject || undefined,
        from: props.filters.from || undefined,
        to: props.filters.to || undefined,
    };
}

function apply(): void {
    router.get(
        '/app/compliance/audit-log',
        {
            event: event.value || undefined,
            subject: subject.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveScroll: true },
    );
}

function clearFilters(): void {
    router.get('/app/compliance/audit-log');
}

function formatTime(value: string): string {
    return formatDateTime(value);
}

function nextHref(): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query())) {
        if (value !== undefined) {
            params.set(key, value);
        }
    }
    params.set('cursor', props.log.nextCursor ?? '');
    return `/app/compliance/audit-log?${params.toString()}`;
}

function firstHref(): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query())) {
        if (value !== undefined) {
            params.set(key, value);
        }
    }
    const search = params.toString();
    return search === '' ? '/app/compliance/audit-log' : `/app/compliance/audit-log?${search}`;
}
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Compliance · Audit log</h1>
        <p class="mt-1 text-sm text-slate-500">
            Actions recorded for this School, newest first. Only the event envelope is shown — never
            the event's details. This log records what happened; it does not certify compliance with
            any law.
        </p>
        <p
            class="mt-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            data-testid="highly-sensitive-notice"
        >
            Highly sensitive: this page shows who did what in this School. Your review is itself
            recorded in the audit log.
        </p>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="block text-xs text-slate-600" for="audit-event">Event</label>
                <select
                    id="audit-event"
                    v-model="event"
                    class="mt-1 max-w-xs rounded border border-slate-300 px-2 py-1.5 text-sm"
                >
                    <option value="">All events</option>
                    <optgroup label="Event groups">
                        <option
                            v-for="family in log.filterOptions.eventFamilies"
                            :key="`family-${family}`"
                            :value="family"
                        >
                            {{ family }} (all)
                        </option>
                    </optgroup>
                    <optgroup label="Single events">
                        <option v-for="type in log.filterOptions.events" :key="type" :value="type">
                            {{ type }}
                        </option>
                    </optgroup>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="audit-subject">Subject</label>
                <select
                    id="audit-subject"
                    v-model="subject"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                >
                    <option value="">Any subject</option>
                    <option v-for="label in log.filterOptions.subjects" :key="label" :value="label">
                        {{ label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="audit-from">From</label>
                <input
                    id="audit-from"
                    v-model="from"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="audit-to">To</label>
                <input
                    id="audit-to"
                    v-model="to"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                />
            </div>
            <button type="submit" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
                Filter
            </button>
            <button v-if="filtered" type="button" class="text-sm underline" @click="clearFilters">
                Clear filters
            </button>
        </form>
        <p class="mt-2 text-xs text-slate-500">
            Dates are whole days in the school's time zone ({{ timezone }}).
        </p>
        <p
            v-for="field in ['event', 'subject', 'from', 'to']"
            v-show="pageErrors[field]"
            :key="field"
            class="mt-2 text-sm text-red-700"
            role="alert"
        >
            {{ pageErrors[field] }}
        </p>

        <EmptyState
            v-if="log.entries.length === 0"
            class="mt-6"
            :title="filtered ? 'No events match these filters' : 'No audit events'"
            :description="
                !isFirstPage
                    ? 'There are no older events.'
                    : filtered
                      ? 'Try a wider date range or another event.'
                      : 'Nothing has been recorded for this School yet.'
            "
        />

        <div v-else class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 pr-4 font-medium">Time</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Event</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Actor (user id)</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Subject</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Request id</th>
                        <th scope="col" class="py-2 font-medium">Event id</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="entry in log.entries" :key="entry.id" data-testid="audit-row">
                        <td class="py-2 pr-4 whitespace-nowrap">
                            <time :datetime="entry.occurredAt">{{
                                formatTime(entry.occurredAt)
                            }}</time>
                        </td>
                        <td class="py-2 pr-4 font-mono text-xs">{{ entry.eventType }}</td>
                        <td class="py-2 pr-4 font-mono text-xs">
                            {{ entry.actorUserId ?? 'system' }}
                        </td>
                        <td class="py-2 pr-4 font-mono text-xs">
                            <template v-if="entry.subjectType">
                                {{ entry.subjectType }}<br />{{ entry.subjectId ?? '' }}
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td class="py-2 pr-4 font-mono text-xs">{{ entry.requestId ?? '—' }}</td>
                        <td class="py-2 font-mono text-xs">{{ entry.id }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="mt-4 flex gap-4 text-sm" aria-label="Audit log pages">
            <a v-if="!isFirstPage" class="underline" :href="firstHref()">Newest events</a>
            <a v-if="log.nextCursor" class="underline" :href="nextHref()">Older events →</a>
        </nav>
    </main>
</template>
