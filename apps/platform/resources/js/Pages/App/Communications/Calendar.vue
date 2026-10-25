<script setup lang="ts">
// ORG.9D-3 (ADR 0076 §75): the staff School calendar -- published Events the
// viewer's CURRENT audience membership reaches (School-wide; Staff while
// they are current staff). Informational only: an Event never changes
// Attendance, the timetable, exams or leave. ORG.9D-3R1 (§76): a consumer
// page (communications.events.view) -- no management action and no link into
// the Communication Hub, which its reader may not hold.
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import SchoolEventList from '../../../Components/SchoolEventList.vue';
import type { SchoolEvent } from '../../../schoolEvents';

const props = defineProps<{
    events: SchoolEvent[];
    filters: { when: 'upcoming' | 'past'; month: string };
    schoolTimezone: string;
}>();

const month = ref(props.filters.month);

function go(when: 'upcoming' | 'past'): void {
    router.get(
        '/app/communications/calendar',
        { when, month: month.value || undefined },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="School calendar" />
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">School calendar</h1>
        <p class="mt-1 text-sm text-slate-500">
            School events for you. Times are in the School's timezone ({{ schoolTimezone }}). Events
            are information only; they do not change attendance, the timetable or leave.
        </p>

        <nav class="mt-4 flex flex-wrap items-end gap-3" aria-label="Calendar filters">
            <button
                type="button"
                class="rounded border px-3 py-1.5 text-sm"
                :class="
                    filters.when === 'upcoming'
                        ? 'border-slate-900 font-medium'
                        : 'border-slate-300'
                "
                :aria-pressed="filters.when === 'upcoming'"
                @click="go('upcoming')"
            >
                Upcoming
            </button>
            <button
                type="button"
                class="rounded border px-3 py-1.5 text-sm"
                :class="
                    filters.when === 'past' ? 'border-slate-900 font-medium' : 'border-slate-300'
                "
                :aria-pressed="filters.when === 'past'"
                @click="go('past')"
            >
                Past
            </button>
            <label class="text-xs text-slate-600"
                >Month
                <input
                    v-model="month"
                    type="month"
                    class="mt-1 block rounded border border-slate-300 px-2 py-1 text-sm"
                    @change="go(filters.when)"
                />
            </label>
        </nav>

        <EmptyState
            v-if="events.length === 0"
            class="mt-6"
            :title="filters.when === 'past' ? 'No past events' : 'No upcoming events'"
            description="Published School events for you appear here."
        />
        <SchoolEventList v-else class="mt-6" :events="events" />
    </main>
</template>
