<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * V11.2 (ADR 0075): a Student's history, read from the School audit
 * trail. Only recorded facts are shown, and each kind of entry only to
 * staff who may already see it at its source. "Recorded" is when the
 * change was saved; "effective" appears only where the change itself
 * carried a date (an enrollment ending). Who made each change is in the
 * School audit log, not here.
 */
interface HistoryEntry {
    id: string;
    recordedAt: string;
    eventType: string;
    category: 'record' | 'placement' | 'guardian' | 'admission';
    effectiveOn: string | null;
    academicYear: string | null;
    section: string | null;
    fromSection: string | null;
    toSection: string | null;
    previousStatus: string | null;
    newStatus: string | null;
    changedFields: string[];
    guardianId: string | null;
    relationshipType: string | null;
}

interface Props {
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
    entries: HistoryEntry[];
    truncated: boolean;
    categories: HistoryEntry['category'][];
    limit: number;
    timezone: string;
}

const props = defineProps<Props>();

const fullName = computed(() =>
    [props.student.firstName, props.student.middleName, props.student.lastName]
        .filter(Boolean)
        .join(' '),
);

const recordedFormat = computed(
    () =>
        new Intl.DateTimeFormat('en-IN', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: props.timezone,
        }),
);

function recorded(entry: HistoryEntry): string {
    return recordedFormat.value.format(new Date(entry.recordedAt));
}

const fieldLabels: Record<string, string> = {
    first_name: 'first name',
    middle_name: 'middle name',
    last_name: 'last name',
    date_of_birth: 'date of birth',
    student_number: 'Student number',
};

function sectionLabel(name: string | null): string {
    return name ?? 'a Section';
}

function describe(entry: HistoryEntry): string {
    switch (entry.eventType) {
        case 'student.created':
            return 'Student record created';
        case 'student.updated':
            return entry.changedFields.length > 0
                ? `Record updated: ${entry.changedFields.map((f) => fieldLabels[f] ?? f.replaceAll('_', ' ')).join(', ')}`
                : 'Record updated';
        case 'student.status_changed':
            return entry.newStatus ? `Marked ${entry.newStatus}` : 'Status changed';
        case 'student_enrollment.created':
            return `Placed in ${sectionLabel(entry.section)}${entry.academicYear ? ` for ${entry.academicYear}` : ''}`;
        case 'student_enrollment.transferred':
            return `Moved from ${sectionLabel(entry.fromSection)} to ${sectionLabel(entry.toSection)}`;
        case 'student_enrollment.completed':
            return `Placement in ${sectionLabel(entry.section)} completed`;
        case 'student_enrollment.withdrawn':
            return `Withdrawn from ${sectionLabel(entry.section)}`;
        case 'student_enrollment.cancelled':
            return `Placement in ${sectionLabel(entry.section)} cancelled`;
        case 'student_guardian.linked':
            return entry.relationshipType
                ? `Guardian linked (${entry.relationshipType.replaceAll('_', ' ')})`
                : 'Guardian linked';
        case 'student_guardian.updated':
            return 'Guardian link details updated';
        case 'student_guardian.primary_changed':
            return 'Primary guardian changed';
        case 'student_guardian.unlinked':
            return 'Guardian unlinked';
        case 'admission_application.converted':
            return 'Admitted from an admission application';
        default:
            return entry.eventType;
    }
}

const categoryLabels: Record<HistoryEntry['category'], string> = {
    record: 'Student record',
    placement: 'Class placement',
    guardian: 'Guardians',
    admission: 'Admission',
};

const hiddenCategories = computed(() =>
    (Object.keys(categoryLabels) as HistoryEntry['category'][]).filter(
        (c) => !props.categories.includes(c),
    ),
);
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← {{ fullName }}</a>

        <h1 class="mt-2 text-xl font-semibold">History</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ fullName }} · {{ student.studentNumber }}. Changes saved to this Student's record,
            newest first, from the School audit trail. Times are in {{ timezone }}.
        </p>
        <p v-if="hiddenCategories.length > 0" class="mt-2 text-sm text-slate-500">
            Not shown to you: {{ hiddenCategories.map((c) => categoryLabels[c]).join(', ') }}.
        </p>

        <EmptyState
            v-if="entries.length === 0"
            class="mt-6"
            title="No recorded history"
            description="Nothing has been recorded for this Student that you can see."
        />

        <ol v-else class="mt-6 divide-y divide-slate-100 border-y border-slate-100">
            <li
                v-for="entry in entries"
                :key="entry.id"
                class="flex flex-wrap gap-x-6 gap-y-1 py-3 text-sm"
            >
                <div class="w-44 shrink-0 text-slate-500">
                    <time :datetime="entry.recordedAt">{{ recorded(entry) }}</time>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ describe(entry) }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ categoryLabels[entry.category] }}
                        <template v-if="entry.effectiveOn">
                            · effective {{ entry.effectiveOn }}</template
                        >
                        <template
                            v-if="
                                entry.eventType === 'student.status_changed' && entry.previousStatus
                            "
                        >
                            · was {{ entry.previousStatus }}</template
                        >
                        <template v-if="entry.guardianId">
                            ·
                            <a class="underline" :href="`/app/guardians/${entry.guardianId}`"
                                >Guardian</a
                            >
                        </template>
                    </p>
                </div>
            </li>
        </ol>

        <p v-if="truncated" class="mt-4 text-sm text-slate-500">
            Showing the {{ limit }} most recent entries. Older entries are in the School audit log.
        </p>
        <p class="mt-6 text-xs text-slate-500">
            A status change has no separate effective date; the recorded time is when it was saved.
            Who made each change is shown in the School audit log to staff who may review it.
        </p>
    </main>
</template>
