<script setup lang="ts">
// HR.R1-C6 (PAY-18, OD-HR-23): a reversal took independence from a posted run.
// Everything here comes from the server. An empty list means the query ran and
// found nothing: a failed read fails the page, it never shows as nothing open.
import { Head, Link } from '@inertiajs/vue3';
import StatusBadge from '../../../../Components/StatusBadge.vue';
import Pagination from '../../../../Components/Pagination.vue';
import { formatDateTime } from '../../../../datetime';

interface Row {
    id: string;
    state: 'open' | 'reviewed';
    openedAt: string;
    reviewedAt: string | null;
    affectedRun: { id: string; periodMonth: string };
    reversedRun: { id: string; periodMonth: string; reversedAt: string };
}

interface Props {
    state: 'open' | 'reviewed' | 'all';
    reconciliations: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
}

defineProps<Props>();

const filters = [
    { key: 'open', label: 'Open' },
    { key: 'reviewed', label: 'Reviewed' },
    { key: 'all', label: 'All' },
] as const;
</script>

<template>
    <Head title="Payroll reconciliations" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/payroll">← Payroll</a>
        <h1 class="mt-2 text-xl font-semibold">Payroll reconciliations</h1>
        <p class="mt-2 text-sm text-ink-muted">
            A reversal took independence from a payroll run that had already posted. Someone
            independent must read what happened. This blocks no other payroll work, and it stays
            open until it is reviewed.
        </p>

        <nav class="mt-4 flex gap-3 text-sm" aria-label="Filter reconciliations">
            <Link
                v-for="f in filters"
                :key="f.key"
                :href="`/app/payroll/reconciliations?state=${f.key}`"
                :aria-current="state === f.key ? 'page' : undefined"
                :class="state === f.key ? 'font-semibold underline' : 'underline'"
            >
                {{ f.label }}
            </Link>
        </nav>

        <p v-if="reconciliations.total === 0" class="mt-6 text-sm">
            {{
                state === 'open'
                    ? 'No reconciliations are open.'
                    : 'No reconciliations match this filter.'
            }}
        </p>
        <div
            v-else
            class="mt-4 overflow-x-auto"
            tabindex="0"
            role="region"
            aria-label="Payroll reconciliations, scrollable"
        >
            <table class="data-table w-full min-w-[36rem] text-sm">
                <caption class="sr-only">
                    Payroll reconciliations
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Affected run</th>
                        <th scope="col">Reversed run</th>
                        <th scope="col">Opened</th>
                        <th scope="col">State</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in reconciliations.data" :key="r.id">
                        <td>
                            <Link class="underline" :href="`/app/payroll/reconciliations/${r.id}`">
                                {{ r.affectedRun.periodMonth }}
                            </Link>
                        </td>
                        <td>{{ r.reversedRun.periodMonth }}</td>
                        <td>{{ formatDateTime(r.openedAt) }}</td>
                        <td><StatusBadge :status="r.state" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination
            :links="reconciliations.links"
            :from="reconciliations.from"
            :to="reconciliations.to"
            :total="reconciliations.total"
        />
    </main>
</template>
