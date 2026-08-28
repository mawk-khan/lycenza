<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../../../Components/EmptyState.vue';
import Pagination from '../../../../../Components/Pagination.vue';

interface JournalEntryRow {
    id: string;
    currency: string;
    description: string;
    postedAt: string;
    reversalOfJournalEntryId: string | null;
    reversedByJournalEntryId: string | null;
    lineCount: number;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    journalEntries: {
        data: JournalEntryRow[];
        links: PageLink[];
        total: number;
    };
    filters: {
        posted_from: string;
        posted_to: string;
        ledger_account_id: string;
        reversed_only: boolean;
        search: string;
    };
    accounts: Array<{ id: string; code: string; name: string }>;
    canPost: boolean;
}

const props = defineProps<Props>();

const postedFrom = ref(props.filters.posted_from);
const postedTo = ref(props.filters.posted_to);
const ledgerAccountId = ref(props.filters.ledger_account_id);
const reversedOnly = ref(props.filters.reversed_only);
const search = ref(props.filters.search);

function applyFilters(): void {
    router.get(
        '/app/finance/journal-entries',
        {
            posted_from: postedFrom.value || undefined,
            posted_to: postedTo.value || undefined,
            ledger_account_id: ledgerAccountId.value || undefined,
            reversed_only: reversedOnly.value || undefined,
            search: search.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

const hasFilters =
    postedFrom.value ||
    postedTo.value ||
    ledgerAccountId.value ||
    reversedOnly.value ||
    search.value;
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/finance">← Finance</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Journal entries</h1>
                <p class="mt-1 text-sm text-slate-500">Posted Ledger history for this School.</p>
            </div>
            <a
                v-if="canPost"
                href="/app/finance/journal-entries/create"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
            >
                Post journal entry
            </a>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-sm text-slate-600" for="filter-posted-from"
                    >Posted from</label
                >
                <input
                    id="filter-posted-from"
                    v-model="postedFrom"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-posted-to">Posted to</label>
                <input
                    id="filter-posted-to"
                    v-model="postedTo"
                    type="date"
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                />
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-account">Account</label>
                <select
                    id="filter-account"
                    v-model="ledgerAccountId"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All accounts</option>
                    <option v-for="account in accounts" :key="account.id" :value="account.id">
                        {{ account.code }} — {{ account.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="filter-search">Description</label>
                <input
                    id="filter-search"
                    v-model="search"
                    type="text"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                />
            </div>
            <label class="mb-2 flex items-center gap-2 text-sm text-slate-600">
                <input v-model="reversedOnly" type="checkbox" @change="applyFilters" />
                Reversals only
            </label>
        </form>

        <EmptyState
            v-if="journalEntries.data.length === 0"
            class="mt-6"
            :title="hasFilters ? 'No journal entries match your filters' : 'No journal entries yet'"
            :description="
                hasFilters
                    ? 'Try a different date range, account, or description.'
                    : canPost
                      ? 'Post the first journal entry to begin building this School\'s Ledger history.'
                      : 'Nothing has been posted to this School\'s Ledger yet.'
            "
        >
            <template v-if="canPost && !hasFilters" #action>
                <a
                    href="/app/finance/journal-entries/create"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                >
                    Post journal entry
                </a>
            </template>
        </EmptyState>

        <template v-else>
            <table class="mt-6 hidden w-full text-left text-sm md:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th scope="col" class="py-2 font-medium">Posted</th>
                        <th scope="col" class="py-2 font-medium">Description</th>
                        <th scope="col" class="py-2 font-medium">Lines</th>
                        <th scope="col" class="py-2 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="entry in journalEntries.data" :key="entry.id">
                        <td class="py-3 text-slate-600">{{ entry.postedAt }}</td>
                        <td class="py-3">
                            <a
                                class="font-medium underline"
                                :href="`/app/finance/journal-entries/${entry.id}`"
                                >{{ entry.description }}</a
                            >
                            <span
                                v-if="entry.reversalOfJournalEntryId"
                                class="ml-1 text-xs text-slate-400"
                                >(reversal)</span
                            >
                        </td>
                        <td class="py-3 text-slate-600">{{ entry.lineCount }}</td>
                        <td class="py-3">
                            <span
                                v-if="entry.reversedByJournalEntryId"
                                class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"
                            >
                                Reversed
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                            >
                                Posted
                            </span>
                        </td>
                        <td class="py-3 text-right">
                            <a
                                class="text-sm underline"
                                :href="`/app/finance/journal-entries/${entry.id}`"
                                >View</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="mt-6 space-y-3 md:hidden">
                <li
                    v-for="entry in journalEntries.data"
                    :key="entry.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <a
                        class="font-medium underline"
                        :href="`/app/finance/journal-entries/${entry.id}`"
                        >{{ entry.description }}</a
                    >
                    <p class="mt-1 text-sm text-slate-500">
                        {{ entry.postedAt }} · {{ entry.lineCount }} lines
                    </p>
                </li>
            </ul>

            <Pagination :links="journalEntries.links" />
        </template>
    </main>
</template>
