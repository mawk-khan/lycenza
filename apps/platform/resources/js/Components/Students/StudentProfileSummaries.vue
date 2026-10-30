<script setup lang="ts">
// SIS.EXP-1: the Student 360 "At a glance" cards. Every card is a READ of its
// owning domain, already authorized on the server: a card the viewer may not
// see is absent from `sections` (never sent and hidden here). This component
// therefore checks only whether a section is present -- it holds no
// capability names and decides no access. Each link leads to the owning
// domain's page, which re-checks its own authority.
import { computed } from 'vue';
import { formatCalendarDate } from '../../datetime';
import { formatMoney } from '../../money';
import type { StudentProfileSections } from './studentProfileSections';

const props = defineProps<{ sections: StudentProfileSections }>();

const hasAny = computed(
    () =>
        props.sections.subjects !== undefined ||
        props.sections.attendance !== undefined ||
        props.sections.fees !== undefined ||
        props.sections.transport !== undefined ||
        props.sections.hostel !== undefined ||
        props.sections.library !== undefined ||
        props.sections.documents !== undefined,
);

const ATTENDANCE_LABELS: Record<string, string> = {
    present: 'Present',
    absent: 'Absent',
    late: 'Late',
    excused: 'Excused',
};

function money(amount: string | null, currency: string | null): string {
    return amount === null || currency === null ? '—' : formatMoney(amount, currency);
}
</script>

