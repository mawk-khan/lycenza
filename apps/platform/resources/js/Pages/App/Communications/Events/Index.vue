<script setup lang="ts">
// ORG.9D-3 (ADR 0076 §75): Events -- every School calendar Event in any
// state, for the people who prepare and publish them.
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import SchoolEventList from '../../../../Components/SchoolEventList.vue';
import type { SchoolEvent } from '../../../../schoolEvents';

const props = defineProps<{
    events: SchoolEvent[];
    meta: { currentPage: number; lastPage: number; total: number };
    filters: { status: string; when: string; audience: string; month: string };
    schoolTimezone: string;
}>();

const status = ref(props.filters.status);
const when = ref(props.filters.when);
const audience = ref(props.filters.audience);
const month = ref(props.filters.month);

function apply(page = 1): void {
    router.get(
        '/app/communications/events',
        {
            status: status.value || undefined,
            when: when.value || undefined,
            audience: audience.value || undefined,
            month: month.value || undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Events" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/communications">← Communication Hub</a>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Events</h1>
            <a
                class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white"
                href="/app/communications/events/create"
                >New event</a
            >
        </div>
        <p class="mt-1 text-sm text-slate-500">
            School calendar events. Every event is approved by someone else before it is published;
            publishing shows it on the calendar and sends nothing. Times are in the School's
            timezone ({{ schoolTimezone }}).
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="apply()">
            <label class="text-xs text-slate-600"
                >State
                <select
                    v-model="status"
                    class="mt-1 block rounded border border-slate-300 px-2 py-1 text-sm"
                    @change="apply()"
                >
                    <option value="">Any</option>
                    <option value="draft">Draft</option>
                    <option value="pending_approval">Waiting for approval</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="published">Published</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </label>
            <label class="text-xs text-slate-600"
                >When
                <select
                    v-model="when"
                    class="mt-1 block rounded border border-slate-300 px-2 py-1 text-sm"
                    @change="apply()"
                >
                    <option value="">Any</option>
                    <option value="upcoming">Upcoming</option>
                    <option value="past">Past</option>
                </select>
            </label>
            <label class="text-xs text-slate-600"
                >Audience
                <select
                    v-model="audience"
                    class="mt-1 block rounded border border-slate-300 px-2 py-1 text-sm"
                    @change="apply()"
                >
                    <option value="">Any</option>
                    <option value="school_wide">Whole School</option>
                    <option value="staff">Staff</option>
                    <option value="grade">Guardians of a Grade</option>
                    <option value="section">Guardians of a Section</option>
                </select>
            </label>
            <label class="text-xs text-slate-600"
                >Month
                <input
                    v-model="month"
                    type="month"
                    class="mt-1 block rounded border border-slate-300 px-2 py-1 text-sm"
                    @change="apply()"
                />
            </label>
        </form>

        <EmptyState
            v-if="events.length === 0"
            class="mt-6"
            title="No events"
            description="Events you create appear here."
        />
        <template v-else>
            <SchoolEventList
                class="mt-6"
                :events="events"
                show-status
                :link-to="(e) => `/app/communications/events/${e.id}`"
            />
            <nav v-if="meta.lastPage > 1" class="mt-4 flex gap-3 text-sm" aria-label="Pages">
                <button
                    v-if="meta.currentPage > 1"
                    type="button"
                    class="underline"
                    @click="apply(meta.currentPage - 1)"
                >
                    Previous
                </button>
                <span>Page {{ meta.currentPage }} of {{ meta.lastPage }}</span>
                <button
                    v-if="meta.currentPage < meta.lastPage"
                    type="button"
                    class="underline"
                    @click="apply(meta.currentPage + 1)"
                >
                    Next
                </button>
            </nav>
        </template>
    </main>
</template>
