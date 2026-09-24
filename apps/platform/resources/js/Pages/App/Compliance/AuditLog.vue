<script setup lang="ts">
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0L.4 — Compliance: School audit-log review (ADR 0042).
 *
 * Shows one School's audit events, newest first, using only the approved
 * envelope fields. Event metadata is never sent to this page (the v1
 * allowlist is empty). Authorization (`school.audit.view`), the read and
 * the audit of this review all happen on the server. No filters, search
 * or export. This page records what happened; it does not state that
 * anything is legally compliant.
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
}

const props = defineProps<{ log: AuditLog }>();

const isFirstPage = !new URLSearchParams(window.location.search).has('cursor');

function formatTime(value: string): string {
    return new Date(value).toLocaleString();
}

function nextHref(): string {
    return `/app/compliance/audit-log?cursor=${encodeURIComponent(props.log.nextCursor ?? '')}`;
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

        <EmptyState
            v-if="log.entries.length === 0"
            class="mt-6"
            title="No audit events"
            :description="
                isFirstPage
                    ? 'Nothing has been recorded for this School yet.'
                    : 'There are no older events.'
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
            <a v-if="!isFirstPage" class="underline" href="/app/compliance/audit-log"
                >Newest events</a
            >
            <a v-if="log.nextCursor" class="underline" :href="nextHref()">Older events →</a>
        </nav>
    </main>
</template>