<template>
    <section v-if="hasAny" id="at-a-glance" class="mt-8" aria-labelledby="at-a-glance-heading">
        <h2 id="at-a-glance-heading" class="text-sm font-medium text-slate-500">At a glance</h2>
        <p class="mt-1 text-xs text-slate-500">
            Read-only summaries from each module. Open a module to change anything.
        </p>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <!-- Subjects (Academic: the current placement's offerings) -->
            <article
                v-if="sections.subjects"
                class="rounded border border-slate-200 p-4"
                aria-labelledby="glance-subjects"
            >
                <h3 id="glance-subjects" class="text-sm font-semibold">Subjects</h3>
                <ul v-if="sections.subjects.items.length > 0" class="mt-2 space-y-1 text-sm">
                    <li v-for="(s, i) in sections.subjects.items" :key="i">
                        {{ s.subjectName }}
                        <span class="text-xs text-slate-500">{{
                            s.required ? 'Required' : 'Elective'
                        }}</span>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">No current subjects.</p>
            </article>

            <!-- Attendance (records only: never a rate or a tally) -->
            <article
                v-if="sections.attendance"
                class="rounded border border-slate-200 p-4"
                aria-labelledby="glance-attendance"
            >
                <h3 id="glance-attendance" class="text-sm font-semibold">Attendance</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Latest records, {{ formatCalendarDate(sections.attendance.from) }} –
                    {{ formatCalendarDate(sections.attendance.to) }}
                </p>
                <ul v-if="sections.attendance.recent.length > 0" class="mt-2 space-y-1 text-sm">
                    <li v-for="(r, i) in sections.attendance.recent" :key="i">
                        {{ formatCalendarDate(r.date) }} ·
                        {{ r.subjectName ?? r.classLabel ?? 'Class' }}
                        <span v-if="r.periodName" class="text-slate-500">({{ r.periodName }})</span>
                        — <strong>{{ ATTENDANCE_LABELS[r.status] ?? r.status }}</strong>
                        <span v-if="r.corrected" class="text-xs text-slate-500">(corrected)</span>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">
                    No attendance records in this period.
                </p>
                <a class="mt-2 inline-block text-sm underline" :href="sections.attendance.link"
                    >Attendance records</a
                >
            </article>

            <!-- Fees (Payments' outstanding-fees read; no family totals) -->
            <article
                v-if="sections.fees"
                class="rounded border border-slate-200 p-4"
                aria-labelledby="glance-fees"
            >
                <h3 id="glance-fees" class="text-sm font-semibold">Fees</h3>
                <p v-if="sections.fees.scope === 'campus'" class="mt-1 text-xs text-slate-500">
                    Charges on your Campuses only.
                </p>
                <dl
                    v-if="sections.fees.hasCharges"
                    class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm"
                >
                    <dt class="text-slate-500">Billed</dt>
                    <dd>{{ money(sections.fees.billed, sections.fees.currency) }}</dd>
                    <dt class="text-slate-500">Concession</dt>
                    <dd>{{ money(sections.fees.concession, sections.fees.currency) }}</dd>
                    <dt class="text-slate-500">Paid</dt>
                    <dd>{{ money(sections.fees.paid, sections.fees.currency) }}</dd>
                    <dt class="font-medium">Outstanding</dt>
                    <dd class="font-medium">
                        {{ money(sections.fees.outstanding, sections.fees.currency) }}
                    </dd>
                    <template
                        v-if="sections.fees.overdue !== null && sections.fees.overdue !== '0.00'"
                    >
                        <dt class="text-red-700">Overdue</dt>
                        <dd class="text-red-700">
                            {{ money(sections.fees.overdue, sections.fees.currency) }}
                        </dd>
                    </template>
                    <template v-if="sections.fees.nextDueDate">
                        <dt class="text-slate-500">Next due</dt>
                        <dd>{{ formatCalendarDate(sections.fees.nextDueDate) }}</dd>
                    </template>
                </dl>
                <p v-else class="mt-2 text-sm text-slate-500">No charges.</p>
                <a
                    v-if="sections.fees.statementLink"
                    class="mt-2 inline-block text-sm underline"
                    :href="sections.fees.statementLink"
                    >Fee statement</a
                >
            </article>

            <!-- Transport (current rider assignment) -->
            <article
                v-if="sections.transport"
                class="rounded border border-slate-200 p-4"
                aria-labelledby="glance-transport"
            >
                <h3 id="glance-transport" class="text-sm font-semibold">Transport</h3>
                <ul v-if="sections.transport.assignments.length > 0" class="mt-2 space-y-2 text-sm">
                    <li v-for="(a, i) in sections.transport.assignments" :key="i">
                        <span class="font-medium">{{ a.routeName }}</span>
                        <span v-if="a.pickupStopName" class="block text-slate-600"
                            >Pick-up: {{ a.pickupStopName }}</span
                        >
                        <span v-if="a.dropoffStopName" class="block text-slate-600"
                            >Drop-off: {{ a.dropoffStopName }}</span
                        >
                        <span class="block text-xs text-slate-500"
                            >Since {{ formatCalendarDate(a.startsOn) }}</span
                        >
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">
                    {{
                        sections.transport.scope === 'campus'
                            ? 'No current route on your Campuses.'
                            : 'No current route.'
                    }}
                </p>
                <a class="mt-2 inline-block text-sm underline" href="/app/transport/assignments"
                    >Rider assignments</a
                >
            </article>

            <!-- Hostel (current residency) -->
            <article
                v-if="sections.hostel"
                class="rounded border border-slate-200 p-4"
                aria-labelledby="glance-hostel"
            >
                <h3 id="glance-hostel" class="text-sm font-semibold">Hostel</h3>
                <ul v-if="sections.hostel.residencies.length > 0" class="mt-2 space-y-1 text-sm">
                    <li v-for="(r, i) in sections.hostel.residencies" :key="i">
                        {{ r.hostelName }} · Room {{ r.roomCode }} · Bed {{ r.bedCode }}
                        <span class="block text-xs text-slate-500"
                            >Since {{ formatCalendarDate(r.startsOn) }}</span
                        >
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-slate-500">
                    {{
                        sections.hostel.scope === 'campus'
                            ? 'No current residency on your Campuses.'
                            : 'No current residency.'
                    }}
                </p>
                <a class="mt-2 inline-block text-sm underline" href="/app/hostel-residency"
                    >Residency</a
                >
            </article>

            <!-- Library (open loans; no fines are computed here) -->
            <article
                v-if="sections.library"
                class="rounded border border-slate-200 p-4"
                aria-labelledby="glance-library"
            >
                <h3 id="glance-library" class="text-sm font-semibold">Library</h3>
                <p class="mt-2 text-sm">
                    {{ sections.library.activeLoans }}
                    {{ sections.library.activeLoans === 1 ? 'book' : 'books' }} on loan<span
                        v-if="sections.library.overdueLoans > 0"
                        class="text-red-700"
                        >, {{ sections.library.overdueLoans }} overdue</span
                    >
                </p>
                <p v-if="sections.library.scope === 'campus'" class="text-xs text-slate-500">
                    Loans from your Campuses only.
                </p>
                <a class="mt-2 inline-block text-sm underline" :href="sections.library.link"
                    >Loans</a
                >
            </article>

            <!-- SIS.EXP-3: Student record documents (counts only; the list
                 and the files need current MFA on their own page). -->
            <article
                v-if="sections.documents"
                class="rounded border border-slate-200 p-4"
                aria-labelledby="glance-documents"
            >
                <h3 id="glance-documents" class="text-sm font-semibold">Documents</h3>
                <p class="mt-2 text-sm">
                    {{ sections.documents.active }}
                    {{ sections.documents.active === 1 ? 'document' : 'documents' }} on record<span
                        v-if="sections.documents.archived > 0"
                        class="text-slate-500"
                        >, {{ sections.documents.archived }} archived</span
                    >
                </p>
                <ul
                    v-if="sections.documents.categories.length > 0"
                    class="mt-1 text-xs text-slate-600"
                >
                    <li v-for="c in sections.documents.categories" :key="c.value">
                        {{ c.label }}: {{ c.count }}
                    </li>
                </ul>
                <a class="mt-2 inline-block text-sm underline" :href="sections.documents.link"
                    >Open documents</a
                >
            </article>
        </div>
    </section>
</template>
