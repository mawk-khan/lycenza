<script setup lang="ts">
// ORG.9D-3 (ADR 0076 §75): the Guardian's School calendar (development only
// until E46) -- School-wide Events and those for the Grade or Section their
// children are in now. Never staff-only Events.
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import SchoolEventList from '../../../Components/SchoolEventList.vue';
import type { SchoolEvent } from '../../../schoolEvents';

const props = defineProps<{
    schoolName: string;
    events: SchoolEvent[];
    filters: { when: 'upcoming' | 'past'; month: string };
    schoolTimezone: string;
}>();

const month = ref(props.filters.month);

function go(when: 'upcoming' | 'past'): void {
    router.get(
        '/app/portal/calendar',
        { when, month: month.value || undefined },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="School calendar" />
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">{{ schoolName }} — School calendar</h1>
        <p class="mt-1 text-sm text-slate-500">
            Times are in the School's timezone ({{ schoolTimezone }}).
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
            description="School events for your family appear here."
        />
        <SchoolEventList v-else class="mt-6" :events="events" />
    </main>
</template>
