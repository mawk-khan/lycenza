<script setup lang="ts">
// SWC.3 (ADR 0073 section 19.3): Hostel fee intent (OPF.2) -- what each
// active residency recorded, per year, and from which mapping (a room
// override or the Hostel default) -- and the next-year carry-forward. A
// view of recorded intent, not a ledger.
import { Head } from '@inertiajs/vue3';
import CarryForwardForm from '../../../Components/Fees/CarryForwardForm.vue';
import FeeIntentTable from '../../../Components/Fees/FeeIntentTable.vue';
import Pagination from '../../../Components/Pagination.vue';
import type { FeeIntentRow } from '../../../support/feeIntent';

const props = defineProps<{
    residencies: {
        data: Array<{ id: string; studentName: string; place: string; intent: FeeIntentRow[] }>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    openYears: Array<{ id: string; name: string; status: string }>;
    carryForward: {
        academicYearId: string;
        linked: number;
        alreadyLinked: number;
        unmapped: number;
        ended: number;
        notApplicable: number;
    } | null;
    canCarryForward: boolean;
}>();
</script>

<template>
    <Head title="Hostel fees" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hostel-residency">← Residency</a>
        <h1 class="mt-2 text-xl font-semibold">Hostel fees</h1>
        <p class="mt-1 text-sm text-slate-600">
            A room's own fee head, or else its Hostel's, is recorded as fee intent when a Student
            takes up residency. Fee intent is not a charge or a payment — Finance creates charges
            when it assesses fees.
        </p>

        <CarryForwardForm
            v-if="props.canCarryForward"
            id="hostel-carry-forward"
            action="/app/hostel-residency/fees/carry-forward"
            source-label="residency"
            :open-years="props.openYears"
            :result="props.carryForward"
        />

        <section class="mt-8" aria-labelledby="hostel-intent-heading">
            <h2 id="hostel-intent-heading" class="font-semibold">Fee intent by active residency</h2>
            <p v-if="props.residencies.data.length === 0" class="mt-2 text-sm text-slate-600">
                No active residency.
            </p>
            <div
                v-for="residency in props.residencies.data"
                :key="residency.id"
                class="mt-4 rounded border border-slate-200 p-3"
            >
                <h3 class="text-sm font-semibold">
                    {{ residency.studentName }} — {{ residency.place }}
                </h3>
                <div class="mt-2">
                    <FeeIntentTable
                        :rows="residency.intent"
                        :caption="`Fee intent for ${residency.studentName}`"
                        show-scope
                    />
                </div>
            </div>
            <Pagination :links="props.residencies.links" />
        </section>
    </main>
</template>
