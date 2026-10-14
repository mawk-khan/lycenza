<script setup lang="ts">
// SWC.3 (ADR 0073 section 19.2): Transport fee intent (OPF.1) -- what each
// active Student assignment recorded, per year -- and the next-year
// carry-forward. A view of recorded intent, not a ledger: Finance owns
// charges and payments.
import { Head } from '@inertiajs/vue3';
import CarryForwardForm from '../../../Components/Fees/CarryForwardForm.vue';
import FeeIntentTable from '../../../Components/Fees/FeeIntentTable.vue';
import Pagination from '../../../Components/Pagination.vue';
import type { FeeIntentRow } from '../../../support/feeIntent';

const props = defineProps<{
    assignments: {
        data: Array<{
            id: string;
            studentName: string;
            routeName: string;
            routeId: string;
            intent: FeeIntentRow[];
        }>;
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
    <Head title="Transport fees" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/transport/assignments">← Student assignments</a>
        <h1 class="mt-2 text-xl font-semibold">Transport fees</h1>
        <p class="mt-1 text-sm text-slate-600">
            A route's fee head (set on the route) is recorded as fee intent when a Student is
            assigned. Fee intent is not a charge or a payment — Finance creates charges when it
            assesses fees.
        </p>

        <CarryForwardForm
            v-if="props.canCarryForward"
            id="transport-carry-forward"
            action="/app/transport/fees/carry-forward"
            source-label="Student transport assignment"
            :open-years="props.openYears"
            :result="props.carryForward"
        />

        <section class="mt-8" aria-labelledby="transport-intent-heading">
            <h2 id="transport-intent-heading" class="font-semibold">
                Fee intent by active assignment
            </h2>
            <p v-if="props.assignments.data.length === 0" class="mt-2 text-sm text-slate-600">
                No active assignment.
            </p>
            <div
                v-for="assignment in props.assignments.data"
                :key="assignment.id"
                class="mt-4 rounded border border-slate-200 p-3"
            >
                <h3 class="text-sm font-semibold">
                    {{ assignment.studentName }} —
                    <a class="underline" :href="`/app/transport/routes/${assignment.routeId}`">{{
                        assignment.routeName
                    }}</a>
                </h3>
                <div class="mt-2">
                    <FeeIntentTable
                        :rows="assignment.intent"
                        :caption="`Fee intent for ${assignment.studentName}`"
                    />
                </div>
            </div>
            <Pagination :links="props.assignments.links" />
        </section>
    </main>
</template>
